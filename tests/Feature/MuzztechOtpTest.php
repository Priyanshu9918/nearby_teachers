<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MuzztechOtpTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('OTP_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set OTP_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database,
            'services.nearby_teacher.jwt_secret' => str_repeat('muzztech-test-secret-', 3),
            'services.nearby_teacher.otp_test_mode' => false, 'services.nearby_teacher.otp_sms_provider' => 'muzztech',
            'services.nearby_teacher.muzztech' => ['api_key' => 'private-test-api-key', 'sender_name' => 'BSLONL', 'template_id' => '1234567890123',
                'message' => 'OTP: {otp}. Valid for 10 minutes.', 'phone_format' => 'digits', 'success_field' => 'status', 'success_value' => 'success']]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_gateway_acknowledgement_sends_the_backend_code_and_existing_verification_works(): void
    {
        Http::fake(['connect.muzztech.com/*' => Http::response(['status' => 'success'])]);
        $response = $this->postJson('/auth/otp/request', ['phone' => '+919876543210'])->assertOk()->assertJsonMissingPath('otp');
        $response->assertJsonMissingPath('api_key');
        $code = $this->sentCode();
        $saved = DB::table('otp_codes')->first();
        $this->assertNotSame($code, $saved->code_hash);
        $this->assertTrue(Hash::check($code, $saved->code_hash));
        $this->postJson('/auth/otp/verify', ['phone' => '+919876543210', 'code' => $code])->assertOk()
            ->assertJsonPath('mobileVerified', true)->assertJsonPath('nextScreen', 'onboarding');
        Http::assertSentCount(1);
    }

    public function test_provider_rejection_invalidates_the_code_and_does_not_expose_gateway_details(): void
    {
        Http::fake(['connect.muzztech.com/*' => Http::response(['status' => 'error', 'message' => 'private-provider-diagnostic'])]);
        $response = $this->postJson('/auth/otp/request', ['phone' => '+919876543210'])->assertStatus(503);
        $this->assertStringNotContainsString('private-test-api-key', $response->getContent());
        $this->assertStringNotContainsString('private-provider-diagnostic', $response->getContent());
        $this->assertNotNull(DB::table('otp_codes')->value('consumed_at'));
        $this->postJson('/auth/otp/verify', ['phone' => '+919876543210', 'code' => $this->sentCode()])->assertUnprocessable();
    }

    public function test_incomplete_configuration_blocks_live_sending_but_local_test_mode_stays_available(): void
    {
        config(['services.nearby_teacher.muzztech.template_id' => '']);
        $this->postJson('/auth/otp/request', ['phone' => '+919876543210'])->assertStatus(503);
        config(['services.nearby_teacher.otp_test_mode' => true]);
        $this->postJson('/auth/otp/request', ['phone' => '+919876543210'])->assertOk()->assertJsonPath('testMode', true)->assertJsonStructure(['otp'])->assertJsonPath('channels.sms', 'simulated');
        Http::assertNothingSent();
    }

    public function test_both_channels_receive_the_same_code_and_history_contains_no_secrets(): void
    {
        $this->enableWhatsApp();
        Http::fake([
            'connect.muzztech.com/*' => Http::response(['status' => 'success']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]]),
        ]);
        $this->postJson('/auth/otp/request', ['phone' => '+919876543210'])->assertOk()
            ->assertJsonMissingPath('otp')->assertJsonPath('channels.sms', 'accepted')->assertJsonPath('channels.whatsapp', 'accepted');
        $code = $this->sentCode();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['to'] === '919876543210'
            && $request['template']['components'][0]['parameters'][0]['text'] === $code
            && $request['template']['components'][1]['parameters'][0]['text'] === $code);
        $this->assertSame(2, DB::table('otp_delivery_attempts')->count());
        $this->get('/admin/reports/otp-delivery')->assertRedirect(route('admin.login'));
        $csv = $this->withSession(['admin_authenticated' => true])->get('/admin/reports/otp-delivery?q=whatsapp&export=1')
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('whatsapp', $csv);
        $this->assertStringNotContainsString(',sms,', $csv);
        $this->assertStringNotContainsString($code, $csv);

        $this->assertSame(2, DB::table('business_activity')->where('event', 'otp.delivery_result')->count());
        $history = json_encode(DB::table('otp_delivery_attempts')->get());
        foreach ([$code, 'private-test-api-key', '+919876543210'] as $secret) {
            $this->assertStringNotContainsString($secret, $history);
        }
    }

    public function test_partial_delivery_keeps_the_sms_code_usable(): void
    {
        $this->enableWhatsApp();
        Http::fake([
            'connect.muzztech.com/*' => Http::response(['status' => 'success']),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'private-token']], 401),
        ]);
        $this->postJson('/auth/otp/request', ['phone' => '+919876543210'])->assertStatus(202)
            ->assertJsonPath('partialDelivery', true)->assertJsonPath('channels.whatsapp', 'failed')->assertJsonMissingPath('otp');
        $this->assertNull(DB::table('otp_codes')->value('consumed_at'));
        $this->postJson('/auth/otp/verify', ['phone' => '+919876543210', 'code' => $this->sentCode()])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_missing_whatsapp_config_prevents_either_channel_from_sending(): void
    {
        $this->enableWhatsApp();
        config(['services.nearby_teacher.whatsapp.access_token' => '']);
        $this->postJson('/auth/otp/request', ['phone' => '+919876543210'])->assertStatus(503)
            ->assertJsonPath('channels.sms', 'not_sent')->assertJsonPath('channels.whatsapp', 'not_sent');
        Http::assertNothingSent();
        $this->assertNotNull(DB::table('otp_codes')->value('consumed_at'));
    }

    public function test_all_channel_rejections_invalidate_the_code(): void
    {
        $this->enableWhatsApp();
        Http::fake([
            'connect.muzztech.com/*' => Http::response(['status' => 'error']),
            'graph.facebook.com/*' => Http::response(['messages' => []]),
        ]);
        $this->postJson('/auth/otp/request', ['phone' => '+919876543210'])->assertStatus(503)
            ->assertJsonPath('channels.sms', 'failed')->assertJsonPath('channels.whatsapp', 'failed');
        Http::assertSentCount(2);
        $this->assertNotNull(DB::table('otp_codes')->value('consumed_at'));
    }

    private function enableWhatsApp(): void
    {
        config(['services.nearby_teacher.otp_delivery_channels' => 'sms,whatsapp',
            'services.nearby_teacher.whatsapp' => ['access_token' => 'private-wa-token', 'phone_number_id' => '123456789',
                'template_name' => 'login_code', 'language' => 'en_US', 'graph_version' => 'v23.0']]);
    }

    private function sentCode(): string
    {
        parse_str(parse_url(Http::recorded()[0][0]->url(), PHP_URL_QUERY), $query);
        preg_match('/\b(\d{6})\b/', $query['message'], $matches);
        $this->assertArrayHasKey(1, $matches);

        return $matches[1];
    }
}
