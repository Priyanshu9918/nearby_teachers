<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Validation\ValidationException;

final class OtherSubjects
{
    public static function rules(?int $maxSubjects = 5): array
    {
        return [
            'otherSubjects' => ['sometimes', 'array', 'min:1', ...($maxSubjects === null ? [] : ['max:'.$maxSubjects])],
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
    public function resolve(array $data, string $field, int $actorId, bool $recordProviderSelection = true, ?int $maxSubjects = 5): array
    {
        $resolved = app(CustomSubjectApproval::class)->resolve($data, $field, $actorId, $maxSubjects);
        if ($recordProviderSelection && ! empty($data['otherSubjects'])) {
            app(BusinessActivityRecorder::class)->record('provider.custom_subjects_selected', $actorId, $actorId,
                after: ['subject_ids' => $resolved[$field] ?? []]);
        }

        return $resolved;
    }

    private static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }
}
