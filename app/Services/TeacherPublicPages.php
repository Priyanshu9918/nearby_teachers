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
    public function publish(int $userId, ?int $actorUserId = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Publish teacher pages inside the profile transaction.');
        }
        $profile = DB::table('teacher_profiles')->where('user_id', $userId)->lockForUpdate()->first();
        $user = DB::table('users')->where('id', $userId)->first();
        if ($profile === null || $user === null) {
            return;
        }
        $country = SeoDirectory::country($profile);
        $subjects = self::subjects($userId);
        $eligible = $user->account_type === 'teacher' && $user->profile_completed_at !== null
            && $user->phone_verified_at !== null && $user->suspended_at === null
            && trim($user->full_name) !== '' && trim($profile->city ?? '') !== '' && $country !== null && $subjects->isNotEmpty();
        $citySlug = $eligible ? SeoDirectory::citySlug($profile->city) : null;
        $slug = $profile->public_page_slug;
        if ($eligible) {
            $base = self::seoSlug($user->full_name, $profile->city);
            $candidate = $base;
            $attempt = 0;
            while (! SeoDirectory::claim('/teacher/'.$candidate, 'teacher', $userId)) {
                $candidate = $base.'-'.$userId.($attempt++ > 0 ? '-'.$attempt : '');
                if ($attempt > 5) {
                    throw new LogicException('Unable to reserve a unique teacher page URL.');
                }
            }
            $slug = $candidate;
            foreach (array_filter([
                '/site/teacher/'.($profile->slug ?? ''),
                $profile->seo_slug ? '/teachers/'.$profile->seo_slug : null,
                $profile->seo_slug ? '/site/teacher/'.$profile->seo_slug : null,
                $profile->public_profile_slug ? '/teachers/'.$profile->public_profile_slug.'/profile' : null,
                $profile->public_profile_slug ? '/site/teacher-profile/'.$profile->public_profile_slug : null,
            ]) as $legacyPath) {
                if ($legacyPath !== '/site/teacher/') {
                    SeoDirectory::claim($legacyPath, 'teacher', $userId);
                }
            }
        }
        $ids = $subjects->pluck('id')->map(fn ($id) => (int) $id)->all();
        $state = ['status' => $eligible ? 'published' : 'ineligible', 'public_page_slug' => $slug,
            'country' => $eligible ? $country : null, 'city_slug' => $citySlug,
            'language' => $eligible ? SeoDirectory::language($country) : null, 'subject_ids' => $ids,
            'online' => in_array('online', (json_decode($profile->lesson_modes ?: '[]', true) ?: []), true)];
        $hash = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
        if ($profile->seo_state_hash !== $hash) {
            DB::table('teacher_profiles')->where('user_id', $userId)->update([
                'public_page_slug' => $slug, 'seo_country' => $state['country'], 'seo_city_slug' => $citySlug,
                'seo_state_hash' => $hash, 'slug' => $profile->slug ?? ($eligible ? $slug : null),
            ]);
            app(BusinessActivityRecorder::class)->record('teacher.seo_state_changed', $userId, $actorUserId,
                before: ['public_page_slug' => $profile->public_page_slug, 'country' => $profile->seo_country, 'city_slug' => $profile->seo_city_slug],
                after: $state);
        }
        if ($eligible) {
            $directory = app(SeoDirectory::class);
            foreach ($subjects as $subject) {
                $directory->seedRoutes($subject, $country);
            }
            $profile->seo_country = $country;
            $profile->seo_city_slug = $citySlug;
            $directory->ensurePages($profile, $ids);
        }
    }

    public static function subjects(int $userId): Collection
    {
        return DB::table('profile_subjects as ps')->join('subjects as s', 's.id', '=', 'ps.subject_id')
            ->where('ps.profile_type', 'teacher')->where('ps.profile_id', $userId)
            ->select('s.id', 's.name')->orderBy('s.name')->orderBy('s.id')->get();
    }

    public static function seoSlug(string $name, string $city): string
    {
        return substr((Str::slug($name) ?: 'teacher').'-'.SeoDirectory::citySlug($city), 0, 200);
    }

    public static function idFromSlug(string $slug): ?int
    {
        if (! str_contains($slug, '-')) {
            return null;
        }
        $suffix = substr($slug, (int) strrpos($slug, '-') + 1);

        return RecordId::valid($suffix) ? (int) $suffix : null;
    }

    public static function urls(object $profile, string $name = ''): array
    {
        $url = isset($profile->public_page_slug) && isset($profile->seo_country)
            ? SeoDirectory::url('/teacher/'.$profile->public_page_slug) : null;

        return ['teacherSeoUrl' => $url, 'teacherProfileUrl' => $url];
    }
}
