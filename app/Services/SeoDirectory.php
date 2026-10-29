<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SeoDirectory
{
    public const COUNT_SQL = "(SELECT count(*) FROM teacher_profiles sp JOIN users su ON su.id = sp.user_id WHERE su.account_type = 'teacher' AND su.suspended_at IS NULL AND su.phone_verified_at IS NOT NULL AND su.profile_completed_at IS NOT NULL AND sp.is_profile_complete = 1 AND sp.public_page_slug IS NOT NULL AND sp.seo_country = r.country AND ((r.kind = 'city' AND sp.seo_city_slug = r.city_slug) OR (r.kind = 'online' AND JSON_CONTAINS(sp.lesson_modes, JSON_QUOTE('online')) = 1)) AND EXISTS (SELECT 1 FROM profile_subjects sx WHERE sx.profile_type = 'teacher' AND sx.profile_id = sp.user_id AND sx.subject_id = r.subject_id))";

    public const LOCATION_COUNT_SQL = "(SELECT count(*) FROM teacher_profiles p JOIN users u ON u.id = p.user_id WHERE u.account_type = 'teacher' AND u.suspended_at IS NULL AND u.phone_verified_at IS NOT NULL AND u.profile_completed_at IS NOT NULL AND p.is_profile_complete = 1 AND p.public_page_slug IS NOT NULL AND p.seo_country = lp.country AND (lp.kind = 'country' OR p.district = lp.district) AND (lp.kind IN ('country', 'district') OR COALESCE(NULLIF(p.locality, ''), p.city) = lp.village) AND (lp.subject_id IS NULL OR EXISTS (SELECT 1 FROM profile_subjects ps WHERE ps.profile_type = 'teacher' AND ps.profile_id = p.user_id AND ps.subject_id = lp.subject_id)))";

    public static function url(string $path): string
    {
        return rtrim(config('seo.public_url'), '/').'/'.ltrim($path, '/');
    }

    public static function country(object $profile): ?string
    {
        $code = strtolower(trim($profile->country_code ?? ''));
        $name = Str::lower(trim($profile->country ?? ''));
        if ($name === '') {
            return isset(config('seo.countries')[$code]) ? $code : null;
        }
        $ascii = Str::lower(Str::ascii($name));
        foreach (config('seo.countries') as $country => $definition) {
            foreach ([$country, $definition['name'], ...$definition['aliases']] as $value) {
                if ($name === Str::lower($value) || ($ascii !== '' && $ascii === Str::lower(Str::ascii($value)))) {
                    return $country;
                }
            }
        }

        return null;
    }

    public static function language(string $country): string
    {
        return config('seo.countries.'.$country.'.language', 'en');
    }

    public static function labels(string $language): array
    {
        return json_decode(file_get_contents(resource_path('seo/'.$language.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function locationCopy(string $language): array
    {
        $catalog = json_decode(file_get_contents(resource_path('seo/location.json')), true, flags: JSON_THROW_ON_ERROR);

        return $catalog[$language] ?? $catalog['en'];
    }

    public static function text(array $labels, string $key, array $values = []): string
    {
        $text = $labels[$key];
        foreach ($values as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    public static function citySlug(string $city): string
    {
        $slug = config('seo.city_slugs.'.Str::lower(trim($city))) ?? substr(Str::slug($city), 0, 180);

        return $slug === 'online' ? 'online-city' : ($slug ?: 'city-'.substr(hash('sha256', $city), 0, 12));
    }

    public function teachers(): Builder
    {
        return DB::table('teacher_profiles as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('u.account_type', 'teacher')->whereNull('u.suspended_at')
            ->whereNotNull('u.phone_verified_at')->whereNotNull('u.profile_completed_at')
            ->where('p.is_profile_complete', true)->whereNotNull('p.public_page_slug')->whereNotNull('p.seo_country')->whereNotNull('p.seo_city_slug')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('profile_subjects as ps')
                ->where('ps.profile_type', 'teacher')->whereColumn('ps.profile_id', 'p.user_id'));
    }

    public function matching(object $page): Builder
    {
        $query = $this->teachers()->where('p.seo_country', $page->country)
            ->whereExists(fn (Builder $subjects) => $subjects->selectRaw('1')->from('profile_subjects as ps')
                ->where('ps.profile_type', 'teacher')->whereColumn('ps.profile_id', 'p.user_id')->where('ps.subject_id', $page->subject_id));
        if ($page->kind === 'online') {
            $query->whereJsonContains('p.lesson_modes', 'online');
        } else {
            $query->where('p.seo_city_slug', $page->city_slug);
        }

        return $query;
    }

    public function pages(): Builder
    {
        $query = DB::table('seo_directory_pages as r')->join('seo_subject_routes as sr', 'sr.id', '=', 'r.route_id')
            ->where('sr.enabled', true)->whereRaw(self::COUNT_SQL.' > 0')
            ->select('r.*', 'sr.display_name');
        $this->restrictToDefaultLanguages($query, 'r.country', 'r.language');

        return $query;
    }

    public static function pagePath(object $route, string $kind, string $citySlug): string
    {
        return '/'.$route->country.'/'.($kind === 'online' ? 'online' : $citySlug).'/'.($kind === 'online' ? $route->online_slug : $route->city_slug);
    }

    public static function claim(string $path, string $type, int $id): bool
    {
        $hash = hash('sha256', $path);
        DB::table('seo_urls')->insertOrIgnore(['path' => $path, 'path_hash' => $hash, 'target_type' => $type, 'target_id' => $id, 'created_at' => now()]);
        $owner = DB::table('seo_urls')->where('path_hash', $hash)->where('path', $path)->first();

        return $owner !== null && $owner->target_type === $type && (int) $owner->target_id === $id;
    }

    public function seedRoutes(object $subject, string $country): void
    {
        $catalog = json_decode(file_get_contents(resource_path('seo/subjects.json')), true, flags: JSON_THROW_ON_ERROR);
        $key = Str::slug($subject->name);
        if ($key === '') {
            return;
        }
        $known = str_starts_with($key, 'ielts-') ? 'ielts' : null;
        foreach ($catalog as $name => $definition) {
            if (in_array($key, $definition['aliases'], true)) {
                $known = $name;
                break;
            }
        }
        foreach (config('seo.countries.'.$country.'.languages') as $language) {
            if (DB::table('seo_subject_routes')->where('subject_id', $subject->id)->where('country', $country)->where('language', $language)->exists()) {
                continue;
            }
            if ($language !== 'en' && ($known === null || ! isset($catalog[$known][$language]))) {
                continue; // Never label English-only content as a translated page.
            }
            $display = $known === null ? $subject->name : $catalog[$known][$language];
            if ($known === 'maths' && $language === 'en' && $country === 'us') {
                $display = 'Math';
            }
            $style = config('seo.countries.'.$country.'.style');
            $base = $known === 'maths' ? ($country === 'us' ? 'math' : 'maths') : ($known ?? $key);
            $citySlug = substr($base.'-'.$style, 0, 220);
            $onlineSlug = $known === 'ielts' ? ($country === 'us' ? 'ielts-prep' : 'ielts-coaching') : $citySlug;
            if ($language === 'es') {
                $citySlug = $known === 'ielts' ? 'preparacion-ielts' : 'clases-particulares-de-'.Str::slug($display);
                $onlineSlug = $citySlug;
            } elseif ($language === 'fr') {
                $citySlug = 'cours-de-'.Str::slug($display);
                $onlineSlug = $citySlug;
            } elseif ($language === 'pt') {
                $citySlug = 'aulas-de-'.Str::slug($display);
                $onlineSlug = $citySlug;
            }
            // Reserved Slavic/East-Asian/Arabic scripts keep English URL wording;
            // Hindi/Bengali/Urdu follow Indian-English wording via the country style.
            if (DB::table('seo_subject_routes')->where('country', $country)->where('language', $language)
                ->where(fn (Builder $q) => $q->where('city_slug', $citySlug)->orWhere('online_slug', $onlineSlug))->exists()) {
                continue;
            }
            $inserted = DB::table('seo_subject_routes')->insertOrIgnore(['subject_id' => $subject->id, 'country' => $country,
                'language' => $language, 'display_name' => $display, 'city_slug' => $citySlug, 'online_slug' => $onlineSlug,
                'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            if (! $inserted) {
                continue;
            }
            $id = DB::table('seo_subject_routes')->where('subject_id', $subject->id)->where('country', $country)->where('language', $language)->value('id');
            app(BusinessActivityRecorder::class)->record('seo.route_configured', $id, null,
                after: ['subject_id' => (int) $subject->id, 'country' => $country, 'language' => $language,
                    'city_slug' => $citySlug, 'online_slug' => $onlineSlug, 'enabled' => true]);
        }
    }

    public function ensurePages(object $profile, array $subjects): void
    {
        $routes = DB::table('seo_subject_routes')->where('country', $profile->seo_country)
            ->where('language', self::language($profile->seo_country))->where('enabled', true)
            ->whereIn('subject_id', $subjects)->get();
        $online = in_array('online', (json_decode($profile->lesson_modes ?: '[]', true) ?: []), true);
        foreach ($routes as $route) {
            foreach ($online ? ['city', 'online'] : ['city'] as $kind) {
                $citySlug = $kind === 'online' ? 'online' : $profile->seo_city_slug;
                $identity = ['route_id' => $route->id, 'kind' => $kind, 'city_slug' => $citySlug];
                $page = DB::table('seo_directory_pages')->where($identity)->first();
                $path = self::pagePath($route, $kind, $citySlug);
                if ($page === null) {
                    $inserted = DB::table('seo_directory_pages')->insertOrIgnore([...$identity, 'subject_id' => $route->subject_id,
                        'country' => $route->country, 'language' => $route->language, 'city' => $kind === 'city' ? $profile->city : null,
                        'path' => $path, 'created_at' => now(), 'updated_at' => now()]);
                    $id = DB::table('seo_directory_pages')->where($identity)->value('id');
                    if ($id === null || ! self::claim($path, 'listing', (int) $id)) {
                        throw new \LogicException('SEO URL is already owned.');
                    }
                    if (! $inserted) {
                        continue;
                    }
                    app(BusinessActivityRecorder::class)->record('seo.page_registered', $id, null,
                        after: ['path' => $path, 'country' => $route->country, 'language' => $route->language,
                            'kind' => $kind, 'subject_id' => (int) $route->subject_id]);
                } elseif ($page->path !== $path) {
                    if (! self::claim($path, 'listing', (int) $page->id)) {
                        throw new \LogicException('SEO URL is already owned.');
                    }
                    DB::table('seo_directory_pages')->where('id', $page->id)->update(['path' => $path, 'updated_at' => now()]);
                    app(BusinessActivityRecorder::class)->record('seo.page_updated', $page->id, null,
                        before: ['path' => $page->path], after: ['path' => $path]);
                }
            }
        }
    }

    /** Create country → district → village/area → subject pages after profile publish. */
    public function ensureLocationPages(object $profile, array $subjects): void
    {
        $country = (string) ($profile->seo_country ?? '');
        $countryConfig = config('seo.countries.'.$country);
        if ($countryConfig === null) {
            return;
        }

        $language = $countryConfig['language'];
        if (! is_file(resource_path('seo/'.$language.'.json'))) {
            return;
        }
        $this->registerLocationPage(['kind' => 'country', 'country' => $country, 'language' => $language]);

        $district = trim((string) ($profile->district ?? ''));
        if ($district === '') {
            return;
        }
        $districtSlug = substr(Str::slug($district), 0, 180);
        if ($districtSlug === '') {
            return;
        }
        $this->registerLocationPage(['kind' => 'district', 'country' => $country, 'language' => $language,
            'district' => $district, 'district_slug' => $districtSlug]);

        $village = trim((string) ($profile->locality ?? '')) ?: trim((string) ($profile->city ?? ''));
        $villageSlug = substr(Str::slug($village), 0, 180);
        if ($village === '' || $villageSlug === '') {
            return;
        }
        $this->registerLocationPage(['kind' => 'village', 'country' => $country, 'language' => $language,
            'district' => $district, 'district_slug' => $districtSlug, 'village' => $village, 'village_slug' => $villageSlug]);

        $routes = DB::table('seo_subject_routes')->where('country', $country)->where('language', $language)
            ->where('enabled', true)->whereIn('subject_id', $subjects)->orderBy('subject_id')->get();
        foreach ($routes as $route) {
            $this->registerLocationPage(['kind' => 'subject_village', 'country' => $country, 'language' => $language,
                'district' => $district, 'district_slug' => $districtSlug, 'village' => $village,
                'village_slug' => $villageSlug, 'subject_id' => (int) $route->subject_id, 'subject_slug' => $route->city_slug]);
        }
    }

    private function registerLocationPage(array $values): void
    {
        $path = self::locationPath($values);
        $existing = DB::table('seo_location_pages')->where('path', $path)->first();
        $inserted = false;
        if ($existing === null) {
            $inserted = DB::table('seo_location_pages')->insertOrIgnore($values + ['path' => $path, 'created_at' => now(), 'updated_at' => now()]) === 1;
            $existing = DB::table('seo_location_pages')->where('path', $path)->first();
        }
        if ($existing === null || ! self::claim($path, 'location', (int) $existing->id)) {
            throw new \LogicException('SEO URL is already owned.');
        }
        if ($inserted) {
            app(BusinessActivityRecorder::class)->record('seo.location_page_registered', (int) $existing->id, null,
                after: ['path' => $path, 'country' => $values['country'], 'language' => $values['language'],
                    'kind' => $values['kind'], 'subject_id' => $values['subject_id'] ?? null]);
        }
    }

    public static function locationPath(array|object $page): string
    {
        $get = static fn (string $key): mixed => is_array($page) ? ($page[$key] ?? null) : ($page->$key ?? null);
        $country = (string) $get('country');
        $language = (string) $get('language');
        $segments = [$country];
        if ($language !== self::language($country)) {
            $segments[] = $language;
        }
        if (in_array($get('kind'), ['district', 'village', 'subject_village'], true)) {
            $segments[] = (string) $get('district_slug');
        }
        if (in_array($get('kind'), ['village', 'subject_village'], true)) {
            $segments[] = (string) $get('village_slug');
        }
        if ($get('kind') === 'subject_village') {
            $segments[] = (string) $get('subject_slug');
        }

        return '/'.implode('/', $segments);
    }

    public function locationPages(): Builder
    {
        $query = DB::table('seo_location_pages as lp')->select('lp.*')
            ->selectRaw(self::LOCATION_COUNT_SQL.' AS eligible_teachers')->whereRaw(self::LOCATION_COUNT_SQL.' > 0');
        $this->restrictToDefaultLanguages($query, 'lp.country', 'lp.language');

        return $query;
    }

    private function restrictToDefaultLanguages(Builder $query, string $countryColumn, string $languageColumn): void
    {
        $query->where(function (Builder $localized) use ($countryColumn, $languageColumn): void {
            foreach (config('seo.countries') as $country => $settings) {
                $localized->orWhere(function (Builder $scope) use ($countryColumn, $languageColumn, $country, $settings): void {
                    $scope->where($countryColumn, $country)->where($languageColumn, $settings['language']);
                });
            }
        });
    }

    public function locationTeachers(object $page): Builder
    {
        $query = DB::table('teacher_profiles as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('u.account_type', 'teacher')->whereNull('u.suspended_at')->whereNotNull('u.phone_verified_at')
            ->whereNotNull('u.profile_completed_at')->where('p.is_profile_complete', true)
            ->whereNotNull('p.public_page_slug')->where('p.seo_country', $page->country);
        if ($page->kind !== 'country') {
            $query->where('p.district', $page->district);
        }
        if (in_array($page->kind, ['village', 'subject_village'], true)) {
            $query->whereRaw("COALESCE(NULLIF(p.locality, ''), p.city) = ?", [$page->village]);
        }
        if ($page->subject_id !== null) {
            $query->whereExists(fn (Builder $subjects) => $subjects->selectRaw('1')->from('profile_subjects as ps')
                ->where('ps.profile_type', 'teacher')->whereColumn('ps.profile_id', 'p.user_id')->where('ps.subject_id', $page->subject_id));
        }

        return $query;
    }

    public function sync(): int
    {
        $count = 0;
        foreach (DB::table('teacher_profiles')->orderBy('user_id')->lazyById(100, 'user_id') as $profile) {
            DB::transaction(fn () => app(TeacherPublicPages::class)->publish((int) $profile->user_id));
            $count++;
        }

        return $count;
    }
}
