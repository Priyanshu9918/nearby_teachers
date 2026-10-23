<?php

namespace Tests\Feature;

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
            ->assertJsonPath('draft.primarySubjectId', $subject)->assertJsonPath('draft.primarySubjectName', 'Saved Mathematics');
        $this->patchJson('/auth/onboarding', ['city' => 'Delhi'])->assertStatus(409);
        $this->assertSame('Jaipur', DB::table('teacher_profiles')->where('user_id', $this->userId)->value('city'));
    }

    public function test_teacher_registration_and_post_registration_tab_details(): void
    {
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Test subjects']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Mathematics']);
        $this->postJson('/auth/register', array_replace($this->sharedFields(), [
            'accountType' => 'teacher', 'city' => 'Jaipur', 'primarySubjectId' => $subject,
        ]))->assertOk()->assertJsonPath('nextStep', 'home');
        $this->assertDatabaseHas('profile_subjects', [
            'profile_type' => 'teacher', 'profile_id' => $this->userId, 'subject_id' => $subject,
        ]);
        $this->patchJson('/auth/onboarding', [
            'accountType' => 'institute', 'city' => 'Delhi', 'contactPerson' => 'Anita Sharma',
        ])->assertOk()->assertJsonPath('draft.contactPerson', 'Anita Sharma');
        $this->patchJson('/auth/onboarding', ['accountType' => 'teacher'])
            ->assertOk()->assertJsonPath('draft.city', 'Jaipur')->assertJsonPath('draft.primarySubjectId', $subject);
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

    public function test_teacher_cannot_register_without_primary_subject(): void
    {
        $this->postJson('/auth/register', array_replace($this->sharedFields(), [
            'accountType' => 'teacher', 'city' => 'Jaipur',
        ]))->assertUnprocessable()->assertJsonValidationErrors('primarySubjectId');
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
