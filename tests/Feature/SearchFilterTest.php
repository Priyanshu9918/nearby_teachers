<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class SearchFilterTest extends TestCase
{
    private bool $transactionStarted = false;

    private int $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set ONBOARDING_TEST_DATABASE to a disposable database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
            'database.connections.mysql.database' => $database, 'app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Public filter tests']);
        $this->subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Filter Course']);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function provider(string $type = 'teacher', array $profile = []): int
    {
        $id = NumericTestIds::next();
        DB::table('users')->insert(['id' => $id, 'account_type' => $type, 'full_name' => 'Filter Provider',
            'phone_verified_at' => now(), 'profile_completed_at' => now(), 'avatar_status' => 'pending',
            'avatar_url' => 'https://example.test/private-pending.png']);
        DB::table($type.'_profiles')->insert(array_merge([
            'user_id' => $id, 'is_profile_complete' => true, 'city' => 'Noida', 'country' => 'India',
            'latitude' => 28.58, 'longitude' => 77.31, 'currency' => 'INR',
            'lesson_modes' => '["online","in_person"]', 'trial_days' => 3,
            $type === 'teacher' ? 'rate_per_hour' : 'starting_rate' => 500,
        ], $profile));
        DB::table('profile_subjects')->insert(['profile_type' => $type, 'profile_id' => $id, 'subject_id' => $this->subject]);

        return $id;
    }

    private function search(array $filters = [])
    {
        return $this->getJson('/search/filter?'.http_build_query(array_merge([
            'mode' => 'nearby', 'lat' => 28.58, 'lng' => 77.31, 'subjectId' => $this->subject,
        ], $filters)));
    }

    public function test_public_combined_filters_match_both_provider_types_before_pagination(): void
    {
        $teacher = $this->provider();
        $institute = $this->provider('institute');
        $this->provider('teacher', ['rate_per_hour' => 1000]);
        $this->provider('teacher', ['currency' => 'USD']);
        $this->provider('teacher', ['lesson_modes' => '["home_tuition"]']);
        $this->provider('teacher', ['trial_days' => 0]);
        $this->provider('teacher', ['latitude' => 29.58]);
        $filters = ['maxPrice' => 500, 'lessonMode' => 'online', 'trialDays' => 3, 'perPage' => 1, 'radiusKm' => 7];
        $response = $this->search($filters)->assertOk()->assertJsonPath('totalCount', 2)->assertJsonCount(1, 'results');
        $first = $response->json('results.0');
        $second = $this->search($filters + ['page' => 2])->assertOk()->assertJsonPath('totalCount', 2)->json('results.0');
        $this->assertEqualsCanonicalizing([$teacher, $institute], [$first['user_id'], $second['user_id']]);
        foreach ([$first, $second] as $provider) {
            $this->assertSame([['id' => $this->subject, 'name' => 'Filter Course', 'is_bsl' => false]], $provider['subjects']);
        }
        foreach (['phone', 'email', 'latitude', 'longitude', 'address_text', 'password_hash'] as $private) {
            $this->assertArrayNotHasKey($private, $first);
        }
        $this->assertNull($first['avatar_url']);
        $this->assertDatabaseHas('search_analytics', ['search_type' => 'filtered', 'max_price' => 500,
            'currency' => 'INR', 'lesson_mode' => 'online', 'trial_days' => 3, 'result_count' => 2]);
    }

    public function test_free_zero_trial_and_open_ended_price_filters(): void
    {
        $free = $this->provider('teacher', ['rate_per_hour' => 0, 'trial_days' => 0]);
        $expensive = $this->provider('institute', ['starting_rate' => 2500]);
        $this->provider('teacher', ['rate_per_hour' => null]);
        $this->search(['freeOnly' => 'true', 'trialDays' => 0])->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.user_id', $free);
        $this->search(['maxPrice' => 0])->assertOk()->assertJsonCount(1, 'results');
        $this->search(['minPrice' => 2000])->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.user_id', $expensive);
    }

    public function test_city_country_and_online_modes_apply_class_filters(): void
    {
        $local = $this->provider('teacher', ['lesson_modes' => '["home_tuition"]']);
        $remote = $this->provider('institute', ['city' => 'Delhi']);
        $this->search(['mode' => 'other-city', 'city' => 'noida', 'lessonMode' => 'home_tuition'])->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.user_id', $local);
        $this->search(['mode' => 'other-city', 'country' => 'India'])->assertOk()->assertJsonPath('totalCount', 2);
        $this->search(['mode' => 'online'])->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.user_id', $remote);
        $this->search(['mode' => 'online', 'country' => 'USA'])->assertOk()->assertJsonPath('totalCount', 0);
    }

    public function test_ineligible_profiles_are_excluded_and_bsl_price_filters_still_apply(): void
    {
        $draft = $this->provider('teacher', ['is_profile_complete' => false]);
        $suspended = $this->provider();
        DB::table('users')->where('id', $suspended)->update(['suspended_at' => now()]);
        $this->search()->assertOk()->assertJsonPath('totalCount', 0);
        DB::table('subjects')->where('id', $this->subject)->update(['is_bsl' => true]);
        $bsl = $this->provider('institute', ['is_bsl' => true, 'city' => 'London', 'latitude' => 51.5, 'longitude' => 0]);
        $this->search(['maxPrice' => 500, 'radiusKm' => 7])->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.user_id', $bsl);
        $this->search(['maxPrice' => 100])->assertOk()->assertJsonPath('totalCount', 0);
        foreach (['nearby', 'other-city', 'online'] as $mode) {
            $url = '/search/filter?'.http_build_query(['mode' => $mode, 'subjectId' => $this->subject, 'maxPrice' => 500]);
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.user_id', $bsl);
            $this->getJson($url.'&maxPrice=100')->assertOk()->assertJsonPath('totalCount', 0);
        }

        $this->assertNotSame($draft, $bsl);
    }

    public function test_invalid_filters_are_rejected_without_authentication(): void
    {
        $this->getJson('/search/filter')->assertUnprocessable()->assertJsonValidationErrors('mode');
        foreach ([['maxPrice' => -1], ['minPrice' => 500, 'maxPrice' => 100], ['trialDays' => 8],
            ['lessonMode' => 'invalid'], ['perPage' => 51], ['radiusKm' => 0], ['lat' => 91],
            ['mode' => 'other-city'], ['mode' => 'online', 'lessonMode' => 'in_person'],
            ['freeOnly' => 'true', 'minPrice' => 1]] as $filters) {
            $this->search($filters)->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('search_analytics')->where('subject_id', $this->subject)->count());
    }

    public function test_no_radius_returns_all_matches_nearest_first_with_unknown_coordinates_last(): void
    {
        $near = $this->provider();
        $far = $this->provider('institute', ['latitude' => 29.58]);
        $unknown = $this->provider('teacher', ['latitude' => null, 'longitude' => null]);
        $this->provider('teacher', ['rate_per_hour' => 1000]);
        DB::table('users')->where('id', $far)->update(['referral_points' => 1000]);
        DB::table('users')->where('id', $unknown)->update(['referral_points' => 2000]);
        foreach (['nearby', 'other-city', 'online'] as $mode) {
            $response = $this->search(['mode' => $mode, 'city' => 'Noida', 'maxPrice' => 500])
                ->assertOk()->assertJsonPath('totalCount', 3);
            $this->assertSame([$near, $far, $unknown], array_column($response->json('results'), 'user_id'));
            $this->assertGreaterThan(7, $response->json('results.1.distance_km'));
            $response->assertJsonPath('results.2.distance_km', null);
        }
        $this->search(['radiusKm' => 7, 'maxPrice' => 500])->assertOk()->assertJsonPath('totalCount', 1);
        $this->assertDatabaseHas('search_analytics', ['subject_id' => $this->subject, 'radius_km' => null, 'result_count' => 3]);
    }

    public function test_coordinates_are_optional_but_must_be_paired_and_are_required_for_radius(): void
    {
        $this->provider();
        $base = '/search/filter?mode=nearby&subjectId='.$this->subject;
        $this->getJson($base)->assertOk()->assertJsonPath('totalCount', 1)->assertJsonPath('results.0.distance_km', null);
        $this->getJson($base.'&lat=28.58')->assertUnprocessable()->assertJsonValidationErrors('lng');
        $this->getJson($base.'&lng=77.31')->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->getJson($base.'&radiusKm=7')->assertUnprocessable()->assertJsonValidationErrors(['lat', 'lng']);
    }

    public function test_search_filter_history_is_admin_only_and_exported_with_access_logging(): void
    {
        $this->search(['maxPrice' => 500, 'lessonMode' => 'online', 'trialDays' => 0])->assertOk();
        $path = '/admin/reports/no-result-searches?q=filtered';
        $this->get($path)->assertRedirect('/admin/login');
        $this->get($path.'&export=1')->assertRedirect('/admin/login');
        $before = DB::table('admin_report_access_log')->where('report', 'no-result-searches')->count();
        $this->withSession(['admin_authenticated' => true])->get($path)->assertOk()->assertSee('max price');
        $csv = $this->get($path.'&export=1')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($csv)));
        $columns = array_flip($rows[0]);
        $this->assertSame('500.00', $rows[1][$columns['max_price']]);
        $this->assertSame('online', $rows[1][$columns['lesson_mode']]);
        $this->assertSame('0', $rows[1][$columns['trial_days']]);
        $this->assertSame($before + 2, DB::table('admin_report_access_log')->where('report', 'no-result-searches')->count());
    }
}
