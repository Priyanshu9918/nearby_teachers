<?php

namespace Tests\Feature;

use App\Jobs\RevokeChatSessions;
use App\Services\AuthenticationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class DeactivateAccountTest extends TestCase
{
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
            'database.connections.mysql.url' => null,
            'database.connections.mysql.database' => $database,
            'services.nearby_teacher.jwt_secret' => str_repeat('deactivate-test-secret-', 3),
        ]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->userId = NumericTestIds::next();
        DB::table('users')->insert([
            'id' => $this->userId, 'account_type' => 'learner', 'full_name' => 'Deactivate User',
            'email' => 'deactivate@example.test', 'phone' => '+919876543210', 'phone_verified_at' => now(),
            'profile_completed_at' => now(), 'date_of_birth' => '2000-01-01',
            'password_hash' => Hash::make('test-password'),
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

    public function test_deactivation_keeps_user_and_history_but_revokes_access(): void
    {
        $token = app(AuthenticationService::class)->issueSessionToken($this->userId);
        $this->deleteJson('/auth/account')->assertUnauthorized();
        $this->deleteJson('/auth/account', [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertExactJson(['message' => 'Account deactivated successfully.']);

        $user = DB::table('users')->where('id', $this->userId)->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->deactivated_at);
        $this->assertSame(1, $user->auth_version);
        $this->assertSame(1, DB::table('business_activity')->where('entity_id', $this->userId)
            ->where('event', 'user.deactivated')->count());
        Queue::assertPushed(RevokeChatSessions::class);
        $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$token])->assertForbidden();
        $this->postJson('/auth/login', ['email' => 'deactivate@example.test', 'password' => 'test-password'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');
    }

}
