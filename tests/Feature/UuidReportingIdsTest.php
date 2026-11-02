<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class UuidReportingIdsTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set ONBOARDING_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;

        $authentication = Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->andReturnUsing(
            fn (string $token): ?object => DB::table('users')->where('id', $token)->first()
        );
        $this->app->instance(AuthenticationService::class, $authentication);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_reporting_and_support_ticket_ids_are_numeric(): void
    {
        $learner = NumericTestIds::next();
        $teacher = NumericTestIds::next();
        $admin = NumericTestIds::next();
        DB::table('users')->insert([
            ['id' => $learner, 'account_type' => 'learner', 'full_name' => 'Test learner', 'phone_verified_at' => now(), 'safety_disclaimer_acknowledged_at' => now()],
            ['id' => $teacher, 'account_type' => 'teacher', 'full_name' => 'Test teacher', 'phone_verified_at' => now(), 'safety_disclaimer_acknowledged_at' => now()],
            ['id' => $admin, 'account_type' => 'teacher', 'full_name' => 'Test admin', 'phone_verified_at' => now(), 'is_admin' => true],
        ]);

        $this->withToken((string) $learner)->postJson('/messages/start', [
            'providerId' => $teacher, 'body' => 'Please confirm the session time.',
        ])->assertOk();
        $messageActivityId = DB::table('business_activity')->where('event', 'message.created')->value('id');
        $this->assertIsInt($messageActivityId);

        $created = $this->withToken((string) $learner)->postJson('/support', [
            'subject' => 'Ticket ID compatibility', 'message' => 'Check the numeric ID storage contract.',
        ])->assertCreated()->json('ticket');
        $this->assertIsInt($created['id']);
        $this->assertSame(1, DB::table('business_activity')->where('event', 'support_ticket.submitted')->count());
        $activityId = DB::table('business_activity')->where('event', 'support_ticket.submitted')->value('id');
        $this->assertIsInt($activityId);

        $this->withToken((string) $admin)->patchJson('/support/'.$created['id'].'/status', ['status' => 'resolved'])
            ->assertOk()->assertJsonPath('ticket.status', 'resolved');
        $accessLogId = DB::table('admin_report_access_log')->where('report', 'support')->value('id');
        $this->assertIsInt($accessLogId);
    }
}
