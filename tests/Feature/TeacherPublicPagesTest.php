<?php

namespace Tests\Feature;

use App\Reporting\BusinessActivityRecorder;
use App\Services\AuthenticationService;
use App\Services\TeacherPublicPages;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class TeacherPublicPagesTest extends TestCase
{
    private bool $transactionStarted = false;

    private int $teacherId;

    private array $subjectIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('REPORTING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set REPORTING_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
            'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;

        $this->teacherId = NumericTestIds::next();
        DB::table('users')->insert([
            'id' => $this->teacherId, 'account_type' => 'teacher', 'full_name' => 'Rahul Sharma',
            'profile_completed_at' => now(), 'phone_verified_at' => now(),
            'phone' => '+919811112222', 'email' => 'private@example.test',
            'password_hash' => 'private-password-hash', 'parental_consent_token' => 'private-consent-token',
        ]);
        DB::table('teacher_profiles')->insert([
            'id' => NumericTestIds::next(), 'user_id' => $this->teacherId, 'slug' => 'rahul-sharma-jaipur',
            'bio' => 'Patient teacher helping students build confidence.', 'city' => 'Jaipur',
            'state' => 'Rajasthan', 'country' => 'India', 'lesson_modes' => '["online","home_tuition"]',
            'rate_per_hour' => 500, 'currency' => 'INR', 'trial_days' => 3,
            'linkedin_url' => 'https://www.linkedin.com/in/rahul-sharma',
            'latitude' => 26.9124, 'longitude' => 75.7873, 'address_text' => 'PRIVATE HOUSE 99',
        ]);
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'SEO test subjects']);
        foreach (['Maths', 'Physics', 'Chemistry', 'English', 'Hindi'] as $name) {
            $id = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => $name]);
            $this->subjectIds[] = $id;
            DB::table('profile_subjects')->insert(['profile_type' => 'teacher', 'profile_id' => $this->teacherId, 'subject_id' => $id]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function profile(): object
    {
        return DB::table('teacher_profiles')->where('user_id', $this->teacherId)->first();
    }

    private function publish(): array
    {
        app(TeacherPublicPages::class)->publish($this->teacherId);

        return TeacherPublicPages::urls($this->profile(), 'Rahul Sharma');
    }

    public function test_two_public_pages_all_subjects_links_canonical_and_private_data(): void
    {
        $urls = $this->publish();
        $this->assertStringContainsString('rahul-sharma-chemistry-english-hindi-maths-physics-jaipur-rajasthan-india-'.$this->teacherId, $urls['teacherSeoUrl']);
        $response = $this->get($urls['teacherSeoUrl'])->assertOk()
            ->assertSee('href="'.$urls['teacherProfileUrl'].'"', false)->assertSee('View full profile');
        $full = $this->get($urls['teacherProfileUrl'])->assertOk()
            ->assertSee('https://www.linkedin.com/in/rahul-sharma')->assertSee('Home tuition')
            ->assertSee('500.00 INR')->assertSee('Trial days: 3');
        foreach ([$response, $full] as $page) {
            $page->assertSee('<link rel="canonical" href="'.$urls['teacherProfileUrl'].'">', false);
            foreach (['Maths', 'Physics', 'Chemistry', 'English', 'Hindi'] as $subject) {
                $page->assertSee($subject);
            }
            foreach (['PRIVATE HOUSE 99', '26.9124', '75.7873', 'private@example.test', '+919811112222',
                'private-password-hash', 'private-consent-token'] as $private) {
                $page->assertDontSee($private);
            }
        }
        $this->get('/site/teacher/rahul-sharma-jaipur')->assertStatus(301)->assertRedirect($urls['teacherSeoUrl']);
        $this->get('/site/city/Jaipur')->assertOk()->assertSee($urls['teacherSeoUrl']);
        $this->get('/sitemap.xml')->assertOk()->assertSee($urls['teacherProfileUrl'])->assertDontSee($urls['teacherSeoUrl']);
        $this->assertSame(1, DB::table('page_views')->where('target', 'seo:'.$this->teacherId)->count());
        $this->assertSame(1, DB::table('page_views')->where('target', 'profile:'.$this->teacherId)->count());
    }

    public function test_profile_save_publishes_and_updates_all_subjects_with_redirects(): void
    {
        $user = DB::table('users')->where('id', $this->teacherId)->first();
        $auth = \Mockery::mock(AuthenticationService::class);
        $auth->shouldReceive('authenticate')->with('teacher-test')->andReturn($user);
        $this->app->instance(AuthenticationService::class, $auth);
        $headers = ['Authorization' => 'Bearer teacher-test'];
        $first = $this->putJson('/profiles/me', ['subjectIds' => $this->subjectIds], $headers)
            ->assertOk()->json();
        $this->assertNotNull($this->profile()->seo_slug);
        $second = $this->putJson('/profiles/me', ['subjectIds' => [$this->subjectIds[0]], 'city' => 'Delhi'], $headers)
            ->assertOk()->json();
        $this->assertStringContainsString('rahul-sharma-maths-delhi-rajasthan-india-', $second['teacherSeoUrl']);
        $this->assertSame($first['teacherProfileUrl'], $second['teacherProfileUrl']);
        $this->get($first['teacherSeoUrl'])->assertStatus(301)->assertRedirect($second['teacherSeoUrl']);
        $this->get($second['teacherSeoUrl'])->assertOk()->assertSee('Maths')->assertDontSee('Physics');
        $this->assertSame(1, DB::table('business_activity')->where('event', 'teacher.pages_published')->where('entity_id', $this->teacherId)->count());
        $this->assertSame(1, DB::table('business_activity')->where('event', 'teacher.pages_updated')->where('entity_id', $this->teacherId)->count());
    }

    public function test_suspended_incomplete_and_missing_teachers_are_not_public(): void
    {
        $urls = $this->publish();
        foreach ([['suspended_at' => now()], ['suspended_at' => null, 'profile_completed_at' => null],
            ['profile_completed_at' => now(), 'account_type' => 'learner']] as $changes) {
            DB::table('users')->where('id', $this->teacherId)->update($changes);
            $this->get($urls['teacherSeoUrl'])->assertNotFound();
            $this->get($urls['teacherProfileUrl'])->assertNotFound();
            $this->get('/sitemap.xml')->assertOk()->assertDontSee($urls['teacherProfileUrl']);
        }
        $this->get('/site/teacher-profile/missing')->assertNotFound();
        $this->get('/site/teacher/missing-9007199254740992')->assertNotFound();
    }

    public function test_publication_rolls_back_if_history_cannot_be_recorded(): void
    {
        $recorder = new class
        {
            public function record(...$arguments): never
            {
                throw new \RuntimeException('History unavailable');
            }
        };
        $this->app->instance(BusinessActivityRecorder::class, $recorder);
        try {
            DB::transaction(function (): void {
                DB::table('teacher_profiles')->where('user_id', $this->teacherId)->update(['city' => 'Delhi']);
                app(TeacherPublicPages::class)->publish($this->teacherId);
            });
            $this->fail('Publication should fail when history fails.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('History unavailable', $exception->getMessage());
        }
        $this->assertNull($this->profile()->seo_slug);
        $this->assertSame('Jaipur', $this->profile()->city);
    }

    public function test_optional_links_are_safe_and_text_is_escaped(): void
    {
        DB::table('teacher_profiles')->where('user_id', $this->teacherId)->update([
            'linkedin_url' => 'javascript:alert(1)', 'bio' => '<script>alert(2)</script>',
        ]);
        $urls = $this->publish();
        $this->get($urls['teacherProfileUrl'])->assertOk()
            ->assertDontSee('javascript:alert(1)', false)->assertDontSee('<script>alert(2)</script>', false)
            ->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false);
    }

    public function test_long_subject_names_are_not_truncated_and_missing_subjects_hide_pages(): void
    {
        foreach ($this->subjectIds as $index => $id) {
            DB::table('subjects')->where('id', $id)->update(['name' => str_repeat('subject', 20).$index]);
        }
        $urls = $this->publish();
        $this->assertGreaterThan(255, strlen($this->profile()->seo_slug));
        foreach (range(0, 4) as $index) {
            $this->assertStringContainsString(str_repeat('subject', 20).$index, $urls['teacherSeoUrl']);
        }
        $this->get($urls['teacherSeoUrl'])->assertOk();
        DB::table('profile_subjects')->where('profile_type', 'teacher')->where('profile_id', $this->teacherId)->delete();
        $this->get($urls['teacherSeoUrl'])->assertNotFound();
        $this->get($urls['teacherProfileUrl'])->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee($urls['teacherProfileUrl']);
    }

    public function test_teacher_registration_publishes_primary_subject_page_and_returns_urls(): void
    {
        DB::table('users')->where('id', $this->teacherId)->update([
            'profile_completed_at' => null, 'account_type' => 'learner', 'password_hash' => null,
        ]);
        DB::table('teacher_profiles')->where('user_id', $this->teacherId)->update(['slug' => null]);
        DB::table('profile_subjects')->where('profile_type', 'teacher')->where('profile_id', $this->teacherId)->delete();
        $authentication = \Mockery::mock(AuthenticationService::class);
        $authentication->shouldReceive('authenticate')->with('registration-test')
            ->andReturn(DB::table('users')->where('id', $this->teacherId)->first());
        $this->app->instance(AuthenticationService::class, $authentication);
        $result = $this->postJson('/auth/register', [
            'accountType' => 'teacher', 'fullName' => 'Rahul Sharma', 'email' => 'teacher@example.test',
            'password' => 'test-secure-password', 'city' => 'Jaipur', 'primarySubjectId' => $this->subjectIds[0],
            'termsAccepted' => true, 'acknowledgeTeachersNotBackgroundChecked' => true,
        ], ['Authorization' => 'Bearer registration-test'])->assertOk()->assertJsonPath('registrationCompleted', true)->json();
        $this->assertStringContainsString('rahul-sharma-maths-jaipur-', $result['teacherSeoUrl']);
        $this->get($result['teacherProfileUrl'])->assertOk()->assertSee('Maths');
    }

    public function test_full_profile_reviews_are_real_escaped_and_bounded(): void
    {
        $learner = NumericTestIds::next();
        DB::table('users')->insert(['id' => $learner, 'account_type' => 'learner', 'full_name' => 'Private Learner']);
        foreach (range(0, 10) as $index) {
            $booking = NumericTestIds::next();
            DB::table('bookings')->insert([
                'id' => $booking, 'learner_id' => $learner, 'provider_id' => $this->teacherId,
                'mode' => 'online', 'requested_time' => now(), 'status' => 'completed',
            ]);
            DB::table('reviews')->insert([
                'id' => NumericTestIds::next(), 'booking_id' => $booking, 'learner_id' => $learner,
                'provider_id' => $this->teacherId, 'rating' => 5,
                'comment' => $index === 0 ? 'Oldest review' : '<b>Helpful lesson '.$index.'</b>',
                'created_at' => now()->addSeconds($index),
            ]);
        }
        $urls = $this->publish();
        $this->get($urls['teacherProfileUrl'])->assertOk()->assertSee('Helpful lesson 10')
            ->assertSee('&lt;b&gt;', false)->assertDontSee('<b>Helpful', false)
            ->assertDontSee('Oldest review')->assertDontSee('Private Learner')->assertSee('Next');
        $this->get($urls['teacherProfileUrl'].'?page=2')->assertOk()->assertSee('Oldest review')
            ->assertDontSee('Helpful lesson 10')->assertSee('Previous');
    }

    public function test_reports_filter_and_export_new_columns_and_history_with_admin_logging(): void
    {
        $this->publish();
        $this->get('/admin/reports/teacher-profiles?export=1')->assertRedirect('/admin/login');
        $this->withSession(['admin_authenticated' => true])
            ->get('/admin/reports/teacher-profiles?q='.$this->profile()->seo_slug)
            ->assertOk()->assertSee($this->profile()->seo_slug)->assertSee('Open SEO page')
            ->assertSee('href="'.route('website.teacher', ['slug' => $this->profile()->seo_slug]).'"', false);
        $csv = $this->get('/admin/reports/teacher-profiles?export=1&q='.$this->profile()->seo_slug)
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('public_profile_slug', $csv);
        $this->assertStringContainsString($this->profile()->seo_slug, $csv);
        $this->get('/admin/reports/activity?q=teacher.pages_published')->assertOk()->assertSee('teacher.pages_published');
        $this->assertGreaterThanOrEqual(3, DB::table('admin_report_access_log')->count());
    }
}
