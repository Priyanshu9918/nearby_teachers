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
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FirebaseTokens;
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
        $this->postJson('/auth/otp/request', ['phone' => $phone])->assertOk()->assertJsonStructure(['otp']);
        $otp = $this->lastOtp;
        $this->patchJson('/auth/link-phone', ['phone' => $phone, 'code' => $otp], $bearer)->assertOk()
            ->assertJsonPath('nextScreen', 'onboarding')->assertJsonPath('phoneVerificationRequired', false);
        $this->postJson('/auth/register', $this->form(), $bearer)->assertOk()->assertJsonPath('nextScreen', 'age_verification');
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'], $bearer)->assertOk();
        $this->getJson('/auth/session', $bearer)->assertOk()->assertJsonPath('nextScreen', 'home');
        $this->google()->assertOk()->assertJsonPath('user.id', $id)->assertJsonPath('nextScreen', 'home')->assertJsonPath('onboardingRequired', false);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'identity.google_linked')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'registration.completed')->count());
    }

    public function test_normal_registration_does_not_depend_on_firebase_and_returns_the_same_routing_contract(): void
    {
        $phone = '+918112912880';
        $this->postJson('/auth/otp/request', ['phone' => $phone])->assertOk()->assertJsonStructure(['otp']);
        $otp = $this->lastOtp;
        $verified = $this->postJson('/auth/otp/verify', ['phone' => $phone, 'code' => $otp])->assertOk()->assertJsonPath('nextScreen', 'onboarding');
        $bearer = ['Authorization' => 'Bearer '.$verified->json('token')];
        $this->postJson('/auth/register', $this->form(), $bearer)->assertOk()->assertJsonPath('registrationCompleted', true);
        $this->postJson('/auth/login', ['email' => 'google@example.test', 'password' => 'test-password'])->assertOk()
            ->assertJsonPath('authMethod', 'password')->assertJsonPath('nextScreen', 'age_verification');
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
        $this->google()->assertOk()->assertJsonPath('nextScreen', 'guardian_consent');
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

    private function google(array $claims = []): TestResponse
    {
        return $this->postJson('/auth/google', ['firebaseIdToken' => $this->firebaseToken($claims)]);
    }

    private function user(array $overrides = []): string
    {
        $id = (string) Str::uuid();
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
