<?php

namespace Tests\Feature;

use App\Jobs\RevokeChatSessions;
use App\Jobs\SendEmailCode;
use App\Services\AuthenticationService;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    private string $userId;

    private string $email = 'anita@example.com';

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set ONBOARDING_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config([
            'database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database,
            'services.nearby_teacher.jwt_secret' => str_repeat('test-secret-', 6),
        ]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->userId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $this->userId, 'account_type' => 'learner', 'full_name' => 'Anita',
            'email' => $this->email, 'phone' => '+919876543210', 'phone_verified_at' => now(),
            'profile_completed_at' => now(), 'password_hash' => Hash::make('old-password'),
        ]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_login_accepts_email_only_and_normalizes_case(): void
    {
        $this->postJson('/auth/login', ['phone' => '+919876543210', 'password' => 'old-password'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone']);
        $this->postJson('/auth/login', ['email' => 'ANITA@example.com', 'password' => 'old-password'])
            ->assertOk()->assertJsonStructure(['token'])->assertJsonMissingPath('user.password_hash');
        $this->postJson('/auth/login', ['email' => $this->email, 'password' => 'wrong-password'])->assertUnauthorized();
    }

    public function test_forgot_response_does_not_disclose_email_or_return_otp(): void
    {
        $known = $this->postJson('/auth/forgot-password', ['email' => $this->email])
            ->assertOk()->assertJsonMissingPath('otp')->assertJsonMissingPath('code')->json();
        $unknown = $this->postJson('/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        DB::table('users')->where('id', $this->userId)->update(['suspended_at' => now()]);
        $this->assertSame($known, $this->postJson('/auth/forgot-password', ['email' => $this->email])->assertOk()->json());
        Queue::assertPushed(SendEmailCode::class, 1);
        $job = Queue::pushed(SendEmailCode::class)->last();
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $row = DB::table('email_otp_codes')->where('id', $job->challengeId)->first();
        $this->assertNotSame($job->code, $row->code_hash);
        $this->assertTrue(Hash::check($job->code, $row->code_hash));
    }

    public function test_registered_account_cannot_use_mobile_otp_as_login(): void
    {
        DB::table('otp_codes')->insert([
            'id' => (string) Str::uuid(), 'phone' => '+919876543210',
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10), 'created_at' => now(),
        ]);
        $this->postJson('/auth/otp/verify', ['phone' => '+919876543210', 'code' => '123456'])
            ->assertStatus(409)->assertJsonPath('loginRequired', true)->assertJsonMissingPath('token');
    }

    public function test_reset_changes_hash_verifies_email_and_revokes_old_tokens(): void
    {
        $auth = app(AuthenticationService::class);
        $oldSession = $auth->issueSessionToken($this->userId);
        $oldOtp = $auth->issueOtpToken($this->userId);
        $legacy = JWT::encode(['sub' => $this->userId, 'iss' => 'nearby-teacher-otp'], config('services.nearby_teacher.jwt_secret'), 'HS256');
        $this->assertNotNull($auth->authenticate($legacy));
        $code = $this->requestCode();
        $this->postJson('/auth/reset-password', $this->resetBody($code))->assertOk()->assertJsonPath('ok', true);
        $saved = DB::table('users')->where('id', $this->userId)->first();
        $this->assertTrue(Hash::check('new-password', $saved->password_hash));
        $this->assertSame(1, $saved->auth_version);
        $this->assertNotNull($saved->email_verified_at);
        foreach ([$oldSession, $oldOtp, $legacy] as $token) {
            $this->assertNull($auth->authenticate($token));
        }
        Queue::assertPushed(RevokeChatSessions::class, fn ($job) => $job->authVersion === 1 && $job->userId === $this->userId);
        $this->postJson('/auth/login', ['email' => $this->email, 'password' => 'old-password'])->assertUnauthorized();
        $login = $this->postJson('/auth/login', ['email' => $this->email, 'password' => 'new-password'])->assertOk();
        $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$login->json('token')])->assertOk();
        $this->postJson('/auth/reset-password', $this->resetBody($code))->assertUnprocessable();
    }

    public function test_expired_codes_and_five_wrong_attempts_do_not_reset_password(): void
    {
        $code = $this->requestCode();
        $wrong = $code === '111111' ? '222222' : '111111';
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/auth/reset-password', $this->resetBody($wrong))
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->postJson('/auth/reset-password', $this->resetBody($code))->assertUnprocessable();
        $row = DB::table('email_otp_codes')->where('user_id', $this->userId)->first();
        $this->assertSame(5, $row->attempts);
        $this->assertNotNull($row->consumed_at);
        $code = $this->requestCode();
        DB::table('email_otp_codes')->whereNull('consumed_at')->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/auth/reset-password', $this->resetBody($code))->assertUnprocessable();
        $this->assertTrue(Hash::check('old-password', DB::table('users')->where('id', $this->userId)->value('password_hash')));
    }

    public function test_resend_invalidates_old_codes_and_old_queued_email_is_skipped(): void
    {
        $old = $this->requestCode();
        $oldJob = Queue::pushed(SendEmailCode::class)->last();
        $new = $this->requestCode();
        $this->assertNotNull(DB::table('email_otp_codes')->where('id', $oldJob->challengeId)->value('consumed_at'));
        Mail::shouldReceive('raw')->never();
        $oldJob->handle();
        if ($old !== $new) {
            $this->postJson('/auth/reset-password', $this->resetBody($old))->assertUnprocessable();
        }
        $this->postJson('/auth/reset-password', $this->resetBody($new))->assertOk();
    }

    public function test_password_confirmation_failure_preserves_code_and_request_rate_is_limited(): void
    {
        $code = $this->requestCode();
        $body = $this->resetBody($code);
        $body['password_confirmation'] = 'different-password';
        $this->postJson('/auth/reset-password', $body)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/auth/reset-password', $this->resetBody($code))->assertOk();
        $this->requestCode();
        $this->requestCode();
        $this->postJson('/auth/forgot-password', ['email' => $this->email])->assertTooManyRequests();
    }

    public function test_email_verification_has_separate_purpose_and_email_changes_clear_verification(): void
    {
        $token = app(AuthenticationService::class)->issueSessionToken($this->userId);
        $this->withHeader('Authorization', 'Bearer '.$token);
        $this->postJson('/auth/email/verification/request')->assertOk();
        $job = Queue::pushed(SendEmailCode::class)->last();
        $this->postJson('/auth/reset-password', $this->resetBody($job->code))->assertUnprocessable();
        $this->postJson('/auth/email/verification/verify', ['code' => $job->code])->assertOk()->assertJsonPath('emailVerified', true);
        $this->patchJson('/auth/onboarding', ['email' => 'new-address@example.com'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->userId, 'email_verified_at' => null]);
    }

    public function test_email_job_sends_code_through_configured_transport(): void
    {
        $code = $this->requestCode();
        config(['mail.default' => 'array']);
        Mail::shouldReceive('raw')->once()->withArgs(fn ($text, $callback) => str_contains($text, $code) && is_callable($callback));
        Queue::pushed(SendEmailCode::class)->last()->handle();
    }

    private function requestCode(): string
    {
        $this->postJson('/auth/forgot-password', ['email' => $this->email])->assertOk();

        return Queue::pushed(SendEmailCode::class)->last()->code;
    }

    private function resetBody(string $code): array
    {
        return ['email' => $this->email, 'code' => $code, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
    }
}
