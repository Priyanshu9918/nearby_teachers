<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use App\Support\RecordId;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class TeacherPublicPages
{
    public function publish(int $userId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Publish teacher pages inside the profile transaction.');
        }

        $profile = DB::table('teacher_profiles')->where('user_id', $userId)->lockForUpdate()->first();
        $user = DB::table('users')->where('id', $userId)->first();
        if ($profile === null || $user === null
            || $user->account_type !== 'teacher' || $user->profile_completed_at === null
            || $user->suspended_at !== null || trim($user->full_name) === ''
            || trim($profile->city ?? '') === '') {
            return;
        }

        $subjects = self::subjects($userId);
        if ($subjects->isEmpty()) {
            return;
        }

        $seoSlug = self::seoSlug($user, $profile, $subjects->pluck('name')->all());
        $fullSlug = $profile->public_profile_slug ?? self::profileSlug($user->full_name, $userId);
        if ($profile->seo_slug === $seoSlug) {
            return;
        }
        DB::table('teacher_profiles')->where('user_id', $userId)->update([
            'seo_slug' => $seoSlug, 'public_profile_slug' => $fullSlug,
            'slug' => $profile->slug ?? $fullSlug,
        ]);
        app(BusinessActivityRecorder::class)->record($profile->seo_slug === null ? 'teacher.pages_published' : 'teacher.pages_updated', $userId, $userId,
            before: $profile->seo_slug === null ? [] : ['seo_slug' => $profile->seo_slug],
            after: ['seo_slug' => $seoSlug, 'public_profile_slug' => $fullSlug,
                'subject_ids' => $subjects->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'city' => $profile->city, 'state' => $profile->state, 'country' => $profile->country]);
    }

    public static function subjects(int $userId): Collection
    {
        return DB::table('profile_subjects as ps')->join('subjects as s', 's.id', '=', 'ps.subject_id')
            ->where('ps.profile_type', 'teacher')->where('ps.profile_id', $userId)
            ->select('s.id', 's.name')->orderBy('s.name')->orderBy('s.id')->get();
    }

    public static function seoSlug(object $user, object $profile, array $subjects): string
    {
        $parts = [$user->full_name, ...$subjects, $profile->city, $profile->state, $profile->country];

        return (Str::slug(implode(' ', array_filter($parts))) ?: 'teacher').'-'.$user->id;
    }

    public static function profileSlug(string $name, int $userId): string
    {
        return (Str::slug($name) ?: 'teacher').'-'.$userId;
    }

    public static function idFromSlug(string $slug): ?int
    {
        if (! str_contains($slug, '-')) {
            return null;
        }
        $suffix = substr($slug, (int) strrpos($slug, '-') + 1);

        return RecordId::valid($suffix) ? (int) $suffix : null;
    }

    public static function urls(object $profile, string $name): array
    {
        if ($profile->slug === null) {
            return ['teacherSeoUrl' => null, 'teacherProfileUrl' => null];
        }

        return [
            'teacherSeoUrl' => route('website.teacher', ['slug' => $profile->seo_slug ?? $profile->slug]),
            'teacherProfileUrl' => route('website.teacher-profile', ['slug' => $profile->public_profile_slug
                ?? self::profileSlug($name, (int) $profile->user_id)]),
        ];
    }
}
