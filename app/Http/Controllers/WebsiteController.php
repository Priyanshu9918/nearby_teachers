<?php

namespace App\Http\Controllers;

use App\Services\TeacherPublicPages;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WebsiteController extends Controller
{
    private const LANGUAGES = ['en', 'hi', 'es', 'fr', 'ar', 'zh', 'pt', 'ru'];

    public function home(Request $request): Response
    {
        return $this->renderWebsite($request, 'site.home');
    }

    public function locationLookup(Request $request): Response
    {
        return $this->renderWebsite($request, 'site.location-lookup');
    }

    public function directory(Request $request): Response
    {
        $teacherCities = DB::table('teacher_profiles')
            ->whereNotNull('city')
            ->whereNotNull('slug')
            ->select('city')
            ->selectRaw('count(*) as listing_count')
            ->groupBy('city');
        $instituteCities = DB::table('institute_profiles')
            ->whereNotNull('city')
            ->whereNotNull('slug')
            ->select('city')
            ->selectRaw('count(*) as listing_count')
            ->groupBy('city');
        $cities = DB::query()
            ->fromSub($teacherCities->unionAll($instituteCities), 'city_counts')
            ->select('city')
            ->selectRaw('sum(listing_count) as listing_count')
            ->groupBy('city')
            ->orderByDesc('listing_count')
            ->orderBy('city')
            ->get();

        $this->logPageView($request, 'city_directory');

        return $this->renderWebsite($request, 'site.directory', ['cities' => $cities]);
    }

    public function institute(Request $request, string $slug): Response
    {
        $institute = DB::table('institute_profiles as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.slug', $slug)
            ->select('p.*', 'u.full_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->selectSub(
                DB::table('bookings')
                    ->selectRaw('count(*)')
                    ->whereColumn('provider_id', 'p.user_id')
                    ->where('status', 'completed'),
                'lessons_completed'
            )
            ->first();

        if ($institute === null) {
            return $this->renderWebsite($request, 'site.not-found', ['message' => 'This institute page could not be found.'], 404);
        }

        $subjects = DB::table('profile_subjects as ps')
            ->join('subjects as s', 's.id', '=', 'ps.subject_id')
            ->where('ps.profile_type', 'institute')
            ->where('ps.profile_id', $institute->user_id)
            ->select('s.name')
            ->orderBy('s.name')
            ->get();

        $this->logPageView($request, 'institute', $slug);

        return $this->renderWebsite($request, 'site.provider', [
            'provider' => $institute,
            'providerType' => 'institute',
            'subjects' => $subjects,
            'downloadHref' => route('download', ['type' => 'institute', 'slug' => $institute->slug]),
        ]);
    }

    public function teacher(Request $request, string $slug): Response
    {
        return $this->teacherPage($request, $slug, false);
    }

    public function teacherProfile(Request $request, string $slug): Response
    {
        return $this->teacherPage($request, $slug, true);
    }

    private function publicTeachers(): Builder
    {
        return DB::table('teacher_profiles as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('u.account_type', 'teacher')->whereNull('u.suspended_at')
            ->whereNotNull('u.profile_completed_at')->whereNotNull('p.slug')
            ->whereNotNull('p.city')->where('p.city', '!=', '')
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')->from('profile_subjects as ps')
                    ->whereColumn('ps.profile_id', 'p.user_id')->where('ps.profile_type', 'teacher');
            });
    }

    private function teacherPage(Request $request, string $slug, bool $full): Response
    {
        $query = $this->publicTeachers();
        $id = TeacherPublicPages::idFromSlug($slug);
        if ($full) {
            if ($id === null) {
                return $this->renderWebsite($request, 'site.not-found', ['message' => 'This teacher page could not be found.'], 404);
            }
            $query->where('p.user_id', $id);
        } else {
            // Existing app/deep-link slugs remain valid; prior SEO slugs resolve by immutable ID.
            $query->where(function (Builder $query) use ($slug, $id): void {
                $query->where('p.slug', $slug);
                if ($id !== null) {
                    $query->orWhere('p.user_id', $id);
                }
            })->orderByRaw('CASE WHEN p.slug = ? THEN 0 ELSE 1 END', [$slug]);
        }
        $teacher = $query->select('p.user_id', 'p.slug', 'p.seo_slug', 'p.public_profile_slug',
            'p.bio', 'p.city', 'p.state', 'p.country', 'p.linkedin_url', 'p.rate_per_hour', 'p.currency',
            'p.lesson_modes', 'p.trial_days', 'p.rating_avg', 'p.rating_count', 'u.full_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->selectSub(DB::table('bookings')->selectRaw('count(*)')
                ->whereColumn('provider_id', 'p.user_id')->where('status', 'completed'), 'lessons_completed')->first();
        if ($teacher === null) {
            return $this->renderWebsite($request, 'site.not-found', ['message' => 'This teacher page could not be found.'], 404);
        }

        $urls = TeacherPublicPages::urls($teacher, $teacher->full_name);
        $expected = $full ? ($teacher->public_profile_slug ?? TeacherPublicPages::profileSlug($teacher->full_name, (int) $teacher->user_id))
            : ($teacher->seo_slug ?? $teacher->slug);
        if ($slug !== $expected) {
            return redirect()->to($urls[$full ? 'teacherProfileUrl' : 'teacherSeoUrl'], 301);
        }
        $subjects = TeacherPublicPages::subjects((int) $teacher->user_id);
        $reviews = $full ? DB::table('reviews')->where('provider_id', $teacher->user_id)
            ->select('rating', 'comment', 'created_at')->orderByDesc('created_at')->orderByDesc('id')
            ->simplePaginate(10)->withQueryString() : null;
        $linkedin = $teacher->linkedin_url;
        if (! is_string($linkedin) || ! filter_var($linkedin, FILTER_VALIDATE_URL)
            || ! in_array(strtolower((string) parse_url($linkedin, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $teacher->linkedin_url = null;
        }
        $this->logPageView($request, 'teacher', ($full ? 'profile:' : 'seo:').$teacher->user_id);

        return $this->renderWebsite($request, 'site.teacher', [
            'teacher' => $teacher, 'subjects' => $subjects, 'fullProfile' => $full, 'reviews' => $reviews,
            'seoUrl' => $urls['teacherSeoUrl'], 'profileUrl' => $urls['teacherProfileUrl'],
            'downloadHref' => route('download', ['type' => 'teacher', 'slug' => $teacher->slug]),
        ]);
    }

    public function city(Request $request, string $city): Response
    {
        $teachers = $this->publicTeachers()
            ->whereRaw('lower(p.city) = lower(?)', [$city])
            ->whereNotNull('p.slug')
            ->selectRaw('COALESCE(p.seo_slug, p.slug) AS slug')->addSelect('p.rating_avg', 'p.rating_count', 'u.full_name')
            ->orderByDesc('p.rating_avg')
            ->get();
        $institutes = DB::table('institute_profiles as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereRaw('lower(p.city) = lower(?)', [$city])
            ->whereNotNull('p.slug')
            ->select('p.slug', 'p.rating_avg', 'p.rating_count', 'p.is_bsl_official', 'u.full_name')
            ->orderByDesc('p.is_bsl_official')
            ->orderByDesc('p.rating_avg')
            ->get();
        $subjects = DB::table('profile_subjects as ps')
            ->join('subjects as s', 's.id', '=', 'ps.subject_id')
            ->leftJoin('teacher_profiles as tp', function ($join): void {
                $join->on('tp.user_id', '=', 'ps.profile_id')->where('ps.profile_type', '=', 'teacher');
            })
            ->leftJoin('institute_profiles as ip', function ($join): void {
                $join->on('ip.user_id', '=', 'ps.profile_id')->where('ps.profile_type', '=', 'institute');
            })
            ->where(function ($query) use ($city): void {
                $query->whereRaw('lower(tp.city) = lower(?)', [$city])
                    ->orWhereRaw('lower(ip.city) = lower(?)', [$city]);
            })
            ->select('s.name')
            ->distinct()
            ->orderBy('s.name')
            ->get();

        $this->logPageView($request, 'city_index', $city);

        return $this->renderWebsite($request, 'site.city', [
            'city' => $city,
            'teachers' => $teachers,
            'institutes' => $institutes,
            'subjects' => $subjects,
        ]);
    }

    public function feed(Request $request, string $city): Response
    {
        $posts = DB::table('posts as p')
            ->join('users as u', 'u.id', '=', 'p.author_id')
            ->whereRaw('lower(p.city) = lower(?)', [$city])
            ->select('p.*', 'u.full_name as author_name')
            ->selectSub(DB::table('post_likes')->selectRaw('count(*)')->whereColumn('post_id', 'p.id'), 'like_count')
            ->selectSub(DB::table('post_comments')->selectRaw('count(*)')->whereColumn('post_id', 'p.id'), 'comment_count')
            ->orderByDesc('p.created_at')
            ->limit(50)
            ->get();

        $this->logPageView($request, 'city_feed', $city);

        return $this->renderWebsite($request, 'site.feed', [
            'city' => $city,
            'posts' => $posts,
            'downloadHref' => route('download', ['type' => 'feed', 'slug' => $city]),
        ]);
    }

    public function download(Request $request): RedirectResponse|Response
    {
        $type = $request->query('type');
        $slug = $request->query('slug');
        $referral = $request->query('ref');
        $platform = $this->detectPlatform((string) $request->userAgent());
        $target = is_string($type) && is_string($slug) ? $type.':'.$slug : null;
        $this->logPageView($request, 'download', $target, $platform);

        $deepLinkPath = is_string($type) && is_string($slug)
            ? '/'.rawurlencode($type).'/'.rawurlencode($slug)
            : '/';
        $referrer = 'deep_link_path='.$deepLinkPath;
        if (is_string($referral) && $referral !== '') {
            $referrer .= '&ref='.rawurlencode($referral);
        }

        $androidUrl = config('services.nearby_teacher.android_play_store_url');
        $iosUrl = config('services.nearby_teacher.ios_app_store_url');

        if ($platform === 'android' && filled($androidUrl)) {
            return redirect()->away($this->appendQueryParameter($androidUrl, 'referrer', $referrer));
        }
        if ($platform === 'ios' && filled($iosUrl)) {
            return redirect()->away($iosUrl);
        }

        return $this->renderWebsite($request, 'site.download', [
            'iosUrl' => $iosUrl,
            'androidUrl' => $androidUrl,
            'deepLinkPath' => $deepLinkPath,
            'ref' => is_string($referral) ? $referral : null,
        ]);
    }

    public function sitemap(): Response
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $urls = [$baseUrl.'/site', $baseUrl.'/location-lookup'];

        $cities = DB::query()
            ->fromSub(
                DB::table('teacher_profiles')->select('city')->whereNotNull('city')->whereNotNull('slug')
                    ->union(DB::table('institute_profiles')->select('city')->whereNotNull('city')->whereNotNull('slug')),
                'cities'
            )
            ->select('city')
            ->distinct()
            ->get();
        foreach ($cities as $entry) {
            $encodedCity = rawurlencode($entry->city);
            $urls[] = $baseUrl.'/site/city/'.$encodedCity;
            $urls[] = $baseUrl.'/site/feed/'.$encodedCity;
        }

        foreach ($this->publicTeachers()->select('p.user_id', 'p.slug', 'p.seo_slug', 'p.public_profile_slug', 'u.full_name')->cursor() as $teacher) {
            $urls[] = TeacherPublicPages::urls($teacher, $teacher->full_name)['teacherProfileUrl'];
        }
        foreach (DB::table('institute_profiles')->whereNotNull('slug')->pluck('slug') as $slug) {
            $urls[] = $baseUrl.'/site/institute/'.rawurlencode($slug);
        }

        return response()->view('site.sitemap', ['urls' => array_values(array_unique($urls))], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function robots(): Response
    {
        $content = "User-agent: *\nAllow: /site/\nAllow: /download\nAllow: /location-lookup\nDisallow: /admin\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n";

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function viewData(Request $request): array
    {
        $lang = $this->language($request);
        $path = base_path('src/i18n/'.$lang.'.json');
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Unable to read the website translation catalogue.');
        }

        return [
            'lang' => $lang,
            't' => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            'languages' => $this->languages(),
        ];
    }

    private function renderWebsite(Request $request, string $view, array $data = [], int $status = 200): Response
    {
        $response = response()->view($view, $data + $this->viewData($request), $status);
        $language = $request->query('lang');
        if (is_string($language) && in_array($language, self::LANGUAGES, true)) {
            $response->withCookie(cookie('nt_lang', $language, 60 * 24 * 365, '/', null, $request->isSecure(), false, false, 'lax'));
        }

        return $response;
    }

    private function language(Request $request): string
    {
        $queryLanguage = $request->query('lang');
        if (is_string($queryLanguage) && in_array($queryLanguage, self::LANGUAGES, true)) {
            return $queryLanguage;
        }

        $cookieLanguage = $request->cookie('nt_lang');
        if (is_string($cookieLanguage) && in_array($cookieLanguage, self::LANGUAGES, true)) {
            return $cookieLanguage;
        }

        $primary = strtolower(substr(trim(explode(',', (string) $request->header('Accept-Language'))[0]), 0, 2));

        return in_array($primary, self::LANGUAGES, true) ? $primary : 'en';
    }

    private function languages(): array
    {
        $languages = [];
        foreach (self::LANGUAGES as $code) {
            $json = file_get_contents(base_path('src/i18n/'.$code.'.json'));
            if ($json === false) {
                throw new \RuntimeException('Unable to read the website language catalogue.');
            }
            $translations = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $languages[] = [
                'code' => $code,
                'name' => $translations['_meta']['name'],
                'nativeName' => $translations['_meta']['nativeName'],
            ];
        }

        return $languages;
    }

    private function logPageView(Request $request, string $pageType, ?string $target = null, ?string $platform = null): void
    {
        try {
            DB::table('page_views')->insert([
                'page_type' => $pageType,
                'target' => $target,
                'platform' => $platform,
                'referrer' => $request->header('referer'),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 2048),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Website page-view logging failed.', ['exception' => $exception::class]);
        }
    }

    private function detectPlatform(string $userAgent): string
    {
        $userAgent = strtolower($userAgent);
        if (preg_match('/iphone|ipad|ipod/', $userAgent) === 1) {
            return 'ios';
        }
        if (str_contains($userAgent, 'android')) {
            return 'android';
        }
        if (str_contains($userAgent, 'mobile')) {
            return 'unknown';
        }

        return 'desktop';
    }

    private function appendQueryParameter(string $url, string $key, string $value): string
    {
        $fragment = '';
        if (($fragmentPosition = strpos($url, '#')) !== false) {
            $fragment = substr($url, $fragmentPosition);
            $url = substr($url, 0, $fragmentPosition);
        }
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.rawurlencode($key).'='.rawurlencode($value).$fragment;
    }
}
