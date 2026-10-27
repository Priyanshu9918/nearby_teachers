<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class StudentRegistrationCompletionTest extends TestCase
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
            'database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database,
            'services.nearby_teacher.jwt_secret' => str_repeat('dob-registration-secret-', 3),
        ]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->userId = NumericTestIds::next();
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
            ->assertJsonPath('autosaveEnabled', false)->assertJsonPath('missingFields', [])
            ->assertJsonPath('canSubmit', false)->assertJsonMissingPath('errors.dateOfBirth');
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
            ->assertJsonPath('registrationCompleted', false)->assertJsonPath('nextScreen', 'age_verification')
            ->assertJsonPath('missingFields', []);
        $this->postJson($endpoint, $this->form())->assertOk()->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('nextStepEndpoint', '/auth/date-of-birth')->assertJsonMissingPath('error');
        $this->assertSame(1, DB::table('business_activity')->where('event', 'registration.submitted')->count());
        $this->patchJson('/auth/onboarding', ['fullName' => 'Unsaved'])->assertStatus(409);
    }

    public function test_dob_is_saved_only_by_its_separate_api_not_the_registration_form(): void
    {
        $this->postJson('/auth/register', [...$this->form(), 'dateOfBirth' => '2000-01-01'])
            ->assertOk()->assertJsonPath('registrationSubmitted', true)
            ->assertJsonPath('registrationCompleted', false)->assertJsonPath('nextScreen', 'age_verification')
            ->assertJsonPath('missingFields', []);
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('date_of_birth'));
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])
            ->assertOk()->assertJsonPath('registrationCompleted', true);
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

    public function test_invalid_or_underage_dob_does_not_complete_registration(): void
    {
        $this->postJson('/auth/register', $this->form())->assertOk();
        $this->postJson('/auth/date-of-birth', [])->assertUnprocessable();
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->addDay()->toDateString()])->assertUnprocessable();
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(10)->toDateString()])->assertStatus(400);
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('date_of_birth'));
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('profile_completed_at'));
        $this->assertSame(0, DB::table('business_activity')->whereIn('event', ['onboarding.age_verified', 'registration.completed'])->count());
        Queue::assertNothingPushed();
    }

    public function test_minor_dob_opens_parent_form_and_details_generate_consent_link(): void
    {
        $this->postJson('/auth/register', $this->form())->assertOk();
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(16)->toDateString()])
            ->assertOk()->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('profileCompleted', false)->assertJsonPath('onboardingRequired', true)
            ->assertJsonPath('isMinor', true)->assertJsonPath('parentalConsentStatus', 'details_required')
            ->assertJsonPath('guardianDetailsRequired', true)->assertJsonPath('parentalConsentRequired', true)
            ->assertJsonPath('nextScreen', 'guardian_details')->assertJsonPath('nextStepEndpoint', '/auth/guardian-details')
            ->assertJsonMissingPath('parentalConsent');
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('parental_consent_token'));
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('profile_completed_at'));
        $this->assertSame(0, DB::table('business_activity')->where('event', 'registration.completed')->count());
        $this->assertSame(0, DB::table('business_activity')->where('event', 'parental_consent.requested')->count());
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('nextScreen', 'guardian_details');
        $this->postJson('/auth/login', ['email' => 'dob@example.test', 'password' => 'test-password'])
            ->assertOk()->assertJsonPath('guardianDetailsRequired', true)->assertJsonPath('nextScreen', 'guardian_details')
            ->assertJsonPath('profileCompleted', false)->assertJsonPath('registrationCompleted', false);
        $this->getJson('/auth/onboarding')->assertOk()->assertJsonPath('profileCompleted', false)
            ->assertJsonPath('autosaveEnabled', false)->assertJsonPath('nextScreen', 'guardian_details');
        $this->patchJson('/auth/onboarding', ['fullName' => 'Changed'])->assertStatus(409);
        $this->postJson('/auth/register', [])->assertOk()->assertJsonPath('nextScreen', 'guardian_details');
        $this->postJson('/messages/start', [])->assertForbidden();
        $this->postJson('/bookings', [])->assertForbidden();
        $details = $this->postJson('/auth/guardian-details', [
            'guardianName' => 'Guardian', 'guardianContact' => '+919876543211',
        ])->assertOk()->assertJsonPath('parentalConsentStatus', 'pending')
            ->assertJsonPath('profileCompleted', false)->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('guardianDetailsRequired', false)->assertJsonPath('nextScreen', 'guardian_consent');
        Queue::assertNothingPushed();
        $token = basename($details->json('parentalConsent.link'));
        $this->assertSame('+919876543211', $details->json('parentalConsent.parent.phone'));
        $saved = DB::table('users')->where('id', $this->userId)->first();
        $this->postJson('/auth/parental-consent/confirm', ['token' => $token])->assertOk();
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('registrationCompleted', true)
            ->assertJsonPath('parentalConsentStatus', 'approved')->assertJsonPath('nextScreen', 'home');
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(16)->toDateString()])
            ->assertOk()->assertJsonPath('parentalConsentStatus', 'approved')->assertJsonMissingPath('parentalConsent');
        $this->assertSame(1, DB::table('business_activity')->where('event', 'parental_consent.requested')->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'registration.completed')->count());
        $this->assertNotNull(DB::table('users')->where('id', $this->userId)->value('profile_completed_at'));
        $history = json_encode(DB::table('business_activity')->get());
        $this->assertStringNotContainsString('+919876543211', $history);
        $this->assertStringNotContainsString($saved->parental_consent_token, $history);
    }

    #[DataProvider('providerRoles')]
    public function test_teacher_and_institute_complete_registration_without_calling_dob(string $role): void
    {
        $form = [...$this->form(), 'accountType' => $role, 'city' => 'Jaipur', 'contactPerson' => 'Contact'];
        if ($role === 'teacher') {
            $domain = DB::table('subject_domains')->insertGetId(['name' => 'DOB test subjects']);
            $form['primarySubjects'] = [DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Mathematics'])];
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
            'id' => NumericTestIds::next(), 'phone' => '+919876543210',
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

    public function test_completion_history_failure_rolls_back_adult_dob_completion(): void
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
            'dateOfBirth' => '2000-01-01',
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

    public function test_parent_page_is_read_only_and_approval_is_one_time_and_reportable(): void
    {
        $link = $this->minorConsentLink();
        $this->get($link)->assertOk()->assertSee('Approve')->assertSee('Reject consent')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY');
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('parentalConsentStatus', 'pending');
        $token = basename($link);
        $this->assertSame(hash('sha256', $token), DB::table('users')->where('id', $this->userId)->value('parental_consent_token'));
        $this->post($link, ['decision' => 'approve'])->assertOk()->assertSee('Consent approved');
        $this->post($link, ['decision' => 'reject'])->assertNotFound();
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('parentalConsentStatus', 'approved')
            ->assertJsonPath('parentalConsentRequired', false)->assertJsonPath('nextScreen', 'home');
        $this->assertSame(1, DB::table('business_activity')->where('event', 'parental_consent.decided')->count());
        $history = json_encode(DB::table('business_activity')->get());
        $this->assertStringNotContainsString($token, $history);
        $this->get('/admin/reports/users?export=1')->assertRedirect(route('admin.login'));
        $this->withSession(['admin_authenticated' => true]);
        $csv = $this->get('/admin/reports/users?q=dob@example.test&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('parental_consent_rejected_at', $csv);
        $this->assertStringNotContainsString($token, $csv);
        $this->assertStringNotContainsString(hash('sha256', $token), $csv);
        $this->get('/admin/reports/activity?q=parental_consent.decided')->assertOk()->assertSee('parental_consent.decided');
        $this->assertSame(2, DB::table('admin_report_access_log')->count());
    }

    public function test_rejection_is_visible_in_login_and_keeps_messaging_and_booking_blocked(): void
    {
        $link = $this->minorConsentLink();
        $this->post($link, ['decision' => 'reject'])->assertOk()->assertSee('Consent rejected');
        $this->postJson('/auth/login', ['email' => 'dob@example.test', 'password' => 'test-password'])
            ->assertOk()->assertJsonPath('status', 'reject')->assertJsonPath('parentalConsentStatus', 'rejected')
            ->assertJsonPath('profileCompleted', false)->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('parentalConsentPending', false)->assertJsonPath('parentalConsentRequired', true)
            ->assertJsonPath('nextScreen', 'guardian_consent')->assertJsonMissingPath('user.parental_consent_token');
        $this->postJson('/messages/start', ['providerId' => $this->userId + 1])->assertStatus(403);
        $this->postJson('/bookings', [])->assertStatus(403);
        $this->get($link)->assertNotFound();
    }

    public function test_expired_and_replaced_links_cannot_make_a_decision(): void
    {
        $old = $this->minorConsentLink();
        $oldHash = DB::table('users')->where('id', $this->userId)->value('parental_consent_token');
        $this->assertSame(hash('sha256', basename($old)), $oldHash);
        $new = $this->minorConsentLink(false);
        $newHash = DB::table('users')->where('id', $this->userId)->value('parental_consent_token');
        $this->assertNotSame($old, $new);
        $this->assertNotSame($oldHash, $newHash);
        $this->assertSame(hash('sha256', basename($new)), $newHash);
        $this->get($old)->assertNotFound();
        $this->postJson('/auth/parental-consent/confirm', ['token' => basename($old), 'decision' => 'approve'])
            ->assertNotFound();
        $this->get($new)->assertOk();
        $this->travel(49)->hours();
        $this->get($new)->assertStatus(410);
        $this->postJson('/auth/parental-consent/confirm', ['token' => basename($new), 'decision' => 'approve'])->assertStatus(410);
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('parentalConsentStatus', 'expired')
            ->assertJsonPath('parentalConsentRequired', true)->assertJsonPath('nextScreen', 'guardian_consent')
            ->assertJsonPath('profileCompleted', false)->assertJsonPath('registrationCompleted', false);
        $this->travelBack();
    }

    public function test_minor_cannot_change_dob_to_bypass_consent_and_contact_must_be_phone(): void
    {
        $this->minorConsentLink();
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])->assertStatus(409);
        $this->postJson('/auth/guardian-details', [
            'guardianName' => 'Guardian', 'guardianContact' => 'guardian@example.test',
        ])->assertUnprocessable();
        $this->getJson('/auth/session')->assertJsonPath('parentalConsentStatus', 'pending');
    }

    public function test_decision_and_request_rollback_when_history_cannot_be_recorded(): void
    {
        $link = $this->minorConsentLink();
        $failHistory = true;
        DB::listen(function ($query) use (&$failHistory): void {
            if ($failHistory && str_starts_with($query->sql, 'insert into `business_activity`')
                && in_array('parental_consent.decided', $query->bindings, true)) {
                throw new \RuntimeException('Simulated consent history failure');
            }
        });
        $this->postJson('/auth/parental-consent/confirm', ['token' => basename($link), 'decision' => 'reject'])->assertStatus(500);
        $failHistory = false;
        $this->getJson('/auth/session')->assertJsonPath('parentalConsentStatus', 'pending');
        $this->get($link)->assertOk();
        $this->postJson('/auth/parental-consent/confirm', ['token' => basename($link), 'decision' => 'approve'])
            ->assertOk()->assertJsonPath('parentalConsentStatus', 'approved');
    }

    #[DataProvider('consentAgeBoundaries')]
    public function test_consent_age_boundaries(int $years, bool $minor): void
    {
        $this->postJson('/auth/register', $this->form())->assertOk();
        $this->postJson('/auth/date-of-birth', [
            'dateOfBirth' => now()->subYears($years)->toDateString(),
        ])->assertOk()->assertJsonPath('isMinor', $minor)
            ->assertJsonPath('parentalConsentStatus', $minor ? 'details_required' : 'not_required')
            ->assertJsonPath('guardianDetailsRequired', $minor);
    }

    public static function consentAgeBoundaries(): array
    {
        return [[13, true], [17, true], [18, false]];
    }

    public function test_parent_decision_page_enforces_csrf_and_escapes_names(): void
    {
        $link = $this->minorConsentLink();
        DB::table('users')->where('id', $this->userId)->update(['full_name' => '<script>alert(1)</script>']);
        $this->get($link)->assertOk()->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        // Laravel normally bypasses CSRF in tests; enable its production branch.
        $this->app['env'] = 'production';
        $this->post($link, ['decision' => 'approve'])->assertStatus(419);
        $this->withSession(['_token' => 'csrf-consent-test'])
            ->post($link, ['decision' => 'approve', '_token' => 'csrf-consent-test'])
            ->assertOk()->assertSee('Consent approved');
    }

    public function test_guardian_details_require_minor_dob_authentication_and_valid_parent_form(): void
    {
        $details = ['guardianName' => 'Guardian', 'guardianContact' => '+919876543211'];
        $this->postJson('/auth/guardian-details', $details)->assertStatus(409);
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(16)->toDateString()])->assertOk();
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(16)->toDateString(), ...$details])
            ->assertUnprocessable();
        $this->postJson('/auth/guardian-details', [])->assertUnprocessable();
        $this->postJson('/auth/guardian-details', [...$details, 'guardianName' => ' '])->assertUnprocessable();
        $this->postJson('/auth/guardian-details', [...$details, 'guardianContact' => 'bad-phone'])->assertUnprocessable()->assertJsonValidationErrors('guardianContact');
        $this->withoutHeader('Authorization')->postJson('/auth/guardian-details', $details)->assertUnauthorized();
        $this->assertSame(0, DB::table('business_activity')->where('event', 'parental_consent.requested')->count());
    }

    public function test_adult_cannot_create_guardian_request(): void
    {
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])->assertOk();
        $this->postJson('/auth/guardian-details', ['guardianName' => 'Guardian', 'guardianContact' => '+919876543211'])
            ->assertStatus(409);
    }

    public function test_guardian_request_rolls_back_parent_and_token_on_history_failure(): void
    {
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(16)->toDateString()])->assertOk();
        $failHistory = true;
        DB::listen(function ($query) use (&$failHistory): void {
            if ($failHistory && str_starts_with($query->sql, 'insert into `business_activity`')
                && in_array('parental_consent.requested', $query->bindings, true)) {
                throw new \RuntimeException('Simulated guardian request history failure');
            }
        });
        $this->postJson('/auth/guardian-details', ['guardianName' => 'Guardian', 'guardianContact' => '+919876543211'])
            ->assertStatus(500);
        $failHistory = false;
        $saved = DB::table('users')->where('id', $this->userId)->first();
        $this->assertNull($saved->guardian_name);
        $this->assertNull($saved->guardian_contact);
        $this->assertNull($saved->parental_consent_token);
        $this->assertNull($saved->parental_consent_expires_at);
        $this->getJson('/auth/session')->assertJsonPath('guardianDetailsRequired', true);
    }

    public function test_consent_status_polling_is_private_read_only_and_observes_parent_approval(): void
    {
        $link = $this->minorConsentLink();
        $before = (array) DB::table('users')->where('id', $this->userId)->first();
        $historyCount = DB::table('business_activity')->count();
        foreach (range(1, 3) as $i) {
            $this->getJson('/auth/parental-consent/status?userId=999999')
                ->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('parentalConsentStatus', 'pending')
                ->assertJsonPath('parent.phone', '+919876543211')->assertJsonPath('nextScreen', 'guardian_consent')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonMissingPath('token')->assertJsonMissingPath('link')->assertJsonMissingPath('user')
                ->assertDontSee(basename($link))->assertDontSee($before['parental_consent_token']);
        }
        $this->assertSame($before, (array) DB::table('users')->where('id', $this->userId)->first());
        $this->assertSame($historyCount, DB::table('business_activity')->count());
        $this->post($link, ['decision' => 'approve'])->assertOk();
        $this->getJson('/auth/parental-consent/status')->assertOk()->assertJsonPath('status', 'approve')
            ->assertJsonPath('parentalConsentStatus', 'approved')->assertJsonPath('parentalConsentRequired', false)
            ->assertJsonPath('nextScreen', 'home');
    }

    public function test_consent_status_distinguishes_rejected_expired_and_missing_details(): void
    {
        $this->postJson('/auth/register', $this->form())->assertOk();
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(16)->toDateString()])->assertOk();
        $this->getJson('/auth/parental-consent/status')->assertOk()->assertJsonPath('status', 'pending')
            ->assertJsonPath('parentalConsentStatus', 'details_required')->assertJsonPath('guardianDetailsRequired', true)
            ->assertJsonPath('parent', null)->assertJsonPath('nextScreen', 'guardian_details');
        $link = $this->minorConsentLink(false);
        $this->post($link, ['decision' => 'reject'])->assertOk();
        $this->getJson('/auth/parental-consent/status')->assertOk()->assertJsonPath('status', 'reject')
            ->assertJsonPath('parentalConsentStatus', 'rejected')->assertJsonPath('parentalConsentRequired', true);
        $this->minorConsentLink(false);
        $this->travel(49)->hours();
        $this->getJson('/auth/parental-consent/status')->assertOk()->assertJsonPath('status', 'pending')
            ->assertJsonPath('parentalConsentStatus', 'expired')->assertJsonPath('parentalConsentRequired', true);
        $this->travelBack();
    }

    public function test_consent_status_requires_auth_and_dob_and_handles_adult_learners(): void
    {
        $this->getJson('/auth/parental-consent/status')->assertStatus(409)->assertJsonPath('code', 'DOB_REQUIRED');
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])->assertOk();
        $this->getJson('/auth/parental-consent/status')->assertOk()->assertJsonPath('status', 'not_required')
            ->assertJsonPath('parentalConsentRequired', false);
        $this->withoutHeader('Authorization')->getJson('/auth/parental-consent/status')->assertUnauthorized();
    }

    #[DataProvider('providerRoles')]
    public function test_providers_cannot_poll_learner_consent(string $role): void
    {
        DB::table('users')->where('id', $this->userId)->update(['account_type' => $role]);
        $this->getJson('/auth/parental-consent/status')->assertForbidden();
    }

    public function test_approval_completes_registration_and_reports_only_when_parent_approves(): void
    {
        $link = $this->minorConsentLink();
        $this->withSession(['admin_authenticated' => true]);
        $this->get(route('admin.dashboard'))->assertOk()->assertViewHas('registered', 0);
        $csv = $this->get('/admin/reports/users?q=dob@example.test&export=1')->assertOk()->streamedContent();
        $lines = explode("\n", trim($csv));
        $row = array_combine(str_getcsv($lines[0]), str_getcsv($lines[1]));
        $this->assertSame('0', $row['registration_completed']);
        $this->assertSame(0, DB::table('business_activity')->where('event', 'registration.completed')->count());
        $this->post($link, ['decision' => 'approve'])->assertOk();
        $this->getJson('/auth/parental-consent/status')->assertOk()->assertJsonPath('profileCompleted', true)
            ->assertJsonPath('registrationCompleted', true)->assertJsonPath('onboardingRequired', false);
        $this->get(route('admin.dashboard'))->assertOk()->assertViewHas('registered', 1);
        $csv = $this->get('/admin/reports/users?q=dob@example.test&export=1')->assertOk()->streamedContent();
        $lines = explode("\n", trim($csv));
        $row = array_combine(str_getcsv($lines[0]), str_getcsv($lines[1]));
        $this->assertSame('1', $row['registration_completed']);
        $this->assertSame(1, DB::table('business_activity')->where('event', 'registration.completed')->count());
        // A replacement request removes effective completion, including legacy timestamps.
        $this->minorConsentLink(false);
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('profileCompleted', false)
            ->assertJsonPath('registrationCompleted', false)->assertJsonPath('nextScreen', 'guardian_consent');
        $this->get(route('admin.dashboard'))->assertOk()->assertViewHas('registered', 0);
    }

    public function test_completion_history_failure_rolls_back_parent_approval_and_keeps_link_usable(): void
    {
        $link = $this->minorConsentLink();
        $failHistory = true;
        DB::listen(function ($query) use (&$failHistory): void {
            if ($failHistory && str_starts_with($query->sql, 'insert into `business_activity`')
                && in_array('registration.completed', $query->bindings, true)) {
                throw new \RuntimeException('Simulated guardian completion failure');
            }
        });
        $this->postJson('/auth/parental-consent/confirm', ['token' => basename($link), 'decision' => 'approve'])
            ->assertStatus(500);
        $failHistory = false;
        $saved = DB::table('users')->where('id', $this->userId)->first();
        $this->assertNull($saved->profile_completed_at);
        $this->assertNull($saved->parental_consent_confirmed_at);
        $this->assertSame(hash('sha256', basename($link)), $saved->parental_consent_token);
        $this->assertSame(0, DB::table('business_activity')->where('event', 'registration.completed')->count());
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('profileCompleted', false);
        $this->post($link, ['decision' => 'approve'])->assertOk();
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('profileCompleted', true);
    }

    public function test_parent_approval_before_form_does_not_prematurely_complete_registration(): void
    {
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(16)->toDateString()])->assertOk();
        $response = $this->postJson('/auth/guardian-details', [
            'guardianName' => 'Guardian', 'guardianContact' => '+919876543211',
        ])->assertOk();
        $this->post($response->json('parentalConsent.link'), ['decision' => 'approve'])->assertOk();
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('profileCompleted', false)
            ->assertJsonPath('nextScreen', 'onboarding');
        $this->postJson('/auth/register', $this->form())->assertOk()->assertJsonPath('profileCompleted', true)
            ->assertJsonPath('registrationCompleted', true)->assertJsonPath('nextScreen', 'home');
        $this->assertSame(1, DB::table('business_activity')->where('event', 'registration.completed')->count());
    }

    private function minorConsentLink(bool $register = true): string
    {
        if ($register) {
            $this->postJson('/auth/register', $this->form())->assertOk();
        }
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => now()->subYears(16)->toDateString()])->assertOk();
        $response = $this->postJson('/auth/guardian-details', [
            'guardianName' => 'Guardian', 'guardianContact' => '+919876543211',
        ])->assertOk()->assertJsonPath('parentalConsentStatus', 'pending')
            ->assertJsonPath('parentalConsent.parent.name', 'Guardian')
            ->assertJsonPath('parentalConsent.parent.phone', '+919876543211');
        Queue::assertNothingPushed();

        return $response->json('parentalConsent.link');
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
