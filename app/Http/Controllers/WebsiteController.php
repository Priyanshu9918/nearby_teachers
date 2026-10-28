<?php

namespace App\Http\Controllers;

use App\Services\SeoDirectory;
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
        if ($request->getHost() === parse_url(config('seo.public_url'), PHP_URL_HOST)) {
            return redirect()->to(SeoDirectory::url('/directory'), 301);
        }

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
            ->where('is_profile_complete', true)
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
            ->where('p.is_profile_complete', true)
            ->select('p.*')
            ->selectRaw("COALESCE(NULLIF(p.listing_name, ''), u.full_name) AS full_name")
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

    private function publicTeachers(): Builder
    {
        return app(SeoDirectory::class)->teachers();
    }

    public function city(Request $request, string $city): Response
    {
        $teachers = $this->publicTeachers()
            ->whereRaw('lower(p.city) = lower(?)', [$city])
            ->whereNotNull('p.slug')
            ->selectRaw('p.public_page_slug AS slug')->addSelect('p.rating_avg', 'p.rating_count')
            ->selectRaw('COALESCE(NULLIF(p.listing_name, \'\'), u.full_name) AS full_name')
            ->orderByDesc('p.rating_avg')
            ->get();
        $institutes = DB::table('institute_profiles as p')
            ->where('p.is_profile_complete', true)
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereRaw('lower(p.city) = lower(?)', [$city])
            ->whereNotNull('p.slug')
            ->select('p.slug', 'p.rating_avg', 'p.rating_count', 'p.is_bsl_official')
            ->selectRaw("COALESCE(NULLIF(p.listing_name, ''), u.full_name) AS full_name")
            ->orderByDesc('p.is_bsl_official')
            ->orderByDesc('p.rating_avg')
            ->get();
        $subjects = DB::table('profile_subjects as ps')
            ->join('subjects as s', 's.id', '=', 'ps.subject_id')
            ->leftJoin('teacher_profiles as tp', function ($join): void {
                $join->on('tp.user_id', '=', 'ps.profile_id')->where('ps.profile_type', '=', 'teacher');
            })
            ->leftJoin('institute_profiles as ip', function ($join): void {
                $join->on('ip.user_id', '=', 'ps.profile_id')->where('ps.profile_type', '=', 'institute')->where('ip.is_profile_complete', true);
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
