<?php

namespace Tests\Feature;

use App\Reporting\BusinessActivityRecorder;
use App\Services\AuthenticationService;
use App\Services\OtpDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class OnboardingPersistenceTest extends TestCase
{
    private int $userId;

    private bool $transactionStarted = false;

    private string $lastOtp;

    protected function setUp(): void
    {
        parent::setUp();

        // Opt in only against a disposable database, never the application's database.
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set ONBOARDING_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config([
            'database.connections.mysql.url' => null,
            'database.connections.mysql.database' => $database,
        ]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;

        $this->userId = NumericTestIds::next();
        DB::table('users')->insert([
            'id' => $this->userId,
            'account_type' => 'learner',
            'full_name' => 'New user',
            'phone' => '+918112912880',
            'phone_verified_at' => now(),
        ]);

        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->with('onboarding-token')
            ->andReturnUsing(function () {
                return (new AuthenticationService)->publicUser(DB::table('users')->where('id', $this->userId)->first());
            });
        $this->app->instance(AuthenticationService::class, $authentication);
        $this->withHeader('Authorization', 'Bearer onboarding-token');
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_registration_blocks_autosave_until_full_form_is_submitted(): void
    {
        $this->patchJson('/auth/onboarding', $this->sharedFields())
            ->assertStatus(409)->assertJsonPath('autosaveEnabled', false);
        $this->putJson('/profiles/me', ['city' => 'Delhi'])->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $this->userId, 'full_name' => 'New user', 'email' => null]);

        $this->postJson('/auth/register', $this->sharedFields())
            ->assertOk()->assertJsonPath('registrationCompleted', false)
            ->assertJsonPath('autosaveEnabled', false)->assertJsonPath('nextStep', 'age_verification')
            ->assertJsonMissingPath('user.password_hash')->assertJsonMissingPath('draft.password');
        $user = DB::table('users')->where('id', $this->userId)->first();
        $this->assertTrue(Hash::check('a-secure-password', $user->password_hash));
        $this->assertNull($user->profile_completed_at);
        $this->assertNotNull($user->registration_submitted_at);
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])
            ->assertOk()->assertJsonPath('registrationCompleted', true);
        $this->patchJson('/auth/onboarding', ['fullName' => 'Updated name'])
            ->assertOk()->assertJsonPath('draft.fullName', 'Updated name');
        $this->getJson('/auth/onboarding')->assertOk()->assertJsonPath('draft.fullName', 'Updated name');
        $this->postJson('/auth/register', $this->sharedFields())->assertStatus(409);
    }

    public function test_existing_incomplete_teacher_details_are_restored_without_new_autosave(): void
    {
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Saved subjects']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Saved Mathematics']);
        DB::table('users')->where('id', $this->userId)->update([
            'account_type' => 'teacher', 'full_name' => 'Saved Teacher', 'email' => 'saved@example.test',
        ]);
        DB::table('teacher_profiles')->insert([
            'id' => NumericTestIds::next(), 'user_id' => $this->userId, 'lesson_modes' => '[]',
            'city' => 'Jaipur', 'primary_subject_id' => $subject,
        ]);
        $this->getJson('/auth/onboarding')->assertOk()
            ->assertJsonPath('registrationCompleted', false)->assertJsonPath('autosaveEnabled', false)
            ->assertJsonPath('draft.role', 'teacher')->assertJsonPath('draft.fullName', 'Saved Teacher')
            ->assertJsonPath('draft.email', 'saved@example.test')->assertJsonPath('draft.city', 'Jaipur')
            ->assertJsonPath('draft.primarySubjects', [$subject])->assertJsonMissingPath('draft.primarySubjectId');
        $this->patchJson('/auth/onboarding', ['city' => 'Delhi'])->assertStatus(409);
        $this->assertSame('Jaipur', DB::table('teacher_profiles')->where('user_id', $this->userId)->value('city'));
    }

    public function test_teacher_registration_and_post_registration_tab_details(): void
    {
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Test subjects']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Mathematics']);
        $this->postJson('/auth/register', array_replace($this->sharedFields(), [
            'accountType' => 'teacher', 'city' => 'Jaipur', 'primarySubjects' => [$subject],
        ]))->assertOk()->assertJsonPath('nextStep', 'home');
        $this->assertDatabaseHas('profile_subjects', [
            'profile_type' => 'teacher', 'profile_id' => $this->userId, 'subject_id' => $subject,
        ]);
        $this->patchJson('/auth/onboarding', [
            'accountType' => 'institute', 'city' => 'Delhi', 'contactPerson' => 'Anita Sharma',
        ])->assertOk()->assertJsonPath('draft.contactPerson', 'Anita Sharma');
        $this->patchJson('/auth/onboarding', ['accountType' => 'teacher'])
            ->assertOk()->assertJsonPath('draft.city', 'Jaipur')->assertJsonPath('draft.primarySubjects', [$subject]);
        $this->patchJson('/auth/onboarding', ['accountType' => 'institute'])
            ->assertOk()->assertJsonPath('draft.city', 'Delhi');
    }

    public function test_institute_registration_is_atomic_and_requires_contact_and_city(): void
    {
        $data = array_replace($this->sharedFields(), ['accountType' => 'institute']);
        $this->postJson('/auth/register', $data)->assertUnprocessable()
            ->assertJsonValidationErrors(['city', 'contactPerson']);
        $this->assertDatabaseMissing('institute_profiles', ['user_id' => $this->userId]);
        $this->assertDatabaseHas('users', ['id' => $this->userId, 'email' => null, 'profile_completed_at' => null]);
        $this->postJson('/auth/register', array_replace($data, ['city' => 'Delhi', 'contactPerson' => 'Anita']))
            ->assertOk()->assertJsonPath('draft.contactPerson', 'Anita');
        $this->patchJson('/auth/onboarding', ['city' => null])->assertOk()->assertJsonPath('draft.city', null);
    }

    public function test_registration_rejects_duplicate_email_and_phone_verification_overrides(): void
    {
        DB::table('users')->insert([
            'id' => NumericTestIds::next(), 'account_type' => 'learner',
            'full_name' => 'Another student', 'email' => 'taken@example.com',
        ]);
        $this->postJson('/auth/register', array_replace($this->sharedFields(), ['email' => 'taken@example.com']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseHas('users', ['id' => $this->userId, 'full_name' => 'New user']);
        $this->postJson('/auth/register', array_replace($this->sharedFields(), [
            'phone' => '+919999999999', 'mobileVerified' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['phone', 'mobileVerified']);
    }

    public function test_teacher_cannot_register_without_subjects(): void
    {
        $this->postJson('/auth/register', array_replace($this->sharedFields(), [
            'accountType' => 'teacher', 'city' => 'Jaipur',
        ]))->assertUnprocessable()->assertJsonValidationErrors('primarySubjects');
    }

    private function teachingSubjects(): array
    {
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Registration subjects']);

        return array_map(fn ($name) => DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => $name]),
            ['Maths', 'Physics', 'Chemistry', 'English', 'Hindi', 'Biology']);
    }

    public function test_registration_accepts_five_subjects_without_a_single_primary(): void
    {
        $ids = array_slice($this->teachingSubjects(), 0, 5);
        $result = $this->postJson('/auth/register', array_replace($this->sharedFields(), [
            'accountType' => 'teacher', 'city' => 'Jaipur', 'primarySubjects' => $ids,
        ]))->assertOk()->assertJsonMissingPath('draft.primarySubjectId')
            ->assertJsonPath('draft.primarySubjects', $ids)->json();
        $this->assertSame(5, DB::table('profile_subjects')->where('profile_type', 'teacher')->where('profile_id', $this->userId)->count());
        $this->assertStringContainsString('chemistry-english-hindi-maths-physics', $result['teacherSeoUrl']);
        $this->getJson('/auth/onboarding')->assertOk()->assertJsonPath('draft.primarySubjects', $ids);
        $event = DB::table('business_activity')->where('event', 'teacher.pages_published')->where('entity_id', $this->userId)->first();
        $recorded = json_decode($event->after_data, true)['subject_ids'];
        sort($ids);
        sort($recorded);
        $this->assertSame($ids, $recorded);
    }

    public function test_registration_alias_accepts_equal_subjects_and_subject_replacement(): void
    {
        $ids = array_slice($this->teachingSubjects(), 0, 5);
        $this->postJson('/auth/onboarding/complete', array_replace($this->sharedFields(), [
            'accountType' => 'teacher', 'city' => 'Jaipur', 'primarySubjects' => $ids,
        ]))->assertOk()->assertJsonMissingPath('draft.primarySubjectId');
        $this->assertNull(DB::table('teacher_profiles')->where('user_id', $this->userId)->value('primary_subject_id'));
        $this->patchJson('/auth/onboarding', ['primarySubjects' => [$ids[3], $ids[4]]])
            ->assertOk()->assertJsonMissingPath('draft.primarySubjectId')
            ->assertJsonPath('draft.primarySubjects', [$ids[3], $ids[4]]);
        $this->assertSame(2, DB::table('profile_subjects')->where('profile_type', 'teacher')->where('profile_id', $this->userId)->count());
    }

    public function test_registration_rejects_invalid_subject_selections_without_saving(): void
    {
        $ids = $this->teachingSubjects();
        foreach ([
            [['primarySubjects' => $ids], 'primarySubjects'],
            [['primarySubjects' => []], 'primarySubjects'],
            [['primarySubjects' => [$ids[0], $ids[0]]], 'primarySubjects.0'],
            [['primarySubjects' => [0]], 'primarySubjects.0'],
            [['primarySubjects' => [$ids[0]], 'primarySubjectId' => $ids[1]], 'primarySubjectId'],
            [['primarySubjects' => '1,2'], 'primarySubjects'],
            [['primarySubjects' => [$ids[0]], 'subjectIds' => [$ids[0]]], 'subjectIds'],
        ] as [$selection, $error]) {
            $this->postJson('/auth/register', array_replace($this->sharedFields(), [
                'accountType' => 'teacher', 'city' => 'Jaipur', ...$selection,
            ]))->assertUnprocessable()->assertJsonValidationErrors($error);
            $this->assertNull(DB::table('users')->where('id', $this->userId)->value('registration_submitted_at'));
            $this->assertSame(0, DB::table('profile_subjects')->where('profile_id', $this->userId)->count());
        }
    }

    public function test_retired_primary_field_is_rejected_without_mutating_saved_subjects(): void
    {
        $ids = $this->teachingSubjects();
        $selected = array_slice($ids, 0, 5);
        $this->postJson('/auth/register', array_replace($this->sharedFields(), [
            'accountType' => 'teacher', 'city' => 'Jaipur', 'primarySubjects' => $selected,
        ]))->assertOk();
        $this->patchJson('/auth/onboarding', ['primarySubjectId' => $ids[5], 'city' => 'Delhi'])
            ->assertUnprocessable()->assertJsonValidationErrors('primarySubjectId');
        $this->assertSame('Jaipur', DB::table('teacher_profiles')->where('user_id', $this->userId)->value('city'));
        $this->getJson('/auth/onboarding')->assertOk()->assertJsonPath('draft.primarySubjects', $selected);
    }

    public function test_registration_subjects_and_completion_roll_back_when_publication_history_fails(): void
    {
        $ids = array_slice($this->teachingSubjects(), 0, 5);
        $this->app->instance(BusinessActivityRecorder::class, new class
        {
            public function record(...$arguments): never
            {
                throw new \RuntimeException('Publication history unavailable');
            }
        });
        $this->postJson('/auth/register', array_replace($this->sharedFields(), [
            'accountType' => 'teacher', 'city' => 'Jaipur', 'primarySubjects' => $ids,
        ]))->assertStatus(500);
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('profile_completed_at'));
        $this->assertFalse(DB::table('teacher_profiles')->where('user_id', $this->userId)->exists());
        $this->assertSame(0, DB::table('profile_subjects')->where('profile_id', $this->userId)->count());
    }

    public function test_otp_verification_does_not_save_form_and_registration_uses_returned_token(): void
    {
        $this->withoutHeader('Authorization');
        config([
            'services.nearby_teacher.otp_test_mode' => false,
            'services.nearby_teacher.jwt_secret' => str_repeat('test-secret-', 6),
            'services.nearby_teacher.otp_sms_provider' => 'msg91',
            'services.nearby_teacher.msg91_auth_key' => 'test-key',
            'services.nearby_teacher.msg91_template_id' => 'test-template',
        ]);
        $this->app->instance(AuthenticationService::class, new AuthenticationService);
        Http::preventStrayRequests();
        Http::fake(['https://control.msg91.com/api/v5/flow/' => Http::response(['type' => 'success'])]);
        $phone = '+919876543210';
        $this->postJson('/auth/otp/request', ['phone' => $phone])->assertOk();
        $code = Http::recorded()[0][0]->data()['recipients'][0]['code'];
        $response = $this->postJson('/auth/otp/verify', ['phone' => $phone, 'code' => $code])
            ->assertOk()->assertJsonPath('mobileVerified', true)->assertJsonMissingPath('user.password_hash');
        $id = $response->json('user.id');
        $this->assertDatabaseHas('users', ['id' => $id, 'full_name' => 'New user', 'email' => null, 'profile_completed_at' => null]);
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'));
        $this->patchJson('/auth/onboarding', ['fullName' => 'Do not save'])->assertStatus(409);
        $this->postJson('/auth/register', $this->sharedFields())->assertOk()->assertJsonPath('autosaveEnabled', false);
        $this->postJson('/auth/date-of-birth', ['dateOfBirth' => '2000-01-01'])->assertOk()->assertJsonPath('registrationCompleted', true);
        $this->withoutHeader('Authorization');
        $this->postJson('/auth/otp/verify', ['phone' => $phone, 'code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_test_mode_returns_verifiable_otp_without_sending_messages(): void
    {
        $this->withoutHeader('Authorization');
        config([
            'services.nearby_teacher.otp_test_mode' => true,
            'services.nearby_teacher.jwt_secret' => str_repeat('test-secret-', 6),
            'services.nearby_teacher.msg91_auth_key' => null,
            'services.nearby_teacher.msg91_template_id' => null,
        ]);
        $this->app->instance(AuthenticationService::class, new AuthenticationService);
        Http::fake();
        $phone = '+919876543210';
        $this->captureOtp();
        $response = $this->postJson('/auth/otp/request', ['phone' => $phone])
            ->assertOk()->assertJsonPath('testMode', true)->assertJsonPath('expiresInMinutes', 10);
        $code = $response->json('otp');
        $this->assertSame($this->lastOtp, $code);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $stored = DB::table('otp_codes')->where('phone', $phone)->first();
        $this->assertNotSame($code, $stored->code_hash);
        $this->assertTrue(Hash::check($code, $stored->code_hash));
        $this->postJson('/auth/otp/verify', ['phone' => $phone, 'code' => $code])
            ->assertOk()->assertJsonPath('mobileVerified', true)->assertJsonStructure(['token']);
        Http::assertNothingSent();
    }

    public function test_production_never_returns_otp_even_if_test_mode_is_enabled(): void
    {
        $this->withoutHeader('Authorization');
        $this->app->instance('env', 'production');
        config([
            'services.nearby_teacher.otp_test_mode' => true,
            'services.nearby_teacher.otp_sms_provider' => 'msg91',
            'services.nearby_teacher.msg91_auth_key' => 'test-key',
            'services.nearby_teacher.msg91_template_id' => 'test-template',
        ]);
        Http::fake(['https://control.msg91.com/api/v5/flow/' => Http::response(['type' => 'success'])]);
        $this->postJson('/auth/otp/request', ['phone' => '+919876543210'])
            ->assertOk()->assertJsonMissingPath('otp')->assertJsonMissingPath('testMode');
        Http::assertSentCount(1);
    }

    private function sharedFields(): array
    {
        return [
            'accountType' => 'student',
            'fullName' => 'Anita Sharma',
            'email' => 'anita@example.com',
            'password' => 'a-secure-password',
            'termsAccepted' => true,
            'acknowledgeTeachersNotBackgroundChecked' => true,
        ];
    }

    public function test_institute_registration_stores_address_and_geocoding_without_subjects(): void
    {
        config(['services.google_geocoding.enabled' => true, 'services.google_geocoding.key' => 'private-institute-test-key']);
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [[
            'place_id' => 'institute-place', 'formatted_address' => 'Private Institute Lane', 'types' => ['street_address'],
            'address_components' => [['long_name' => '001234', 'short_name' => '001234', 'types' => ['postal_code']]],
        ]]])]);
        $data = ['accountType' => 'institute', 'city' => 'Delhi', 'contactPerson' => 'Institute Contact',
            'district' => 'New Delhi', 'subDistrict' => 'Sample Tehsil', 'locality' => 'Ward 4', 'localityType' => 'ward',
            'state' => 'Delhi', 'country' => 'India', 'postalCode' => '001234', 'latitude' => 28.61, 'longitude' => 77.20];
        $result = $this->postJson('/auth/register', array_replace($this->sharedFields(), $data))->assertOk()
            ->assertJsonMissingPath('draft.primarySubjects')->assertJsonPath('draft.locationDetails.status', 'ok')
            ->assertJsonPath('draft.postalCode', '001234')->assertJsonPath('draft.latitude', 28.61);
        $this->assertDatabaseHas('institute_profiles', ['user_id' => $this->userId, 'district' => 'New Delhi',
            'sub_district' => 'Sample Tehsil', 'postal_code' => '001234']);
        $this->assertSame(0, DB::table('profile_subjects')->where('profile_id', $this->userId)->count());
        $this->assertDatabaseHas('business_activity', ['event' => 'institute.address_updated', 'entity_id' => $this->userId]);
        $this->assertDatabaseHas('business_activity', ['event' => 'institute.location_updated', 'entity_id' => $this->userId]);
        Http::assertSentCount(1);
        $this->putJson('/profiles/me', ['postalCode' => 'A1B 2C3', 'state' => 'Updated State'])->assertOk()
            ->assertJsonPath('locationDetails.place_id', 'institute-place');
        Http::assertSentCount(1);
        $this->patchJson('/auth/onboarding', ['locality' => 'Sample Village', 'localityType' => 'village',
            'latitude' => null, 'longitude' => null])->assertOk()->assertJsonPath('draft.locationDetails', null)
            ->assertJsonPath('draft.postalCode', 'A1B 2C3')->assertJsonPath('draft.localityType', 'village');
        $this->withoutHeader('Authorization')->getJson('/profiles/institute/'.$this->userId)->assertOk()
            ->assertJsonMissingPath('profile.location_details')->assertJsonMissingPath('profile.postal_code')
            ->assertJsonMissingPath('profile.locality');
        $report = '/admin/reports/institute-profiles?user='.$this->userId.'&q=A1B';
        $this->get($report)->assertRedirect('/admin/login');
        $this->withSession(['admin_authenticated' => true])->get($report)->assertOk()->assertSee('A1B 2C3');
        $csv = $this->get($report.'&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('sub_district', $csv);
        $this->assertStringContainsString('A1B 2C3', $csv);
    }

    public function test_institute_registration_alias_requires_agreements_and_valid_pairs_but_no_subjects(): void
    {
        Http::fake();
        $base = array_replace($this->sharedFields(), ['accountType' => 'institute', 'city' => 'Delhi', 'contactPerson' => 'Institute Contact']);
        foreach ([['primarySubjects', [1]], ['termsAccepted', false], ['acknowledgeTeachersNotBackgroundChecked', false],
            ['latitude', 28.61], ['postalCode', 12345]] as [$field, $value]) {
            $this->postJson('/auth/onboarding/complete', [...$base, $field => $value])->assertUnprocessable()
                ->assertJsonValidationErrors($field === 'latitude' ? 'location' : $field);
        }
        $this->postJson('/auth/onboarding/complete', $base)->assertOk()->assertJsonPath('registrationCompleted', true)
            ->assertJsonMissingPath('draft.primarySubjects');
        Http::assertNothingSent();
    }

    public function test_institute_location_outage_and_history_failure_do_not_corrupt_existing_details(): void
    {
        config(['services.google_geocoding.enabled' => false]);
        $this->postJson('/auth/register', array_replace($this->sharedFields(), ['accountType' => 'institute',
            'city' => 'Delhi', 'contactPerson' => 'Institute Contact', 'district' => 'Original District',
            'latitude' => 28.61, 'longitude' => 77.20]))->assertOk()->assertJsonPath('draft.locationDetails.status', 'not_configured');
        $this->app->instance(BusinessActivityRecorder::class, new class
        {
            public function record(...$arguments): never
            {
                throw new \RuntimeException('History unavailable');
            }
        });
        $this->putJson('/profiles/me', ['district' => 'Changed', 'latitude' => 0, 'longitude' => 0])->assertStatus(500);
        $this->assertDatabaseHas('institute_profiles', ['user_id' => $this->userId, 'district' => 'Original District',
            'latitude' => 28.61, 'longitude' => 77.20]);
    }

    public function test_teacher_address_fields_are_saved_returned_and_editable_without_exposing_precise_details(): void
    {
        $subjects = $this->teachingSubjects();
        $address = ['district' => 'Sample District', 'subDistrict' => 'Sample Tehsil',
            'locality' => 'Private Sector 7', 'localityType' => 'sector', 'state' => 'Delhi',
            'country' => 'India', 'postalCode' => '001234'];
        $result = $this->postJson('/auth/register', array_replace($this->sharedFields(), [
            'accountType' => 'teacher', 'city' => 'Delhi', 'primarySubjects' => [$subjects[0]], ...$address,
        ]))->assertOk()->assertJsonPath('draft.termsAccepted', true)
            ->assertJsonPath('draft.acknowledgeTeachersNotBackgroundChecked', true);
        foreach ($address as $field => $value) {
            $result->assertJsonPath('draft.'.$field, $value);
        }
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->userId,
            'district' => 'Sample District', 'sub_district' => 'Sample Tehsil', 'postal_code' => '001234']);
        $event = DB::table('business_activity')->where('event', 'teacher.address_updated')->where('entity_id', $this->userId)->first();
        $this->assertContains('postal_code', json_decode($event->after_data, true)['changed_fields']);
        $this->assertStringNotContainsString('001234', $event->after_data);
        $this->putJson('/profiles/me', ['locality' => 'Sample Village', 'localityType' => 'village', 'postalCode' => 'A1B 2C3'])->assertOk();
        $this->getJson('/auth/onboarding')->assertOk()->assertJsonPath('draft.postalCode', 'A1B 2C3')->assertJsonPath('draft.localityType', 'village');
        $this->patchJson('/auth/onboarding', ['subDistrict' => null])->assertOk()
            ->assertJsonPath('draft.subDistrict', null)->assertJsonPath('draft.district', 'Sample District');
        $this->withoutHeader('Authorization')->getJson('/profiles/teacher/'.$this->userId)
            ->assertOk()->assertJsonMissingPath('profile.postal_code')->assertJsonMissingPath('profile.locality');
        $report = '/admin/reports/teacher-profiles?user='.$this->userId.'&q=A1B';
        $this->get($report)->assertRedirect('/admin/login');
        $this->withSession(['admin_authenticated' => true])->get($report)->assertOk()->assertSee('A1B 2C3');
        $csv = $this->get($report.'&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('sub_district', $csv);
        $this->assertStringContainsString('A1B 2C3', $csv);
    }

    public function test_teacher_registration_requires_both_agreements_and_valid_address_fields(): void
    {
        $subjects = $this->teachingSubjects();
        foreach ([
            ['termsAccepted', false], ['acknowledgeTeachersNotBackgroundChecked', false],
            ['postalCode', 12345], ['district', str_repeat('x', 121)], ['localityType', 'unknown'],
        ] as [$field, $value]) {
            $this->postJson('/auth/register', array_replace($this->sharedFields(), [
                'accountType' => 'teacher', 'city' => 'Delhi', 'primarySubjects' => [$subjects[0]], $field => $value,
            ]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('profile_completed_at'));
    }

    public function test_teacher_address_update_rolls_back_when_its_history_fails(): void
    {
        $subjects = $this->teachingSubjects();
        $this->postJson('/auth/register', array_replace($this->sharedFields(), [
            'accountType' => 'teacher', 'city' => 'Delhi', 'primarySubjects' => [$subjects[0]], 'district' => 'Original District',
        ]))->assertOk();
        $this->app->instance(BusinessActivityRecorder::class, new class
        {
            public function record(...$arguments): never
            {
                throw new \RuntimeException('History unavailable');
            }
        });
        $this->putJson('/profiles/me', ['district' => 'Changed District', 'city' => 'Changed City'])->assertStatus(500);
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->userId, 'district' => 'Original District', 'city' => 'Delhi']);
    }

    private function captureOtp(): void
    {
        $real = app(OtpDelivery::class);
        $spy = \Mockery::mock(OtpDelivery::class);
        $spy->shouldReceive('send')->andReturnUsing(function ($phone, $code, $country) use ($real) {
            $this->lastOtp = $code;

            return $real->send($phone, $code, $country);
        });
        $this->app->instance(OtpDelivery::class, $spy);
    }
}
