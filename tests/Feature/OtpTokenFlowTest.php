<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAuthActivity;
use App\Services\AuthenticationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OtpTokenFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(TrackAuthActivity::class);
    }

    #[DataProvider('googlePhoneVerificationEndpoints')]
    public function test_google_bearer_verifies_phone_on_existing_identity_and_returns_fresh_token(string $method, string $endpoint): void
    {
        $user = (object) [
            'id' => 1, 'google_id' => 'firebase-uid', 'suspended_at' => null,
            'phone_verified_at' => null, 'phone' => null, 'is_admin' => false,
        ];
        $authentication = $this->bindAuthentication();
        $authentication->shouldReceive('authenticate')->once()->with('google-session')->andReturn($user);
        $authentication->shouldNotReceive('issueOtpToken');
        $authentication->shouldReceive('issueSessionToken')->once()->with($user->id, 0)->andReturn('verified-google-session');
        $this->app['auth']->guard();
        DB::shouldReceive('transaction')->twice()->andReturnUsing(fn ($callback) => $callback());
        $otp = (object) [
            'id' => 2, 'consumed_at' => null, 'expires_at' => now()->addMinutes(5),
            'attempts' => 0, 'code_hash' => Hash::make('123456'),
        ];
        $otpQuery = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('otp_codes')->twice()->andReturn($otpQuery);
        $otpQuery->shouldReceive('where')->with('phone', '+918112912880')->once()->andReturnSelf();
        $otpQuery->shouldReceive('orderByDesc')->with('created_at')->once()->andReturnSelf();
        $otpQuery->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $otpQuery->shouldReceive('first')->once()->andReturn($otp);
        $otpQuery->shouldReceive('where')->with('id', 2)->once()->andReturnSelf();
        $otpQuery->shouldReceive('update')->once()->with(\Mockery::on(fn ($data) => isset($data['consumed_at'])))->andReturn(1);
        $query = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->times(4)->andReturn($query);
        $query->shouldReceive('select')->with('id')->once()->andReturnSelf();
        $query->shouldReceive('where')->with('phone', '+918112912880')->once()->andReturnSelf();
        $query->shouldReceive('where')->with('id', $user->id)->times(3)->andReturnSelf();
        $query->shouldReceive('lockForUpdate')->twice()->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn($user);
        $query->shouldReceive('first')->once()->andReturnNull();
        $query->shouldReceive('update')->once()->with(\Mockery::on(
            fn ($data) => $data['phone'] === '+918112912880' && isset($data['phone_verified_at'])
        ))->andReturn(1);
        $fresh = (object) [...(array) $user, 'phone_verified_at' => now()];
        $query->shouldReceive('first')->once()->andReturn($fresh);
        $authentication->shouldReceive('publicUser')->once()->with($fresh)->andReturn($fresh);
        DB::shouldReceive('transactionLevel')->once()->andReturn(1);
        $activity = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('business_activity')->once()->andReturn($activity);
        $activity->shouldReceive('insertGetId')->once()->andReturn(3);

        $this->json($method, $endpoint, ['authMethod' => 'google', 'phone' => '+918112912880', 'code' => '123456'], ['Authorization' => 'Bearer google-session'])
            ->assertOk()->assertJsonPath('user.id', 1)->assertJsonPath('token', 'verified-google-session')->assertJsonPath('authMethod', 'google')
            ->assertJsonPath('mobileVerified', true)->assertJsonPath('phoneVerificationRequired', false);
    }

    public function test_invalid_bearer_cannot_fall_back_to_creating_phone_account(): void
    {
        $authentication = $this->bindAuthentication();
        $authentication->shouldReceive('authenticate')->once()->with('expired-session')->andReturnNull();
        $authentication->shouldNotReceive('issueOtpToken');

        $this->postJson('/auth/otp/verify', [], ['Authorization' => 'Bearer expired-session'])
            ->assertForbidden()->assertJsonMissingPath('token');
    }

    public function test_malformed_authorization_cannot_fall_back_to_normal_signup(): void
    {
        $authentication = $this->bindAuthentication();
        $authentication->shouldNotReceive('authenticate');
        $authentication->shouldNotReceive('issueOtpToken');

        $this->postJson('/auth/otp/verify', [], ['Authorization' => 'Basic invalid'])
            ->assertForbidden()->assertJsonMissingPath('token');
    }

    public function test_suspended_google_account_cannot_verify_phone(): void
    {
        $authentication = $this->bindAuthentication();
        $authentication->shouldReceive('authenticate')->once()->with('suspended-session')
            ->andReturn((object) ['google_id' => 'firebase-uid', 'suspended_at' => now()]);

        $this->postJson('/auth/otp/verify', [], ['Authorization' => 'Bearer suspended-session'])
            ->assertForbidden()->assertJsonPath('suspended', true)->assertJsonMissingPath('token');
    }

    public function test_normal_signup_rejects_existing_bearer_instead_of_switching_accounts(): void
    {
        $authentication = $this->bindAuthentication();
        $authentication->shouldReceive('authenticate')->once()->with('phone-session')
            ->andReturn((object) ['google_id' => null, 'suspended_at' => null]);

        $this->postJson('/auth/otp/verify', [], ['Authorization' => 'Bearer phone-session'])
            ->assertStatus(409)->assertJsonPath('code', 'OTP_BEARER_NOT_GOOGLE')->assertJsonMissingPath('token');
    }

    #[DataProvider('otpEndpoints')]
    public function test_google_otp_requires_bearer_on_request_and_verification(string $endpoint): void
    {
        $authentication = $this->bindAuthentication();
        $authentication->shouldNotReceive('authenticate');
        $this->postJson($endpoint, ['authMethod' => 'google', 'phone' => '+918112912880'])
            ->assertUnauthorized()->assertJsonPath('code', 'GOOGLE_AUTH_REQUIRED');
    }

    #[DataProvider('otpEndpoints')]
    public function test_otp_flow_flag_is_allowlisted(string $endpoint): void
    {
        $this->postJson($endpoint, ['authMethod' => 'bypass'])
            ->assertUnprocessable()->assertJsonValidationErrors('authMethod');
    }

    public static function otpEndpoints(): array
    {
        return [['/auth/otp/request'], ['/auth/otp/verify']];
    }

    public static function googlePhoneVerificationEndpoints(): array
    {
        return [
            'Google OTP verification' => ['POST', '/auth/otp/verify'],
            'Google phone linking' => ['PATCH', '/auth/link-phone'],
        ];
    }

    private function bindAuthentication(): MockInterface
    {
        $authentication = \Mockery::mock(AuthenticationService::class);
        $this->app->instance(AuthenticationService::class, $authentication);

        return $authentication;
    }
}
