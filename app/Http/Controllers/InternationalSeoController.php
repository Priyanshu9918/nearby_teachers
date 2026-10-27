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
            $parameters = $request->only(['page']);
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
            'p.lesson_modes', 'p.trial_days', 'p.rating_avg', 'p.rating_count', 'u.full_name')
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
        $language ??= SeoDirectory::language($country);
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
        $teachers = $this->directory->matching($page)->select('p.user_id', 'p.public_page_slug', 'p.city',
            'p.rate_per_hour', 'p.currency', 'p.rating_avg', 'p.rating_count', 'p.lesson_modes', 'u.full_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->orderByDesc('p.rating_count')->orderByDesc('p.rating_avg')->orderBy('p.user_id')
            ->paginate(20)->withQueryString();
        abort_if($teachers->isEmpty(), 404);
        $variants = $this->variants($page, $teachers->currentPage());
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

    private function variants(object $page, int $number = 1): array
    {
        return $this->directory->pages()->where('r.country', $page->country)->where('r.subject_id', $page->subject_id)
            ->where('r.kind', $page->kind)->where('r.city_slug', $page->city_slug)->orderBy('r.language')->get()
            ->map(fn ($variant) => ['code' => $variant->language.'-'.strtoupper($variant->country), 'language' => $variant->language,
                'url' => SeoDirectory::url($variant->path).($number > 1 ? '?page='.$number : '')])->all();
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
            foreach (['teachers' => $this->directory->teachers()->count(), 'directory' => $this->directory->pages()->count()] as $kind => $count) {
                for ($page = 1; $page <= (int) ceil($count / 1000); $page++) {
                    echo '<sitemap><loc>'.htmlspecialchars(SeoDirectory::url('/sitemaps/'.$kind.'-'.$page.'.xml'), ENT_XML1 | ENT_QUOTES).'</loc></sitemap>';
                }
            }
            echo '</sitemapindex>';
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function sitemapPart(string $kind, int $number): Response
    {
        $query = $kind === 'teachers' ? $this->directory->teachers()->select('p.user_id', 'p.public_page_slug')->orderBy('p.user_id')
            : $this->directory->pages()->orderBy('r.id');
        abort_if($number < 1 || ($number - 1) * 1000 >= (clone $query)->count(), 404);

        return response()->stream(function () use ($query, $number, $kind): void {
            echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
            foreach ($query->forPage($number, 1000)->cursor() as $record) {
                $url = $kind === 'teachers' ? SeoDirectory::url('/teacher/'.$record->public_page_slug) : SeoDirectory::url($record->path);
                echo '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES).'</loc>';
                if ($kind === 'directory') {
                    foreach ($this->variants($record) as $variant) {
                        echo '<xhtml:link rel="alternate" hreflang="'.htmlspecialchars($variant['code'], ENT_XML1 | ENT_QUOTES).'" href="'.htmlspecialchars($variant['url'], ENT_XML1 | ENT_QUOTES).'" />';
                    }
                }
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
