<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\I18nController;
use App\Http\Middleware\PersistPublicLanguage;
use App\Services\SeoDirectory;
use App\Support\PublicLanguages;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicLanguagesTest extends TestCase
{
    public static function languages(): array
    {
        return array_map(fn ($code) => [$code], ['en', 'hi', 'bn', 'ar', 'ur', 'es', 'fr', 'pt', 'de', 'ru', 'zh', 'ja', 'id', 'vi']);
    }

    public function test_shared_language_list_and_api_expose_the_fourteen_requested_languages(): void
    {
        $this->assertSame(array_column(self::languages(), 0), PublicLanguages::codes());
        $languages = app(I18nController::class)->languages()->getData(true)['languages'];
        $this->assertSame(PublicLanguages::codes(), array_column($languages, 'code'));
        $this->assertSame(['ar', 'ur'], array_column(array_values(array_filter($languages, fn ($language) => $language['rtl'])), 'code'));
    }

    #[DataProvider('languages')]
    public function test_catalogues_are_complete_and_preserve_interpolation_tokens(string $language): void
    {
        $response = app(I18nController::class)->translations($language)->getData(true);
        $this->assertSame($language, $response['served']);
        $catalogue = $response['translations'];
        $this->assertSame($language, $catalogue['_meta']['code']);
        $english = Arr::dot(PublicLanguages::translations('en'));
        $actual = Arr::dot($catalogue);
        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($actual))));
        foreach ($english as $key => $value) {
            if (! is_string($value) || str_starts_with($key, '_meta.')) {
                continue;
            }
            preg_match_all('/\{[a-zA-Z_]+\}/', $value, $expectedTokens);
            preg_match_all('/\{[a-zA-Z_]+\}/', $actual[$key], $actualTokens);
            sort($expectedTokens[0]);
            sort($actualTokens[0]);
            $this->assertSame($expectedTokens[0], $actualTokens[0], $language.':'.$key);
            $this->assertNotSame('', $actual[$key]);
        }
        $seo = SeoDirectory::labels($language);
        $this->assertSame([], array_values(array_diff(array_keys(SeoDirectory::labels('en')), array_keys($seo))));
        $this->assertNotEmpty(SeoDirectory::locationCopy($language));
        $this->assertArrayHasKey($language, config('seo.language_names'));
    }

    #[DataProvider('languages')]
    public function test_language_preferences_accept_all_supported_codes(string $language): void
    {
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->once()->andReturn($query);
        $query->shouldReceive('where')->with('id', 42)->once()->andReturnSelf();
        $query->shouldReceive('update')->once()->with(Mockery::on(fn ($row) => $row['preferred_language'] === $language))->andReturn(1);
        $request = Request::create('/auth/preferred-language', 'PATCH', ['language' => $language]);
        $request->attributes->set('authenticated_user', (object) ['id' => 42]);
        $response = app(AuthController::class)->setPreferredLanguage($request);
        $this->assertSame($language, $response->getData(true)['preferredLanguage']);
    }

    public function test_detection_honours_query_then_cookie_then_browser_and_uses_safe_fallbacks(): void
    {
        $request = Request::create('/?lang=vi', 'GET', [], ['nt_lang' => 'ur'], [], ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);
        $this->assertSame('vi', PublicLanguages::resolve($request));
        $request = Request::create('/', 'GET', [], ['nt_lang' => 'ur'], [], ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);
        $this->assertSame('ur', PublicLanguages::resolve($request));
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'xx,de-DE;q=0.9']);
        $this->assertSame('de', PublicLanguages::resolve($request));
        $this->assertSame('id', PublicLanguages::resolve(Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => '']), 'id'));
        $response = app(I18nController::class)->translations('../../etc/passwd')->getData(true);
        $this->assertSame('en', $response['served']);
    }

    public function test_encrypted_language_cookie_persists_from_seo_to_referral_without_javascript(): void
    {
        $encrypt = app(EncryptCookies::class);
        $persist = app(PersistPublicLanguage::class);
        $request = Request::create('/directory?lang=vi', 'GET');
        $response = $encrypt->handle($request, fn ($request) => $persist->handle($request,
            fn ($request) => response()->json(['language' => PublicLanguages::resolve($request)])));
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'nt_lang');
        $this->assertNotNull($cookie);
        $this->assertNotSame('vi', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $referral = Request::create('/download?ref=ABC123', 'GET', [], ['nt_lang' => $cookie->getValue()], [], ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        $response = $encrypt->handle($referral, fn ($request) => $persist->handle($request,
            fn ($request) => response()->json(['language' => PublicLanguages::resolve($request)])));
        $this->assertSame('vi', json_decode($response->getContent(), true)['language']);
    }

    public function test_language_is_not_persisted_on_invalid_or_failed_requests(): void
    {
        $middleware = app(PersistPublicLanguage::class);
        $response = $middleware->handle(Request::create('/?lang=xx'), fn () => response('OK'));
        $this->assertSame([], $response->headers->getCookies());
        $response = $middleware->handle(Request::create('/?lang=de'), fn () => response('Invalid', 422));
        $this->assertSame([], $response->headers->getCookies());
    }

    #[DataProvider('languages')]
    public function test_referral_pages_translate_invitations_and_offer_all_languages_without_database_access(string $language): void
    {
        $t = PublicLanguages::translations($language);
        $html = view('site.referral', [
            'lang' => $language, 't' => $t, 'languages' => PublicLanguages::all(), 'referralValid' => true,
            'ref' => 'ABC123', 'downloadUrl' => '/download?ref=ABC123', 'downloadStore' => 'Android',
        ])->render();
        $this->assertStringContainsString('lang="'.$language.'"', $html);
        $this->assertStringContainsString('dir="'.($t['_meta']['rtl'] ? 'rtl' : 'ltr').'"', $html);
        $this->assertStringContainsString(e($t['referral']['landingTitle']), $html);
        $this->assertStringContainsString('ABC123', $html);
        $this->assertStringContainsString('id="referral-language"', $html);
        $this->assertSame(1, substr_count($html, 'name="lang"'));
        $this->assertLessThan(strpos($html, '<main'), strpos($html, 'id="referral-language"'));
        $this->assertStringNotContainsString('document.cookie', $html);
        foreach (PublicLanguages::codes() as $code) {
            $this->assertStringContainsString('value="'.$code.'"', $html);
        }
    }

    #[DataProvider('languages')]
    public function test_seo_headers_offer_all_languages_for_every_country(string $language): void
    {
        $html = view('seo.partials.header', ['language' => $language, 'headerCountry' => 'us', 'labels' => SeoDirectory::labels($language)])->render();
        foreach (PublicLanguages::codes() as $code) {
            $this->assertStringContainsString('value="'.$code.'"', $html);
        }
    }

    #[DataProvider('languages')]
    public function test_seo_filters_and_share_page_render_in_every_requested_language(string $language): void
    {
        $t = PublicLanguages::translations($language);
        $labels = SeoDirectory::labels($language);
        $html = view('seo.location', [
            'providers' => new LengthAwarePaginator([], 0, 20),
            'page' => (object) ['country' => 'in', 'kind' => 'country'],
            'language' => $language, 'lang' => $language, 'direction' => $t['_meta']['rtl'] ? 'rtl' : 'ltr', 'labels' => $labels, 't' => $t,
            'title' => $labels['directory'], 'description' => $labels['teachers'], 'canonicalUrl' => 'http://localhost/in',
            'url' => 'http://localhost/in', 'variants' => [], 'children' => [], 'breadcrumbs' => [],
            'filters' => [], 'searchTerm' => '', 'trialOnly' => false, 'hasActiveFilters' => false, 'clearFiltersUrl' => '/in',
        ])->render();
        $this->assertStringContainsString(e($labels['apply_filters']), $html);
        $this->assertStringContainsString(e($labels['empty']), $html);
        $html = view('site.my-webpage', [
            'provider' => (object) ['full_name' => 'Asha', 'city' => 'Kanpur', 'state' => 'UP', 'avatar_url' => null],
            'subjects' => collect(), 'related' => collect(), 'url' => 'http://localhost/my-webpage/teacher/asha',
            'oldProfileUrl' => 'http://localhost/teacher/asha', 'qrUrl' => 'https://example.test/qr.png',
            'lang' => $language, 'language' => $language, 't' => $t,
        ])->render();
        $this->assertStringContainsString(e($t['webpage']['title']), $html);
        $this->assertStringContainsString(e($t['webpage']['print']), $html);
        $this->assertStringContainsString('lang="'.$language.'"', $html);
    }
}
