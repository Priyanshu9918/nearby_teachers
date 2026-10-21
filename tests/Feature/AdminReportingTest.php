<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAuthActivity;
use App\Reporting\BusinessActivityRecorder;
use App\Reporting\ReportRegistry;
use App\Services\AuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminReportingTest extends TestCase
{
    private bool $transactionStarted = false;

    private string $learner;

    private string $teacher;

    private string $institute;

    private string $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('REPORTING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set REPORTING_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database,
            'services.nearby_teacher.jwt_secret' => str_repeat('reporting-test-secret', 3), 'admin.email' => 'admin@example.test']);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        foreach (['learner', 'teacher', 'institute'] as $type) {
            $id = (string) Str::uuid();
            $this->$type = $id;
            DB::table('users')->insert([
                'id' => $id, 'account_type' => $type, 'full_name' => ucfirst($type).' Person',
                'email' => $type.'@example.test', 'password_hash' => Hash::make('test-password'),
                'parental_consent_token' => 'private-consent-secret', 'phone_verified_at' => now(),
                'profile_completed_at' => now(), 'created_at' => '2026-10-20 12:00:00',
            ]);
        }
        DB::table('teacher_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->teacher, 'lesson_modes' => '[]']);
        DB::table('institute_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->institute, 'lesson_modes' => '[]']);
        foreach ([$this->teacher, $this->institute] as $provider) {
            $id = (string) Str::uuid();
            DB::table('conversations')->insert(['id' => $id, 'learner_id' => $this->learner, 'provider_id' => $provider]);
            if ($provider === $this->teacher) {
                $this->conversation = $id;
            }
            DB::table('messages')->insert(['id' => (string) Str::uuid(), 'conversation_id' => $id, 'sender_id' => $this->learner,
                'body' => '=HYPERLINK("https://example.test")', 'created_at' => '2026-10-20 12:00:00']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_every_report_is_admin_only_and_unknown_reports_are_rejected(): void
    {
        foreach (['/admin/dashboard', '/admin/reports/users', '/admin/reports/users/'.$this->learner,
            '/admin/reports/conversations/'.$this->conversation, '/admin/reports/logins?export=1'] as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
        $this->withSession(['developer_authenticated' => true])->get('/admin/reports/users')->assertRedirect(route('admin.login'));
        $this->withSession(['admin_authenticated' => true])->get('/admin/reports/otp_codes')->assertNotFound();
    }

    public function test_all_report_pages_and_empty_exports_load(): void
    {
        $this->withSession(['admin_authenticated' => true]);
        $this->get('/admin')->assertRedirect(route('admin.dashboard'));
        $this->get('/admin/dashboard')->assertOk()->assertSee('Completed registrations');
        foreach (app(ReportRegistry::class)->all() as $key => $entry) {
            $this->get('/admin/reports/'.$key)->assertOk()->assertSee($entry->title);
            $response = $this->get('/admin/reports/'.$key.'?export=1')->assertOk();
            $this->assertNotEmpty($response->streamedContent());
        }
    }

    public function test_reports_filter_users_dates_and_participants_without_exposing_credentials(): void
    {
        $this->withSession(['admin_authenticated' => true]);
        $this->get('/admin/reports/users?type=teacher')->assertOk()->assertSee('teacher@example.test')->assertDontSee('institute@example.test');
        $this->get('/admin/reports/users?from=2026-10-21')->assertOk()->assertSee('No matching records.');
        $this->get('/admin/reports/users?to=2026-10-20')->assertOk()->assertSee('teacher@example.test');
        $this->get('/admin/reports/users?from=2026-10-21&to=2026-10-20')->assertSessionHasErrors('to');
        $this->get('/admin/reports/conversations?user='.$this->teacher)->assertOk()->assertSee('teacher@example.test')->assertDontSee('institute@example.test');
        $this->get('/admin/reports/conversations?type=institute')->assertOk()->assertSee('institute@example.test')->assertDontSee('teacher@example.test');
        $user = $this->get('/admin/reports/users/'.$this->teacher)->assertOk()->assertSee('Provider profile');
        $user->assertDontSee('private-consent-secret')->assertDontSee('password_hash')->assertDontSee('parental_consent_token');
        $csv = $this->get('/admin/reports/users?export=1')->streamedContent();
        $this->assertStringNotContainsString('password_hash', $csv);
        $this->assertStringNotContainsString('private-consent-secret', $csv);
    }

    public function test_chat_history_and_exports_are_audited_and_csv_formulas_are_escaped(): void
    {
        $this->withSession(['admin_authenticated' => true]);
        $this->get('/admin/reports/conversations/'.$this->conversation)->assertOk()->assertSee('Teacher Person')->assertSee('HYPERLINK');
        $this->assertSame(1, DB::table('admin_report_access_log')->where('record_id', $this->conversation)->where('action', 'view')->count());
        $response = $this->get('/admin/reports/conversations/'.$this->conversation.'?export=1')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertSame(1, DB::table('admin_report_access_log')->where('record_id', $this->conversation)->where('action', 'export')->count());
        $this->get('/admin/reports/conversations/'.$this->conversation.'?from=2026-10-21')->assertOk()->assertSee('No matching messages.');
    }

    public function test_real_email_logins_record_success_failure_and_do_not_store_passwords_or_tokens(): void
    {
        $success = $this->postJson('/auth/login', ['email' => 'learner@example.test', 'password' => 'test-password'])->assertOk();
        $this->postJson('/auth/login', ['email' => 'learner@example.test', 'password' => 'wrong-password'])->assertUnauthorized();
        $rows = DB::table('auth_activity')->where('event', 'email_login')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows->where('successful', 1)->count());
        $this->assertSame($this->learner, $rows->firstWhere('successful', 1)->user_id);
        $serialized = json_encode($rows);
        $this->assertStringNotContainsString('test-password', $serialized);
        $this->assertStringNotContainsString($success->json('token'), $serialized);
    }

    public function test_registration_and_google_events_are_classified_separately_from_otp_verification(): void
    {
        foreach (['auth/register' => 'registration_completed', 'auth/onboarding/complete' => 'registration_completed', 'auth/google' => 'google_login', 'auth/otp/verify' => 'otp_verification'] as $path => $event) {
            $request = Request::create('/'.$path, 'POST', ['password' => 'never-store-this', 'code' => '123456']);
            $request->setRouteResolver(fn () => new Route('POST', $path, fn () => null));
            (new TrackAuthActivity)->handle($request, fn () => response()->json(['user' => ['id' => $this->learner], 'token' => 'private-token']));
            $this->assertTrue(DB::table('auth_activity')->where('event', $event)->where('user_id', $this->learner)->exists());
        }
        $this->assertStringNotContainsString('never-store-this', json_encode(DB::table('auth_activity')->get()));
    }

    public function test_a_new_report_uses_shared_pages_exports_and_explicit_columns_without_controller_changes(): void
    {
        config(['reports.account-names' => [
            'title' => 'Account names only', 'table' => 'users', 'columns' => ['id', 'full_name'],
            'timestamp' => null, 'participants' => [], 'search' => ['full_name'],
        ]]);
        $this->withSession(['admin_authenticated' => true]);
        $this->get('/admin/reports/account-names?q=Teacher')->assertOk()->assertSee('Teacher Person')->assertDontSee('teacher@example.test');
        $csv = $this->get('/admin/reports/account-names?export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('full_name', $csv);
        $this->assertStringNotContainsString('email', $csv);
        $this->assertStringNotContainsString('password', $csv);
    }

    public function test_business_history_rolls_back_atomically_rejects_unknown_fields_and_survives_source_deletion(): void
    {
        $recorder = app(BusinessActivityRecorder::class);
        try {
            $recorder->record('booking.created', (string) Str::uuid(), $this->learner, after: ['password' => 'secret']);
            $this->fail('Unexpected snapshot fields must be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, DB::table('business_activity')->count());
        }
        $entityId = (string) Str::uuid();
        try {
            DB::transaction(function () use ($recorder, $entityId): void {
                $recorder->record('booking.status_changed', $entityId, $this->learner, $this->teacher,
                    before: ['status' => 'requested'], after: ['status' => 'accepted']);
                throw new \RuntimeException('Roll back source mutation and history.');
            });
        } catch (\RuntimeException) {
            $this->assertSame(0, DB::table('business_activity')->where('entity_id', $entityId)->count());
        }
        $id = $recorder->record('registration.completed', $this->institute, $this->institute,
            after: ['account_type' => 'institute', 'profile_completed_at' => now()->toDateTimeString()]);
        DB::table('users')->where('id', $this->institute)->delete();
        $this->assertTrue(DB::table('business_activity')->where('id', $id)->exists());
        $this->withSession(['admin_authenticated' => true])->get('/admin/reports/activity?user='.$this->institute)
            ->assertOk()->assertSee('registration.completed');
    }

    public function test_real_message_and_booking_mutations_record_history_for_both_participants(): void
    {
        DB::table('users')->whereIn('id', [$this->learner, $this->teacher])->update(['safety_disclaimer_acknowledged_at' => now()]);
        $auth = app(AuthenticationService::class);
        $this->withToken($auth->issueSessionToken($this->learner));
        $this->postJson('/messages/start', ['providerId' => $this->teacher, 'body' => 'First message'])->assertOk();
        $this->postJson('/messages/'.$this->conversation, ['body' => 'Second message'])->assertOk();
        $this->assertSame(2, DB::table('business_activity')->where('event', 'message.created')->count());
        $this->assertStringNotContainsString('Second message', json_encode(DB::table('business_activity')->get()));
        $booking = $this->postJson('/bookings', ['providerId' => $this->teacher, 'mode' => 'online', 'requestedTime' => '2026-12-01 12:00:00'])->assertOk()->json('booking');
        $this->withToken($auth->issueSessionToken($this->teacher));
        $this->patchJson('/bookings/'.$booking['id'].'/status', ['status' => 'accepted'])->assertOk();
        $change = DB::table('business_activity')->where('event', 'booking.status_changed')->first();
        $this->assertSame('requested', json_decode($change->before_data, true)['status']);
        $this->assertSame('accepted', json_decode($change->after_data, true)['status']);
        $this->assertSame($this->teacher, $change->actor_user_id);
        $this->assertSame($this->learner, $change->related_user_id);
        $this->patchJson('/bookings/'.$booking['id'].'/status', ['status' => 'accepted'])->assertOk();
        $this->assertSame(1, DB::table('business_activity')->where('event', 'booking.status_changed')->count());
    }

    public function test_business_history_requires_a_source_transaction(): void
    {
        DB::rollBack();
        $this->transactionStarted = false;
        $this->expectException(\LogicException::class);
        app(BusinessActivityRecorder::class)->record('booking.created', (string) Str::uuid(), null);
    }
}
