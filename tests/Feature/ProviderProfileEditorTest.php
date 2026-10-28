<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class ProviderProfileEditorTest extends TestCase
{
    private int $userId;

    private int $subjectId;

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set ONBOARDING_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->userId = NumericTestIds::next();
        DB::table('users')->insert(['id' => $this->userId, 'account_type' => 'institute', 'full_name' => 'Example Academy',
            'phone_verified_at' => now(), 'profile_completed_at' => now()]);
        DB::table('institute_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $this->userId, 'lesson_modes' => '[]']);
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Provider editor tests']);
        $this->subjectId = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Mathematics']);
        $auth = \Mockery::mock(AuthenticationService::class);
        $auth->shouldReceive('authenticate')->with('provider-token')->andReturnUsing(
            fn () => (new AuthenticationService)->publicUser(DB::table('users')->where('id', $this->userId)->first())
        );
        $this->app->instance(AuthenticationService::class, $auth);
        $this->withToken('provider-token');
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function useTeacher(): void
    {
        DB::table('users')->where('id', $this->userId)->update(['account_type' => 'teacher']);
        DB::table('teacher_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $this->userId, 'lesson_modes' => '[]']);
    }

    public static function providers(): array
    {
        return [['teacher'], ['institute']];
    }

    private function saveThree(string $type): void
    {
        $this->patchJson('/profiles/me/steps/1', ['fullName' => 'Saved Academy', 'listingName' => 'Public Academy', 'contactPerson' => 'Asha Sharma'])
            ->assertOk()->assertJsonPath('profileDraft.nextStep', 2)
            ->assertJsonPath('profileDraft.steps.0.step', 1)->assertJsonPath('profileDraft.steps.0.data.fullName', 'Saved Academy');
        $this->patchJson('/profiles/me/steps/2', [$type === 'teacher' ? 'primarySubjects' : 'subjectIds' => [$this->subjectId]])
            ->assertOk()->assertJsonPath('profileDraft.nextStep', 3);
        $this->patchJson('/profiles/me/steps/3', ['lessonModes' => ['online'], 'city' => 'Kanpur', 'district' => 'Kanpur Nagar', 'countryCode' => 'IN'])
            ->assertOk()->assertJsonPath('profileDraft.nextStep', 4);
    }

    #[DataProvider('providers')]
    public function test_shared_endpoints_save_resume_and_publish_each_provider(string $type): void
    {
        if ($type === 'teacher') {
            $this->useTeacher();
        }
        $this->saveThree($type);
        $this->travel(1)->days();
        $this->getJson('/profiles/me')->assertOk()->assertJsonPath('profileDraft.completedSteps', [1, 2, 3])
            ->assertJsonPath('profileDraft.nextStep', 4)->assertJsonPath('profileComplete', false)
            ->assertJsonPath('user.full_name', 'Saved Academy')->assertJsonPath('profile.listing_name', 'Public Academy')
            ->assertJsonPath('subjects.0.id', $this->subjectId)->assertJsonPath('webPageUrl', null)
            ->assertJsonCount(6, 'profileDraft.steps')->assertJsonPath('profileDraft.steps.0.data.listingName', 'Public Academy')
            ->assertJsonPath('profileDraft.steps.1.data.'.($type === 'teacher' ? 'primarySubjects' : 'subjectIds'), [$this->subjectId])
            ->assertJsonPath('profileDraft.steps.2.data.city', 'Kanpur')->assertJsonPath('profileDraft.steps.3.completed', false);
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('profileDraft.nextStep', 4)->assertJsonPath('profileComplete', false)
            ->assertJsonCount(6, 'profileDraft.steps')->assertJsonPath('profileDraft.steps.2.data.city', 'Kanpur');
        $this->travelBack();
        $this->patchJson('/profiles/me/steps/4', [$type === 'teacher' ? 'ratePerHour' : 'startingRate' => 600, 'currency' => 'INR', 'trialDays' => 2])
            ->assertOk()->assertJsonPath('profileDraft.nextStep', 5);
        $this->patchJson('/profiles/me/steps/5', [$type === 'teacher' ? 'bio' : 'description' => str_repeat('We help students build confidence through regular practice and feedback. ', 10)])
            ->assertOk()->assertJsonPath('profileDraft.nextStep', 6);
        $published = $this->postJson('/profiles/me/publish', ['agreeToTerms' => true])->assertOk()
            ->assertJsonPath('profileComplete', true)->assertJsonPath('profileDraft.status', 'published')
            ->assertJsonPath('profileDraft.nextStep', null)->assertJsonPath('profileDraft.completedSteps', [1, 2, 3, 4, 5, 6])
            ->assertJsonPath('profileDraft.steps.5.completed', true)->assertJsonPath('profileDraft.steps.5.data.agreeToTerms', true)
            ->assertJsonPath('profileDraft.steps.3.data.'.($type === 'teacher' ? 'ratePerHour' : 'startingRate'), '600.00');
        $this->assertNotNull($published->json($type === 'teacher' ? 'teacherProfileUrl' : 'instituteProfileUrl'));
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('profileComplete', true)->assertJsonPath('publicProfilePublished', true);
        $this->assertDatabaseHas('business_activity', ['event' => $type.'.profile_published', 'entity_id' => (string) $this->userId]);
        $events = DB::table('business_activity')->where('entity_id', (string) $this->userId)->where('event', $type.'.profile_draft_saved')->get();
        $this->assertCount(5, $events);
        foreach ($events as $event) {
            $this->assertSame(1, $event->version);
            $this->assertEqualsCanonicalizing(['step', 'completed_steps', 'next_step'], array_keys(json_decode($event->after_data, true)));
        }

        $this->assertDatabaseHas('profile_subjects', ['profile_type' => $type, 'profile_id' => $this->userId, 'subject_id' => $this->subjectId]);
        if ($type === 'institute') {
            $this->get($published->json('instituteProfileUrl'))->assertOk()->assertSee('Public Academy');
            $this->getJson('/teacher/profile')->assertForbidden();
        } else {
            $this->getJson('/teacher/profile')->assertOk()->assertJsonPath('profileDraft.nextStep', null);
        }
    }

    public function test_invalid_fields_and_incomplete_publication_do_not_advance_or_publish(): void
    {
        $this->patchJson('/profiles/me/steps/2', ['subjectIds' => []])->assertUnprocessable();
        $this->patchJson('/profiles/me/steps/2', ['primarySubjects' => [$this->subjectId]])->assertUnprocessable();
        $this->patchJson('/profiles/me/steps/5', ['description' => ['invalid']])->assertUnprocessable();
        $this->patchJson('/profiles/me/steps/5', ['description' => 'Too short'])->assertUnprocessable();
        $this->patchJson('/profiles/me/steps/4', ['startingRate' => -1])->assertUnprocessable();
        $this->patchJson('/profiles/me/steps/3', ['latitude' => 10])->assertUnprocessable();
        $this->postJson('/profiles/me/publish', ['agreeToTerms' => false])->assertUnprocessable();
        $this->postJson('/profiles/me/publish', ['agreeToTerms' => true])->assertUnprocessable()->assertJsonFragment(['contactPerson']);
        $this->getJson('/profiles/me')->assertOk()->assertJsonPath('profileDraft.completedSteps', [])
            ->assertJsonPath('profileDraft.lastSavedAt', null)->assertJsonPath('profileComplete', false);
        $this->assertSame(0, DB::table('business_activity')->where('entity_id', (string) $this->userId)->count());
    }

    public function test_progress_is_owned_by_the_bearer_and_privileged_fields_are_ignored(): void
    {
        $this->patchJson('/profiles/me/steps/1', ['listingName' => 'Saved Academy', 'userId' => 1, 'accountType' => 'teacher',
            'is_profile_complete' => true, 'completedSteps' => [1, 2, 3, 4, 5], 'institute_type' => 'bsl_direct', 'is_bsl_official' => true])->assertOk()
            ->assertJsonPath('profileDraft.completedSteps', [1]);
        $this->assertDatabaseHas('institute_profiles', ['user_id' => $this->userId, 'institute_type' => 'independent', 'is_bsl_official' => false, 'is_profile_complete' => false]);
        $this->assertDatabaseMissing('teacher_profiles', ['user_id' => $this->userId]);
        $this->patchJson('/profiles/me/steps/4', ['trialDays' => 3])->assertOk()->assertJsonPath('profileDraft.nextStep', 2);
        $this->patchJson('/profiles/me/steps/1', ['listingName' => 'Revised Academy'])->assertOk()->assertJsonPath('profileDraft.completedSteps', [1, 4]);
    }

    public function test_registration_role_and_authentication_gates_are_enforced(): void
    {
        DB::table('users')->where('id', $this->userId)->update(['profile_completed_at' => null]);
        $this->patchJson('/profiles/me/steps/1', ['fullName' => 'Academy'])->assertStatus(409);
        $this->postJson('/profiles/me/publish', ['agreeToTerms' => true])->assertStatus(409);
        DB::table('users')->where('id', $this->userId)->update(['account_type' => 'learner']);
        $this->getJson('/profiles/me')->assertForbidden();
        $this->patchJson('/profiles/me/steps/1', ['fullName' => 'Student'])->assertForbidden();
        $this->postJson('/profiles/me/publish', ['agreeToTerms' => true])->assertForbidden();
        $this->postJson('/profiles/me/photo')->assertForbidden();
        $this->patchJson('/profiles/me/steps/6', ['agreeToTerms' => true])->assertNotFound();
        $this->withHeader('Authorization', '');
        $this->getJson('/profiles/me')->assertUnauthorized();
        $this->patchJson('/profiles/me/steps/1', ['fullName' => 'Academy'])->assertUnauthorized();
        $this->postJson('/profiles/me/publish', ['agreeToTerms' => true])->assertUnauthorized();
        $this->postJson('/profiles/me/photo')->assertUnauthorized();
    }

    #[DataProvider('failureEvents')]
    public function test_history_failure_rolls_back_profile_progress_and_publication(string $event): void
    {
        if ($event === 'institute.profile_published') {
            $this->saveThree('institute');
            $this->patchJson('/profiles/me/steps/5', ['description' => str_repeat('Experienced instructors guide students through practice and clear feedback daily. ', 10)])->assertOk();
        }
        $before = DB::table('institute_profiles')->where('user_id', $this->userId)->first();
        $terms = DB::table('users')->where('id', $this->userId)->value('terms_accepted_at');
        DB::listen(function ($query) use ($event): void {
            if (str_contains($query->sql, 'insert into `business_activity`') && in_array($event, $query->bindings, true)) {
                throw new \RuntimeException('History unavailable');
            }
        });
        if ($event === 'institute.profile_published') {
            $this->postJson('/profiles/me/publish', ['agreeToTerms' => true])->assertStatus(500);
        } else {
            $this->patchJson('/profiles/me/steps/1', ['fullName' => 'Must roll back', 'listingName' => 'Must roll back'])->assertStatus(500);
        }
        $after = DB::table('institute_profiles')->where('user_id', $this->userId)->first();
        $this->assertEquals($before, $after);
        $this->assertDatabaseHas('users', ['id' => $this->userId, 'full_name' => $event === 'institute.profile_published' ? 'Saved Academy' : 'Example Academy', 'terms_accepted_at' => $terms]);
    }

    public static function failureEvents(): array
    {
        return [['institute.profile_draft_saved'], ['institute.profile_published']];
    }

    public function test_institute_draft_web_page_is_hidden_until_publish(): void
    {
        $this->saveThree('institute');
        $slug = DB::table('institute_profiles')->where('user_id', $this->userId)->value('slug');
        $this->assertNotNull($slug);
        $this->get('/site/institute/'.$slug)->assertNotFound();
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('instituteProfileUrl', null);
    }

    public function test_institute_photo_is_moderated_without_advancing_draft(): void
    {
        Storage::fake('public');
        $image = UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $this->postJson('/profiles/me/photo', ['image' => $image])->assertCreated()->assertJsonPath('user.avatar_status', 'pending');
        $this->getJson('/profiles/me')->assertOk()->assertJsonPath('profileDraft.completedSteps', []);
        $this->assertDatabaseHas('business_activity', ['event' => 'institute.avatar_submitted', 'entity_id' => (string) $this->userId]);
    }

    public function test_institute_reports_filter_export_and_audit_draft_progress(): void
    {
        $this->saveThree('institute');
        $path = '/admin/reports/institute-profiles?user='.$this->userId.'&q=Public%20Academy';
        $this->get($path)->assertRedirect('/admin/login');
        $this->get($path.'&export=1')->assertRedirect('/admin/login');
        $before = DB::table('admin_report_access_log')->where('report', 'institute-profiles')->count();
        $this->withSession(['admin_authenticated' => true])->get($path)->assertOk()->assertSee('completed profile steps')->assertSee('Public Academy');
        $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($this->get($path.'&export=1')->assertOk()->streamedContent())));
        $this->assertCount(2, $rows);
        $columns = array_flip($rows[0]);
        $this->assertSame([1, 2, 3], json_decode($rows[1][$columns['completed_profile_steps']], true));
        $this->assertSame((string) $this->userId, $rows[1][$columns['user_id']]);
        $this->assertSame('Asha Sharma', $rows[1][$columns['contact_person']]);
        $this->assertSame($before + 2, DB::table('admin_report_access_log')->where('report', 'institute-profiles')->count());
    }
}
