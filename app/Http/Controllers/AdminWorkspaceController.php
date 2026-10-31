<?php

namespace App\Http\Controllers;

use App\Reporting\BusinessActivityRecorder;
use App\Reporting\ReportAccessLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminWorkspaceController extends Controller
{
    public function __construct(private readonly ReportAccessLog $access) {}

    public function photos(Request $request): View
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:15,25,50'],
        ]);
        $photos = DB::table('users')->where('avatar_status', 'pending')
            ->select('id', 'full_name', 'avatar_url', 'account_type', 'updated_at')
            ->orderBy('updated_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $this->access->record($request, 'users', 'photo-review-list');

        return view('admin.moderation.photos', compact('photos'));
    }

    public function reviewPhoto(Request $request, int $userId): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        $changed = DB::transaction(function () use ($data, $userId): bool {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()
                ->first(['id', 'avatar_status']);
            abort_if($user === null, 404);
            if ($user->avatar_status !== 'pending') {
                return false;
            }

            DB::table('users')->where('id', $userId)->update([
                'avatar_status' => $data['status'],
                'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('avatar.reviewed', (string) $userId, null, $userId,
                before: ['avatar_status' => $user->avatar_status], after: ['avatar_status' => $data['status']]);

            return true;
        }, 3);
        $this->access->record($request, 'users', 'photo-review', $userId);

        if (! $changed) {
            return back()->withErrors(['photo' => 'This photo has already been reviewed. The queue has been refreshed.']);
        }

        return back()->with('status', 'Photo '.($data['status'] === 'approved' ? 'approved' : 'rejected').'.');
    }

    public function bslCentres(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:15,25,50'],
        ]);
        $centres = DB::table('institute_profiles as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where(fn ($query) => $query->where('p.institute_type', 'bsl_direct')->orWhere('p.is_bsl_official', true))
            ->when(filled($filters['q'] ?? null), fn ($query) => $query->where(function ($search) use ($filters): void {
                $term = '%'.trim($filters['q']).'%';
                $search->where('u.full_name', 'like', $term)->orWhere('p.city', 'like', $term);
            }))
            ->select('p.id', 'p.user_id', 'p.institute_type', 'p.is_bsl_official', 'p.bsl_official_since', 'p.city', 'p.country', 'p.is_profile_complete', 'u.full_name')
            ->orderByDesc('p.is_bsl_official')->orderBy('u.full_name')->orderBy('p.id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $this->access->record($request, 'institute-profiles', 'bsl-list');

        return view('admin.moderation.bsl-centres', compact('centres', 'filters'));
    }

    public function setBslVerified(Request $request, int $userId): RedirectResponse
    {
        $data = $request->validate(['verified' => ['required', 'boolean']]);
        $verified = (bool) $data['verified'];
        $changed = DB::transaction(function () use ($verified, $userId): bool {
            $profile = DB::table('institute_profiles')->where('user_id', $userId)->lockForUpdate()
                ->first(['id', 'user_id', 'institute_type', 'is_bsl_official']);
            abort_if($profile === null, 404);
            if ($verified && $profile->institute_type !== 'bsl_direct') {
                throw ValidationException::withMessages([
                    'verified' => 'BSL Verified is only for directly operated BSL centres.',
                ]);
            }

            $wasVerified = (bool) $profile->is_bsl_official;
            if ($wasVerified === $verified) {
                return false;
            }

            DB::table('institute_profiles')->where('user_id', $userId)->update([
                'is_bsl_official' => $verified,
                'bsl_official_since' => $verified ? now() : null,
                'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('institute.bsl_verified_changed', (string) $profile->id,
                null, $userId, before: ['is_bsl_official' => $wasVerified], after: ['is_bsl_official' => $verified]);

            return true;
        }, 3);
        $this->access->record($request, 'institute-profiles', 'bsl-verification', $userId);

        return back()->with('status', $changed
            ? ($verified ? 'BSL Verified badge granted.' : 'BSL Verified badge removed.')
            : 'No change was needed.');
    }

    public function suspendedUsers(Request $request): View
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:15,25,50'],
        ]);
        $users = DB::table('users as u')->leftJoin('users as admin', 'admin.id', '=', 'u.suspended_by')
            ->whereNotNull('u.suspended_at')
            ->where('u.is_admin', false)
            ->select('u.id', 'u.full_name', 'u.account_type', 'u.email', 'u.phone', 'u.suspended_at', 'u.suspended_reason', 'admin.full_name as suspended_by_name')
            ->orderByDesc('u.suspended_at')->orderBy('u.id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $this->access->record($request, 'users', 'suspended-list');

        return view('admin.moderation.suspended-users', compact('users'));
    }

    public function suspendUser(Request $request, int $userId): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $changed = DB::transaction(function () use ($data, $userId): bool {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()
                ->first(['id', 'is_admin', 'suspended_at', 'suspended_reason']);
            abort_if($user === null, 404);
            if ($user->is_admin) {
                throw ValidationException::withMessages(['user' => 'Admin accounts cannot be suspended here.']);
            }
            if ($user->suspended_at !== null) {
                return false;
            }

            DB::table('users')->where('id', $userId)->update([
                'suspended_at' => now(),
                'suspended_reason' => trim($data['reason']),
                'suspended_by' => null,
                'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('user.suspended', (string) $userId, null, $userId,
                before: ['suspended' => false, 'reason_set' => false],
                after: ['suspended' => true, 'reason_set' => true]);

            return true;
        }, 3);
        $this->access->record($request, 'users', 'suspend', $userId);

        return back()->with('status', $changed ? 'Account suspended.' : 'This account is already suspended.');
    }

    public function unsuspendUser(Request $request, int $userId): RedirectResponse
    {
        $changed = DB::transaction(function () use ($userId): bool {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()
                ->first(['id', 'is_admin', 'suspended_at', 'suspended_reason']);
            abort_if($user === null, 404);
            if ($user->is_admin) {
                throw ValidationException::withMessages(['user' => 'Admin accounts cannot be changed here.']);
            }
            if ($user->suspended_at === null) {
                return false;
            }

            DB::table('users')->where('id', $userId)->update([
                'suspended_at' => null,
                'suspended_reason' => null,
                'suspended_by' => null,
                'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('user.unsuspended', (string) $userId, null, $userId,
                before: ['suspended' => true, 'reason_set' => $user->suspended_reason !== null],
                after: ['suspended' => false, 'reason_set' => false]);

            return true;
        }, 3);
        $this->access->record($request, 'users', 'unsuspend', $userId);

        return back()->with('status', $changed ? 'Account restored.' : 'This account is already active.');
    }

    public function possibleDuplicates(Request $request): View
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:15,25,50'],
        ]);
        $matches = DB::table('teacher_profiles as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereNotNull('p.city')->where('p.city', '!=', '')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('teacher_profiles as other_profile')
                    ->join('users as other_user', 'other_user.id', '=', 'other_profile.user_id')
                    ->whereColumn('other_user.id', '!=', 'u.id')
                    ->whereRaw('LOWER(TRIM(other_user.full_name)) = LOWER(TRIM(u.full_name))')
                    ->whereRaw('LOWER(TRIM(other_profile.city)) = LOWER(TRIM(p.city))')
                    ->whereRaw("LOWER(TRIM(COALESCE(other_profile.country, ''))) = LOWER(TRIM(COALESCE(p.country, '')))");
            })
            ->select('u.id', 'u.full_name', 'u.phone', 'p.city', 'p.country', 'p.created_at')
            ->orderBy('u.full_name')->orderBy('p.city')->orderBy('u.id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $this->access->record($request, 'teacher-profiles', 'possible-duplicates');

        return view('admin.moderation.possible-duplicates', compact('matches'));
    }

    public function updateSafetyReport(Request $request, int $reportId): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,reviewed,dismissed']]);
        $changed = DB::transaction(function () use ($data, $reportId): bool {
            $report = DB::table('reports')->where('id', $reportId)->lockForUpdate()
                ->first(['id', 'reporter_id', 'status']);
            abort_if($report === null, 404);
            if ($report->status === $data['status']) {
                return false;
            }

            DB::table('reports')->where('id', $reportId)->update(['status' => $data['status']]);
            app(BusinessActivityRecorder::class)->record('safety_report.status_changed', (string) $reportId,
                null, $report->reporter_id,
                before: ['status' => $report->status], after: ['status' => $data['status']]);

            return true;
        }, 3);
        $this->access->record($request, 'reports', 'safety-status', $reportId);

        return back()->with('status', $changed ? 'Safety report status updated.' : 'No change was needed.');
    }
}
