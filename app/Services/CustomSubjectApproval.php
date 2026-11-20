<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomSubjectApproval
{
    /** Called by the source mutation transaction. Pending names never become subjects. */
    public function resolve(array $data, string $field, int $userId, ?int $limit): array
    {
        $type = match ($field) {
            'primarySubjects' => 'teacher', 'subjectIds' => 'institute', 'courseIds' => 'job',
            default => throw new \LogicException('Unknown course selection field.'),
        };
        if (! array_key_exists($field, $data) && empty($data['otherSubjects'])) {
            return $data;
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Submit courses inside the profile transaction.');
        }
        // Explicit selections replace earlier pending requests, preventing a late
        // approval from attaching a course the provider no longer selected.
        $pending = DB::table('custom_subject_requests')->where('user_id', $userId)->where('profile_type', $type)
            ->where('status', 'pending')->lockForUpdate()->get();
        $names = [];
        foreach ($data['otherSubjects'] ?? [] as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', $name));
            $names[hash('sha256', mb_strtolower($name))] ??= $name;
        }
        foreach ($pending as $request) {
            if ($type !== 'job' && ! isset($names[$request->name_hash])) {
                DB::table('custom_subject_requests')->where('id', $request->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                $this->event('course_request.cancelled', $request->id, $userId, 'pending', 'cancelled');
            }
        }
        if ($names === []) {
            return $data;
        }
        $ids = array_map('intval', $data[$field] ?? ($type === 'job' ? [] : ProfileEditor::subjects($userId, $type)->pluck('id')->all()));
        // This existing lock also serializes catalogue creation with legacy paths.
        DB::table('subject_domains')->insertOrIgnore(['name' => 'Other', 'sort_order' => 999]);
        $domain = DB::table('subject_domains')->where('name', 'Other')->lockForUpdate()->first();
        $pendingCount = 0;
        $pendingCourses = [];
        foreach ($names as $hash => $name) {
            $subject = DB::table('subjects')->where('domain_id', $domain->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($subject !== null) {
                $ids[] = (int) $subject->id;

                continue;
            }
            $pendingCount++;
            $request = DB::table('custom_subject_requests')->where('user_id', $userId)->where('profile_type', $type)
                ->where('name_hash', $hash)->lockForUpdate()->first();
            if ($request === null) {
                $id = DB::table('custom_subject_requests')->insertGetId([
                    'user_id' => $userId, 'profile_type' => $type, 'name' => $name, 'name_hash' => $hash,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->event('course_request.submitted', $id, $userId, null, 'pending');
            } elseif ($request->status !== 'pending') {
                DB::table('custom_subject_requests')->where('id', $request->id)->update([
                    'status' => 'pending', 'name' => $name, 'subject_id' => null, 'reviewed_at' => null, 'updated_at' => now(),
                ]);
                $this->event('course_request.submitted', $request->id, $userId, $request->status, 'pending');
            }
            $pendingCourses[] = ['request_id' => $request->id ?? $id, 'name' => $name];
        }
        $data[$field] = array_values(array_unique($ids));
        if ($limit !== null && count($data[$field]) + $pendingCount > $limit) {
            throw ValidationException::withMessages(['otherSubjects' => 'Choose at most five subjects including courses awaiting approval.']);
        }

        if ($type === 'job') {
            $data['_pendingCourses'] = $pendingCourses;
        }

        return $data;
    }

    public function review(int $id, string $decision, ?int $domainId = null): bool
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose approve or reject.']);
        }

        return DB::transaction(function () use ($id, $decision, $domainId): bool {
            $candidate = DB::table('custom_subject_requests')->where('id', $id)->first(['user_id']);
            abort_if($candidate === null, 404);
            // Match registration/profile saves: serialize all decisions for an
            // owner before locking their requests and catalogue domain.
            $user = DB::table('users')->where('id', $candidate->user_id)->lockForUpdate()->first(['id', 'account_type']);
            $request = DB::table('custom_subject_requests')->where('id', $id)->lockForUpdate()->first();
            abort_if($request === null, 404);
            if ($request->status !== 'pending') {
                return false;
            }
            $subjectId = null;
            if ($decision === 'approved') {
                if ($user === null || ($request->profile_type !== 'job' && $user->account_type !== $request->profile_type)) {
                    throw ValidationException::withMessages(['course' => 'The submitting provider account is no longer available. Reject this request.']);
                }
                $table = $request->profile_type === 'job' ? null : ProfileEditor::table($request->profile_type);
                if ($table !== null) {
                    abort_if(DB::table($table)->where('user_id', $user->id)->lockForUpdate()->first() === null, 422, 'Provider profile not found.');
                }
                if ($domainId === null) {
                    DB::table('subject_domains')->insertOrIgnore(['name' => 'Other', 'sort_order' => 999]);
                    $domain = DB::table('subject_domains')->where('name', 'Other')->lockForUpdate()->first();
                } else {
                    $domain = DB::table('subject_domains')->where('id', $domainId)->lockForUpdate()->first();
                    if ($domain === null) {
                        throw ValidationException::withMessages(['domain_id' => 'Choose an existing category.']);
                    }
                }
                $subjectId = DB::table('subjects')->where('domain_id', $domain->id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($request->name)])->value('id');
                $selected = $table === null ? [] : DB::table('profile_subjects')->where('profile_type', $request->profile_type)->where('profile_id', $user->id)->pluck('subject_id')->map(fn ($value) => (int) $value)->all();
                $limit = $table === null ? null : ProfileEditor::subjectLimit((int) $user->id, $request->profile_type);
                if ($limit !== null && ! in_array((int) $subjectId, $selected, true) && count($selected) >= $limit) {
                    throw ValidationException::withMessages(['course' => 'This profile already has five subjects. Ask the provider to remove one before approving.']);
                }
                if ($subjectId === null) {
                    $subjectId = DB::table('subjects')->insertGetId(['domain_id' => $domain->id, 'name' => $request->name, 'is_bsl' => false]);
                    app(BusinessActivityRecorder::class)->record('subject.other_created', $subjectId, null, $request->user_id,
                        after: ['domain_id' => (int) $domain->id, 'name' => $request->name]);
                }
                if ($table !== null) {
                    DB::table('profile_subjects')->insertOrIgnore(['profile_type' => $request->profile_type, 'profile_id' => $user->id, 'subject_id' => $subjectId]);
                    DB::table($table)->where('user_id', $user->id)->update(['updated_at' => now()]);
                }
            }
            DB::table('custom_subject_requests')->where('id', $id)->update([
                'status' => $decision, 'subject_id' => $subjectId, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('course_request.reviewed', $id, null, $request->user_id,
                before: ['status' => 'pending'], after: ['status' => $decision, 'subject_id' => $subjectId]);
            if ($decision === 'approved' && $request->profile_type !== 'job') {
                app($request->profile_type === 'teacher' ? TeacherPublicPages::class : InstitutePublicPages::class)->publish((int) $request->user_id);
            }

            return true;
        }, 3);
    }

    private function event(string $event, int $id, int $actor, ?string $before, string $after): void
    {
        app(BusinessActivityRecorder::class)->record($event, $id, $actor,
            before: ['status' => $before], after: ['status' => $after]);
    }
}
