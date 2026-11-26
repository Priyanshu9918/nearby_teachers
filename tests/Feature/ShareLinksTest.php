<?php

namespace Tests\Feature;

use Tests\TestCase;

class ShareLinksTest extends TestCase
{
    public function test_share_pages_only_offer_store_buttons_and_do_not_show_target_content(): void
    {
        config()->set('services.nearby_teacher.android_play_store_url', 'https://play.google.com/store/apps/details?id=com.bsl_nearbyteacher');
        config()->set('services.nearby_teacher.ios_app_store_url', 'https://apps.apple.com/app/id6751044599');

        foreach (['/share', '/share/', '/share/teacher/123', '/share/city-feed/delhi'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('Download for Android')
                ->assertSee('Download for iPhone')
                ->assertDontSee('123')
                ->assertDontSee('delhi')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $this->get('/share/teacher/abc')->assertNotFound();
        $this->get('/share/teacher/0')->assertNotFound();
    }

    public function test_app_share_api_returns_the_public_share_url_without_authentication(): void
    {
        config()->set('services.nearby_teacher.share_url', 'https://teachers.britishschooloflanguages.com/share/');

        $this->getJson('/api/share')->assertOk()
            ->assertExactJson(['shareUrl' => 'https://teachers.britishschooloflanguages.com/share/']);
    }

    public function test_mobile_association_endpoints_include_the_supplied_app_identifiers(): void
    {
        $this->getJson('/.well-known/assetlinks.json')->assertOk()
            ->assertJsonPath('0.target.package_name', 'com.bsl_nearbyteacher')
            ->assertJsonCount(2, '0.target.sha256_cert_fingerprints');

        $this->get('/.well-known/apple-app-site-association')->assertOk()
            ->assertJsonPath('applinks.details.0.appID', '6A5V7Y4R6A.com.bsl.nearbyteacher')
            ->assertJsonPath('applinks.details.0.paths.0', '/share')
            ->assertJsonPath('applinks.details.0.paths.1', '/share/');
    }
}
