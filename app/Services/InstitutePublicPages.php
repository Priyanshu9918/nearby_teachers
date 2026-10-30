<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

final class InstitutePublicPages
{
    public function publish(int $userId, ?int $actorUserId = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Publish institute pages inside the profile transaction.');
        }

        $profile = DB::table('institute_profiles')->where('user_id', $userId)->lockForUpdate()->first();
        $user = DB::table('users')->where('id', $userId)->first();
        if ($profile === null || $user === null) {
            return;
        }

        $country = SeoDirectory::country($profile);
        $subjects = ProfileEditor::subjects($userId, 'institute');
        $eligible = $user->account_type === 'institute' && (bool) $profile->is_profile_complete
            && $user->profile_completed_at !== null && $user->phone_verified_at !== null
            && $user->suspended_at === null && trim($user->full_name) !== ''
            && trim((string) ($profile->listing_name ?: $user->full_name)) !== ''
            && trim((string) ($profile->city ?? '')) !== '' && $country !== null && $subjects->isNotEmpty();

        $citySlug = $eligible ? SeoDirectory::citySlug($profile->city) : null;
        $slug = $profile->public_page_slug;
        if ($eligible) {
            $displayName = trim((string) ($profile->listing_name ?? '')) ?: $user->full_name;
            if (! filled($profile->slug)) {
                $legacyBase = Str::slug(trim($displayName.' '.$profile->city)) ?: 'institute-'.$userId;
                $legacySlug = $legacyBase;
                for ($suffix = 1; DB::table('institute_profiles')->where('slug', $legacySlug)->where('user_id', '!=', $userId)->exists(); $suffix++) {
                    $legacySlug = $legacyBase.'-'.$suffix;
                }
                DB::table('institute_profiles')->where('user_id', $userId)->update(['slug' => $legacySlug]);
                $profile->slug = $legacySlug;
            }
            $base = substr((Str::slug($displayName) ?: 'institute').'-'.$citySlug, 0, 210);
            $candidate = $base;
            $attempt = 0;
            while (! SeoDirectory::claim('/institute/'.$candidate, 'institute', $userId)) {
                $candidate = substr($base, 0, 190).'-'.$userId.($attempt++ > 0 ? '-'.$attempt : '');
                if ($attempt > 5) {
                    throw new LogicException('Unable to reserve a unique institute page URL.');
                }
            }
            $slug = $candidate;
        }

        $subjectIds = $subjects->pluck('id')->map(fn ($id) => (int) $id)->all();
        $modes = json_decode($profile->lesson_modes ?: '[]', true, flags: JSON_THROW_ON_ERROR) ?: [];
        $state = ['status' => $eligible ? 'published' : 'ineligible', 'public_page_slug' => $slug,
            'country' => $eligible ? $country : null, 'city_slug' => $citySlug, 'subject_ids' => $subjectIds,
            'online' => in_array('online', $modes, true)];
        $hash = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
        if ($profile->seo_state_hash !== $hash) {
            DB::table('institute_profiles')->where('user_id', $userId)->update([
                'public_page_slug' => $slug, 'seo_country' => $state['country'], 'seo_city_slug' => $citySlug,
                'seo_state_hash' => $hash,
            ]);
            app(BusinessActivityRecorder::class)->record('institute.seo_state_changed', $userId, $actorUserId,
                before: ['public_page_slug' => $profile->public_page_slug, 'country' => $profile->seo_country,
                    'city_slug' => $profile->seo_city_slug], after: $state);
        }

        if ($eligible) {
            $directory = app(SeoDirectory::class);
            foreach ($subjects as $subject) {
                $directory->seedRoutes($subject, $country);
            }
            $profile->seo_country = $country;
            $profile->seo_city_slug = $citySlug;
            try {
                $directory->ensurePages($profile, $subjectIds);
                $directory->ensureLocationPages($profile, $subjectIds);
            } catch (LogicException $error) {
                if ($error->getMessage() !== 'SEO URL is already owned.') {
                    throw $error;
                }
                Log::warning('Institute SEO pages could not be fully synchronized.', [
                    'user_id' => $userId,
                    'reason' => $error->getMessage(),
                ]);
            }
        }
    }

    public static function urls(?object $profile): array
    {
        $seoUrl = isset($profile->public_page_slug) && isset($profile->seo_country)
            ? SeoDirectory::url('/institute/'.$profile->public_page_slug) : null;

        return ['instituteSeoUrl' => $seoUrl];
    }
}
