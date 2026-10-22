<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Services\AuthenticationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentRegistrationCompletionTest extends TestCase
{
    private bool $transactionStarted = false;

    private string $userId;

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
            'services.nearby_teacher.jwt_secret' => str_repeat('dob-registration-secret-', 3),
        ]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->userId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $this->userId, 'account_type' => 'learner', 'full_name' => 'New user',
            'phone' => '+919876543210', 'phone_verified_at' => now(),
        ]);
        $this->withToken(app(AuthenticationService::class)->issueOtpToken($this->userId));
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    #[DataProvider('registrationEndpoints')]
    public function test_student_form_is_saved_but_registration_and_login_wait_for_dob(string $endpoint): void
    {
        $this->postJson($endpoint, $this->form())->assertOk()
            ->assertJsonPath('registrationSubmitted', true)->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('profileCompleted', false)->assertJsonPath('onboardingRequired', true)
            ->assertJsonPath('nextScreen', 'age_verification')->assertJsonPath('nextStepEndpoint', '/auth/date-of-birth')
            ->assertJsonPath('autosaveEnabled', false)->assertJsonPath('missingFields', ['dateOfBirth']);
        $saved = DB::table('users')->where('id', $this->userId)->first();
        $this->assertNotNull($saved->registration_submitted_at);
        $this->assertNull($saved->profile_completed_at);
        $this->assertSame(1, DB::table('business_activity')->where('event', 'registration.submitted')->count());
        $this->assertSame(0, DB::table('business_activity')->where('event', 'registration.completed')->count());
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('nextStep', 'age_verification');
        $this->postJson('/auth/login', ['email' => 'dob@example.test', 'password' => 'test-password'])
            ->assertStatus(409)->assertJsonMissingPath('token')->assertJsonPath('ageVerificationRequired', true);
        $this->getJson('/auth/onboarding')->assertOk()->assertJsonPath('draft.fullName', 'DOB Student')
            ->assertJsonPath('registrationCompleted', false)->assertJsonPath('nextScreen', 'age_verification');
        $this->postJson($endpoint, $this->form())->assertStatus(409)->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('nextStepEndpoint', '/auth/date-of-birth');
        $this->patchJson('/auth/onboarding', ['fullName' => 'Unsaved'])->assertStatus(409);
    }

    public function test_adult_dob_completes_student_registration_once_and_allows_login(): void
    {
        $this->postJson('/auth/register', $this->form())->assertOk();
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])->assertOk()
            ->assertJsonPath('registrationCompleted', true)->assertJsonPath('profileCompleted', true)
            ->assertJsonPath('ageVerificationRequired', false)->assertJsonPath('nextScreen', 'home');
        $this->assertNotNull(DB::table('users')->where('id', $this->userId)->value('profile_completed_at'));
        $this->getJson('/auth/onboarding')->assertOk()->assertJsonPath('registrationCompleted', true)
            ->assertJsonPath('autosaveEnabled', true)->assertJsonPath('missingFields', []);
        $this->postJson('/auth/login', ['email' => 'dob@example.test', 'password' => 'test-password'])
            ->assertOk()->assertJsonStructure(['token'])->assertJsonPath('nextScreen', 'home');
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])->assertOk();
        $this->assertSame(1, DB::table('business_activity')->where('event', 'registration.completed')->count());
    }

    public function test_invalid_or_underage_dob_and_missing_guardian_do_not_complete_registration(): void
    {
        $this->postJson('/auth/register', $this->form())->assertOk();
        $this->postJson('/auth/date-of-birth', [])->assertUnprocessable();
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->addDay()->toDateString()])->assertUnprocessable();
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(10)->toDateString()])->assertStatus(400);
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(16)->toDateString()])->assertStatus(400);
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('date_of_birth'));
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('profile_completed_at'));
        $this->assertSame(0, DB::table('business_activity')->whereIn('event', ['onboarding.age_verified', 'registration.completed'])->count());
        Queue::assertNothingPushed();
    }

    public function test_minor_dob_completes_registration_and_keeps_guardian_consent_as_the_next_step(): void
    {
        $this->postJson('/auth/register', $this->form())->assertOk();
        $this->postJson('/auth/date-of-birth', [
            'dateOfBirth' => now()->subYears(16)->toDateString(),
            'guardianName' => 'Guardian', 'guardianContact' => 'guardian@example.test',
        ])->assertOk()->assertJsonPath('registrationCompleted', true)
            ->assertJsonPath('isMinor', true)->assertJsonPath('parentalConsentPending', true)
            ->assertJsonPath('nextScreen', 'guardian_consent')->assertJsonMissingPath('parental_consent_token');
        Queue::assertPushed(SendNotificationEmail::class, fn ($job) => $job->afterCommit === true);
        $saved = DB::table('users')->where('id', $this->userId)->first();
        $this->postJson('/auth/parental-consent/confirm', ['token' => $saved->parental_consent_token])->assertOk();
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('registrationCompleted', true)
            ->assertJsonPath('parentalConsentPending', false)->assertJsonPath('nextScreen', 'home');
        $history = json_encode(DB::table('business_activity')->get());
        $this->assertStringNotContainsString('guardian@example.test', $history);
        $this->assertStringNotContainsString($saved->parental_consent_token, $history);
    }

    #[DataProvider('providerRoles')]
    public function test_teacher_and_institute_complete_registration_without_calling_dob(string $role): void
    {
        $form = [...$this->form(), 'accountType' => $role, 'city' => 'Jaipur', 'contactPerson' => 'Contact'];
        if ($role === 'teacher') {
            $domain = DB::table('subject_domains')->insertGetId(['name' => 'DOB test subjects']);
            $form['primarySubjectId'] = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Mathematics']);
        }
        $this->postJson('/auth/register', $form)->assertOk()->assertJsonPath('registrationCompleted', true)
            ->assertJsonPath('ageVerificationRequired', false)->assertJsonPath('nextScreen', 'home');
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('date_of_birth'));
        $this->assertNotNull(DB::table('users')->where('id', $this->userId)->value('profile_completed_at'));
        $this->assertSame(1, DB::table('business_activity')->where('event', 'registration.completed')->count());
        $this->postJson('/auth/login', ['email' => 'dob@example.test', 'password' => 'test-password'])->assertOk();
    }

    public function test_dob_alone_does_not_complete_registration_without_the_required_form(): void
    {
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])->assertOk()
            ->assertJsonPath('registrationCompleted', false)->assertJsonPath('registrationSubmitted', false)
            ->assertJsonPath('nextScreen', 'onboarding');
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('profile_completed_at'));
        $this->postJson('/auth/register', $this->form())->assertOk()->assertJsonPath('registrationCompleted', true);
    }

    public function test_otp_resume_preserves_saved_student_form_and_routes_to_pending_dob(): void
    {
        $this->postJson('/auth/register', $this->form())->assertOk();
        DB::table('otp_codes')->insert([
            'id' => (string) Str::uuid(), 'phone' => '+919876543210',
            'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5),
        ]);
        $this->withoutHeader('Authorization');
        $verified = $this->postJson('/auth/otp/verify', [
            'authMethod' => 'phone_otp', 'phone' => '+919876543210', 'code' => '123456',
        ])->assertOk()->assertJsonPath('user.id', $this->userId)->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('nextScreen', 'age_verification');
        $this->withToken($verified->json('token'))->getJson('/auth/onboarding')->assertOk()
            ->assertJsonPath('draft.fullName', 'DOB Student')->assertJsonPath('nextStep', 'age_verification');
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_legacy_completed_timestamp_without_student_dob_does_not_allow_login(): void
    {
        DB::table('users')->where('id', $this->userId)->update([
            'full_name' => 'DOB Student', 'email' => 'dob@example.test',
            'password_hash' => Hash::make('test-password'), 'profile_completed_at' => now()->subDay(),
        ]);
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('nextStep', 'age_verification');
        $this->postJson('/auth/login', ['email' => 'dob@example.test', 'password' => 'test-password'])
            ->assertStatus(409)->assertJsonMissingPath('token');
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])->assertOk()
            ->assertJsonPath('registrationCompleted', true);
    }

    public function test_completion_history_failure_rolls_back_dob_completion_and_guardian_notification(): void
    {
        $this->postJson('/auth/register', $this->form())->assertOk();
        $failHistory = true;
        DB::listen(function ($query) use (&$failHistory): void {
            if ($failHistory && str_starts_with($query->sql, 'insert into `business_activity`')
                && in_array('registration.completed', $query->bindings, true)) {
                throw new \RuntimeException('Simulated completion history failure');
            }
        });
        $this->postJson('/auth/date-of-birth', [
            'dateOfBirth' => now()->subYears(16)->toDateString(),
            'guardianName' => 'Guardian', 'guardianContact' => 'guardian@example.test',
        ])->assertStatus(500);
        $failHistory = false;
        $saved = DB::table('users')->where('id', $this->userId)->first();
        $this->assertNull($saved->date_of_birth);
        $this->assertNull($saved->profile_completed_at);
        $this->assertNull($saved->parental_consent_token);
        $this->assertSame(0, DB::table('business_activity')->whereIn('event', ['onboarding.age_verified', 'registration.completed'])->count());
        Queue::assertNothingPushed();
    }

    public function test_registration_reports_match_dob_completion_and_export_the_saved_form_timestamp(): void
    {
        $this->postJson('/auth/register', $this->form())->assertOk();
        $this->get('/admin/reports/users?export=1')->assertRedirect(route('admin.login'));
        $this->withSession(['admin_authenticated' => true]);
        $this->get(route('admin.dashboard'))->assertOk()->assertViewHas('registered', 0);
        $csv = $this->get('/admin/reports/users?q=dob@example.test&export=1')->assertOk()->streamedContent();
        $lines = explode("\n", trim($csv));
        $row = array_combine(str_getcsv($lines[0]), str_getcsv($lines[1]));
        $this->assertNotEmpty($row['registration_submitted_at']);
        $this->assertSame('0', $row['registration_completed']);
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])->assertOk();
        $this->get(route('admin.dashboard'))->assertOk()->assertViewHas('registered', 1);
        $csv = $this->get('/admin/reports/users?q=dob@example.test&export=1')->assertOk()->streamedContent();
        $lines = explode("\n", trim($csv));
        $row = array_combine(str_getcsv($lines[0]), str_getcsv($lines[1]));
        $this->assertSame('1', $row['registration_completed']);
        $this->assertSame(2, DB::table('admin_report_access_log')->where('action', 'export')->count());
    }

    public static function registrationEndpoints(): array
    {
        return [['/auth/register'], ['/auth/onboarding/complete']];
    }

    public static function providerRoles(): array
    {
        return [['teacher'], ['institute']];
    }

    private function form(): array
    {
        return [
            'accountType' => 'student', 'fullName' => 'DOB Student', 'email' => 'dob@example.test',
            'password' => 'test-password', 'termsAccepted' => true,
            'acknowledgeTeachersNotBackgroundChecked' => true,
        ];
    }
}
