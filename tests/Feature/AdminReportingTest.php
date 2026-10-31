<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAuthActivity;
use App\Reporting\BusinessActivityRecorder;
use App\Reporting\OwnerMetrics;
use App\Reporting\ReportRegistry;
use App\Reporting\SearchAnalyticsRecorder;
use App\Services\AuthenticationService;
use App\Services\SafetyReportCreator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class AdminReportingTest extends TestCase
{
    private bool $transactionStarted = false;

    private int $learner;

    private int $teacher;

    private int $institute;

    private int $conversation;

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
            $id = NumericTestIds::next();
            $this->$type = $id;
            DB::table('users')->insert([
                'id' => $id, 'account_type' => $type, 'full_name' => ucfirst($type).' Person',
                'email' => $type.'@example.test', 'password_hash' => Hash::make('test-password'),
                'parental_consent_token' => 'private-consent-secret', 'phone_verified_at' => now(),
                'profile_completed_at' => now(), 'date_of_birth' => $type === 'learner' ? '2000-01-01' : null,
                'created_at' => '2026-10-20 12:00:00',
            ]);
        }
        DB::table('teacher_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $this->teacher, 'lesson_modes' => '[]']);
        DB::table('institute_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $this->institute, 'lesson_modes' => '[]']);
        foreach ([$this->teacher, $this->institute] as $provider) {
            $id = NumericTestIds::next();
            DB::table('conversations')->insert(['id' => $id, 'learner_id' => $this->learner, 'provider_id' => $provider]);
            if ($provider === $this->teacher) {
                $this->conversation = $id;
            }
            DB::table('messages')->insert(['id' => NumericTestIds::next(), 'conversation_id' => $id, 'sender_id' => $this->learner,
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
        $this->assertStringContainsString('referral_points', $csv);
        $this->assertStringNotContainsString('password_hash', $csv);
        $this->assertStringNotContainsString('private-consent-secret', $csv);
    }

    public function test_report_pages_offer_quick_date_ranges_and_compare_adjacent_periods(): void
    {
        $this->withSession(['admin_authenticated' => true]);
        $this->get('/admin/reports/users')->assertOk()->assertSee('Quick date range')->assertSee('Since launch');
        $this->get('/admin/reports/users?from=2026-10-20&to=2026-10-20')
            ->assertOk()->assertSee('Period comparison')->assertSee('Previous period');
    }

    public function test_empty_search_report_and_owner_metrics_use_privacy_safe_search_analytics(): void
    {
        $recorder = app(SearchAnalyticsRecorder::class);
        $recorder->record('nearby', 'nearby_grid', null, null, 0, 28.6139, 77.2090, 7);
        $recorder->record('subject', 'city', 'Gurugram', 1, 2);

        $metrics = app(OwnerMetrics::class)->summarize(now()->startOfDay(), now()->startOfDay());
        $this->assertSame(2, $metrics['searches']['value']);
        $this->assertSame(1, $metrics['empty_searches']['value']);
        $nearby = DB::table('search_analytics')->where('search_type', 'nearby')->first();
        $this->assertSame(28.6, (float) $nearby->latitude_band);
        $this->assertSame(77.2, (float) $nearby->longitude_band);
        $this->assertArrayNotHasKey('q', (array) $nearby);
        $this->assertArrayNotHasKey('search_query', (array) $nearby);

        $this->withSession(['admin_authenticated' => true]);
        $this->get('/admin/reports/no-result-searches')->assertOk()->assertSee('nearby_grid')->assertDontSee('Gurugram');
    }

    public function test_all_reports_export_as_one_filtered_excel_workbook(): void
    {
        $response = $this->withSession(['admin_authenticated' => true])
            ->get('/admin/reports/export-all?from=2026-10-20&to=2026-10-20&type=teacher')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $file = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($file);
        $workbook = new \ZipArchive;
        $this->assertNotFalse($workbook->open($file));
        $workbookXml = $workbook->getFromName('xl/workbook.xml');
        $this->assertStringContainsString('Users and registrations', $workbookXml);
        $this->assertGreaterThan(10, $workbook->numFiles);
        $sheets = simplexml_load_string($workbookXml);
        $sheets->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $userSheetIndex = 0;
        foreach ($sheets->xpath('//x:sheet') as $index => $sheet) {
            if ((string) $sheet['name'] === 'Users and registrations') {
                $userSheetIndex = $index + 1;
                break;
            }
        }
        $userSheet = $workbook->getFromName('xl/worksheets/sheet'.$userSheetIndex.'.xml');
        $this->assertStringContainsString('teacher@example.test', $userSheet);
        $this->assertStringNotContainsString('learner@example.test', $userSheet);
        $workbook->close();
        $this->assertSame(count(app(ReportRegistry::class)->all()), DB::table('admin_report_access_log')->where('action', 'export')->count());
    }

    public function test_safety_report_queue_shows_open_fraud_reports_first(): void
    {
        foreach ([
            ['category' => 'spam', 'status' => 'open', 'reason' => 'Open spam sentinel', 'created_at' => '2026-10-30 12:00:00'],
            ['category' => 'fraud', 'status' => 'reviewed', 'reason' => 'Reviewed fraud sentinel', 'created_at' => '2026-10-31 12:00:00'],
            ['category' => 'fraud', 'status' => 'open', 'reason' => 'Open fraud sentinel', 'created_at' => '2026-10-20 12:00:00'],
        ] as $report) {
            DB::table('reports')->insert([
                'reporter_id' => $this->learner, 'target_type' => 'user', 'target_id' => $this->teacher,
                'reason_category' => $report['category'], 'status' => $report['status'],
                'reason' => $report['reason'], 'created_at' => $report['created_at'],
            ]);
        }

        $html = $this->withSession(['admin_authenticated' => true])
            ->get('/admin/reports/reports')->assertOk()->getContent();
        $fraudPosition = strpos($html, 'Open fraud sentinel');
        $spamPosition = strpos($html, 'Open spam sentinel');
        $reviewedPosition = strpos($html, 'Reviewed fraud sentinel');
        $this->assertNotFalse($fraudPosition);
        $this->assertNotFalse($spamPosition);
        $this->assertNotFalse($reviewedPosition);
        $this->assertLessThan($spamPosition, $fraudPosition);
        $this->assertLessThan($reviewedPosition, $spamPosition);

        $reportId = DB::table('reports')->where('reason', 'Open fraud sentinel')->value('id');
        $this->patch('/admin/moderation/safety-reports/'.$reportId.'/status', ['status' => 'reviewed'])
            ->assertRedirect()->assertSessionHas('status', 'Safety report status updated.');
        $this->assertSame('reviewed', DB::table('reports')->where('id', $reportId)->value('status'));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'safety_report.status_changed')->where('entity_id', $reportId)->count());
        $this->assertSame(1, DB::table('admin_report_access_log')->where('report', 'reports')->where('action', 'safety-status')->where('record_id', $reportId)->count());
    }

    public function test_referral_click_report_is_admin_only_and_never_exposes_visitor_hashes(): void
    {
        $visitorHash = str_repeat('f', 64);
        DB::table('referral_clicks')->insert([
            'id' => NumericTestIds::next(), 'referrer_id' => $this->teacher,
            'visitor_hash' => $visitorHash, 'created_at' => now(),
        ]);

        $this->get('/admin/reports/referral-clicks')->assertRedirect(route('admin.login'));

        $this->withSession(['admin_authenticated' => true]);
        $this->get('/admin/reports/referral-clicks')->assertOk()->assertSee((string) $this->teacher)->assertDontSee($visitorHash);
        $csv = $this->get('/admin/reports/referral-clicks?export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('referrer_id', $csv);
        $this->assertStringNotContainsString($visitorHash, $csv);
        $this->assertSame(1, DB::table('admin_report_access_log')->where('report', 'referral-clicks')->where('action', 'export')->count());
    }

    public function test_admin_workspace_queues_require_admin_session_and_record_page_access(): void
    {
        foreach ([
            '/admin/moderation/photos',
            '/admin/moderation/bsl-centres',
            '/admin/moderation/suspended-users',
            '/admin/moderation/possible-duplicates',
        ] as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }

        $this->withSession(['admin_authenticated' => true]);
        $this->get('/admin/dashboard')->assertOk()->assertSee('What would you like to do?')->assertSee('BSL admin overview');
        $this->get('/admin/moderation/photos')->assertOk()->assertSee('Photos to approve');
        $this->get('/admin/moderation/bsl-centres')->assertOk()->assertSee('BSL Verified centres');
        $this->get('/admin/moderation/suspended-users')->assertOk()->assertSee('Suspended accounts');
        $this->get('/admin/moderation/possible-duplicates')->assertOk()->assertSee('Possible duplicate teachers');

        $this->assertSame(1, DB::table('admin_report_access_log')->where('report', 'dashboard')->where('action', 'view')->count());
        $this->assertSame(1, DB::table('admin_report_access_log')->where('report', 'users')->where('action', 'photo-review-list')->count());
        $this->assertSame(1, DB::table('admin_report_access_log')->where('report', 'institute-profiles')->where('action', 'bsl-list')->count());
    }

    public function test_photo_review_and_bsl_badge_mutations_are_logged_and_evented(): void
    {
        DB::table('users')->where('id', $this->teacher)->update([
            'avatar_url' => 'https://images.example.test/pending.jpg', 'avatar_status' => 'pending',
        ]);
        DB::table('institute_profiles')->where('user_id', $this->institute)->update([
            'institute_type' => 'bsl_direct', 'is_bsl_official' => false,
        ]);
        $this->withSession(['admin_authenticated' => true]);

        $this->patch('/admin/moderation/photos/'.$this->teacher, ['status' => 'approved'])
            ->assertRedirect()->assertSessionHas('status', 'Photo approved.');
        $this->assertSame('approved', DB::table('users')->where('id', $this->teacher)->value('avatar_status'));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'avatar.reviewed')->where('entity_id', $this->teacher)->count());

        $this->patch('/admin/moderation/bsl-centres/'.$this->institute, ['verified' => '1'])
            ->assertRedirect()->assertSessionHas('status', 'BSL Verified badge granted.');
        $this->assertSame(1, (int) DB::table('institute_profiles')->where('user_id', $this->institute)->value('is_bsl_official'));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'institute.bsl_verified_changed')->count());

        DB::table('institute_profiles')->where('user_id', $this->institute)->update(['institute_type' => 'franchise']);
        $this->patch('/admin/moderation/bsl-centres/'.$this->institute, ['verified' => '1'])->assertSessionHasErrors('verified');
        $this->assertSame(1, DB::table('business_activity')->where('event', 'institute.bsl_verified_changed')->count());
        $this->assertSame(1, DB::table('admin_report_access_log')->where('action', 'photo-review')->where('record_id', $this->teacher)->count());
    }

    public function test_failed_photo_history_write_rolls_back_the_photo_decision(): void
    {
        DB::table('users')->where('id', $this->teacher)->update(['avatar_status' => 'pending']);
        $recorder = \Mockery::mock(BusinessActivityRecorder::class);
        $recorder->shouldReceive('record')->once()->andThrow(new \RuntimeException('History write failed.'));
        $this->app->instance(BusinessActivityRecorder::class, $recorder);
        $this->withSession(['admin_authenticated' => true])->withoutExceptionHandling();

        try {
            $this->patch('/admin/moderation/photos/'.$this->teacher, ['status' => 'rejected']);
            $this->fail('The failed history write should abort the request.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('History write failed.', $exception->getMessage());
        }

        $this->assertSame('pending', DB::table('users')->where('id', $this->teacher)->value('avatar_status'));
        $this->assertSame(0, DB::table('business_activity')->where('event', 'avatar.reviewed')->count());
    }

    public function test_suspensions_require_a_reason_protect_admin_accounts_and_record_safe_history(): void
    {
        $this->withSession(['admin_authenticated' => true]);

        $this->post('/admin/moderation/users/'.$this->teacher.'/suspend', ['reason' => 'Repeated fake listing reports'])
            ->assertRedirect()->assertSessionHas('status', 'Account suspended.');
        $teacher = DB::table('users')->where('id', $this->teacher)->first();
        $this->assertNotNull($teacher->suspended_at);
        $this->assertSame('Repeated fake listing reports', $teacher->suspended_reason);
        $event = DB::table('business_activity')->where('event', 'user.suspended')->first();
        $this->assertSame(['suspended' => true, 'reason_set' => true], json_decode($event->after_data, true));

        DB::table('users')->where('id', $this->institute)->update(['is_admin' => true]);
        $this->post('/admin/moderation/users/'.$this->institute.'/suspend', ['reason' => 'Not allowed'])
            ->assertSessionHasErrors('user');
        $this->assertNull(DB::table('users')->where('id', $this->institute)->value('suspended_at'));

        $this->post('/admin/moderation/users/'.$this->teacher.'/unsuspend')->assertRedirect()->assertSessionHas('status', 'Account restored.');
        $this->assertNull(DB::table('users')->where('id', $this->teacher)->value('suspended_at'));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'user.unsuspended')->count());
        $this->assertSame(2, DB::table('admin_report_access_log')->whereIn('action', ['suspend', 'unsuspend'])->count());
    }

    public function test_existing_admin_api_moderation_writes_the_same_business_history(): void
    {
        DB::table('users')->where('id', $this->teacher)->update(['is_admin' => true, 'avatar_status' => 'pending']);
        DB::table('institute_profiles')->where('user_id', $this->institute)->update([
            'institute_type' => 'bsl_direct', 'is_bsl_official' => false,
        ]);
        $token = app(AuthenticationService::class)->issueSessionToken($this->teacher);
        $this->withToken($token);

        $this->patchJson('/moderation/photos/'.$this->teacher, ['status' => 'approved'])->assertOk();
        $this->patchJson('/profiles/institute/'.$this->institute.'/bsl-verified', ['verified' => true])->assertOk();
        $this->postJson('/moderation/users/'.$this->learner.'/suspend', ['reason' => 'First reason'])->assertOk();
        $this->postJson('/moderation/users/'.$this->learner.'/suspend', ['reason' => 'Updated reason'])->assertOk();
        $this->postJson('/moderation/users/'.$this->learner.'/unsuspend')->assertOk();

        $this->assertSame($this->teacher, (int) DB::table('business_activity')->where('event', 'avatar.reviewed')->value('actor_user_id'));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'institute.bsl_verified_changed')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'user.suspension_reason_changed')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'user.unsuspended')->count());
        $this->assertStringNotContainsString('Updated reason', json_encode(DB::table('business_activity')->get()));
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
            $recorder->record('booking.created', NumericTestIds::next(), $this->learner, after: ['password' => 'secret']);
            $this->fail('Unexpected snapshot fields must be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, DB::table('business_activity')->count());
        }
        $entityId = NumericTestIds::next();
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
        app(BusinessActivityRecorder::class)->record('booking.created', NumericTestIds::next(), null);
    }

    public function test_management_sections_have_bounded_stable_pagination(): void
    {
        $this->withSession(['admin_authenticated' => true]);
        $domain = DB::table('subject_domains')->value('id') ?? DB::table('subject_domains')->insertGetId(['name' => 'Paging domain', 'sort_order' => 999]);
        for ($i = 0; $i < 31; $i++) {
            DB::table('subjects')->insert(['domain_id' => $domain, 'name' => 'Paging subject '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
            foreach (['learner', 'teacher', 'institute'] as $type) {
                $id = NumericTestIds::next();
                DB::table('users')->insert(['id' => $id, 'account_type' => $type, 'full_name' => 'Same paging name']);
                if ($type === 'teacher') {
                    DB::table('teacher_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $id, 'lesson_modes' => '[]']);
                }
                if ($type === 'institute') {
                    DB::table('institute_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $id, 'lesson_modes' => '[]']);
                }
            }
        }
        foreach (['students', 'teachers', 'institutes', 'subjects'] as $section) {
            $response = $this->get('/admin/'.$section.'?per_page=15')->assertOk()->assertSee('Records pagination')->assertSee('Rows per page');
            $first = $response->viewData('records');
            $this->assertSame(15, $first->perPage());
            $this->assertCount(15, $first->items());
            $this->assertGreaterThanOrEqual(31, $first->total());
            $next = $this->get($first->nextPageUrl())->assertOk()->viewData('records');
            $this->assertSame([], array_values(array_intersect(array_column($first->items(), 'id'), array_column($next->items(), 'id'))));
            $this->assertSame(2, $next->currentPage());
            $this->assertSame(15, $next->perPage());
        }
        $this->get('/admin/students?per_page=10000')->assertSessionHasErrors('per_page');
        $this->get('/admin/students?page=0')->assertSessionHasErrors('page');
    }

    public function test_report_pagination_preserves_filters_and_csv_contains_all_matching_pages(): void
    {
        $this->withSession(['admin_authenticated' => true]);
        for ($i = 0; $i < 36; $i++) {
            DB::table('users')->insert(['id' => NumericTestIds::next(), 'account_type' => 'learner', 'full_name' => 'Paging person '.$i, 'created_at' => '2026-10-20 12:00:00']);
        }
        $url = '/admin/reports/users?q=Paging&type=learner&from=2026-10-01&to=2026-10-31&per_page=15';
        $first = $this->get($url)->assertOk()->assertSee('Showing 1–15 of 36 records')->viewData('records');
        parse_str(parse_url($first->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('Paging', $query['q']);
        $this->assertSame('learner', $query['type']);
        $this->assertSame('2026-10-01', $query['from']);
        $this->assertSame('2026-10-31', $query['to']);
        $this->assertSame('15', $query['per_page']);
        $this->get($first->nextPageUrl())->assertOk()->assertSee('Showing 16–30 of 36 records');
        $csv = $this->get($url.'&page=2&export=1')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', explode("\n", trim($csv)));
        $this->assertCount(37, $rows);
        $this->assertStringContainsString($first->items()[0]->id, $csv);
        $this->assertSame(1, DB::table('admin_report_access_log')->where('report', 'users')->where('action', 'export')->count());
        $this->get('/admin/reports/users?per_page=100000')->assertSessionHasErrors('per_page');
    }

    public function test_learner_teacher_and_institute_can_submit_report_fields_for_admin_reporting(): void
    {
        $auth = app(AuthenticationService::class);
        foreach ([['id' => $this->learner, 'target' => $this->teacher],
            ['id' => $this->teacher, 'target' => $this->institute],
            ['id' => $this->institute, 'target' => $this->learner]] as $index => $case) {
            $details = 'Review detail '.$index.' NeedleReport';
            $response = $this->withToken($auth->issueSessionToken($case['id']))->postJson('/reports', [
                'targetType' => 'user', 'targetId' => $case['target'], 'reasonCategory' => 'fake_profile',
                'additionalDetails' => $details, 'evidenceLink' => 'https://example.test/report-'.$index,
            ])->assertCreated()->assertJsonPath('report.status', 'open')
                ->assertJsonPath('report.reasonCategory', 'fake_profile')
                ->assertJsonPath('report.additionalDetails', $details)
                ->assertJsonPath('report.evidenceLink', 'https://example.test/report-'.$index)
                ->assertJsonPath('report.screenshotAttached', false);

            $reportId = $response->json('report.id');
            $this->assertDatabaseHas('reports', ['id' => $reportId, 'reporter_id' => $case['id'],
                'target_id' => $case['target'], 'reason' => $details]);
            $event = DB::table('business_activity')->where('event', 'safety_report.submitted')
                ->where('entity_id', (string) $reportId)->first();
            $this->assertNotNull($event);
            $this->assertStringNotContainsString($details, $event->after_data);
            $this->assertStringNotContainsString('example.test', $event->after_data);
        }

        $this->withSession(['admin_authenticated' => true]);
        $this->get('/admin/reports/reports?q=NeedleReport')->assertOk()->assertSee('NeedleReport');
        $csv = $this->get('/admin/reports/reports?q=NeedleReport&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('evidence_link', $csv);
        $this->assertStringContainsString('screenshot_available', $csv);
        $this->assertStringContainsString('https://example.test/report-', $csv);
        $this->assertStringNotContainsString('screenshot_path', $csv);
        $this->assertSame(1, DB::table('admin_report_access_log')->where('report', 'reports')->where('action', 'view')->count());
        $this->assertSame(1, DB::table('admin_report_access_log')->where('report', 'reports')->where('action', 'export')->count());

        DB::table('users')->where('id', $this->institute)->update(['is_admin' => true]);
        $apiReports = $this->withToken($auth->issueSessionToken($this->institute))
            ->getJson('/safety/reports?perPage=1')->assertOk()->assertJsonCount(1, 'reports')
            ->assertJsonPath('pagination.page', 1)->assertJsonPath('pagination.perPage', 1)
            ->assertJsonPath('pagination.hasMore', true);
        $apiReports->assertDontSee('screenshot_path');
        $this->assertSame(1, DB::table('admin_report_access_log')
            ->where('report', 'reports')->where('action', 'api-view')->count());
    }

    public function test_displayed_report_category_label_is_normalized_to_the_saved_category(): void
    {
        $auth = app(AuthenticationService::class);
        $response = $this->withToken($auth->issueSessionToken($this->learner))->postJson('/reports', [
            'targetType' => 'user', 'targetId' => $this->teacher,
            'reasonCategory' => 'Fake profile / not who they claim',
        ])->assertCreated()->assertJsonPath('report.reasonCategory', 'fake_profile');

        $this->assertDatabaseHas('reports', [
            'id' => $response->json('report.id'), 'reason_category' => 'fake_profile',
        ]);
    }

    public function test_legacy_safety_report_endpoint_accepts_all_account_types_and_screen_evidence(): void
    {
        Storage::fake('local');
        $auth = app(AuthenticationService::class);
        foreach ([['id' => $this->learner, 'target' => $this->teacher],
            ['id' => $this->teacher, 'target' => $this->institute],
            ['id' => $this->institute, 'target' => $this->learner]] as $case) {
            $this->withToken($auth->issueSessionToken($case['id']))->postJson('/safety/reports', [
                'targetType' => 'user', 'targetId' => $case['target'], 'reasonCategory' => 'fake_profile',
                'additionalDetails' => 'This profile needs review.', 'evidenceLink' => 'https://example.test/profile',
            ])->assertCreated()
                ->assertJsonPath('report.reason', 'This profile needs review.')
                ->assertJsonPath('report.evidence_link', 'https://example.test/profile');
        }

        $response = $this->withToken($auth->issueSessionToken($this->learner))->post('/safety/reports', [
            'targetType' => 'user', 'targetId' => $this->teacher, 'reasonCategory' => 'spam',
            'screenshot' => UploadedFile::fake()->image('report.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertTrue($response->json('report.screenshot_available'));
        $this->assertArrayNotHasKey('screenshot_path', $response->json('report'));
        $storedScreenshot = DB::table('reports')->where('id', $response->json('report.id'))->value('screenshot_path');
        Storage::disk('local')->assertExists($storedScreenshot);
    }

    public function test_report_screenshots_are_private_and_browser_admin_access_is_logged(): void
    {
        Storage::fake('local');
        $auth = app(AuthenticationService::class);
        $response = $this->withToken($auth->issueSessionToken($this->learner))->post('/reports', [
            'targetType' => 'user', 'targetId' => $this->teacher, 'reasonCategory' => 'spam',
            'screenshot' => UploadedFile::fake()->image('evidence.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $reportId = $response->json('report.id');
        $path = DB::table('reports')->where('id', $reportId)->value('screenshot_path');
        $this->assertStringStartsWith('reports/', $path);
        Storage::disk('local')->assertExists($path);
        $response->assertJsonPath('report.screenshotAttached', true);

        $this->get('/admin/reports/safety/'.$reportId.'/screenshot')->assertRedirect(route('admin.login'));
        $this->withSession(['admin_authenticated' => true])
            ->get('/admin/reports/safety/'.$reportId.'/screenshot')->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store');
        $this->assertSame(1, DB::table('admin_report_access_log')->where('report', 'reports')
            ->where('record_id', (string) $reportId)->where('action', 'evidence')->count());
    }

    public function test_message_report_requires_conversation_participation_and_other_sender(): void
    {
        $messageId = DB::table('messages')->where('conversation_id', $this->conversation)->value('id');
        $auth = app(AuthenticationService::class);

        $this->withToken($auth->issueSessionToken($this->institute))->postJson('/reports', [
            'targetType' => 'message', 'targetId' => $messageId, 'reasonCategory' => 'spam',
        ])->assertUnprocessable();

        $this->withToken($auth->issueSessionToken($this->teacher))->postJson('/reports', [
            'targetType' => 'message', 'targetId' => $messageId, 'reasonCategory' => 'spam',
        ])->assertCreated();
    }

    public function test_report_and_submission_history_roll_back_together(): void
    {
        $reports = app(SafetyReportCreator::class);
        $beforeReports = DB::table('reports')->count();
        $beforeEvents = DB::table('business_activity')->where('event', 'safety_report.submitted')->count();
        try {
            DB::transaction(function () use ($reports): void {
                $reports->create($this->learner, 'user', $this->teacher, 'spam', 'Rollback this report');
                throw new \RuntimeException('Force outer transaction rollback.');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Force outer transaction rollback.', $error->getMessage());
        }

        $this->assertSame($beforeReports, DB::table('reports')->count());
        $this->assertSame($beforeEvents, DB::table('business_activity')->where('event', 'safety_report.submitted')->count());
    }
}
