<?php

namespace App\Http\Controllers;

use App\Services\SeoDirectory;
use App\Services\ProfileEditor;
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
        $languages = config('seo.countries.'.$teacher->seo_country.'.languages', ['en']);
        $request->validate(['lang' => ['sometimes', 'string', 'in:'.implode(',', $languages)]]);
        $language = (string) $request->query('lang', SeoDirectory::language($teacher->seo_country));
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
        $related = DB::table('seo_directory_pages as r')->join('seo_subject_routes as sr', function ($join) use ($language): void {
            $join->on('sr.subject_id', '=', 'r.subject_id')->on('sr.country', '=', 'r.country')
                ->where('sr.language', '=', $language)->where('sr.enabled', '=', true);
        })->where('r.country', $teacher->seo_country)
            ->where('r.kind', 'city')->where('r.city_slug', $teacher->seo_city_slug)
            ->whereIn('r.subject_id', $subjects->pluck('id'))->orderBy('r.id')->limit(5)
            ->get(['r.path', 'r.city', 'sr.display_name']);
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
        $providers = $this->directory->matching($page)->select('providers.provider_type', 'providers.user_id', 'providers.public_page_slug',
            'providers.city', 'providers.rate', 'providers.currency', 'providers.rating_avg', 'providers.rating_count',
            'providers.lesson_modes', 'providers.trial_days', 'providers.subject_names', 'providers.is_bsl_official',
            'providers.avatar_url', 'providers.referral_points', 'providers.full_name')
            ->orderByDesc('providers.is_bsl_official')->orderByDesc('providers.referral_points')
            ->orderByDesc('providers.rating_count')->orderByDesc('providers.rating_avg')->orderBy('providers.provider_type')->orderBy('providers.user_id')
            ->paginate(20)->withQueryString();
        abort_if($providers->isEmpty(), 404);
        $variants = [];
        $labels = SeoDirectory::labels($language);
        $values = ['subject' => $page->display_name, 'city' => $page->city,
            'country' => config('seo.countries.'.$country.'.name')];
        $title = SeoDirectory::text($labels, $page->kind.'_title', $values);
        $description = SeoDirectory::text($labels, $page->kind.'_description', $values);
        $canonicalUrl = $url.($providers->currentPage() > 1 ? '?page='.$providers->currentPage() : '');
        $this->logView($request, 'city_index', 'listing:'.$page->id);

        return response()->view('seo.listing', compact('page', 'providers', 'labels', 'language', 'variants', 'title', 'description', 'canonicalUrl')
            + ['lang' => $language, 't' => []]);
    }

    public function institute(Request $request, string $slug): Response
    {
        $path = '/'.$request->path();
        $ownership = DB::table('seo_urls')->where('path_hash', hash('sha256', $path))->where('path', $path)
            ->where('target_type', 'institute')->first();
        $query = $this->directory->institutes();
        if ($ownership !== null) {
            $query->where('p.user_id', $ownership->target_id);
        } else {
            $query->where(fn (Builder $scope) => $scope->where('p.public_page_slug', $slug)->orWhere('p.slug', $slug));
        }
        $institute = $query->select('p.user_id', 'p.slug', 'p.public_page_slug', 'p.seo_country', 'p.seo_city_slug',
            'p.description', 'p.website_url', 'p.city', 'p.state', 'p.country', 'p.starting_rate', 'p.currency',
            'p.lesson_modes', 'p.trial_days', 'p.rating_avg', 'p.rating_count', 'p.is_bsl_official', 'p.bsl_official_since', 'p.institute_type')
            ->selectRaw('COALESCE(NULLIF(p.listing_name, \'\'), u.full_name) AS full_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->selectSub(DB::table('bookings')->selectRaw('count(*)')->whereColumn('provider_id', 'p.user_id')
                ->where('status', 'completed'), 'lessons_completed')->first();
        abort_if($institute === null, 404);
        $url = SeoDirectory::url('/institute/'.$institute->public_page_slug);
        if ($redirect = $this->canonical($request, $url)) {
            return $redirect;
        }
        $website = is_string($institute->website_url) ? parse_url($institute->website_url) : false;
        if (! is_array($website) || ! in_array(strtolower((string) ($website['scheme'] ?? '')), ['http', 'https'], true)) {
            $institute->website_url = null;
        }

        $languages = config('seo.countries.'.$institute->seo_country.'.languages', ['en']);
        $request->validate(['lang' => ['sometimes', 'string', 'in:'.implode(',', $languages)]]);
        $language = (string) $request->query('lang', SeoDirectory::language($institute->seo_country));
        $labels = SeoDirectory::labels($language);
        $subjects = ProfileEditor::subjects((int) $institute->user_id, 'institute');
        $names = DB::table('seo_subject_routes')->where('country', $institute->seo_country)->where('language', $language)
            ->where('enabled', true)->pluck('display_name', 'subject_id');
        foreach ($subjects as $subject) {
            $subject->name = $names[$subject->id] ?? $subject->name;
        }
        $reviews = DB::table('reviews')->where('provider_id', $institute->user_id)->select('rating', 'comment', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')->simplePaginate(10)->withQueryString();
        $related = DB::table('seo_directory_pages as r')->join('seo_subject_routes as sr', function ($join) use ($language): void {
            $join->on('sr.subject_id', '=', 'r.subject_id')->on('sr.country', '=', 'r.country')
                ->where('sr.language', '=', $language)->where('sr.enabled', '=', true);
        })->where('r.country', $institute->seo_country)
            ->where('r.kind', 'city')->where('r.city_slug', $institute->seo_city_slug)
            ->whereIn('r.subject_id', $subjects->pluck('id'))->orderBy('r.id')->limit(5)
            ->get(['r.path', 'r.city', 'sr.display_name']);
        $this->logView($request, 'institute', 'profile:'.$institute->user_id);

        return response()->view('seo.institute', compact('institute', 'subjects', 'reviews', 'language', 'labels', 'url', 'related') + [
            'lang' => $language, 't' => [], 'downloadHref' => route('download', ['type' => 'institute', 'slug' => $institute->slug]),
        ]);
    }

    public function myWebpage(Request $request, string $type, string $slug): Response
    {
        abort_unless(in_array($type, ['teacher', 'institute'], true), 404);
        $query = $type === 'teacher' ? $this->directory->teachers() : $this->directory->institutes();
        $query->where('p.public_page_slug', $slug);
        if ($type === 'teacher') {
            $provider = $query->select('p.user_id', 'p.public_page_slug', 'p.slug as legacy_slug', 'p.seo_country', 'p.seo_city_slug', 'p.bio as description',
                'p.rate_per_hour as rate', 'p.currency', 'p.lesson_modes', 'p.city', 'p.state', 'p.country')
                ->selectRaw('COALESCE(NULLIF(p.listing_name, \'\'), u.full_name) AS full_name')
                ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
                ->first();
        } else {
            $provider = $query->select('p.user_id', 'p.public_page_slug', 'p.slug as legacy_slug', 'p.seo_country', 'p.seo_city_slug', 'p.description',
                'p.starting_rate as rate', 'p.currency', 'p.lesson_modes', 'p.city', 'p.state', 'p.country', 'p.website_url')
                ->selectRaw('COALESCE(NULLIF(p.listing_name, \'\'), u.full_name) AS full_name')
                ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
                ->first();
        }
        abort_if($provider === null, 404);

        $subjects = $type === 'teacher'
            ? TeacherPublicPages::subjects((int) $provider->user_id)
            : ProfileEditor::subjects((int) $provider->user_id, 'institute');
        $subjectIds = $subjects->pluck('id')->all();
        $language = SeoDirectory::language((string) $provider->seo_country);
        $related = DB::table('seo_directory_pages as r')->join('seo_subject_routes as sr', function ($join) use ($language): void {
            $join->on('sr.subject_id', '=', 'r.subject_id')->on('sr.country', '=', 'r.country')
                ->where('sr.language', '=', $language)->where('sr.enabled', '=', true);
        })->where('r.country', $provider->seo_country)->where('r.city_slug', $provider->seo_city_slug)
            ->where('r.kind', 'city')->whereIn('r.subject_id', $subjectIds)->orderBy('r.id')->limit(6)
            ->get(['r.path', 'r.city', 'sr.display_name']);

        $url = SeoDirectory::url('/my-webpage/'.$type.'/'.$provider->public_page_slug);
        $oldProfileUrl = $type === 'teacher'
            ? SeoDirectory::url('/teacher/'.$provider->public_page_slug)
            : route('website.institute', ['slug' => $provider->legacy_slug]);
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=420x420&margin=12&data='.rawurlencode($url);

        return response()->view('site.my-webpage', compact('provider', 'type', 'subjects', 'related', 'url', 'oldProfileUrl', 'qrUrl') + [
            'lang' => 'en', 't' => [],
        ]);
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
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mode' => ['sometimes', 'nullable', 'in:online,home_tuition,in_person'],
            'trial' => ['sometimes', 'boolean'],
        ]);
        $searchTerm = trim((string) ($filters['q'] ?? ''));
        $trialOnly = in_array($filters['trial'] ?? null, [true, 1, '1'], true);
        $query = $this->directory->locationProviders($page)
            ->select('providers.provider_type', 'providers.user_id', 'providers.public_page_slug', 'providers.city',
                'providers.district', 'providers.locality', 'providers.rate', 'providers.currency', 'providers.rating_avg',
                'providers.rating_count', 'providers.lesson_modes', 'providers.trial_days', 'providers.full_name',
                'providers.avatar_url', 'providers.subject_names', 'providers.referral_points', 'providers.is_bsl_official')
            ->orderByDesc('providers.is_bsl_official')->orderByDesc('providers.referral_points')
            ->orderByDesc('providers.rating_count')->orderByDesc('providers.rating_avg')->orderBy('providers.provider_type')
            ->orderBy('providers.user_id');
        if ($searchTerm !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($searchTerm)).'%';
            $query->where(fn (Builder $search) => $search
                ->whereRaw('LOWER(COALESCE(providers.full_name, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(providers.description, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(providers.subject_names, \'\')) LIKE ?', [$term]));
        }
        if (isset($filters['mode'])) {
            $query->whereRaw(
                'JSON_CONTAINS(COALESCE(providers.lesson_modes, JSON_ARRAY()), JSON_QUOTE(?)) = 1',
                [$filters['mode']]
            );
        }
        if ($trialOnly) {
            $query->where('providers.trial_days', '>', 0);
        }
        $providers = $query->paginate(20)->withQueryString();
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
        $url = SeoDirectory::url($page->path);
        $hasActiveFilters = $searchTerm !== '' || isset($filters['mode']) || $trialOnly;
        $canonicalUrl = $url;
        if (! $hasActiveFilters && $providers->currentPage() > 1) {
            $canonicalUrl .= '?page='.$providers->currentPage();
        }
        $robots = $hasActiveFilters ? 'noindex,follow' : 'index,follow';
        $clearFiltersUrl = $request->url();
        if (($filters['lang'] ?? null) !== null && $language !== SeoDirectory::language($page->country)) {
            $clearFiltersUrl .= '?lang='.rawurlencode($language);
        }
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
        $this->logView($request, 'seo_'.$page->kind, $page->path);

        return response()->view('seo.location', compact('page', 'providers', 'title', 'description', 'variants', 'children', 'url', 'subject', 'values', 'breadcrumbs', 'filters', 'searchTerm', 'trialOnly', 'hasActiveFilters', 'canonicalUrl', 'robots', 'clearFiltersUrl')
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
        $request->validate(['lang' => ['sometimes', 'string', 'in:'.implode(',', array_keys(config('seo.language_names')))]]);
        $language = (string) $request->query('lang', 'en');
        $labels = SeoDirectory::labels($language);
        $pages = $this->directory->pages()->orderBy('r.country')->orderBy('r.city_slug')->orderBy('r.id')->paginate(40)->withQueryString();

        return response()->view('seo.hub', ['pages' => $pages, 'lang' => $language, 'language' => $language,
            'labels' => $labels, 'headerCountry' => null, 't' => []]);
    }

    public function sitemap(): Response
    {
        return response()->stream(function (): void {
            echo '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach (['teachers' => $this->directory->teachers()->count(), 'institutes' => $this->directory->institutes()->count(),
                'directory' => $this->directory->pages()->count(),
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
        abort_unless(in_array($kind, ['teachers', 'institutes', 'directory', 'locations'], true), 404);
        $query = match ($kind) {
            'teachers' => $this->directory->teachers()->select('p.user_id', 'p.public_page_slug')->orderBy('p.user_id'),
            'institutes' => $this->directory->institutes()->select('p.user_id', 'p.public_page_slug')->orderBy('p.user_id'),
            'directory' => $this->directory->pages()->orderBy('r.id'),
            default => $this->directory->locationPages()->orderBy('lp.id'),
        };
        abort_if($number < 1 || ($number - 1) * 1000 >= (clone $query)->count(), 404);

        return response()->stream(function () use ($query, $number, $kind): void {
            echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($query->forPage($number, 1000)->cursor() as $record) {
                $url = in_array($kind, ['teachers', 'institutes'], true)
                    ? SeoDirectory::url('/'.($kind === 'teachers' ? 'teacher' : 'institute').'/'.$record->public_page_slug)
                    : SeoDirectory::url($record->path);
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
