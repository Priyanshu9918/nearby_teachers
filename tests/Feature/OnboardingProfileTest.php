<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class OnboardingProfileTest extends TestCase
{
    public function test_onboarding_routes_require_authentication(): void
    {
        $this->getJson('/auth/onboarding')
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Missing Authorization header']);
        $this->patchJson('/auth/onboarding', ['fullName' => 'Anita'])
            ->assertUnauthorized();
        $this->postJson('/auth/onboarding/complete', [])
            ->assertForbidden()->assertJsonPath('code', 'OTP_VERIFICATION_REQUIRED');
        $this->postJson('/auth/register', [])->assertForbidden()
            ->assertJsonPath('code', 'OTP_VERIFICATION_REQUIRED');
    }

    public function test_registration_prompts_for_inline_otp_before_authentication_for_all_roles(): void
    {
        foreach (['student', 'teacher', 'institute'] as $role) {
            foreach (['/auth/register', '/auth/onboarding/complete'] as $endpoint) {
                $this->postJson($endpoint, ['accountType' => $role])
                    ->assertForbidden()
                    ->assertJsonPath('message', 'Please verify your mobile number with OTP before registering.')
                    ->assertJsonPath('code', 'OTP_VERIFICATION_REQUIRED')
                    ->assertJsonPath('phoneVerificationRequired', true)
                    ->assertJsonPath('canSubmit', false)
                    ->assertJsonPath('requestOtpEndpoint', '/auth/otp/request')
                    ->assertJsonPath('verifyPhoneEndpoint', '/auth/otp/verify');
            }
        }
    }

    public function test_registration_with_invalid_token_prompts_for_otp_again(): void
    {
        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->with('invalid-token')->once()->andReturnNull();
        $this->app->instance(AuthenticationService::class, $authentication);

        $this->postJson('/auth/register', ['accountType' => 'student'], ['Authorization' => 'Bearer invalid-token'])
            ->assertForbidden()->assertJsonPath('code', 'OTP_VERIFICATION_REQUIRED')
            ->assertJsonPath('verifyPhoneEndpoint', '/auth/otp/verify');
    }

    public function test_unverified_phone_can_read_onboarding_with_mobile_verified_false(): void
    {
        $this->bindUser((object) [
            'id' => NumericTestIds::next(),
            'account_type' => 'learner',
            'full_name' => 'New user',
            'email' => null,
            'phone' => null,
            'phone_verified_at' => null,
            'terms_accepted_at' => null,
            'safety_disclaimer_acknowledged_at' => null,
            'profile_completed_at' => null,
            'password_set' => false,
            'suspended_at' => null,
            'is_admin' => false,
        ]);

        $this->getJson('/auth/onboarding', ['Authorization' => 'Bearer onboarding-token'])
            ->assertOk()
            ->assertJsonPath('mobileVerified', false)
            ->assertJsonPath('canSubmit', false)
            ->assertJsonPath('nextStep', 'mobile_verification')
            ->assertJsonPath('blockers.0', 'mobile_not_verified')
            ->assertJsonPath('draft.role', 'student');
    }

    public function test_autosave_and_complete_are_blocked_until_mobile_verified(): void
    {
        $this->bindUser((object) [
            'id' => NumericTestIds::next(),
            'suspended_at' => null,
            'is_admin' => false,
            'phone_verified_at' => null,
        ]);

        $this->patchJson('/auth/onboarding', [
            'fullName' => 'Anita Sharma',
        ], ['Authorization' => 'Bearer onboarding-token'])
            ->assertForbidden()
            ->assertJsonPath('mobileVerified', false)
            ->assertJsonPath('phoneVerificationRequired', true);

        $this->postJson('/auth/onboarding/complete', [
            'accountType' => 'student',
            'fullName' => 'Anita Sharma',
            'email' => 'anita@example.com',
            'password' => 'a-secure-password',
            'termsAccepted' => true,
            'acknowledgeTeachersNotBackgroundChecked' => true,
        ], ['Authorization' => 'Bearer onboarding-token'])
            ->assertForbidden()
            ->assertJsonPath('mobileVerified', false);

        $this->postJson('/auth/register', ['accountType' => 'student'], ['Authorization' => 'Bearer onboarding-token'])
            ->assertForbidden()->assertJsonPath('code', 'OTP_VERIFICATION_REQUIRED')
            ->assertJsonPath('verifyPhoneEndpoint', '/auth/link-phone');
    }

    public function test_complete_still_requires_profile_fields_after_mobile_verification(): void
    {
        $user = (object) [
            'id' => NumericTestIds::next(),
            'account_type' => 'learner',
            'full_name' => 'New user',
            'email' => null,
            'phone' => '+918112912880',
            'phone_verified_at' => now(),
            'password_set' => false,
            'terms_accepted_at' => null,
            'safety_disclaimer_acknowledged_at' => null,
            'profile_completed_at' => null,
            'suspended_at' => null,
            'is_admin' => false,
        ];
        $this->bindUser($user);
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        $query = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->once()->andReturn($query);
        $query->shouldReceive('where')->with('id', $user->id)->once()->andReturnSelf();
        $query->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn($user);

        $activity = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('auth_activity')->once()->andReturn($activity);
        $activity->shouldReceive('insert')->once()->with(\Mockery::on(fn ($row) => $row['status_code'] === 422 && $row['successful'] === false))->andReturn(true);

        $this->postJson('/auth/onboarding/complete', [], ['Authorization' => 'Bearer onboarding-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accountType', 'fullName', 'email', 'password', 'termsAccepted', 'acknowledgeTeachersNotBackgroundChecked']);
    }

    public function test_google_onboarding_does_not_report_email_or_password_as_missing(): void
    {
        $this->bindUser((object) [
            'id' => NumericTestIds::next(), 'account_type' => 'learner',
            'google_id' => 'verified-google-user', 'full_name' => 'Google User',
            'email' => 'google@example.test', 'phone' => '+918112912880',
            'phone_verified_at' => now(), 'password_set' => false,
            'terms_accepted_at' => now(), 'safety_disclaimer_acknowledged_at' => now(),
            'profile_completed_at' => null, 'suspended_at' => null, 'is_admin' => false,
        ]);

        $this->getJson('/auth/onboarding', ['Authorization' => 'Bearer onboarding-token'])
            ->assertOk()->assertJsonPath('canSubmit', true)
            ->assertJsonPath('missingFields', [])->assertJsonPath('draft.passwordSet', false);
    }

    public function test_google_registration_validates_profile_without_requiring_credentials(): void
    {
        $user = (object) [
            'id' => NumericTestIds::next(), 'google_id' => 'verified-google-user',
            'phone_verified_at' => now(), 'profile_completed_at' => null,
            'suspended_at' => null, 'is_admin' => false,
        ];
        $this->bindUser($user);
        $this->app['auth']->guard();
        DB::shouldReceive('transaction')->twice()->andReturnUsing(fn ($callback) => $callback());
        $query = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->twice()->andReturn($query);
        $query->shouldReceive('where')->with('id', $user->id)->twice()->andReturnSelf();
        $query->shouldReceive('lockForUpdate')->twice()->andReturnSelf();
        $query->shouldReceive('first')->twice()->andReturn($user);
        $activity = \Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('auth_activity')->twice()->andReturn($activity);
        $activity->shouldReceive('insert')->twice()->andReturn(true);

        $this->postJson('/auth/register', [], ['Authorization' => 'Bearer onboarding-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accountType', 'fullName', 'termsAccepted', 'acknowledgeTeachersNotBackgroundChecked'])
            ->assertJsonMissingValidationErrors(['email', 'password']);
        $this->postJson('/auth/onboarding/complete', ['email' => 'invalid', 'password' => 'short'], ['Authorization' => 'Bearer onboarding-token'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_password_login_validates_identifier_and_password(): void
    {
        $this->postJson('/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    private function bindUser(object $user): void
    {
        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')
            ->with('onboarding-token')
            ->andReturn($user);
        $this->app->instance(AuthenticationService::class, $authentication);
    }
}
