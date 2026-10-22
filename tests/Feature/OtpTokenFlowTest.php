<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\TrackAuthActivity;
use App\Services\AuthenticationService;
use App\Services\OtpDelivery;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use Tests\TestCase;

class OtpTokenFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(TrackAuthActivity::class);
    }

    public function test_google_bearer_verifies_phone_on_existing_identity_without_issuing_token(): void
    {
        $user = (object) ['id' => 'google-user', 'google_id' => 'firebase-uid', 'suspended_at' => null];
        $authentication = $this->bindAuthentication();
        $authentication->shouldReceive('authenticate')->once()->with('google-session')->andReturn($user);
        $authentication->shouldNotReceive('issueOtpToken');
        $authentication->shouldNotReceive('issueSessionToken');
        $controller = \Mockery::mock(AuthController::class, [$authentication, $this->app->make(OtpDelivery::class)])->makePartial();
        $controller->shouldReceive('linkPhone')->once()->with(\Mockery::on(
            fn (Request $request) => $request->attributes->get('authenticated_user') === $user
                && $request->input('phone') === '+918112912880' && $request->input('code') === '123456'
        ))->andReturn(response()->json(['user' => $user, 'mobileVerified' => true]));
        $this->app->instance(AuthController::class, $controller);

        $this->postJson('/auth/otp/verify', ['phone' => '+918112912880', 'code' => '123456'], ['Authorization' => 'Bearer google-session'])
            ->assertOk()->assertJsonPath('user.id', 'google-user')->assertJsonMissingPath('token');
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

    private function bindAuthentication(): MockInterface
    {
        $authentication = \Mockery::mock(AuthenticationService::class);
        $this->app->instance(AuthenticationService::class, $authentication);

        return $authentication;
    }
}
