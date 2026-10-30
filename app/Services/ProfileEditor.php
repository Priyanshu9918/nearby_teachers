<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProfileEditor
{
    public static function table(string $type): string
    {
        return match ($type) {
            'teacher' => 'teacher_profiles',
            'institute' => 'institute_profiles',
            default => throw new InvalidArgumentException('Provider account required.'),
        };
    }

    public static function subjects(int $userId, string $type): Collection
    {
        self::table($type);

        return DB::table('profile_subjects as ps')->join('subjects as s', 's.id', '=', 'ps.subject_id')
            ->where('ps.profile_type', $type)->where('ps.profile_id', $userId)->orderBy('s.name')->orderBy('s.id')
            ->get(['s.id', 's.name', 's.is_bsl'])->map(function (object $subject): object {
                $subject->id = (int) $subject->id;
                $subject->is_bsl = (bool) $subject->is_bsl;

                return $subject;
            });
    }

    public static function missingFields(object $profile, string $type, bool $hasSubjects): array
    {
        self::table($type);
        $missing = [];
        if (! $hasSubjects) {
            $missing[] = $type === 'teacher' ? 'primarySubjects' : 'subjectIds';
        }
        if (trim((string) ($profile->city ?? '')) === '') {
            $missing[] = 'city';
        }
        if (trim((string) ($profile->district ?? '')) === '') {
            $missing[] = 'district';
        }
        if (SeoDirectory::country($profile) === null) {
            $missing[] = 'countryCode';
        }
        $story = $type === 'teacher' ? 'bio' : 'description';
        if (self::characterCount((string) ($profile->$story ?? '')) < 100) {
            $missing[] = $story;
        }
        $modes = $profile->lesson_modes ?? [];
        if (is_string($modes)) {
            $modes = json_decode($modes, true, flags: JSON_THROW_ON_ERROR);
        }
        if (empty($modes)) {
            $missing[] = 'lessonModes';
        }
        if ($type === 'institute' && trim((string) ($profile->contact_person ?? '')) === '') {
            $missing[] = 'contactPerson';
        }

        return $missing;
    }

    public static function characterCount(string $value): int
    {
        return mb_strlen(trim($value), 'UTF-8');
    }

    public static function urls(?object $profile, string $type): array
    {
        if ($type === 'teacher') {
            return TeacherPublicPages::urls($profile ?? (object) []);
        }
        self::table($type);
        $url = (bool) ($profile->is_profile_complete ?? false) && filled($profile->slug ?? null)
            ? route('website.institute', ['slug' => $profile->slug]) : null;

        return ['instituteProfileUrl' => $url, 'webPageUrl' => $url,
            ...InstitutePublicPages::urls($profile)];
    }
}
