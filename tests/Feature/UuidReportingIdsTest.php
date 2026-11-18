<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_support_screenshot_upload_owner_access_and_admin_reporting(): void
    {
        Storage::fake('local');
        Queue::fake();
        $owner = DB::table('users')->insertGetId(['account_type' => 'teacher', 'full_name' => 'Screenshot owner', 'phone_verified_at' => now()]);
        $other = DB::table('users')->insertGetId(['account_type' => 'learner', 'full_name' => 'Other user', 'phone_verified_at' => now()]);
        $image = UploadedFile::fake()->createWithContent('screen.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $ticket = $this->withToken((string) $owner)->post('/support', [
            'subject' => 'Screenshot issue', 'message' => 'Please check the attached error.', 'screenshot' => $image,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('ticket.screenshotAvailable', true)->json('ticket');
        $this->assertArrayNotHasKey('screenshot_path', $ticket);
        $path = DB::table('support_tickets')->where('id', $ticket['id'])->value('screenshot_path');
        Storage::disk('local')->assertExists($path);
        $this->getJson('/support/mine')->assertOk()->assertJsonPath('tickets.0.screenshotAvailable', true)->assertDontSee('support-screenshots');
        $this->get('/support/'.$ticket['id'].'/screenshot')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->withToken((string) $other)->getJson('/support/'.$ticket['id'].'/screenshot')->assertNotFound();
        $this->withSession(['admin_authenticated' => true])->get('/admin/support/tickets/'.$ticket['id'].'/screenshot')->assertOk();
        $this->get('/admin/reports/support?status=pending')->assertOk()->assertSee('View screenshot');
        $csv = $this->get('/admin/reports/support?status=pending&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('screenshot_available', $csv);
        $this->assertStringNotContainsString('support-screenshots', $csv);
        $this->assertDatabaseHas('admin_report_access_log', ['report' => 'support', 'action' => 'screenshot-view', 'record_id' => $ticket['id']]);
    }

    public function test_failed_support_attachment_history_rolls_back_the_ticket_and_removes_the_file(): void
    {
        Storage::fake('local');
        $owner = DB::table('users')->insertGetId(['account_type' => 'teacher', 'full_name' => 'Rollback owner', 'phone_verified_at' => now()]);
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert into `business_activity`') && in_array('support_ticket.screenshot_attached', $query->bindings, true)) {
                throw new \RuntimeException('History unavailable');
            }
        });
        $image = UploadedFile::fake()->createWithContent('screen.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $this->withToken((string) $owner)->post('/support', ['subject' => 'Must roll back', 'message' => 'No partial ticket', 'screenshot' => $image], ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertDatabaseMissing('support_tickets', ['subject' => 'Must roll back']);
        $this->assertDatabaseMissing('business_activity', ['actor_user_id' => $owner, 'event' => 'support_ticket.screenshot_attached']);
        $this->assertSame([], Storage::disk('local')->allFiles('support-screenshots'));
    }
}
