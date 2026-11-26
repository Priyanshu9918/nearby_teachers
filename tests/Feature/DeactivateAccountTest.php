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
            ->assertOk()->assertExactJson(['message' => 'Account deleted successfully.']);

        $user = DB::table('users')->where('id', $this->userId)->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->deactivated_at);
        $this->assertSame(1, $user->auth_version);
        $this->assertSame(1, DB::table('business_activity')->where('entity_id', $this->userId)
            ->where('event', 'user.deactivated')->count());
        Queue::assertPushed(RevokeChatSessions::class);
        $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$token])->assertForbidden();
        $this->postJson('/auth/login', ['email' => 'deactivate@example.test', 'password' => 'test-password'])
            ->assertForbidden()->assertExactJson([
                'code' => 'ACCOUNT_DELETED',
                'error' => 'This account has been deleted. Contact support to restore it.',
            ]);
    }

    public function test_only_admin_can_reactivate_and_suspension_still_blocks_login(): void
    {
        $authentication = app(AuthenticationService::class);
        $userToken = $authentication->issueSessionToken($this->userId);
        $this->deleteJson('/auth/account', [], ['Authorization' => 'Bearer '.$userToken])->assertOk();

        $adminId = NumericTestIds::next();
        DB::table('users')->insert([
            'id' => $adminId, 'account_type' => 'learner', 'full_name' => 'Admin',
            'is_admin' => true, 'phone_verified_at' => now(),
        ]);
        $adminToken = $authentication->issueSessionToken($adminId);
        $path = '/moderation/users/'.$this->userId.'/reactivate';

        $this->postJson($path)->assertUnauthorized();
        $this->postJson($path, [], ['Authorization' => 'Bearer '.$userToken])->assertForbidden();
        $activeUserId = NumericTestIds::next();
        DB::table('users')->insert([
            'id' => $activeUserId, 'account_type' => 'learner', 'full_name' => 'Other User',
            'phone_verified_at' => now(),
        ]);
        $activeUserToken = $authentication->issueSessionToken($activeUserId);
        $this->postJson($path, [], ['Authorization' => 'Bearer '.$activeUserToken])->assertForbidden();
        $this->getJson('/moderation/users/deactivated', ['Authorization' => 'Bearer '.$adminToken])
            ->assertOk()->assertJsonFragment(['id' => $this->userId]);

        DB::table('users')->where('id', $this->userId)->update(['suspended_at' => now()]);
        $this->postJson($path, [], ['Authorization' => 'Bearer '.$adminToken])
            ->assertOk()->assertJsonPath('user.deactivated', false)
            ->assertJsonPath('user.suspended', true);
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('deactivated_at'));
        $this->assertSame(1, DB::table('business_activity')->where('entity_id', $this->userId)
            ->where('event', 'user.reactivated')->count());
        $this->postJson('/auth/login', ['email' => 'deactivate@example.test', 'password' => 'test-password'])
            ->assertForbidden()->assertJsonPath('suspended', true);
        $this->postJson($path, [], ['Authorization' => 'Bearer '.$adminToken])->assertStatus(409);
        DB::table('users')->where('id', $this->userId)->update(['suspended_at' => null]);
        $this->postJson('/auth/login', ['email' => 'deactivate@example.test', 'password' => 'test-password'])
            ->assertOk();
        $this->getJson('/auth/session', ['Authorization' => 'Bearer '.$userToken])->assertForbidden();
    }

    public function test_admin_dashboard_lists_and_reactivates_deactivated_account(): void
    {
        DB::table('users')->where('id', $this->userId)->update(['deactivated_at' => now()]);
        $list = '/admin/moderation/deactivated-users';
        $action = '/admin/moderation/users/'.$this->userId.'/reactivate';
        $this->get($list)->assertRedirect(route('admin.login'));
        $this->post($action)->assertRedirect(route('admin.login'));

        $this->withSession(['admin_authenticated' => true]);
        $this->get($list)->assertOk()->assertSee('Reactivate account')
            ->assertSee('Deactivate User');
        $this->get('/admin/reports/users/'.$this->userId)->assertOk()
            ->assertSee('Deactivated')->assertSee('Reactivate account');
        $this->post($action)->assertRedirect()->assertSessionHas('status', 'Account reactivated.');

        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('deactivated_at'));
        $this->assertSame(1, DB::table('business_activity')->where('entity_id', $this->userId)
            ->where('event', 'user.reactivated')->count());
        $this->assertSame(1, DB::table('admin_report_access_log')->where('report', 'users')
            ->where('action', 'reactivate')->where('record_id', $this->userId)->count());
    }

}
