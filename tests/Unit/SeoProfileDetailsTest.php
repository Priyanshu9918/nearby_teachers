<?php

namespace Tests\Unit;

use App\Services\SeoDirectory;
use App\Support\SeoProfileDetails;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeoProfileDetailsTest extends TestCase
{
    public function test_masks_national_number_and_removes_original_phone_from_public_data(): void
    {
        $profile = SeoProfileDetails::prepare((object) ['contact_phone' => '+917800000123']);
        $this->assertSame('7800000xxx', $profile->masked_phone);
        $this->assertObjectNotHasProperty('contact_phone', $profile);
        $this->assertSame('2125550xxx', SeoProfileDetails::maskPhone('+12125550123'));
        $this->assertSame('xxxxxx', SeoProfileDetails::maskPhone('123456'));
        $this->assertNull(SeoProfileDetails::maskPhone(null));
    }

    public function test_full_address_includes_available_parts_without_duplicate_city(): void
    {
        $this->assertSame('House 99, Kanpur, Bithoor, Kanpur Nagar, Uttar Pradesh, 208012, India', SeoProfileDetails::address((object) [
            'address_text' => 'House 99, Kanpur', 'locality' => 'Bithoor', 'city' => 'Kanpur',
            'district' => 'Kanpur Nagar', 'state' => 'Uttar Pradesh', 'postal_code' => '208012', 'country' => 'India',
        ]));
    }

    public static function listingCases(): array
    {
        return [['seo.listing', 3], ['seo.listing', 1], ['seo.listing', 0], ['seo.location', 7], ['seo.location', 0]];
    }

    #[DataProvider('listingCases')]
    public function test_directory_templates_show_profile_details_and_only_positive_trial_durations(string $view, int $days): void
    {
        $item = SeoProfileDetails::prepare((object) [
            'provider_type' => 'teacher', 'user_id' => 1, 'public_page_slug' => 'example', 'full_name' => 'Example Teacher',
            'city' => 'Kanpur', 'locality' => 'Bithoor', 'district' => 'Kanpur Nagar', 'address_text' => 'House 99',
            'state' => 'Uttar Pradesh', 'country' => 'India', 'postal_code' => '208012', 'avatar_url' => null,
            'rating_avg' => 0, 'rating_count' => 0, 'subject_names' => 'Maths', 'lesson_modes' => '["online"]',
            'trial_days' => $days, 'rate' => 200, 'currency' => 'INR', 'referral_points' => 0,
            'email' => 'teacher@example.test', 'contact_phone' => '+917800000123',
            'description' => '<script>About teacher</script>', 'linkedin_url' => 'https://linkedin.com/in/example',
            'youtube_url' => 'https://youtube.com/@example',
        ]);
        $providers = new LengthAwarePaginator(collect([$item]), 1, 20);
        $html = view($view, [
            'providers' => $providers, 'page' => (object) ['country' => 'in', 'city' => 'Kanpur', 'kind' => 'city'],
            'language' => 'en', 'lang' => 'en', 'direction' => 'ltr', 'labels' => SeoDirectory::labels('en'), 't' => [],
            'title' => 'Maths teachers', 'description' => 'Find teachers', 'canonicalUrl' => 'http://localhost/in/kanpur/maths',
            'url' => 'http://localhost/in/kanpur/maths', 'variants' => [], 'children' => [], 'breadcrumbs' => [],
            'filters' => [], 'searchTerm' => '', 'trialOnly' => false, 'hasActiveFilters' => false, 'clearFiltersUrl' => '/',
        ])->render();
        foreach (['House 99', '208012', 'teacher@example.test', '7800000xxx', 'https://linkedin.com/in/example', 'https://youtube.com/@example', '&lt;script&gt;About teacher&lt;/script&gt;'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('7800000123', $html);
        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringNotContainsString('<script>About teacher</script>', $html);
        if ($days > 0) {
            $this->assertStringContainsString($days.($days === 1 ? ' day trial' : ' days trial'), $html);
        } else {
            $this->assertStringNotContainsString(' days trial', $html);
        }
    }

    public function test_missing_details_and_unsafe_social_urls_are_hidden(): void
    {
        $html = view('seo.partials.profile-details', ['item' => (object) [
            'linkedin_url' => 'javascript:alert(1)', 'youtube_url' => 'https://evil.test',
        ], 'labels' => SeoDirectory::labels('en'), 'contactHref' => '/download'])->render();
        foreach (['mailto:', 'Call', 'javascript:', 'evil.test', 'YouTube', 'LinkedIn'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
    }

    public static function profileCases(): array
    {
        return [['teacher', 3], ['teacher', 0], ['institute', 7], ['institute', 0]];
    }

    #[DataProvider('profileCases')]
    public function test_both_detail_templates_show_address_contact_about_social_links_and_trial(string $kind, int $days): void
    {
        $profile = SeoProfileDetails::prepare((object) [
            'full_name' => 'Example Teacher', 'slug' => 'example', 'seo_country' => 'in', 'city' => 'Kanpur',
            'state' => 'Uttar Pradesh', 'country' => 'India', 'address_text' => 'House 99', 'postal_code' => '208012',
            'email' => 'teacher@example.test', 'contact_phone' => '+917800000123', 'avatar_url' => null,
            'bio' => 'About this teacher', 'description' => 'About this institute', 'lesson_modes' => '["online"]',
            'linkedin_url' => 'https://linkedin.com/in/example', 'youtube_url' => 'https://youtube.com/@example',
            'website_url' => 'https://example.test', 'lessons_completed' => 0, 'rating_count' => 0, 'rating_avg' => 0,
            'rate_per_hour' => 200, 'starting_rate' => 200, 'currency' => 'INR', 'trial_days' => $days,
        ]);
        $html = view('seo.'.$kind, [
            $kind => $profile, 'subjects' => collect([(object) ['name' => 'Maths']]), 'reviews' => new LengthAwarePaginator([], 0, 10),
            'language' => 'en', 'lang' => 'en', 'labels' => SeoDirectory::labels('en'), 't' => [],
            'url' => 'http://localhost/'.$kind.'/example', 'related' => collect(), 'downloadHref' => '/download',
        ])->render();
        foreach (['House 99', '208012', 'teacher@example.test', '7800000xxx', 'About this '.$kind, 'https://youtube.com/@example'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('7800000123', $html);
        $this->assertStringNotContainsString('tel:', $html);
        if ($days > 0) {
            $this->assertStringContainsString($days.' days trial', $html);
        } else {
            $this->assertStringNotContainsString(' days trial', $html);
        }
    }
}
