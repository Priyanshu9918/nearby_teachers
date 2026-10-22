<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAuthActivity;
use App\Services\AuthenticationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $this->app['auth']->guard();
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        $otp = (object) [
            'id' => 'otp-id', 'consumed_at' => null, 'expires_at' => now()->addMinutes(5),
            'attempts' => 0, 'code_hash' => Hash::make('123456'),
        ];
        $otpQuery = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('otp_codes')->twice()->andReturn($otpQuery);
        $otpQuery->shouldReceive('where')->with('phone', '+918112912880')->once()->andReturnSelf();
        $otpQuery->shouldReceive('orderByDesc')->with('created_at')->once()->andReturnSelf();
        $otpQuery->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $otpQuery->shouldReceive('first')->once()->andReturn($otp);
        $otpQuery->shouldReceive('where')->with('id', 'otp-id')->once()->andReturnSelf();
        $otpQuery->shouldReceive('update')->once()->with(\Mockery::on(fn ($data) => isset($data['consumed_at'])))->andReturn(1);
        $query = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->times(3)->andReturn($query);
        $query->shouldReceive('select')->with('id')->once()->andReturnSelf();
        $query->shouldReceive('where')->with('phone', '+918112912880')->once()->andReturnSelf();
        $query->shouldReceive('where')->with('id', $user->id)->twice()->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturnNull();
        $query->shouldReceive('update')->once()->with(\Mockery::on(
            fn ($data) => $data['phone'] === '+918112912880' && isset($data['phone_verified_at'])
        ))->andReturn(1);
        $fresh = (object) [...(array) $user, 'phone_verified_at' => now()];
        $query->shouldReceive('first')->once()->andReturn($fresh);
        $authentication->shouldReceive('publicUser')->once()->with($fresh)->andReturn($fresh);

        $this->postJson('/auth/otp/verify', ['phone' => '+918112912880', 'code' => '123456'], ['Authorization' => 'Bearer google-session'])
            ->assertOk()->assertJsonPath('user.id', 'google-user')->assertJsonPath('token', 'google-session');
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
