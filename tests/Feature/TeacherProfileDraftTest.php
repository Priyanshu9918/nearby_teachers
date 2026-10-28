<?php

namespace Tests\Feature;

use App\Services\AuthenticationService;
use App\Support\RecordId;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class TeacherProfileDraftTest extends TestCase
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
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
            'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->userId = NumericTestIds::next();
        DB::table('users')->insert(['id' => $this->userId, 'account_type' => 'teacher',
            'full_name' => 'Draft Teacher', 'phone_verified_at' => now(), 'profile_completed_at' => now()]);
        DB::table('teacher_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $this->userId, 'lesson_modes' => '[]']);
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Draft test']);
        $this->subjectId = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Draft maths']);
        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->with('draft-token')->andReturnUsing(
            fn () => (new AuthenticationService)->publicUser(DB::table('users')->where('id', $this->userId)->first())
        );
        $this->app->instance(AuthenticationService::class, $authentication);
        $this->withHeader('Authorization', 'Bearer draft-token');
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function saveFirstThree(int $next = 4): void
    {
        $this->patchJson('/teacher/profile/steps/1', ['fullName' => 'Saved Teacher', 'listingName' => 'Maths Classes'])
            ->assertOk()->assertJsonPath('profileDraft.nextStep', 2);
        $this->patchJson('/teacher/profile/steps/2', ['primarySubjects' => [$this->subjectId]])
            ->assertOk()->assertJsonPath('profileDraft.nextStep', 3);
        $this->patchJson('/teacher/profile/steps/3', ['lessonModes' => ['online'], 'city' => 'Kanpur', 'district' => 'Kanpur Nagar', 'countryCode' => 'IN'])
            ->assertOk()->assertJsonPath('profileDraft.nextStep', $next);
    }

    public function test_saved_data_and_resume_step_survive_a_return_the_next_day(): void
    {
        $this->saveFirstThree();
        $savedAt = DB::table('teacher_profiles')->where('user_id', $this->userId)->value('profile_draft_saved_at');
        $this->travel(1)->days();
        $this->getJson('/teacher/profile')->assertOk()
            ->assertJsonPath('user.full_name', 'Saved Teacher')->assertJsonPath('profile.city', 'Kanpur')
            ->assertJsonPath('subjects.0.id', $this->subjectId)->assertJsonPath('profileComplete', false)
            ->assertJsonPath('profileDraft.status', 'draft')->assertJsonPath('profileDraft.completedSteps', [1, 2, 3])
            ->assertJsonPath('profileDraft.nextStep', 4)->assertJsonPath('profileDraft.lastSavedAt', $savedAt);
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('profileDraft.nextStep', 4)
            ->assertJsonPath('profileDraft.completedSteps', [1, 2, 3]);
        $this->travelBack();
        $this->patchJson('/teacher/profile/steps/4', ['ratePerHour' => 500])->assertOk()->assertJsonPath('profileDraft.nextStep', 5);
    }

    public function test_revisiting_steps_and_out_of_order_saves_resume_at_first_unsaved_step(): void
    {
        $this->patchJson('/teacher/profile/steps/4', ['trialDays' => 2])->assertOk()->assertJsonPath('profileDraft.nextStep', 1);
        $this->saveFirstThree(5);
        $this->patchJson('/teacher/profile/steps/1', ['fullName' => 'Edited Teacher'])->assertOk()
            ->assertJsonPath('profileDraft.completedSteps', [1, 2, 3, 4])->assertJsonPath('profileDraft.nextStep', 5);
    }

    public function test_failed_validation_and_failed_publish_do_not_advance_progress(): void
    {
        $this->patchJson('/teacher/profile/steps/2', ['primarySubjects' => []])->assertUnprocessable();
        $this->patchJson('/teacher/profile/steps/5', ['bio' => 'Too short'])->assertUnprocessable();
        $this->postJson('/teacher/profile/publish', ['agreeToTerms' => true])->assertUnprocessable();
        $this->getJson('/teacher/profile')->assertOk()->assertJsonPath('profileDraft.completedSteps', [])
            ->assertJsonPath('profileDraft.nextStep', 1)->assertJsonPath('profileDraft.lastSavedAt', null);
        $this->assertSame(0, DB::table('business_activity')->where('entity_id', (string) $this->userId)->count());
    }

    public function test_progress_is_scoped_to_the_authenticated_teacher(): void
    {
        $other = NumericTestIds::next();
        DB::table('users')->insert(['id' => $other, 'account_type' => 'teacher', 'full_name' => 'Other Teacher']);
        DB::table('teacher_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $other, 'lesson_modes' => '[]']);
        $this->patchJson('/teacher/profile/steps/1', ['fullName' => 'My Name', 'userId' => $other, 'completedSteps' => [1, 2, 3, 4, 5]])
            ->assertOk()->assertJsonPath('profileDraft.completedSteps', [1]);
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $other, 'completed_profile_steps' => null]);
        DB::table('users')->where('id', $this->userId)->update(['account_type' => 'learner']);
        $this->getJson('/teacher/profile')->assertForbidden();
        $this->patchJson('/teacher/profile/steps/1', ['fullName' => 'Student'])->assertForbidden();
    }

    public function test_event_failure_rolls_back_both_section_data_and_draft_progress(): void
    {
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert into `business_activity`')
                && in_array('teacher.profile_draft_saved', $query->bindings, true)) {
                throw new \RuntimeException('Draft history unavailable');
            }
        });
        $this->patchJson('/teacher/profile/steps/1', ['fullName' => 'Must Roll Back'])->assertStatus(500);
        $this->assertDatabaseHas('users', ['id' => $this->userId, 'full_name' => 'Draft Teacher']);
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->userId,
            'completed_profile_steps' => null, 'profile_draft_saved_at' => null]);
    }

    public function test_draft_progress_reports_require_admin_and_export_only_the_filtered_teacher(): void
    {
        $this->saveFirstThree();
        $path = '/admin/reports/teacher-profiles?user='.$this->userId;
        $accessCount = DB::table('admin_report_access_log')->where('report', 'teacher-profiles')->count();
        $this->get($path)->assertRedirect('/admin/login');
        $this->get($path.'&export=1')->assertRedirect('/admin/login');
        $this->withSession(['admin_authenticated' => true])->get($path)->assertOk()
            ->assertSee('completed profile steps')->assertSee('profile draft saved at');
        $csv = $this->get($path.'&export=1')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($csv)));
        $this->assertCount(2, $rows);
        $header = array_flip($rows[0]);
        $this->assertSame([1, 2, 3], json_decode($rows[1][$header['completed_profile_steps']], true));
        $this->assertSame((string) $this->userId, $rows[1][$header['user_id']]);
        $this->assertNotEmpty($rows[1][$header['profile_draft_saved_at']]);
        $this->assertSame($accessCount + 2, DB::table('admin_report_access_log')->where('report', 'teacher-profiles')->count());
    }

    public function test_publish_finishes_step_six_and_progress_history_contains_only_metadata(): void
    {
        $this->saveFirstThree();
        $this->patchJson('/teacher/profile/steps/4', ['ratePerHour' => 500])->assertOk();
        $this->patchJson('/teacher/profile/steps/5', ['bio' => str_repeat('Experienced teacher helps students learn with confidence every single day. ', 10)])
            ->assertOk()->assertJsonPath('profileDraft.nextStep', 6);
        $events = DB::table('business_activity')->where('entity_id', (string) $this->userId)
            ->where('event', 'teacher.profile_draft_saved')->orderBy('created_at')->get();
        $this->assertCount(5, $events);
        foreach ($events as $event) {
            $this->assertSame(1, $event->version);
            $this->assertTrue(RecordId::valid($event->id));
            $this->assertEqualsCanonicalizing(['step', 'completed_steps', 'next_step'], array_keys(json_decode($event->after_data, true)));
        }
        $this->postJson('/teacher/profile/publish', ['agreeToTerms' => true])->assertOk()
            ->assertJsonPath('profileDraft.status', 'published')->assertJsonPath('profileDraft.completedSteps', [1, 2, 3, 4, 5, 6])
            ->assertJsonPath('profileDraft.nextStep', null)->assertJsonPath('profileComplete', true);
        $this->getJson('/teacher/profile')->assertOk()->assertJsonPath('profileDraft.nextStep', null);
    }
}
