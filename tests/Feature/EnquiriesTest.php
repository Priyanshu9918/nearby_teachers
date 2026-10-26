<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class EnquiriesTest extends TestCase
{
    private bool $transactionStarted = false;

    private int $userId;

    private array $courseIds;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('ENQUIRIES_TEST_DATABASE') ?: getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set ENQUIRIES_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database,
            'services.nearby_teacher.jwt_secret' => str_repeat('enquiry-test-secret-', 4), 'admin.email' => 'admin@example.test']);
        DB::purge('mysql');
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->userId = NumericTestIds::next();
        DB::table('users')->insert(['id' => $this->userId, 'account_type' => 'learner',
            'full_name' => 'Account name', 'phone' => '+919876543210', 'phone_verified_at' => now()]);
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Enquiry courses']);
        $this->courseIds = [];
        for ($i = 1; $i <= 6; $i++) {
            $this->courseIds[] = DB::table('subjects')->insertGetId([
                'domain_id' => $domain, 'name' => 'BSL Course '.$i, 'is_bsl' => true,
            ]);
        }
        $this->withToken(app(AuthenticationService::class)->issueOtpToken($this->userId));
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    #[DataProvider('roles')]
    public function test_both_forms_save_contact_snapshots_and_atomic_history_for_each_role(string $role): void
    {
        DB::table('users')->where('id', $this->userId)->update(['account_type' => $role]);
        $franchise = $this->postJson('/franchise/enquiries', [...$this->franchise(), 'userId' => 999, 'status' => 'approved'])
            ->assertCreated()->assertJsonPath('enquiry.name', 'Asha Applicant')
            ->assertJsonPath('enquiry.phone', '+919876543211')->assertJsonPath('enquiry.status', 'submitted');
        $id = $franchise->json('enquiry.id');
        $this->assertIsInt($id);
        $this->assertDatabaseHas('franchise_enquiries', ['id' => $id, 'user_id' => $this->userId, 'location' => 'Lucknow, Alambagh']);
        $job = $this->postJson('/jobs/applications', [...$this->job(), 'courseIds' => array_slice($this->courseIds, 0, 5)])
            ->assertCreated()->assertJsonPath('application.expectedSalary', '25000.50')
            ->assertJsonPath('application.currency', 'INR')->assertJsonPath('application.salaryPeriod', 'monthly')
            ->assertJsonPath('application.status', 'submitted')->assertJsonCount(5, 'application.courses');
        $this->assertStringContainsString('no-store', $job->headers->get('Cache-Control'));
        $this->assertDatabaseHas('teaching_job_applications', ['id' => $job->json('application.id'), 'user_id' => $this->userId,
            'phone' => '+919876543211', 'expected_salary' => '25000.50']);
        $this->assertDatabaseCount('teaching_job_application_courses', 5);
        DB::table('subjects')->where('id', $this->courseIds[0])->update(['name' => 'Renamed course']);
        DB::table('users')->where('id', $this->userId)->update(['full_name' => 'Changed account']);
        $this->assertDatabaseHas('teaching_job_application_courses', ['course_id' => $this->courseIds[0], 'course_name' => 'BSL Course 1']);
        $this->assertDatabaseHas('franchise_enquiries', ['id' => $id, 'name' => 'Asha Applicant']);
        $events = DB::table('business_activity')->where('actor_user_id', $this->userId)->get();
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(['franchise_enquiry.submitted', 'teaching_job_application.submitted'], $events->pluck('event')->all());
        foreach ($events as $event) {
            $this->assertSame(1, $event->version);
            $this->assertStringNotContainsString('Asha Applicant', $event->after_data);
            $this->assertStringNotContainsString('+919876543211', $event->after_data);
        }
    }

    #[DataProvider('invalidForms')]
    public function test_invalid_forms_do_not_create_records(string $endpoint, array $override, string $field): void
    {
        $body = $endpoint === '/franchise/enquiries' ? $this->franchise() : $this->job();
        if (($override['courseIds'] ?? null) === 'too_many') {
            $override['courseIds'] = $this->courseIds;
        } elseif (($override['courseIds'] ?? null) === 'duplicate') {
            $override['courseIds'] = [$this->courseIds[0], (string) $this->courseIds[0]];
        } elseif (($override['courseIds'] ?? null) === 'not_bsl') {
            DB::table('subjects')->where('id', $this->courseIds[0])->update(['is_bsl' => false]);
            $override['courseIds'] = [$this->courseIds[0]];
        }
        $this->postJson($endpoint, [...$body, ...$override])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('franchise_enquiries', 0);
        $this->assertDatabaseCount('teaching_job_applications', 0);
        $this->assertDatabaseCount('teaching_job_application_courses', 0);
        $this->assertSame(0, DB::table('business_activity')->where('actor_user_id', $this->userId)->count());
    }

    public function test_authentication_phone_verification_and_suspension_are_enforced(): void
    {
        $token = app(AuthenticationService::class)->issueOtpToken($this->userId);
        foreach (['/franchise/enquiries' => $this->franchise(), '/jobs/applications' => $this->job()] as $endpoint => $body) {
            $this->withoutHeader('Authorization')->postJson($endpoint, $body)->assertUnauthorized();
            $this->withToken($token);
            DB::table('users')->where('id', $this->userId)->update(['phone_verified_at' => null]);
            $this->postJson($endpoint, $body)->assertForbidden();
            DB::table('users')->where('id', $this->userId)->update(['phone_verified_at' => now(), 'suspended_at' => now()]);
            $this->postJson($endpoint, $body)->assertForbidden();
            DB::table('users')->where('id', $this->userId)->update(['suspended_at' => null]);
        }
        $this->assertDatabaseCount('franchise_enquiries', 0);
        $this->assertDatabaseCount('teaching_job_applications', 0);
    }

    public function test_each_submission_endpoint_is_rate_limited(): void
    {
        foreach (['/franchise/enquiries' => $this->franchise(), '/jobs/applications' => $this->job()] as $endpoint => $body) {
            for ($i = 0; $i < 5; $i++) {
                $this->postJson($endpoint, $body)->assertCreated();
            }
            $this->postJson($endpoint, $body)->assertStatus(429);
        }
        $this->assertDatabaseCount('franchise_enquiries', 5);
        $this->assertDatabaseCount('teaching_job_applications', 5);
    }

    public function test_history_failure_rolls_back_both_forms_and_selected_courses(): void
    {
        $failHistory = true;
        DB::listen(function ($query) use (&$failHistory): void {
            if ($failHistory && str_starts_with($query->sql, 'insert into `business_activity`')) {
                throw new \RuntimeException('Simulated enquiry history failure');
            }
        });
        try {
            $this->postJson('/franchise/enquiries', $this->franchise())->assertStatus(500);
            $this->postJson('/jobs/applications', $this->job())->assertStatus(500);
        } finally {
            $failHistory = false;
        }
        $this->assertDatabaseCount('franchise_enquiries', 0);
        $this->assertDatabaseCount('teaching_job_applications', 0);
        $this->assertDatabaseCount('teaching_job_application_courses', 0);
        $this->assertSame(0, DB::table('business_activity')->where('actor_user_id', $this->userId)->count());
    }

    public function test_reports_and_exports_are_admin_only_filtered_and_access_logged(): void
    {
        $this->postJson('/franchise/enquiries', $this->franchise())->assertCreated();
        $this->postJson('/jobs/applications', $this->job())->assertCreated();
        foreach (['franchise-enquiries', 'job-applications', 'job-application-courses'] as $report) {
            $path = '/admin/reports/'.$report;
            $this->get($path)->assertRedirect(route('admin.login'));
            $this->get($path.'?export=1')->assertRedirect(route('admin.login'));
            $this->withSession(['developer_authenticated' => true])->get($path)->assertRedirect(route('admin.login'));
            $this->assertSame(0, DB::table('admin_report_access_log')->where('report', $report)->count());
        }
        $this->withSession(['admin_authenticated' => true]);
        foreach (['franchise-enquiries' => 'Lucknow, Alambagh', 'job-applications' => 'BSL Course 1', 'job-application-courses' => 'BSL Course 1'] as $report => $expected) {
            $path = '/admin/reports/'.$report;
            $filters = ['user' => $this->userId, 'type' => 'learner', 'from' => now()->toDateString(), 'to' => now()->toDateString(),
                'q' => $report === 'job-application-courses' ? 'BSL Course' : 'Asha Applicant'];
            $this->get($path.'?'.http_build_query($filters))->assertOk()->assertSee($expected);
            $csv = $this->get($path.'?'.http_build_query([...$filters, 'export' => 1]))->assertOk()->streamedContent();
            $this->assertStringContainsString($expected, $csv);
            foreach ([['user' => $this->userId + 1], ['type' => 'teacher'], ['from' => now()->addDay()->toDateString()], ['q' => 'no-match']] as $excluded) {
                $this->get($path.'?'.http_build_query($excluded))->assertOk()->assertSee('No matching records.');
                $csv = $this->get($path.'?'.http_build_query([...$excluded, 'export' => 1]))->assertOk()->streamedContent();
                $this->assertStringNotContainsString($expected, $csv);
            }
            $this->assertDatabaseHas('admin_report_access_log', ['report' => $report, 'action' => 'view']);
            $this->assertDatabaseHas('admin_report_access_log', ['report' => $report, 'action' => 'export']);
        }
    }

    private function franchise(): array
    {
        return ['name' => 'Asha Applicant', 'phone' => '+91 98765 43211', 'location' => 'Lucknow, Alambagh'];
    }

    private function job(): array
    {
        return ['name' => 'Asha Applicant', 'phone' => '+91 98765 43211', 'expectedSalary' => '25000.50', 'courseIds' => [$this->courseIds[0]]];
    }

    public static function roles(): array
    {
        return [['learner'], ['teacher'], ['institute']];
    }

    public static function invalidForms(): array
    {
        return [
            'empty name' => ['/franchise/enquiries', ['name' => '   '], 'name'],
            'long name' => ['/jobs/applications', ['name' => str_repeat('x', 161)], 'name'],
            'missing location' => ['/franchise/enquiries', ['location' => null], 'location'],
            'long location' => ['/franchise/enquiries', ['location' => str_repeat('x', 256)], 'location'],
            'invalid phone' => ['/franchise/enquiries', ['phone' => 'invalid'], 'phone'],
            'no country code' => ['/jobs/applications', ['phone' => '9876543211'], 'phone'],
            'extension' => ['/jobs/applications', ['phone' => '+919876543211 ext 12'], 'phone'],
            'numeric phone' => ['/jobs/applications', ['phone' => 9876543211], 'phone'],
            'missing salary' => ['/jobs/applications', ['expectedSalary' => null], 'expectedSalary'],
            'negative salary' => ['/jobs/applications', ['expectedSalary' => -1], 'expectedSalary'],
            'zero salary' => ['/jobs/applications', ['expectedSalary' => 0], 'expectedSalary'],
            'salary too large' => ['/jobs/applications', ['expectedSalary' => 100000000], 'expectedSalary'],
            'salary precision' => ['/jobs/applications', ['expectedSalary' => '1.001'], 'expectedSalary'],
            'salary label' => ['/jobs/applications', ['expectedSalary' => '₹25,000 per month'], 'expectedSalary'],
            'no courses' => ['/jobs/applications', ['courseIds' => []], 'courseIds'],
            'too many courses' => ['/jobs/applications', ['courseIds' => 'too_many'], 'courseIds'],
            'duplicate courses' => ['/jobs/applications', ['courseIds' => 'duplicate'], 'courseIds.0'],
            'unknown course' => ['/jobs/applications', ['courseIds' => [4294967295]], 'courseIds'],
            'non BSL course' => ['/jobs/applications', ['courseIds' => 'not_bsl'], 'courseIds'],
            'non-list courses' => ['/jobs/applications', ['courseIds' => ['key' => 1]], 'courseIds'],
        ];
    }
}
