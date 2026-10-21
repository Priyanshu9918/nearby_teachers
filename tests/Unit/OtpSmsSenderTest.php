<?php

namespace Tests\Unit;

use App\Services\OtpSmsSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OtpSmsSenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nearby_teacher.otp_sms_provider' => 'muzztech', 'services.nearby_teacher.muzztech' => [
            'api_key' => 'test-api-key', 'sender_name' => 'BSLONL', 'template_id' => '1234567890123',
            'message' => 'Code {otp}. Valid for 10 minutes & do not share.', 'phone_format' => 'digits',
            'success_field' => 'status', 'success_value' => 'success',
        ]]);
        Http::preventStrayRequests();
    }

    public function test_muzztech_encodes_the_supplied_get_contract_and_inserts_the_generated_otp(): void
    {
        Http::fake(['connect.muzztech.com/*' => Http::response(['status' => 'success'])]);
        app(OtpSmsSender::class)->send('+919876543210', '123456', 'IN');
        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://connect.muzztech.com/api/sms/send?')
                && $query === ['api_key' => 'test-api-key', 'phone_number' => '919876543210', 'sender_name' => 'BSLONL',
                    'message' => 'Code 123456. Valid for 10 minutes & do not share.', 'template_id' => '1234567890123'];
        });
        Http::assertSentCount(1);
    }

    public function test_phone_formats_and_nested_boolean_success_marker_are_configurable(): void
    {
        config(['services.nearby_teacher.muzztech.success_field' => 'data.accepted', 'services.nearby_teacher.muzztech.success_value' => true]);
        Http::fake(['*' => Http::response(['data' => ['accepted' => true]])]);
        foreach (['e164' => '+919876543210', 'national' => '9876543210'] as $format => $expected) {
            config(['services.nearby_teacher.muzztech.phone_format' => $format]);
            app(OtpSmsSender::class)->send('+919876543210', '123456', 'IN');
            Http::assertSent(function (Request $request) use ($expected): bool {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

                return $query['phone_number'] === $expected;
            });
        }
    }

    public function test_http_errors_gateway_rejections_and_unknown_response_shapes_are_not_success(): void
    {
        Http::fakeSequence()->push(['status' => 'error', 'message' => 'private-provider-detail'])
            ->push(['status' => 'success'], 503)->push(['status' => 'success'], 302)
            ->push('<html>Login required</html>')->push(['unrecognized' => true]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                app(OtpSmsSender::class)->send('+919876543210', '123456', 'IN');
                $this->fail('Unacknowledged requests must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Muzztech SMS delivery was not acknowledged.', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }
        Http::assertSentCount(5);
    }

    public function test_connection_exceptions_are_scrubbed_without_retry_or_previous_exception(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        try {
            app(OtpSmsSender::class)->send('+919876543210', '123456', 'IN');
            $this->fail('Expected a safe delivery failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Muzztech SMS delivery was not acknowledged.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('test-api-key', (string) $exception);
            $this->assertStringNotContainsString('123456', (string) $exception);
        }
    }

    public function test_missing_or_placeholder_template_and_message_without_otp_never_call_the_gateway(): void
    {
        foreach (['', 'xxxxxxxxxxxxx'] as $template) {
            config(['services.nearby_teacher.muzztech.template_id' => $template]);
            try {
                app(OtpSmsSender::class)->send('+919876543210', '123456', 'IN');
                $this->fail('Template must be real.');
            } catch (RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        config(['services.nearby_teacher.muzztech.template_id' => '1234567890123', 'services.nearby_teacher.muzztech.message' => 'A message without a code.']);
        $this->expectException(RuntimeException::class);
        try {
            app(OtpSmsSender::class)->send('+919876543210', '123456', 'IN');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_auto_provider_keeps_existing_india_msg91_and_international_twilio_routing(): void
    {
        config(['services.nearby_teacher.otp_sms_provider' => 'auto', 'services.nearby_teacher.msg91_auth_key' => 'msg91-test-key',
            'services.nearby_teacher.msg91_template_id' => 'msg91-template', 'services.nearby_teacher.twilio_account_sid' => 'ACtest',
            'services.nearby_teacher.twilio_auth_token' => 'twilio-test-key', 'services.nearby_teacher.twilio_from_number' => '+12025550100']);
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success']), 'api.twilio.com/*' => Http::response(['sid' => 'SMtest'], 201)]);
        app(OtpSmsSender::class)->send('+919876543210', '123456', 'IN');
        app(OtpSmsSender::class)->send('+12025550101', '123456', 'US');
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'muzztech'));
    }
}
