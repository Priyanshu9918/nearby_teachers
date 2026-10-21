<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    private function provider(string $type, float $lat, float $lng): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id, 'account_type' => $type, 'full_name' => 'Map provider',
            'phone_verified_at' => now(), 'profile_completed_at' => now(),
            'avatar_url' => 'https://example.com/pending.jpg', 'avatar_status' => 'pending',
        ]);
        DB::table($type.'_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $id,
            'latitude' => $lat, 'longitude' => $lng, 'lesson_modes' => '["online"]',
            ($type === 'teacher' ? 'rate_per_hour' : 'starting_rate') => 500,
        ]);

        return $id;
    }
}
