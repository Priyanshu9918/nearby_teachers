<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use App\Services\OtpDelivery;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FirebaseTokens;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class FirebaseGoogleAuthTest extends TestCase
{
    use FirebaseTokens;

    private bool $transactionStarted = false;

    private string $lastOtp;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('FIREBASE_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set FIREBASE_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database,
            'services.nearby_teacher.jwt_secret' => str_repeat('firebase-test-secret-', 3), 'services.nearby_teacher.otp_test_mode' => true]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->fakeFirebase();
        $this->captureOtp();
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_google_signup_links_phone_completes_common_registration_and_resumes_at_home_on_next_login(): void
    {
        $login = $this->google()->assertOk()->assertJsonPath('authMethod', 'google')->assertJsonPath('nextScreen', 'onboarding')
            ->assertJsonPath('onboardingRequired', true)->assertJsonPath('phoneVerificationRequired', true);
        $id = $login->json('user.id');
        $bearer = ['Authorization' => 'Bearer '.$login->json('token')];
        $this->getJson('/auth/onboarding', $bearer)->assertOk()->assertJsonPath('draft.email', 'google@example.test')->assertJsonPath('nextScreen', 'onboarding');
        $this->postJson('/auth/register', $this->form(), $bearer)->assertForbidden()->assertJsonPath('code', 'OTP_VERIFICATION_REQUIRED');
        $phone = '+918112912880';
        $this->postJson('/auth/otp/request', ['authMethod' => 'google', 'phone' => $phone], $bearer)->assertOk()->assertJsonStructure(['otp']);
        $otp = $this->lastOtp;
        $linked = $this->patchJson('/auth/link-phone', ['phone' => $phone, 'code' => $otp], $bearer)->assertOk()
            ->assertJsonPath('nextScreen', 'onboarding')->assertJsonPath('phoneVerificationRequired', false)
            ->assertJsonPath('authMethod', 'google');
        $this->assertNotSame($login->json('token'), $linked->json('token'));
        $bearer = ['Authorization' => 'Bearer '.$linked->json('token')];
        $form = $this->form();
        unset($form['email'], $form['password']);
        $this->postJson('/auth/register', $form, $bearer)->assertOk()
            ->assertJsonPath('nextScreen', 'age_verification')->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('draft.passwordSet', false)
            ->assertJsonPath('draft.email', 'google@example.test')->assertJsonPath('missingFields', []);
        $saved = DB::table('users')->where('id', $id)->first();
        $this->assertNull($saved->password_hash);
        $this->assertNotNull($saved->email_verified_at);
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'], $bearer)->assertOk();
        $this->getJson('/auth/session', $bearer)->assertOk()->assertJsonPath('nextScreen', 'home');
        $this->google()->assertOk()->assertJsonPath('user.id', $id)->assertJsonPath('nextScreen', 'home')->assertJsonPath('onboardingRequired', false);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'identity.google_linked')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'registration.completed')->count());
    }

    public function test_google_otp_verify_issues_fresh_token_for_same_account_and_completes_passwordless_registration(): void
    {
        $login = $this->google()->assertOk();
        $id = $login->json('user.id');
        $bearer = ['Authorization' => 'Bearer '.$login->json('token')];
        $phone = '+918112912880';
        $this->postJson('/auth/otp/request', ['authMethod' => 'google', 'phone' => $phone], $bearer)->assertOk()->assertJsonMissingPath('token');
        $verified = $this->postJson('/auth/otp/verify', ['authMethod' => 'google', 'phone' => $phone, 'code' => $this->lastOtp], $bearer)
            ->assertOk()->assertJsonPath('user.id', $id)->assertJsonPath('mobileVerified', true)
            ->assertJsonPath('authMethod', 'google');
        $this->assertNotSame($login->json('token'), $verified->json('token'));
        $bearer = ['Authorization' => 'Bearer '.$verified->json('token')];
        $this->getJson('/auth/session', $bearer)->assertOk()->assertJsonPath('user.id', $id)
            ->assertJsonPath('mobileVerified', true);
        $form = $this->form();
        unset($form['email'], $form['password']);
        $this->postJson('/auth/register', $form, $bearer)->assertOk()->assertJsonPath('registrationCompleted', false);
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'], $bearer)
            ->assertOk()->assertJsonPath('registrationCompleted', true);
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_google_otp_cannot_be_verified_without_bearer_or_by_another_google_account(): void
    {
        $login = $this->google()->assertOk();
        $bearer = ['Authorization' => 'Bearer '.$login->json('token')];
        $phone = '+918112912880';
        $this->postJson('/auth/otp/request', ['authMethod' => 'google', 'phone' => $phone], $bearer)->assertOk();
        $code = $this->lastOtp;
        $this->assertSame($login->json('user.id'), DB::table('otp_codes')->value('google_user_id'));
        $this->postJson('/auth/otp/verify', ['phone' => $phone, 'code' => $code])
            ->assertUnauthorized()->assertJsonPath('code', 'GOOGLE_AUTH_REQUIRED')->assertJsonMissingPath('token');
        $other = $this->google(['sub' => 'different-google-user', 'email' => 'other@example.test'])->assertOk();
        $otherBearer = ['Authorization' => 'Bearer '.$other->json('token')];
        $this->postJson('/auth/otp/verify', ['authMethod' => 'google', 'phone' => $phone, 'code' => $code], $otherBearer)
            ->assertForbidden()->assertJsonPath('code', 'OTP_GOOGLE_ACCOUNT_MISMATCH')->assertJsonMissingPath('token');
        $this->assertNull(DB::table('otp_codes')->value('consumed_at'));
        $this->assertFalse(DB::table('users')->where('phone', $phone)->exists());
        $this->postJson('/auth/otp/verify', ['authMethod' => 'google', 'phone' => $phone, 'code' => $code], $bearer)
            ->assertOk()->assertJsonPath('user.id', $login->json('user.id'));
    }

    public function test_google_phone_verification_is_atomic_with_otp_and_allowlisted_history(): void
    {
        $login = $this->google()->assertOk();
        $id = $login->json('user.id');
        $bearer = ['Authorization' => 'Bearer '.$login->json('token')];
        $phone = '+918112912880';
        $this->postJson('/auth/otp/request', ['authMethod' => 'google', 'phone' => $phone], $bearer)->assertOk();
        $failHistory = true;
        DB::listen(function ($query) use (&$failHistory): void {
            if ($failHistory && str_starts_with($query->sql, 'insert into `business_activity`')) {
                throw new \RuntimeException('Simulated phone history failure');
            }
        });
        $this->postJson('/auth/otp/verify', ['authMethod' => 'google', 'phone' => $phone, 'code' => $this->lastOtp], $bearer)
            ->assertStatus(500)->assertJsonMissingPath('token');
        $this->assertNull(DB::table('users')->where('id', $id)->value('phone_verified_at'));
        $this->assertNull(DB::table('otp_codes')->value('consumed_at'));
        $this->assertSame(0, DB::table('business_activity')->where('event', 'identity.phone_verified')->count());
        $failHistory = false;
        $this->postJson('/auth/otp/verify', ['authMethod' => 'google', 'phone' => $phone, 'code' => $this->lastOtp], $bearer)->assertOk();
        $history = DB::table('business_activity')->where('event', 'identity.phone_verified')->first();
        $this->assertSame(['verified' => false], json_decode($history->before_data, true));
        $this->assertSame(['verified' => true, 'phone_changed' => true], json_decode($history->after_data, true));
        $this->assertStringNotContainsString($phone, $history->after_data);
        $this->get('/admin/reports/activity?q=identity.phone_verified')->assertRedirect(route('admin.login'));
        $this->withSession(['admin_authenticated' => true])->get('/admin/reports/activity?q=identity.phone_verified')
            ->assertOk()->assertSee('identity.phone_verified');
        $csv = $this->get('/admin/reports/activity?q=identity.phone_verified&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('identity.phone_verified', $csv);
        $this->assertStringNotContainsString('identity.google_linked', $csv);
        $this->assertStringNotContainsString($phone, $csv);
        $this->assertSame(1, DB::table('admin_report_access_log')->where('action', 'export')->count());

    }

    public function test_google_verification_preserves_failed_attempts_and_locks_after_five_guesses(): void
    {
        $login = $this->google()->assertOk();
        $bearer = ['Authorization' => 'Bearer '.$login->json('token')];
        $phone = '+918112912880';
        $this->postJson('/auth/otp/request', ['authMethod' => 'google', 'phone' => $phone], $bearer)->assertOk();
        $wrong = $this->lastOtp === '000000' ? '000001' : '000000';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/auth/otp/verify', ['authMethod' => 'google', 'phone' => $phone, 'code' => $wrong], $bearer)
                ->assertUnprocessable()->assertJsonValidationErrors('code');
            $this->assertSame($attempt, DB::table('otp_codes')->value('attempts'));
        }
        $this->postJson('/auth/otp/verify', ['authMethod' => 'google', 'phone' => $phone, 'code' => $this->lastOtp], $bearer)
            ->assertUnprocessable()->assertJsonMissingPath('token');
        $this->assertNull(DB::table('otp_codes')->value('consumed_at'));
    }

    public function test_google_phone_conflict_does_not_consume_otp_or_merge_accounts(): void
    {
        $login = $this->google()->assertOk();
        $phone = '+918112912880';
        $this->user(['email' => 'phone-owner@example.test', 'phone' => $phone]);
        $bearer = ['Authorization' => 'Bearer '.$login->json('token')];
        $this->postJson('/auth/otp/request', ['authMethod' => 'google', 'phone' => $phone], $bearer)->assertOk();
        $this->postJson('/auth/otp/verify', ['authMethod' => 'google', 'phone' => $phone, 'code' => $this->lastOtp], $bearer)
            ->assertStatus(409)->assertJsonMissingPath('token');
        $this->assertNull(DB::table('otp_codes')->value('consumed_at'));
        $this->assertNull(DB::table('users')->where('id', $login->json('user.id'))->value('phone'));
        $this->assertSame(2, DB::table('users')->count());
    }

    public function test_normal_registration_does_not_depend_on_firebase_and_returns_the_same_routing_contract(): void
    {
        $phone = '+918112912880';
        $this->postJson('/auth/otp/request', ['phone' => $phone])->assertOk()->assertJsonStructure(['otp']);
        $otp = $this->lastOtp;
        $verified = $this->postJson('/auth/otp/verify', ['phone' => $phone, 'code' => $otp])->assertOk()->assertJsonPath('nextScreen', 'onboarding');
        $bearer = ['Authorization' => 'Bearer '.$verified->json('token')];
        $this->postJson('/auth/register', $this->form(), $bearer)->assertOk()->assertJsonPath('registrationCompleted', false);
        $this->postJson('/auth/login', ['email' => 'google@example.test', 'password' => 'test-password'])
            ->assertStatus(409)->assertJsonPath('ageVerificationRequired', true)->assertJsonMissingPath('token');
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'], $bearer)
            ->assertOk()->assertJsonPath('registrationCompleted', true);
        $this->postJson('/auth/login', ['email' => 'google@example.test', 'password' => 'test-password'])->assertOk()
            ->assertJsonPath('authMethod', 'password')->assertJsonPath('nextScreen', 'home');
        Http::assertNothingSent();
    }

    public function test_existing_email_account_is_linked_once_and_preserves_registration_and_password(): void
    {
        $id = $this->user();
        $this->google()->assertOk()->assertJsonPath('user.id', $id)->assertJsonPath('nextScreen', 'home');
        $this->google()->assertOk()->assertJsonPath('user.id', $id);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertTrue(Hash::check('test-password', DB::table('users')->where('id', $id)->value('password_hash')));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'identity.google_linked')->count());
    }

    public function test_suspended_and_conflicting_accounts_do_not_receive_session_tokens_or_change_identity(): void
    {
        $id = $this->user(['suspended_at' => now()]);
        $this->google()->assertForbidden()->assertJsonMissingPath('token');
        $this->assertNull(DB::table('users')->where('id', $id)->value('google_id'));
        DB::table('users')->where('id', $id)->update(['suspended_at' => null, 'google_id' => 'another-firebase-user']);
        $this->google()->assertStatus(409)->assertJsonMissingPath('token');
        $this->assertSame('another-firebase-user', DB::table('users')->where('id', $id)->value('google_id'));
    }

    public function test_invalid_google_tokens_fail_closed_and_certificate_outages_are_retryable(): void
    {
        foreach ([['email_verified' => false], ['firebase' => ['sign_in_provider' => 'password']], ['aud' => 'different-project']] as $claims) {
            $this->google($claims)->assertStatus(400)->assertJsonMissingPath('token');
        }
        $this->assertSame(0, DB::table('users')->count());
        Cache::forget('firebase.securetoken.certificates');
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $this->google()->assertStatus(503)->assertJsonPath('code', 'FIREBASE_AUTH_UNAVAILABLE');
    }

    public function test_password_reset_rejects_older_firebase_sessions_and_response_hides_consent_tokens(): void
    {
        $id = $this->user(['google_id' => 'firebase-google-user', 'auth_tokens_invalid_before' => now(), 'parental_consent_token' => 'private-consent']);
        $this->google()->assertStatus(409)->assertJsonMissingPath('token');
        $this->google(['auth_time' => now()->timestamp + 1])->assertStatus(400);
        $this->travel(2)->seconds();
        $this->google(['auth_time' => now()->timestamp])->assertOk()->assertJsonMissingPath('user.parental_consent_token')->assertJsonMissingPath('user.password_hash');
    }

    public function test_provider_minor_and_incomplete_accounts_receive_correct_resume_screens(): void
    {
        $id = $this->user(['account_type' => 'teacher', 'date_of_birth' => null]);
        $this->google()->assertOk()->assertJsonPath('nextScreen', 'home')->assertJsonPath('ageVerificationRequired', false);
        DB::table('users')->where('id', $id)->update(['account_type' => 'institute']);
        $this->google()->assertOk()->assertJsonPath('nextScreen', 'home');
        DB::table('users')->where('id', $id)->update(['profile_completed_at' => null]);
        $this->google()->assertOk()->assertJsonPath('nextScreen', 'onboarding')->assertJsonPath('nextStep', 'complete_profile');
        DB::table('users')->where('id', $id)->update(['account_type' => 'learner', 'profile_completed_at' => now(), 'date_of_birth' => '2010-01-01', 'is_minor' => true]);
        $this->google()->assertOk()->assertJsonPath('nextScreen', 'guardian_details')
            ->assertJsonPath('guardianDetailsRequired', true)->assertJsonPath('parentalConsentStatus', 'details_required');
    }

    public function test_explicit_google_linking_is_audited_once_and_cannot_replace_another_identity(): void
    {
        $id = $this->user();
        $bearer = ['Authorization' => 'Bearer '.app(AuthenticationService::class)->issueSessionToken($id)];
        $this->patchJson('/auth/link-google', ['firebaseIdToken' => $this->firebaseToken()], $bearer)->assertOk();
        $this->patchJson('/auth/link-google', ['firebaseIdToken' => $this->firebaseToken()], $bearer)->assertOk();
        $this->assertSame(1, DB::table('business_activity')->where('event', 'identity.google_linked')->count());
        $this->patchJson('/auth/link-google', ['firebaseIdToken' => $this->firebaseToken(['sub' => 'other-uid'])], $bearer)->assertStatus(409);
        $this->assertSame('firebase-google-user', DB::table('users')->where('id', $id)->value('google_id'));
    }

    #[DataProvider('learnerConsentLoginCases')]
    public function test_login_status_is_learner_only_and_matches_parent_decision_for_google_and_password(
        string $role, ?int $age, bool $approved, bool $rejected, ?string $expected
    ): void {
        $id = $this->user();
        DB::table('users')->where('id', $id)->update([
            'account_type' => $role,
            'date_of_birth' => $age === null ? null : now()->subYears($age)->toDateString(),
            'is_minor' => $age !== null && $age < 18,
            'parental_consent_confirmed_at' => $approved ? now() : null,
            'parental_consent_rejected_at' => $rejected ? now() : null,
            'parental_consent_expires_at' => now()->subDay(),
        ]);
        $google = $this->google()->assertOk()->assertJsonStructure(['token', 'user']);
        $password = $this->postJson('/auth/login', ['email' => 'google@example.test', 'password' => 'test-password']);
        $password->assertStatus($age === null ? 409 : 200);
        foreach ([$google, $password] as $response) {
            if ($expected === null) {
                $response->assertJsonMissingPath('status');
            } else {
                $response->assertJsonPath('status', $expected);
            }
        }
    }

    public static function learnerConsentLoginCases(): array
    {
        return [
            'thirteen pending' => ['learner', 13, false, false, 'pending'],
            'seventeen rejected' => ['learner', 17, false, true, 'reject'],
            'sixteen approved' => ['learner', 16, true, false, 'approve'],
            'adult' => ['learner', 18, false, false, null],
            'under thirteen' => ['learner', 12, false, false, null],
            'teacher' => ['teacher', 16, false, false, null],
            'institute' => ['institute', 16, false, false, null],
            'dob missing' => ['learner', null, false, false, null],
        ];
    }

    private function google(array $claims = []): TestResponse
    {
        return $this->postJson('/auth/google', ['firebaseIdToken' => $this->firebaseToken($claims)]);
    }

    private function user(array $overrides = []): int
    {
        $id = NumericTestIds::next();
        DB::table('users')->insert(array_replace([
            'id' => $id, 'account_type' => 'learner', 'full_name' => 'Existing Person', 'email' => 'google@example.test',
            'password_hash' => Hash::make('test-password'), 'phone_verified_at' => now(),
            'profile_completed_at' => now(), 'date_of_birth' => '2000-01-01',
        ], $overrides));

        return $id;
    }

    private function form(): array
    {
        return ['accountType' => 'student', 'fullName' => 'Google Person', 'email' => 'google@example.test', 'password' => 'test-password',
            'termsAccepted' => true, 'acknowledgeTeachersNotBackgroundChecked' => true];
    }

    private function captureOtp(): void
    {
        $real = app(OtpDelivery::class);
        $spy = \Mockery::mock(OtpDelivery::class);
        $spy->shouldReceive('send')->andReturnUsing(function ($phone, $code, $country) use ($real) {
            $this->lastOtp = $code;

            return $real->send($phone, $code, $country);
        });
        $this->app->instance(OtpDelivery::class, $spy);
    }
}
