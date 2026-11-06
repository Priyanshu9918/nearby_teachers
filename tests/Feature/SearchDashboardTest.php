<?php

namespace Tests\Feature;

use App\Services\BslInstitute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class SearchDashboardTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_dashboard_validates_coordinates_and_radius_before_querying(): void
    {
        $this->getJson('/search/dashboard')->assertUnprocessable()->assertJsonValidationErrors(['lat', 'lng']);
        foreach (['lat=91&lng=0', 'lat=0&lng=-181', 'lat=0&lng=0&radiusKm=0', 'lat=0&lng=0&radiusKm=501'] as $query) {
            $this->getJson('/search/dashboard?'.$query)->assertUnprocessable();
        }
    }

    public function test_dashboard_returns_all_nearby_listings_with_map_details_and_subjects(): void
    {
        $this->useTestDatabase();
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Dashboard test']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Map subject']);
        $teacher = $this->provider('teacher', 10, 20);
        $institute = $this->provider('institute', 10.01, 20);
        DB::table('profile_subjects')->insert([
            'profile_type' => 'teacher', 'profile_id' => $teacher, 'subject_id' => $subject,
        ]);
        foreach (range(1, 51) as $i) {
            $this->provider('teacher', 10.02, 20);
        }
        $outside = $this->provider('teacher', 11, 20);
        $response = $this->getJson('/search/dashboard?lat=10&lng=20&radiusKm=7')->assertOk()
            ->assertJsonPath('teacherCount', 52)->assertJsonPath('instituteCount', 1)
            ->assertJsonPath('totalCount', 53)->assertJsonCount(53, 'results')
            ->assertJsonPath('results.0.user_id', $teacher)
            ->assertJsonPath('results.0.latitude', 10)
            ->assertJsonPath('results.0.longitude', 20)
            ->assertJsonPath('results.0.lesson_modes', ['online'])
            ->assertJsonPath('results.0.price', 500)
            ->assertJsonPath('results.0.subjects.0.id', $subject)
            ->assertJsonPath('results.0.subjects.0.is_bsl', false)
            ->assertJsonPath('results.0.avatar_url', null);
        $rows = $response->json('results');
        $this->assertNotContains($outside, array_column($rows, 'user_id'));
        $distances = array_column($rows, 'distance_km');
        $sorted = $distances;
        sort($sorted);
        $this->assertSame($sorted, $distances);
        foreach ($rows as $row) {
            foreach (['phone', 'email', 'password_hash', 'guardian_contact', 'parental_consent_token'] as $privateField) {
                $this->assertArrayNotHasKey($privateField, $row);
            }
        }
        $instituteRow = collect($rows)->firstWhere('user_id', $institute);
        $this->assertSame('institute', $instituteRow['profile_type']);
        $this->assertSame('independent', $instituteRow['institute_type']);
        $this->assertSame([], $instituteRow['subjects']);
        $this->assertFalse($instituteRow['is_bsl_official']);
    }

    public function test_dashboard_excludes_unpublished_and_suspended_accounts_and_handles_empty_results(): void
    {
        $this->useTestDatabase();
        foreach (['suspended_at', 'phone_verified_at', 'profile_completed_at'] as $field) {
            $id = $this->provider('teacher', 10, 20);
            DB::table('users')->where('id', $id)->update([$field => $field === 'suspended_at' ? now() : null]);
        }
        $id = $this->provider('teacher', 10, 20);
        DB::table('teacher_profiles')->where('user_id', $id)->update(['latitude' => null, 'longitude' => null]);
        $this->getJson('/search/dashboard?lat=10&lng=20')->assertOk()
            ->assertJsonPath('radiusKm', 7)->assertJsonPath('totalCount', 0)
            ->assertJsonPath('teacherCount', 0)->assertJsonPath('instituteCount', 0)
            ->assertJsonPath('results', []);
    }

    public function test_dashboard_handles_dateline_and_exact_radius_instead_of_only_a_bounding_box(): void
    {
        $this->useTestDatabase();
        $acrossDateline = $this->provider('teacher', 0, -179.99);
        $this->provider('institute', 0.06, 179.94); // Inside the bounding box, outside the 7 km circle.
        $this->getJson('/search/dashboard?lat=0&lng=180&radiusKm=7')->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.user_id', $acrossDateline);
        $this->getJson('/search/dashboard?lat=0&lng=-180&radiusKm=7')->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.user_id', $acrossDateline);
    }

    public function test_bsl_flag_requires_api_authentication(): void
    {
        $this->patchJson('/profiles/institute/1/bsl', ['is_bsl' => true])->assertUnauthorized();
    }

    public function test_bsl_activation_assigns_subjects_and_rejects_a_second_institute(): void
    {
        $this->useTestDatabase();
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'BSL activation test']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'BSL course', 'is_bsl' => true]);
        $normal = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Normal course']);
        $first = $this->provider('institute', 50, 50);
        $second = $this->provider('institute', 10, 20);
        DB::table('profile_subjects')->insert(['profile_type' => 'institute', 'profile_id' => $first, 'subject_id' => $normal]);
        $service = app(BslInstitute::class);
        DB::beginTransaction();
        $service->set($first, true);
        DB::rollBack();
        $this->assertDatabaseHas('profile_subjects', ['profile_type' => 'institute', 'profile_id' => $first, 'subject_id' => $normal]);
        $this->assertDatabaseMissing('profile_subjects', ['profile_type' => 'institute', 'profile_id' => $first, 'subject_id' => $subject]);
        $this->assertDatabaseHas('institute_profiles', ['user_id' => $first, 'is_bsl' => false]);
        $service->set($first, true);
        $this->assertDatabaseHas('profile_subjects', ['profile_type' => 'institute', 'profile_id' => $first, 'subject_id' => $subject]);
        $this->assertDatabaseMissing('profile_subjects', ['profile_type' => 'institute', 'profile_id' => $first, 'subject_id' => $normal]);
        try {
            $service->set($second, true);
            $this->fail('A second BSL institute must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_bsl', $e->errors());
        }
        $this->assertDatabaseHas('institute_profiles', ['user_id' => $second, 'is_bsl' => false]);
        $later = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Later BSL course', 'is_bsl' => true]);
        $service->syncSubjects();
        $this->assertDatabaseHas('profile_subjects', ['profile_type' => 'institute', 'profile_id' => $first, 'subject_id' => $later]);
        DB::beginTransaction();
        $service->set($first, false);
        DB::rollBack();
        $this->assertDatabaseHas('institute_profiles', ['user_id' => $first, 'is_bsl' => true]);
    }

    public function test_all_search_apis_route_bsl_subjects_to_the_single_global_institute(): void
    {
        $this->useTestDatabase();
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'BSL search test']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Exclusive BSL course', 'is_bsl' => true]);
        $teacher = $this->provider('teacher', 10, 20);
        DB::table('profile_subjects')->insert(['profile_type' => 'teacher', 'profile_id' => $teacher, 'subject_id' => $subject]);
        $institute = $this->provider('institute', 50, 50);
        app(BslInstitute::class)->set($institute, true);
        foreach ([
            '/search?subjectId='.$subject,
            '/search/dashboard?subjectId='.$subject,
            '/search/by-subject/'.$subject.'?tier=nearby',
            '/search/by-subject/'.$subject.'?tier=city',
            '/search/by-subject/'.$subject.'?tier=state',
            '/search/by-subject/'.$subject.'?tier=country',
            '/search?lat=10&lng=20&subjectId='.$subject,
            '/search?lat=10&lng=20&subjectId='.$subject.'&q=Exclusive%20BSL',
            '/search/other-city?subjectId='.$subject,

            '/search/dashboard?lat=10&lng=20&subjectId='.$subject,
            '/search/other-city?city=Unrelated&subjectId='.$subject,
            '/search/by-subject/'.$subject.'?tier=nearby&lat=10&lng=20',
            '/search/by-subject/'.$subject.'?tier=city&city=Unrelated',
            '/search/by-subject/'.$subject.'?tier=state&state=Unrelated',
            '/search/by-subject/'.$subject.'?tier=country&country=Unrelated',
        ] as $url) {
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'results')
                ->assertJsonPath('results.0.profile_type', 'institute')->assertJsonPath('results.0.user_id', $institute);
        }
        // BSL discovery must be identical for arbitrary, empty and missing cities.
        foreach (['Kanpur', 'Noida', 'Unknown City', ''] as $city) {
            foreach ([
                '/search/other-city?'.http_build_query(['subjectId' => $subject, 'city' => $city]),
                '/search/by-subject/'.$subject.'?'.http_build_query(['tier' => 'city', 'city' => $city]),
            ] as $url) {
                $this->getJson($url)->assertOk()->assertJsonCount(1, 'results')
                    ->assertJsonPath('results.0.user_id', $institute);
            }
        }
        $this->getJson('/search?lat=10&lng=20&q=Exclusive%20BSL')->assertOk()->assertJsonCount(0, 'results');
        $this->getJson('/search/counts?subjectId='.$subject)->assertOk()
            ->assertJsonPath('teacher_count', 0)->assertJsonPath('institute_count', 1);
        $this->getJson('/search/dashboard?lat=10&lng=20')->assertOk()
            ->assertJsonPath('results.0.user_id', $teacher);
        app(BslInstitute::class)->set($institute, false);
        $this->getJson('/search?lat=10&lng=20&subjectId='.$subject)->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_other_city_includes_bsl_without_a_subject_for_any_city(): void
    {
        $this->useTestDatabase();
        $institute = $this->provider('institute', 50, 50);
        app(BslInstitute::class)->set($institute, true);
        DB::table('institute_profiles')->where('user_id', $institute)
            ->update(['city' => 'Noida', 'lesson_modes' => '["in_person"]']);
        $teacher = $this->provider('teacher', 10, 20);
        DB::table('teacher_profiles')->where('user_id', $teacher)->update(['city' => 'Kanpur']);
        foreach (['Kanpur', 'Noida', 'Unknown City'] as $city) {
            $response = $this->getJson('/search/other-city?'.http_build_query(['city' => $city]))
                ->assertOk()->assertJsonPath('results.0.user_id', $institute)
                ->assertJsonPath('results.0.is_bsl', true);
            $ids = array_column($response->json('results'), 'user_id');
            if ($city === 'Kanpur') {
                $this->assertContains($teacher, $ids);
            } else {
                $this->assertNotContains($teacher, $ids);
            }
        }
        DB::table('users')->where('id', $institute)->update(['suspended_at' => now()]);
        $this->getJson('/search/other-city?city=UnknownCity')->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_city_and_subject_results_include_assigned_subject_names(): void
    {
        $this->useTestDatabase();
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Search subject response test']);
        $subject = DB::table('subjects')->insertGetId([
            'domain_id' => $domain, 'name' => 'Response Course', 'is_bsl' => false,
        ]);
        $teacher = $this->provider('teacher', 10, 20);
        DB::table('teacher_profiles')->where('user_id', $teacher)->update(['city' => 'SubjectResponseCity']);
        DB::table('profile_subjects')->insert([
            'profile_type' => 'teacher', 'profile_id' => $teacher, 'subject_id' => $subject,
        ]);
        foreach ([
            '/search/other-city?city=SubjectResponseCity',
            '/search/by-subject/'.$subject.'?tier=city&city=SubjectResponseCity',
            '/search/by-subject/'.$subject.'?tier=nearby&lat=10&lng=20',
        ] as $url) {
            $response = $this->getJson($url)->assertOk();
            $row = collect($response->json('results'))->firstWhere('user_id', $teacher);
            $this->assertNotNull($row);
            $this->assertSame([
                ['id' => $subject, 'name' => 'Response Course', 'is_bsl' => false],
            ], $row['subjects']);
        }
    }

    private function useTestDatabase(): void
    {
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set ONBOARDING_TEST_DATABASE to an isolated MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        // Keep fixtures isolated from any earlier test runs' committed provider data.
        DB::table('teacher_profiles')->update(['latitude' => null, 'longitude' => null]);
        DB::table('institute_profiles')->update(['latitude' => null, 'longitude' => null]);
    }

    private function provider(string $type, float $lat, float $lng): int
    {
        $id = NumericTestIds::next();
        DB::table('users')->insert([
            'id' => $id, 'account_type' => $type, 'full_name' => 'Map provider',
            'phone_verified_at' => now(), 'profile_completed_at' => now(),
            'avatar_url' => 'https://example.com/pending.jpg', 'avatar_status' => 'pending',
        ]);
        DB::table($type.'_profiles')->insert([
            'id' => NumericTestIds::next(), 'user_id' => $id,
            'latitude' => $lat, 'longitude' => $lng, 'lesson_modes' => '["online"]',
            ($type === 'teacher' ? 'rate_per_hour' : 'starting_rate') => 500,
        ]);

        return $id;
    }
}
