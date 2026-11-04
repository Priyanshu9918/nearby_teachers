<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OtherSubjects
{
    public static function rules(): array
    {
        return [
            'otherSubjects' => ['sometimes', 'array', 'min:1', 'max:5'],
            'otherSubjects.*' => ['required', 'string', 'max:200'],
        ];
    }

    public static function validateSelection(array $data): void
    {
        foreach ($data['otherSubjects'] ?? [] as $index => $name) {
            $normalized = self::normalize($name);
            if ($normalized === '') {
                throw ValidationException::withMessages(['otherSubjects.'.$index => 'Enter a course name.']);
            }
        }
    }

    /** Resolve only inside the source transaction, so failed source mutations leave no catalogue entries. */
    public function resolve(array $data, string $field, int $actorId, bool $recordProviderSelection = true): array
    {
        if (empty($data['otherSubjects'])) {
            return $data;
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Resolve custom subjects inside the source transaction.');
        }
        // The unique category name plus this row lock serializes concurrent custom submissions.
        DB::table('subject_domains')->insertOrIgnore(['name' => 'Other', 'sort_order' => 999]);
        $domain = DB::table('subject_domains')->where('name', 'Other')->lockForUpdate()->first();
        $ids = array_map('intval', $data[$field] ?? []);
        foreach ($data['otherSubjects'] as $name) {
            $name = self::normalize($name);
            $subject = DB::table('subjects')->where('domain_id', $domain->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->lockForUpdate()->first();
            if ($subject === null) {
                $id = DB::table('subjects')->insertGetId(['domain_id' => $domain->id, 'name' => $name, 'is_bsl' => false]);
                app(BusinessActivityRecorder::class)->record('subject.other_created', $id, $actorId,
                    after: ['domain_id' => (int) $domain->id, 'name' => $name]);
            } else {
                $id = (int) $subject->id;
            }
            $ids[] = $id;
        }
        $data[$field] = array_values(array_unique($ids));
        if (count($data[$field]) > 5) {
            throw ValidationException::withMessages(['otherSubjects' => 'Choose at most five subjects including custom courses.']);
        }
        if ($recordProviderSelection) {
            app(BusinessActivityRecorder::class)->record('provider.custom_subjects_selected', $actorId, $actorId,
                after: ['subject_ids' => $data[$field]]);
        }

        return $data;
    }

    private static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }
}
