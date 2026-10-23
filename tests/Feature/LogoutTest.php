<?php

namespace Tests\Feature;

use App\Jobs\RevokeChatSessions;
use App\Services\AuthenticationService;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FirebaseTokens;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use FirebaseTokens;

    private bool $transactionStarted = false;

    private int $userId;

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
            'services.nearby_teacher.jwt_secret' => str_repeat('logout-test-secret-', 3),
        ]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->userId = NumericTestIds::next();
        DB::table('users')->insert([
            'id' => $this->userId, 'account_type' => 'learner', 'full_name' => 'Logout User',
            'email' => 'google@example.test', 'phone' => '+919876543210', 'phone_verified_at' => now(),
            'profile_completed_at' => now(), 'date_of_birth' => '2000-01-01', 'password_hash' => Hash::make('test-password'),
        ]);
        $this->fakeFirebase();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_logout_requires_valid_authentication_and_does_not_change_other_users(): void
    {
        $this->postJson('/auth/logout')->assertUnauthorized();
        $this->postJson('/auth/logout', [], ['Authorization' => 'Bearer invalid-token'])->assertForbidden();
        $this->assertSame(0, DB::table('users')->where('id', $this->userId)->value('auth_version'));
        $this->assertSame(0, DB::table('business_activity')->count());
        Queue::assertNothingPushed();
    }

    public function test_logout_revokes_otp_and_session_tokens_and_new_password_login_still_works(): void
    {
        $authentication = app(AuthenticationService::class);
        $sessionToken = $authentication->issueSessionToken($this->userId);
        $otherSession = $authentication->issueSessionToken($this->userId);
        $otpToken = $authentication->issueOtpToken($this->userId);
        $this->postJson('/auth/logout', [], ['Authorization' => 'Bearer '.$sessionToken])
            ->assertOk()->assertExactJson([
                'ok' => true, 'message' => 'Logged out successfully.', 'loggedOutAllDevices' => true,
            ]);
        foreach ([$sessionToken, $otherSession, $otpToken] as $oldToken) {
            $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$oldToken])->assertForbidden();
        }
        $this->postJson('/auth/logout', [], ['Authorization' => 'Bearer '.$sessionToken])->assertForbidden();
        $saved = DB::table('users')->where('id', $this->userId)->first();
        $this->assertSame(1, $saved->auth_version);
        $this->assertNotNull($saved->auth_tokens_invalid_before);
        Queue::assertPushed(RevokeChatSessions::class, fn ($job) => $job->userId === $this->userId
            && $job->authVersion === 1 && $job->afterCommit === true);
        $login = $this->postJson('/auth/login', ['email' => 'google@example.test', 'password' => 'test-password'])->assertOk();
        $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$login->json('token')])->assertOk();
    }

    public function test_google_user_can_logout_before_mobile_verification_and_older_firebase_sessions_are_rejected(): void
    {
        DB::table('users')->where('id', $this->userId)->update([
            'google_id' => 'firebase-google-user', 'phone' => null,
            'phone_verified_at' => null, 'profile_completed_at' => null,
        ]);
        $firebase = $this->firebaseToken();
        $login = $this->postJson('/auth/google', ['firebaseIdToken' => $firebase])->assertOk();
        $this->postJson('/auth/logout', [], ['Authorization' => 'Bearer '.$login->json('token')])->assertOk();
        $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$login->json('token')])->assertForbidden();
        $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$firebase])->assertForbidden();
        $this->postJson('/auth/google', ['firebaseIdToken' => $firebase])->assertStatus(409);
        $this->travel(2)->seconds();
        $previousTimestamp = JWT::$timestamp;
        JWT::$timestamp = now()->timestamp;
        try {
            $freshLogin = $this->postJson('/auth/google', [
                'firebaseIdToken' => $this->firebaseToken(['auth_time' => now()->timestamp]),
            ])->assertOk();
            $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$freshLogin->json('token')])->assertOk();
        } finally {
            JWT::$timestamp = $previousTimestamp;
        }
    }

    public function test_raw_firebase_bearer_can_logout_without_mobile_verification(): void
    {
        DB::table('users')->where('id', $this->userId)->update([
            'google_id' => 'firebase-google-user', 'phone_verified_at' => null,
        ]);
        $firebase = $this->firebaseToken();
        $this->postJson('/auth/logout', [], ['Authorization' => 'Bearer '.$firebase])->assertOk();
        $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$firebase])->assertForbidden();
    }

    public function test_suspended_user_can_revoke_tokens_and_cannot_logout_another_user(): void
    {
        $otherId = NumericTestIds::next();
        DB::table('users')->insert([
            'id' => $otherId, 'account_type' => 'learner', 'full_name' => 'Other User', 'phone_verified_at' => now(),
        ]);
        DB::table('users')->where('id', $this->userId)->update(['suspended_at' => now()]);
        $authentication = app(AuthenticationService::class);
        $otherToken = $authentication->issueSessionToken($otherId);
        $this->postJson('/auth/logout', ['userId' => $otherId], [
            'Authorization' => 'Bearer '.$authentication->issueSessionToken($this->userId),
        ])->assertOk();
        $this->assertSame(0, DB::table('users')->where('id', $otherId)->value('auth_version'));
        $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$otherToken])->assertOk();
    }

    public function test_a_request_authenticated_before_another_revocation_cannot_revoke_newer_sessions(): void
    {
        $staleUser = DB::table('users')->where('id', $this->userId)->first();
        DB::table('users')->where('id', $this->userId)->update(['auth_version' => 1]);
        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->once()->with('stale-session')->andReturn($staleUser);
        $this->app->instance(AuthenticationService::class, $authentication);
        $this->postJson('/auth/logout', [], ['Authorization' => 'Bearer stale-session'])->assertForbidden();
        $this->assertSame(1, DB::table('users')->where('id', $this->userId)->value('auth_version'));
        $this->assertSame(0, DB::table('business_activity')->count());
        Queue::assertNothingPushed();
    }

    public function test_logout_history_failure_rolls_back_revocation_and_does_not_dispatch_chat_revocation(): void
    {
        $token = app(AuthenticationService::class)->issueSessionToken($this->userId);
        $failHistory = true;
        DB::listen(function ($query) use (&$failHistory): void {
            if ($failHistory && str_starts_with($query->sql, 'insert into `business_activity`')) {
                throw new \RuntimeException('Simulated logout history failure');
            }
        });
        $this->postJson('/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertStatus(500);
        $failHistory = false;
        $this->assertSame(0, DB::table('users')->where('id', $this->userId)->value('auth_version'));
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('auth_tokens_invalid_before'));
        $this->assertSame(0, DB::table('business_activity')->where('event', 'auth.logged_out')->count());
        Queue::assertNothingPushed();
        $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_logout_history_is_filtered_exportable_admin_only_and_contains_no_credentials(): void
    {
        $token = app(AuthenticationService::class)->issueSessionToken($this->userId);
        $this->postJson('/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertOk();
        $history = DB::table('business_activity')->where('event', 'auth.logged_out')->first();
        $this->assertSame(1, $history->version);
        $this->assertSame($this->userId, $history->actor_user_id);
        $this->assertSame(['auth_version' => 0], json_decode($history->before_data, true));
        $this->assertEquals(['auth_version' => 1, 'scope' => 'all_sessions'], json_decode($history->after_data, true));
        $this->get('/admin/reports/activity?q=auth.logged_out')->assertRedirect(route('admin.login'));
        $this->get('/admin/reports/activity?q=auth.logged_out&export=1')->assertRedirect(route('admin.login'));
        $this->withSession(['admin_authenticated' => true])->get('/admin/reports/activity?q=auth.logged_out')
            ->assertOk()->assertSee('auth.logged_out');
        $csv = $this->get('/admin/reports/activity?q=auth.logged_out&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('auth.logged_out', $csv);
        foreach ([$token, 'test-password'] as $credential) {
            $this->assertStringNotContainsString($credential, $csv);
        }
        $this->assertStringNotContainsString('google@example.test', $history->before_data.$history->after_data);
        $this->assertSame(1, DB::table('admin_report_access_log')->where('action', 'export')->count());
    }
}
