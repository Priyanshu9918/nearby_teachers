<?php

namespace Tests\Feature;

use App\Reporting\BusinessActivityRecorder;
use App\Services\InstitutePublicPages;
use App\Services\TeacherPublicPages;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class TeacherPublicPagesTest extends TestCase
{
    private bool $transactionStarted = false;

    private array $subjects;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('REPORTING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set REPORTING_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
            'database.connections.mysql.database' => $database, 'seo.public_url' => 'http://localhost', 'app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'International SEO test']);
        foreach (['Maths', 'English', 'Physics', 'Chemistry', 'Hindi', 'IELTS'] as $name) {
            $this->subjects[$name] = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function teacher(string $name = 'Asha Sharma', string $city = 'Kanpur', ?string $country = 'India', array $subjects = ['Maths'], array $modes = ['online'], bool $complete = true): object
    {
        $id = NumericTestIds::next();
        DB::table('users')->insert(['id' => $id, 'account_type' => 'teacher', 'full_name' => $name,
            'phone_verified_at' => now(), 'profile_completed_at' => now()]);
        DB::table('teacher_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $id,
            'is_profile_complete' => $complete,
            'slug' => 'legacy-'.$id, 'bio' => '<b>Patient teacher</b>', 'city' => $city, 'country' => $country,
            'lesson_modes' => json_encode($modes), 'rate_per_hour' => 500, 'currency' => 'INR',
            'linkedin_url' => 'https://www.linkedin.com/in/asha', 'address_text' => 'PRIVATE HOUSE 99',
            'postal_code' => 'PRIVATE-PIN', 'location_details' => '{"private":"DO NOT EXPOSE"}']);
        foreach ($subjects as $subject) {
            DB::table('profile_subjects')->insert(['profile_type' => 'teacher', 'profile_id' => $id, 'subject_id' => $this->subjects[$subject]]);
        }
        app(TeacherPublicPages::class)->publish($id, $id);

        return DB::table('teacher_profiles')->where('user_id', $id)->first();
    }

    private function institute(string $name = 'Bright Academy', string $city = 'Kanpur', ?string $country = 'India', array $subjects = ['Maths'], array $modes = ['online'], bool $complete = true): object
    {
        $id = NumericTestIds::next();
        DB::table('users')->insert(['id' => $id, 'account_type' => 'institute', 'full_name' => $name,
            'phone_verified_at' => now(), 'profile_completed_at' => now()]);
        DB::table('institute_profiles')->insert(['id' => NumericTestIds::next(), 'user_id' => $id,
            'is_profile_complete' => $complete, 'slug' => 'legacy-'.$id, 'listing_name' => $name,
            'description' => str_repeat('A welcoming institute for learners. ', 4), 'city' => $city,
            'country' => $country, 'district' => 'Kanpur Nagar', 'locality' => 'Bithoor',
            'lesson_modes' => json_encode($modes), 'starting_rate' => 700, 'currency' => 'INR']);
        foreach ($subjects as $subject) {
            DB::table('profile_subjects')->insert(['profile_type' => 'institute', 'profile_id' => $id, 'subject_id' => $this->subjects[$subject]]);
        }
        app(InstitutePublicPages::class)->publish($id, $id);

        return DB::table('institute_profiles')->where('user_id', $id)->first();
    }

    public function test_seo_pages_are_created_only_after_profile_completion(): void
    {
        $teacher = $this->teacher(complete: false);
        $this->assertNull($teacher->public_page_slug);
        $this->assertFalse((bool) $teacher->is_profile_complete);
        $this->get('/in/kanpur/maths-tuition')->assertNotFound();

        DB::table('teacher_profiles')->where('user_id', $teacher->user_id)->update(['is_profile_complete' => true]);
        app(TeacherPublicPages::class)->publish($teacher->user_id, $teacher->user_id);
        $published = DB::table('teacher_profiles')->where('user_id', $teacher->user_id)->first();
        $this->assertSame('asha-sharma-kanpur', $published->public_page_slug);
        $this->get('/in/kanpur/maths-tuition')->assertOk()->assertSee('Asha Sharma');
        $this->assertSame('http://localhost/teacher/asha-sharma-kanpur', TeacherPublicPages::urls($published)['webPageUrl']);
    }

    public function test_location_directory_uses_short_paths_and_mobile_teacher_cards(): void
    {
        $teacher = $this->teacher();
        DB::table('teacher_profiles')->where('user_id', $teacher->user_id)->update([
            'district' => 'Kanpur Nagar', 'locality' => 'Bithoor',
        ]);
        app(TeacherPublicPages::class)->publish($teacher->user_id, $teacher->user_id);

        $paths = DB::table('seo_location_pages')->where('country', 'in')->where('language', 'en')->pluck('path')->all();
        $this->assertContains('/in', $paths);
        $this->assertContains('/in/kanpur-nagar', $paths);
        $this->assertContains('/in/kanpur-nagar/bithoor', $paths);
        $this->assertContains('/in/kanpur-nagar/bithoor/maths-tuition', $paths);
        $this->assertNotContains('/in/en/kanpur-nagar/bithoor', $paths);

        $page = $this->get('/in/kanpur-nagar/bithoor')->assertOk()
            ->assertSee('<h1>Teachers and institutes in Bithoor, Kanpur Nagar</h1>', false)
            ->assertSee('directory-search')->assertSee('Referral points:')
            ->assertSee('Maths')->assertSee('Get the app to contact')->assertSee('View profile');
        $this->assertStringNotContainsString('/in/en/', $page->getContent());
        $this->get('/in/hi/kanpur-nagar/bithoor')->assertStatus(301)
            ->assertRedirect('http://localhost/in/kanpur-nagar/bithoor?lang=hi');
        $this->get('/in/kanpur-nagar/bithoor?lang=hi')->assertOk()
            ->assertSee('<html lang="hi"', false)
            ->assertSee('<link rel="canonical" href="http://localhost/in/kanpur-nagar/bithoor">', false);
        $this->get('/in/kanpur-nagar/bithoor?mode=home_tuition')->assertOk()
            ->assertSee('No teachers or institutes match these filters.');
    }

    public function test_institutes_publish_after_completion_and_join_teacher_subject_and_location_pages(): void
    {
        $institute = $this->institute(subjects: ['Maths', 'English', 'Physics', 'Chemistry', 'Hindi'], complete: false);
        $this->assertNull($institute->public_page_slug);
        $this->get('/institute/'.$institute->slug)->assertNotFound();
        $this->get('/in/kanpur/maths-tuition')->assertNotFound();

        DB::table('institute_profiles')->where('user_id', $institute->user_id)->update(['is_profile_complete' => true]);
        app(InstitutePublicPages::class)->publish((int) $institute->user_id, (int) $institute->user_id);
        $published = DB::table('institute_profiles')->where('user_id', $institute->user_id)->first();
        $this->assertSame('bright-academy-kanpur', $published->public_page_slug);
        $this->get('/institute/'.$published->public_page_slug)->assertOk()->assertSee('Bright Academy')
            ->assertSee('Maths')->assertSee('Chemistry');
        $this->get('/in/kanpur/maths-tuition')->assertOk()->assertSee('Bright Academy')->assertSee('Institute')
            ->assertSee('directory-topbar')->assertSee('seo-language');
        $this->get('/in/kanpur-nagar/bithoor')->assertOk()->assertSee('Bright Academy');
        $this->get('/institute/'.$published->public_page_slug.'?lang=hi')->assertOk()
            ->assertSee('<html lang="hi"', false)->assertSee('भाषा');

        foreach (['Maths', 'English', 'Physics', 'Chemistry', 'Hindi'] as $subject) {
            $route = DB::table('seo_subject_routes')->where('country', 'in')->where('language', 'en')
                ->where('subject_id', $this->subjects[$subject])->first();
            $this->assertNotNull($route);
            $this->assertTrue(app(\App\Services\SeoDirectory::class)->pages()
                ->where('r.subject_id', $route->subject_id)->where('r.city_slug', 'kanpur')->exists());
        }
        $this->assertDatabaseHas('business_activity', [
            'event' => 'institute.seo_state_changed', 'entity_id' => $institute->user_id,
        ]);
    }

    public function test_one_teacher_page_all_subjects_privacy_and_old_routes_redirect(): void
    {
        $profile = $this->teacher(subjects: ['Maths', 'English', 'Physics', 'Chemistry', 'Hindi']);
        $urls = TeacherPublicPages::urls($profile);
        $this->assertSame('http://localhost/teacher/asha-sharma-kanpur', $urls['teacherSeoUrl']);
        $this->assertSame($urls['teacherSeoUrl'], $urls['teacherProfileUrl']);
        $this->get($urls['teacherSeoUrl'])->assertOk()->assertSee('<html lang="en"', false)
            ->assertSee('<link rel="canonical" href="'.$urls['teacherSeoUrl'].'">', false)
            ->assertSee('Maths')->assertSee('Physics')->assertSee('View LinkedIn')->assertSee('Download the app to message')
            ->assertSee('&lt;b&gt;Patient teacher&lt;/b&gt;', false)->assertDontSee('PRIVATE HOUSE 99')
            ->assertDontSee('PRIVATE-PIN')->assertDontSee('DO NOT EXPOSE')->assertDontSee('message or book');
        $this->get($urls['teacherSeoUrl'].'?lang=hi')->assertOk()->assertSee('<html lang="hi"', false)->assertSee('seo-language');
        foreach (['/site/teacher/'.$profile->slug, '/teachers/'.$profile->slug,
            '/teachers/old-subject-location-'.$profile->user_id.'/profile', '/site/teacher-profile/old-'.$profile->user_id] as $old) {
            $this->get($old)->assertStatus(301)->assertRedirect($urls['teacherProfileUrl']);
        }
    }

    public function test_long_legacy_subject_urls_are_preserved_without_truncation(): void
    {
        $teacher = $this->teacher();
        $old = str_repeat('long-subject-', 100).$teacher->user_id;
        DB::table('teacher_profiles')->where('user_id', $teacher->user_id)->update(['seo_slug' => $old]);
        app(TeacherPublicPages::class)->publish($teacher->user_id);
        $this->assertSame('/teachers/'.$old, DB::table('seo_urls')->where('path_hash', hash('sha256', '/teachers/'.$old))->value('path'));
        $this->get('/teachers/'.$old)->assertStatus(301)->assertRedirect('http://localhost/teacher/asha-sharma-kanpur');
    }

    public function test_listing_languages_share_the_short_canonical_url(): void
    {
        $this->teacher();
        $this->get('/in/kanpur/maths-tuition')->assertOk()->assertSee('Maths teachers and institutes in Kanpur')
            ->assertSee('Get the app to contact')->assertSee('directory-profile')
            ->assertSee('<link rel="canonical" href="http://localhost/in/kanpur/maths-tuition">', false)
            ->assertDontSee('hreflang=', false);
        $this->get('/in/hi/kanpur/maths-tuition')->assertStatus(301)
            ->assertRedirect('http://localhost/in/kanpur/maths-tuition?lang=hi');
        $this->get('/in/kanpur/maths-tuition?lang=hi')->assertOk()->assertSee('<html lang="hi"', false)
            ->assertSee('गणित')->assertSee('<link rel="canonical" href="http://localhost/in/kanpur/maths-tuition">', false)
            ->assertDontSee('hreflang=', false);
        $this->get('/in/en/kanpur/maths-tuition')->assertStatus(301)
            ->assertRedirect('http://localhost/in/kanpur/maths-tuition?lang=en');
        $this->get('/in/ja/kanpur/maths-tuition')->assertNotFound();
        $this->get('/in/unknown/maths-tuition')->assertNotFound();
    }

    public function test_country_slug_wording_and_native_page_language(): void
    {
        foreach ([['United Kingdom', 'London', '/gb/london/maths-tutor', 'en'],
            ['United States', 'Houston', '/us/houston/math-tutoring', 'en'],
            ['Spain', 'Madrid', '/es/madrid/clases-particulares-de-matematicas', 'es'],
            ['United Arab Emirates', 'Dubai', '/ae/dubai/maths-tutor', 'ar'],
            ['Japan', 'Tokyo', '/jp/tokyo/maths-tutor', 'ja'],
            ['Russia', 'Moscow', '/ru/moscow/maths-tutor', 'ru'],
            ['Bangladesh', 'Dhaka', '/bd/dhaka/maths-tuition', 'bn'],
            ['Pakistan', 'Lahore', '/pk/lahore/maths-tuition', 'ur'],
            ['China', 'Beijing', '/cn/beijing/maths-tutor', 'zh']] as [$country, $city, $path, $language]) {
            $teacher = $this->teacher('Teacher '.$city, $city, $country);
            $this->get($path)->assertOk()->assertSee('<html lang="'.$language.'"', false);
            $this->get('/teacher/'.$teacher->public_page_slug)->assertOk()->assertSee('<html lang="'.$language.'"', false);
        }
        $this->get('/ae/dubai/maths-tutor')->assertOk()->assertSee('dir="rtl"', false);
    }

    public function test_online_results_use_country_and_online_mode(): void
    {
        $this->teacher('India Online', subjects: ['IELTS']);
        $this->teacher('India Offline', subjects: ['IELTS'], modes: ['in_person']);
        $this->teacher('USA Online', 'Houston', 'United States', ['IELTS']);
        $this->get('/in/online/ielts-coaching')->assertOk()->assertSee('India Online')->assertDontSee('India Offline')->assertDontSee('USA Online');
        $this->get('/us/online/ielts-prep')->assertOk()->assertSee('USA Online')->assertDontSee('India Online');
        $this->get('/in/kanpur/ielts-tuition')->assertOk()->assertSee('India Offline');
    }

    public function test_duplicate_names_and_edits_keep_url_ownership_and_aliases(): void
    {
        $first = $this->teacher();
        $second = $this->teacher();
        $this->assertSame('asha-sharma-kanpur', $first->public_page_slug);
        $this->assertSame('asha-sharma-kanpur-'.$second->user_id, $second->public_page_slug);
        DB::table('users')->where('id', $first->user_id)->update(['full_name' => 'Asha Verma']);
        DB::table('teacher_profiles')->where('user_id', $first->user_id)->update(['city' => 'Delhi']);
        app(TeacherPublicPages::class)->publish($first->user_id, $first->user_id);
        $this->get('/teacher/asha-sharma-kanpur')->assertStatus(301)->assertRedirect('http://localhost/teacher/asha-verma-delhi');
        $this->get('/in/delhi/maths-tuition')->assertOk()->assertSee('Asha Verma');
        $this->get('/in/kanpur/maths-tuition')->assertOk()->assertDontSee('Asha Verma');
    }

    public function test_missing_country_and_ineligible_teachers_do_not_publish_empty_pages(): void
    {
        $missing = $this->teacher(country: null);
        $this->assertNull(TeacherPublicPages::urls($missing)['teacherSeoUrl']);
        $valid = $this->teacher();
        DB::table('users')->where('id', $valid->user_id)->update(['suspended_at' => now()]);
        $this->get('/teacher/'.$valid->public_page_slug)->assertNotFound();
        $this->get('/in/kanpur/maths-tuition')->assertNotFound();
        $this->get('/in/online/maths-tuition')->assertNotFound();
        $xml = $this->get('/sitemap.xml')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('/sitemaps/teachers-', $xml);
    }

    public function test_explicit_country_edit_overrides_an_old_country_code(): void
    {
        $teacher = $this->teacher();
        DB::table('teacher_profiles')->where('user_id', $teacher->user_id)->update(['country_code' => 'IN', 'country' => 'Spain']);
        app(TeacherPublicPages::class)->publish($teacher->user_id);
        $this->assertSame('es', DB::table('teacher_profiles')->where('user_id', $teacher->user_id)->value('seo_country'));
        $this->get('/in/kanpur/maths-tuition')->assertNotFound();
        $this->get('/es/kanpur/clases-particulares-de-matematicas')->assertOk()->assertSee('<html lang="es"', false);
    }

    public function test_untranslated_subjects_wait_for_admin_localized_wording(): void
    {
        $domain = DB::table('subjects')->where('id', $this->subjects['Maths'])->value('domain_id');
        $this->subjects['Guitar'] = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Guitar']);
        $this->teacher(subjects: ['Guitar']);
        $this->get('/in/kanpur/guitar-tuition')->assertOk();
        $this->get('/in/hi/kanpur/guitar-tuition')->assertNotFound();
        $this->withSession(['admin_authenticated' => true])->post('/admin/seo/subjects', [
            'subject_id' => $this->subjects['Guitar'], 'country' => 'in', 'language' => 'hi',
            'display_name' => 'गिटार', 'city_slug' => 'guitar-tuition', 'online_slug' => 'guitar-tuition', 'enabled' => true,
        ])->assertRedirect();
        $this->get('/in/hi/kanpur/guitar-tuition')->assertStatus(301)
            ->assertRedirect('http://localhost/in/kanpur/guitar-tuition?lang=hi');
        $this->get('/in/kanpur/guitar-tuition?lang=hi')->assertOk()->assertSee('गिटार');
    }

    public function test_admin_mapping_edits_redirect_old_listing_paths_and_export_logged_reports(): void
    {
        $this->teacher();
        $this->get('/admin/seo/subjects')->assertRedirect('/admin/login');
        $this->post('/admin/seo/sync')->assertRedirect('/admin/login');
        $route = DB::table('seo_subject_routes')->where('country', 'in')->where('language', 'en')->first();
        $body = ['id' => $route->id, 'subject_id' => $route->subject_id, 'country' => 'in', 'language' => 'en',
            'display_name' => 'Mathematics', 'city_slug' => 'mathematics-tuition', 'online_slug' => 'maths-tuition', 'enabled' => true];
        $this->withSession(['admin_authenticated' => true])->post('/admin/seo/subjects', $body)->assertRedirect();
        $this->get('/in/kanpur/maths-tuition')->assertStatus(301)->assertRedirect('http://localhost/in/kanpur/mathematics-tuition');
        $this->get('/in/kanpur/mathematics-tuition')->assertOk()->assertSee('Mathematics');
        $this->post('/admin/seo/subjects', [
            'subject_id' => $this->subjects['English'], 'country' => 'in', 'language' => 'en',
            'display_name' => 'English', 'city_slug' => 'maths-tuition', 'online_slug' => 'english-tuition', 'enabled' => true,
        ])->assertSessionHasErrors('city_slug');
        $this->get('/admin/reports/seo-pages?q=mathematics-tuition')->assertOk()->assertSee('Open page')->assertSee('Published');
        $csv = $this->get('/admin/reports/seo-pages?q=mathematics-tuition&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('/in/kanpur/mathematics-tuition', $csv);
        $this->assertStringContainsString('eligible_providers', $csv);
        $this->assertGreaterThanOrEqual(3, DB::table('admin_report_access_log')->count());
        $this->post('/admin/seo/subjects', [...$body, 'enabled' => false])->assertRedirect();
        $this->get('/in/kanpur/mathematics-tuition')->assertNotFound();
        $this->get('/admin/reports/seo-pages?q=mathematics-tuition')->assertOk()->assertSee('Disabled');
    }

    public function test_listing_pagination_and_streamed_sitemaps_use_new_canonical_urls(): void
    {
        foreach (range(1, 21) as $index) {
            $this->teacher('Teacher '.$index, modes: ['in_person']);
        }
        $this->get('/in/kanpur/maths-tuition?page=2')->assertOk()
            ->assertSee('<link rel="canonical" href="http://localhost/in/kanpur/maths-tuition?page=2">', false);
        $this->get('/in/kanpur/maths-tuition?page=999')->assertNotFound();
        $index = $this->get('/sitemap.xml')->assertOk()->streamedContent();
        $this->assertStringContainsString('/sitemaps/teachers-1.xml', $index);
        $xml = $this->get('/sitemaps/directory-1.xml')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('hreflang=', $xml);
        $this->assertStringNotContainsString('/in/hi/', $xml);
        $this->assertStringContainsString('/in/kanpur/maths-tuition', $xml);
        $this->assertStringNotContainsString('/teachers/', $xml);
        $this->get('/sitemaps/directory-999.xml')->assertNotFound();
    }

    public function test_publication_rolls_back_source_state_url_registry_and_history_together(): void
    {
        $teacher = $this->teacher();
        $this->app->instance(BusinessActivityRecorder::class, new class
        {
            public function record(...$arguments): never
            {
                throw new \RuntimeException('History unavailable');
            }
        });
        try {
            DB::transaction(function () use ($teacher): void {
                DB::table('teacher_profiles')->where('user_id', $teacher->user_id)->update(['city' => 'Delhi']);
                app(TeacherPublicPages::class)->publish($teacher->user_id);
            });
            $this->fail('Expected publication failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('History unavailable', $error->getMessage());
        }
        $this->assertSame('Kanpur', DB::table('teacher_profiles')->where('user_id', $teacher->user_id)->value('city'));
        $this->assertFalse(DB::table('seo_urls')->where('path', '/teacher/asha-sharma-delhi')->exists());
    }
}
