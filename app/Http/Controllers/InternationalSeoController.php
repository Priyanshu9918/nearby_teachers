<?php

namespace App\Http\Controllers;

use App\Services\SeoDirectory;
use App\Services\TeacherPublicPages;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class InternationalSeoController extends Controller
{
    public function __construct(private readonly SeoDirectory $directory) {}

    private function canonical(Request $request, string $url): ?Response
    {
        if ($request->url() !== $url) {
            $parameters = $request->only(['page', 'lang', 'q', 'mode', 'trial']);
            $suffix = $parameters ? '?'.http_build_query($parameters) : '';

            return redirect()->to($url.$suffix, 301);
        }

        return null;
    }

    private function logView(Request $request, string $type, string $target): void
    {
        try {
            DB::table('page_views')->insert(['page_type' => $type, 'target' => $target,
                'referrer' => $request->header('referer'), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 2048), 'created_at' => now()]);
        } catch (\Throwable) {
            Log::warning('SEO page view could not be recorded.');
        }
    }

    public function teacher(Request $request, string $slug): Response
    {
        $query = $this->directory->teachers();
        $ownership = DB::table('seo_urls')->where('path_hash', hash('sha256', '/'.$request->path()))->where('path', '/'.$request->path())->where('target_type', 'teacher')->first();
        if ($ownership !== null) {
            $query->where('p.user_id', $ownership->target_id);
        } else {
            $id = TeacherPublicPages::idFromSlug($slug);
            $query->where(function (Builder $q) use ($slug, $id): void {
                $q->where('p.public_page_slug', $slug)->orWhere('p.slug', $slug)
                    ->orWhere('p.seo_slug', $slug)->orWhere('p.public_profile_slug', $slug);
                if ($id !== null && ! request()->routeIs('website.teacher')) {
                    $q->orWhere('p.user_id', $id);
                }
            })->orderByRaw('CASE WHEN p.slug = ? THEN 0 ELSE 1 END', [$slug]);
        }
        $teacher = $query->select('p.user_id', 'p.slug', 'p.public_page_slug', 'p.seo_country', 'p.seo_city_slug',
            'p.bio', 'p.city', 'p.state', 'p.country', 'p.linkedin_url', 'p.rate_per_hour', 'p.currency',
            'p.lesson_modes', 'p.trial_days', 'p.rating_avg', 'p.rating_count')
            ->selectRaw('COALESCE(NULLIF(p.listing_name, \'\'), u.full_name) AS full_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->selectSub(DB::table('bookings')->selectRaw('count(*)')->whereColumn('provider_id', 'p.user_id')
                ->where('status', 'completed'), 'lessons_completed')->first();
        abort_if($teacher === null, 404);
        $url = SeoDirectory::url('/teacher/'.$teacher->public_page_slug);
        if ($redirect = $this->canonical($request, $url)) {
            return $redirect;
        }
        $language = SeoDirectory::language($teacher->seo_country);
        $labels = SeoDirectory::labels($language);
        $subjects = TeacherPublicPages::subjects((int) $teacher->user_id);
        $names = DB::table('seo_subject_routes')->where('country', $teacher->seo_country)->where('language', $language)
            ->where('enabled', true)->pluck('display_name', 'subject_id');
        foreach ($subjects as $subject) {
            $subject->name = $names[$subject->id] ?? $subject->name;
        }
        $linkedin = $teacher->linkedin_url;
        if (! is_string($linkedin) || ! filter_var($linkedin, FILTER_VALIDATE_URL)
            || ! in_array(strtolower((string) parse_url($linkedin, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $teacher->linkedin_url = null;
        }
        $reviews = DB::table('reviews')->where('provider_id', $teacher->user_id)->select('rating', 'comment', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')->simplePaginate(10)->withQueryString();
        $related = $this->directory->pages()->where('r.country', $teacher->seo_country)->where('r.language', $language)
            ->where('r.kind', 'city')->where('r.city_slug', $teacher->seo_city_slug)
            ->whereIn('r.subject_id', $subjects->pluck('id'))->orderBy('r.id')->limit(5)->get();
        $this->logView($request, 'teacher', 'profile:'.$teacher->user_id);

        return response()->view('seo.teacher', compact('teacher', 'subjects', 'reviews', 'language', 'labels', 'url', 'related') + [
            'lang' => $language, 't' => [], 'downloadHref' => route('download', ['type' => 'teacher', 'slug' => $teacher->slug]),
        ]);
    }

    public function listing(Request $request, string $country, string $city, string $slug, ?string $language = null): Response
    {
        abort_unless(isset(config('seo.countries')[$country]), 404);
        $language ??= (string) $request->query('lang', SeoDirectory::language($country));
        abort_unless(in_array($language, config('seo.countries.'.$country.'.languages'), true), 404);
        $path = '/'.$request->path();
        $page = $this->directory->pages()->where('r.path', $path)->first();
        if ($page === null) {
            $alias = DB::table('seo_urls')->where('path_hash', hash('sha256', $path))->where('path', $path)->where('target_type', 'listing')->first();
            $page = $alias ? $this->directory->pages()->where('r.id', $alias->target_id)->first() : null;
        }
        if ($page === null) {
            $page = $this->directory->pages()->where('r.country', $country)->where('r.language', $language)
                ->where('r.city_slug', $city)->where('r.kind', $city === 'online' ? 'online' : 'city')
                ->where($city === 'online' ? 'sr.online_slug' : 'sr.city_slug', $slug)->first();
        }
        abort_if($page === null, 404);
        $url = SeoDirectory::url($page->path);
        if ($redirect = $this->canonical($request, $url)) {
            return $redirect;
        }
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        if ($language !== SeoDirectory::language($country)) {
            $page->display_name = DB::table('seo_subject_routes')->where('country', $country)->where('language', $language)
                ->where('subject_id', $page->subject_id)->where('enabled', true)->value('display_name') ?? $page->display_name;
        }
        $teachers = $this->directory->matching($page)->select('p.user_id', 'p.public_page_slug', 'p.city',
            'p.rate_per_hour', 'p.currency', 'p.rating_avg', 'p.rating_count', 'p.lesson_modes')
            ->selectRaw('COALESCE(NULLIF(p.listing_name, \'\'), u.full_name) AS full_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->orderByDesc('p.rating_count')->orderByDesc('p.rating_avg')->orderBy('p.user_id')
            ->paginate(20)->withQueryString();
        abort_if($teachers->isEmpty(), 404);
        $variants = [];
        $labels = SeoDirectory::labels($language);
        $values = ['subject' => $page->display_name, 'city' => $page->city,
            'country' => config('seo.countries.'.$country.'.name')];
        $title = SeoDirectory::text($labels, $page->kind.'_title', $values);
        $description = SeoDirectory::text($labels, $page->kind.'_description', $values);
        $canonicalUrl = $url.($teachers->currentPage() > 1 ? '?page='.$teachers->currentPage() : '');
        $this->logView($request, 'city_index', 'listing:'.$page->id);

        return response()->view('seo.listing', compact('page', 'teachers', 'labels', 'language', 'variants', 'title', 'description', 'canonicalUrl')
            + ['lang' => $language, 't' => []]);
    }

    public function localizedListing(Request $request, string $country, string $language, string $city, string $slug): Response
    {
        return $this->listing($request, $country, $city, $slug, $language);
    }

    public function geoCountry(Request $request, string $country): Response
    {
        abort_unless(isset(config('seo.countries')[$country]), 404);
        $page = $this->directory->locationPages()->where('lp.path', '/'.$request->path())->first();
        abort_if($page === null, 404);

        return $this->renderLocationPage($request, $page);
    }

    public function geoLevelTwo(Request $request, string $country, string $segment): Response
    {
        abort_unless(isset(config('seo.countries')[$country]), 404);
        $page = $this->directory->locationPages()->where('lp.path', '/'.$request->path())->first();
        if ($page !== null) {
            return $this->renderLocationPage($request, $page);
        }

        $countryConfig = config('seo.countries.'.$country);
        if (in_array($segment, $countryConfig['languages'], true)) {
            return redirect()->to(SeoDirectory::url('/'.$country).'?lang='.rawurlencode($segment), 301);
        }

        abort(404);
    }

    public function geoLevelThree(Request $request, string $country, string $district, string $village): Response
    {
        abort_unless(isset(config('seo.countries')[$country]), 404);
        if (in_array($district, config('seo.countries.'.$country.'.languages'), true)) {
            $locationPath = '/'.$country.'/'.$village;
            if (DB::table('seo_location_pages')->where('path', $locationPath)->exists()) {
                return redirect()->to(SeoDirectory::url($locationPath).'?lang='.rawurlencode($district), 301);
            }
        }
        $page = $this->directory->locationPages()->where('lp.path', '/'.$request->path())->first();
        if ($page !== null) {
            return $this->renderLocationPage($request, $page);
        }

        // Preserve the existing /country/city/subject listing URL contract.
        return $this->listing($request, $country, $district, $village);
    }

    public function geoLevelFour(Request $request, string $country, string $first, string $second, string $third): Response
    {
        abort_unless(isset(config('seo.countries')[$country]), 404);
        $page = $this->directory->locationPages()->where('lp.path', '/'.$request->path())->first();
        if ($page !== null) {
            return $this->renderLocationPage($request, $page);
        }

        // Old locale-in-path URLs redirect to the same short URL with a UI language preference.
        if (in_array($first, config('seo.countries.'.$country.'.languages'), true)) {
            $locationPath = '/'.$country.'/'.$second.'/'.$third;
            if (DB::table('seo_location_pages')->where('path', $locationPath)->exists()) {
                return redirect()->to(SeoDirectory::url($locationPath).'?lang='.rawurlencode($first), 301);
            }

            $subjectRoute = DB::table('seo_subject_routes')->where('country', $country)->where('language', $first)
                ->where(fn ($query) => $query->where('city_slug', $third)->orWhere('online_slug', $third))->where('enabled', true)->first();
            $defaultRoute = $subjectRoute ? DB::table('seo_subject_routes')->where('country', $country)
                ->where('language', SeoDirectory::language($country))->where('subject_id', $subjectRoute->subject_id)->where('enabled', true)->first() : null;
            abort_if($defaultRoute === null, 404);
            $slug = $second === 'online' ? $defaultRoute->online_slug : $defaultRoute->city_slug;

            return redirect()->to(SeoDirectory::url('/'.$country.'/'.$second.'/'.$slug).'?lang='.rawurlencode($first), 301);
        }

        abort(404);
    }

    public function localizedGeoLevelFive(Request $request, string $country, string $language, string $district, string $village, string $subject): Response
    {
        abort_unless(isset(config('seo.countries')[$country]), 404);
        abort_unless(in_array($language, config('seo.countries.'.$country.'.languages'), true), 404);

        $localized = DB::table('seo_subject_routes')->where('country', $country)->where('language', $language)
            ->where('city_slug', $subject)->where('enabled', true)->first();
        $default = $localized ? DB::table('seo_subject_routes')->where('country', $country)
            ->where('language', SeoDirectory::language($country))->where('subject_id', $localized->subject_id)->where('enabled', true)->first() : null;
        abort_if($default === null, 404);

        return redirect()->to(SeoDirectory::url('/'.$country.'/'.$district.'/'.$village.'/'.$default->city_slug).'?lang='.rawurlencode($language), 301);
    }

    private function renderLocationPage(Request $request, object $page): Response
    {
        if ($redirect = $this->canonical($request, SeoDirectory::url($page->path))) {
            return $redirect;
        }
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'lang' => ['sometimes', 'string', 'in:'.implode(',', config('seo.countries.'.$page->country.'.languages'))],
            'q' => ['sometimes', 'string', 'max:100'],
            'mode' => ['sometimes', 'in:online,home_tuition,in_person'],
            'trial' => ['sometimes', 'boolean'],
        ]);
        $query = $this->directory->locationTeachers($page)
            ->select('p.user_id', 'p.public_page_slug', 'p.city', 'p.district', 'p.locality', 'p.rate_per_hour',
                'p.currency', 'p.rating_avg', 'p.rating_count', 'p.lesson_modes', 'p.trial_days')
            ->selectRaw('COALESCE(NULLIF(p.listing_name, \'\'), u.full_name) AS full_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->selectSub(DB::table('profile_subjects as ps')->join('subjects as s', 's.id', '=', 'ps.subject_id')
                ->selectRaw("GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')")
                ->where('ps.profile_type', 'teacher')->whereColumn('ps.profile_id', 'p.user_id'), 'subject_names')
            ->selectSub(DB::table('referrals')->selectRaw('count(*)')->whereColumn('referrer_id', 'p.user_id'), 'referral_points')
            ->orderByDesc('referral_points')->orderByDesc('p.rating_count')->orderByDesc('p.rating_avg')->orderBy('p.user_id');
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';
            $query->where(fn (Builder $search) => $search->where('u.full_name', 'like', $term)
                ->orWhere('p.listing_name', 'like', $term)->orWhere('p.bio', 'like', $term)
                ->orWhereExists(fn (Builder $subjects) => $subjects->selectRaw('1')->from('profile_subjects as ps')
                    ->join('subjects as s', 's.id', '=', 'ps.subject_id')->where('ps.profile_type', 'teacher')
                    ->whereColumn('ps.profile_id', 'p.user_id')->where('s.name', 'like', $term)));
        }
        if (isset($filters['mode'])) {
            $query->whereJsonContains('p.lesson_modes', $filters['mode']);
        }
        if (($filters['trial'] ?? null) === true || ($filters['trial'] ?? null) === '1') {
            $query->where('p.trial_days', '>', 0);
        }
        $teachers = $query->paginate(20)->withQueryString();
        $language = $filters['lang'] ?? SeoDirectory::language($page->country);
        $subject = null;
        if ($page->subject_id !== null) {
            $subject = DB::table('seo_subject_routes')->where('country', $page->country)->where('language', $language)
                ->where('subject_id', $page->subject_id)->where('enabled', true)->value('display_name');
        }
        $copy = SeoDirectory::locationCopy($language);
        $values = ['country' => config('seo.countries.'.$page->country.'.name'), 'district' => $page->district,
            'village' => $page->village, 'subject' => $subject];
        $titleKey = $page->kind.'_title';
        $title = SeoDirectory::text($copy, $titleKey, $values);
        $description = $title.'. '.SeoDirectory::text($copy, 'browse', $values);
        $variants = [];
        $children = $this->locationChildren($page);
        $breadcrumbs = [['label' => config('seo.countries.'.$page->country.'.name'), 'url' => SeoDirectory::url('/'.$page->country)]];
        if ($page->kind !== 'country') {
            $districtPath = '/'.$page->country.'/'.$page->district_slug;
            $breadcrumbs[] = ['label' => $page->district, 'url' => SeoDirectory::url($districtPath)];
        }
        if (in_array($page->kind, ['village', 'subject_village'], true)) {
            $villagePath = '/'.$page->country.'/'.$page->district_slug.'/'.$page->village_slug;
            $breadcrumbs[] = ['label' => $page->village, 'url' => SeoDirectory::url($villagePath)];
        }
        if ($page->kind === 'subject_village') {
            $breadcrumbs[] = ['label' => $subject, 'url' => null];
        }
        $url = SeoDirectory::url($page->path);
        $this->logView($request, 'seo_'.$page->kind, $page->path);

        return response()->view('seo.location', compact('page', 'teachers', 'title', 'description', 'variants', 'children', 'url', 'subject', 'values', 'breadcrumbs')
            + ['language' => $language, 'labels' => SeoDirectory::labels($language), 'lang' => $language,
                't' => [], 'direction' => in_array($language, ['ar', 'ur'], true) ? 'rtl' : 'ltr']);
    }

    private function locationChildren(object $page): array
    {
        $query = $this->directory->locationPages()->where('lp.country', $page->country)->where('lp.language', $page->language);
        if ($page->kind === 'country') {
            $query->where('lp.kind', 'district')->orderBy('lp.district');
        } elseif ($page->kind === 'district') {
            $query->where('lp.kind', 'village')->where('lp.district_slug', $page->district_slug)->orderBy('lp.village');
        } elseif ($page->kind === 'village') {
            $query->where('lp.kind', 'subject_village')->where('lp.district_slug', $page->district_slug)
                ->where('lp.village_slug', $page->village_slug)->orderBy('lp.subject_id');
        } else {
            return [];
        }
        $children = $query->get();
        foreach ($children as $child) {
            $child->label = match ($child->kind) {
                'district' => $child->district,
                'village' => $child->village,
                'subject_village' => DB::table('seo_subject_routes')->where('country', $child->country)
                    ->where('language', $child->language)->where('subject_id', $child->subject_id)->value('display_name'),
            };
            $child->url = SeoDirectory::url($child->path);
        }

        return $children->all();
    }

    public function hub(Request $request): Response
    {
        if ($redirect = $this->canonical($request, SeoDirectory::url('/directory'))) {
            return $redirect;
        }
        $pages = $this->directory->pages()->orderBy('r.country')->orderBy('r.city_slug')->orderBy('r.id')->paginate(40);

        return response()->view('seo.hub', ['pages' => $pages, 'lang' => 'en', 't' => []]);
    }

    public function sitemap(): Response
    {
        return response()->stream(function (): void {
            echo '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach (['teachers' => $this->directory->teachers()->count(), 'directory' => $this->directory->pages()->count(),
                'locations' => $this->directory->locationPages()->count()] as $kind => $count) {
                for ($page = 1; $page <= (int) ceil($count / 1000); $page++) {
                    echo '<sitemap><loc>'.htmlspecialchars(SeoDirectory::url('/sitemaps/'.$kind.'-'.$page.'.xml'), ENT_XML1 | ENT_QUOTES).'</loc></sitemap>';
                }
            }
            echo '</sitemapindex>';
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function sitemapPart(string $kind, int $number): Response
    {
        abort_unless(in_array($kind, ['teachers', 'directory', 'locations'], true), 404);
        $query = match ($kind) {
            'teachers' => $this->directory->teachers()->select('p.user_id', 'p.public_page_slug')->orderBy('p.user_id'),
            'directory' => $this->directory->pages()->orderBy('r.id'),
            default => $this->directory->locationPages()->orderBy('lp.id'),
        };
        abort_if($number < 1 || ($number - 1) * 1000 >= (clone $query)->count(), 404);

        return response()->stream(function () use ($query, $number, $kind): void {
            echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($query->forPage($number, 1000)->cursor() as $record) {
                $url = $kind === 'teachers' ? SeoDirectory::url('/teacher/'.$record->public_page_slug) : SeoDirectory::url($record->path);
                echo '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES).'</loc>';
                echo '</url>';
            }
            echo '</urlset>';
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        return response("User-agent: *\nAllow: /\nAllow: /location-lookup\nDisallow: /admin\nDisallow: /developer\nSitemap: ".SeoDirectory::url('/sitemap.xml')."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
