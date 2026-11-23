<?php

namespace Tests\Feature;

use App\Reporting\OwnerMetrics;
use App\Services\AuthenticationService;
use App\Services\LocationIdentity;
use App\Services\ProfileDraft;
use App\Services\SeoDirectory;
use App\Services\TeacherAddress;
use App\Services\TeacherPublicPages;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocationIdentityTest extends TestCase
{
    private bool $transactionStarted = false;

    private int $country;

    private int $state;

    private int $city;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Requires disposable ONBOARDING_TEST_DATABASE ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database, 'app.url' => 'http://localhost', 'seo.public_url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        DB::purge('mysql');
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->country = DB::table('countries')->insertGetId(['name' => 'India', 'sortname' => 'IN', 'phonecode' => 91]);
        $this->state = DB::table('states')->insertGetId(['name' => 'Uttar Pradesh', 'country_id' => $this->country]);
        $this->city = DB::table('cities')->insertGetId(['name' => 'Kanpur', 'state_id' => $this->state]);
        DB::table('location_translations')->insert(['location_type' => 'city', 'location_id' => $this->city, 'language' => 'hi', 'name' => 'कानपुर']);
        $this->user = DB::table('users')->insertGetId(['full_name' => 'Location Test', 'account_type' => 'teacher']);
        DB::table('teacher_profiles')->insert(['user_id' => $this->user, 'city' => 'कानपुर', 'state' => 'Uttar Pradesh', 'country' => 'India', 'country_code' => 'IN', 'lesson_modes' => '[]']);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_numeric_city_derives_parents_and_ignores_display_labels(): void
    {
        $data = app(LocationIdentity::class)->normalize(['cityId' => $this->city, 'city' => 'गलत', 'country' => 'गलत']);
        $this->assertSame('Kanpur', $data['city']);
        $this->assertSame($this->country, $data['countryId']);
        $this->assertSame($this->state, $data['stateId']);
        $this->assertSame('IN', $data['countryCode']);
        $this->assertSame('in', SeoDirectory::country((object) ['country_id' => $this->country, 'country' => 'गलत']));
    }

    public function test_sub_district_catalog_id_is_saved_and_its_parent_is_checked(): void
    {
        $district = DB::table('districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'city_id' => $this->city, 'name' => 'Kanpur Nagar']);
        $subDistrict = DB::table('sub_districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'city_id' => $this->city, 'district_id' => $district, 'name' => 'Kanpur Sadar']);
        DB::transaction(fn () => app(TeacherAddress::class)->store($this->user, ['subDistrictId' => $subDistrict]));
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'sub_district_id' => $subDistrict, 'district_id' => $district, 'city_id' => $this->city]);
        $this->getJson('/geo/sub-districts?district_id='.$district)->assertOk()->assertJsonPath('sub_districts.0.id', $subDistrict);

        $other = DB::table('districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'name' => 'Other District']);
        $this->expectException(ValidationException::class);
        app(LocationIdentity::class)->normalize(['subDistrictId' => $subDistrict, 'districtId' => $other]);
    }

    public function test_maps_text_resolves_on_server_without_client_ids(): void
    {
        $district = DB::table('districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'name' => 'Kanpur Nagar']);
        $subDistrict = DB::table('sub_districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'city_id' => $this->city, 'district_id' => $district, 'name' => 'Kanpur Sadar']);
        $payload = ['countryCode' => 'IN', 'state' => 'Uttar Pradesh', 'city' => ' kanpur ', 'district' => 'Kanpur Nagar', 'subDistrict' => 'Kanpur Sadar'];
        $this->postJson('/geo/resolve', $payload)->assertOk()
            ->assertJsonPath('locations.city.id', $this->city)
            ->assertJsonPath('locations.district.id', $district)
            ->assertJsonPath('locations.subDistrict.id', $subDistrict);
        DB::transaction(fn () => app(TeacherAddress::class)->store($this->user, $payload));
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'city_id' => $this->city, 'district_id' => $district, 'sub_district_id' => $subDistrict]);
    }

    public function test_registration_save_creates_missing_city_district_and_sub_district_once(): void
    {
        $input = ['countryCode' => 'IN', 'state' => 'Uttar Pradesh', 'city' => 'New City',
            'district' => 'New District', 'subDistrict' => 'New Tehsil'];
        $this->postJson('/geo/resolve', $input)->assertOk()->assertJsonPath('locations.city.status', 'unresolved');
        $this->assertDatabaseMissing('cities', ['name' => 'New City']);
        DB::transaction(fn () => app(TeacherAddress::class)->store($this->user, $input));
        $city = DB::table('cities')->where('state_id', $this->state)->where('name', 'New City')->first();
        $this->assertNotNull($city);
        $district = DB::table('districts')->where('city_id', $city->id)->where('name', 'New District')->first();
        $this->assertNotNull($district);
        $subDistrict = DB::table('sub_districts')->where('district_id', $district->id)->where('name', 'New Tehsil')->first();
        $this->assertNotNull($subDistrict);
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'city_id' => $city->id,
            'district_id' => $district->id, 'sub_district_id' => $subDistrict->id]);
        DB::transaction(fn () => app(TeacherAddress::class)->store($this->user, $input));
        $this->assertSame(1, DB::table('cities')->where('state_id', $this->state)->where('name', 'New City')->count());
        $this->assertDatabaseHas('business_activity', ['event' => 'location.city_registered', 'entity_id' => $city->id]);
    }

    public function test_existing_catalog_rows_link_to_profile_and_owner_response(): void
    {
        $district = DB::table('districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'name' => 'Kanpur Nagar']);
        $subDistrict = DB::table('sub_districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state,
            'district_id' => $district, 'name' => 'Kanpur Sadar']);
        DB::table('teacher_profiles')->where('user_id', $this->user)->update(['district' => 'Kanpur Nagar', 'sub_district' => 'Kanpur Sadar']);
        DB::transaction(fn () => app(LocationIdentity::class)->store($this->user, [], 'teacher', true));
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'district_id' => $district, 'sub_district_id' => $subDistrict]);
        $profile = DB::table('teacher_profiles')->where('user_id', $this->user)->first();
        $draft = ProfileDraft::loadForOwner($profile, $this->user, 'teacher', 'en');
        $this->assertSame($subDistrict, $draft['steps'][2]['data']['subDistrictId']);
    }

    public function test_inconsistent_parent_ids_are_rejected(): void
    {
        $other = DB::table('countries')->insertGetId(['name' => 'Other', 'sortname' => 'ZZ', 'phonecode' => 999]);
        $this->expectException(ValidationException::class);
        app(LocationIdentity::class)->normalize(['cityId' => $this->city, 'countryId' => $other]);
    }

    public function test_unassigned_city_rejects_a_state_that_is_not_in_its_hierarchy(): void
    {
        $countryCity = DB::table('cities')->insertGetId(['name' => 'Country-only city', 'country_id' => $this->country]);
        $this->expectException(ValidationException::class);
        app(LocationIdentity::class)->normalize(['cityId' => $countryCity, 'stateId' => $this->state]);
    }

    public function test_translated_dropdown_keeps_same_numeric_id(): void
    {
        $this->getJson('/geo/cities?state_id='.$this->state.'&lang=hi&search='.rawurlencode('कानपुर'))
            ->assertOk()->assertJsonCount(1, 'cities')->assertJsonPath('cities.0.id', $this->city)
            ->assertJsonPath('cities.0.name', 'कानपुर')->assertJsonPath('cities.0.canonical_name', 'Kanpur');
    }

    public function test_country_only_cities_can_be_selected_without_inventing_a_state(): void
    {
        $city = DB::table('cities')->insertGetId(['name' => 'Country-only city', 'country_id' => $this->country]);

        $this->getJson('/geo/cities?country_id='.$this->country)
            ->assertOk()->assertJsonPath('cities.0.id', $city)->assertJsonPath('cities.0.state_id', null)
            ->assertJsonPath('cities.0.country_id', $this->country);
    }

    public function test_backfill_links_ids_without_changing_labels_urls_or_page_counts_and_is_rollback_safe(): void
    {
        $beforeUrls = DB::table('seo_urls')->count();
        $beforePages = DB::table('seo_location_pages')->count();
        DB::beginTransaction();
        app(LocationIdentity::class)->store($this->user, [], 'teacher', true);
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'city_id' => $this->city, 'city' => 'कानपुर']);
        $this->assertDatabaseHas('business_activity', ['event' => 'teacher.location_identity_changed', 'entity_id' => $this->user]);
        DB::rollBack();
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'city_id' => null]);
        $this->assertDatabaseMissing('business_activity', ['event' => 'teacher.location_identity_changed', 'entity_id' => $this->user]);
        $this->assertSame($beforeUrls, DB::table('seo_urls')->count());
        $this->assertSame($beforePages, DB::table('seo_location_pages')->count());
    }

    public function test_same_location_language_switch_preserves_existing_seo_labels(): void
    {
        $locations = app(LocationIdentity::class);
        $locations->store($this->user, [], 'teacher', true);
        $data = $locations->forProfile(['cityId' => $this->city], DB::table('teacher_profiles')->where('user_id', $this->user)->first());
        $this->assertSame('कानपुर', $data['city']);
        app(TeacherAddress::class)->store($this->user, $data);
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'city_id' => $this->city, 'city' => 'कानपुर']);
    }

    public function test_owner_draft_localizes_display_names_while_returning_stable_ids(): void
    {
        app(LocationIdentity::class)->store($this->user, [], 'teacher', true);
        $profile = DB::table('teacher_profiles')->where('user_id', $this->user)->first();
        $user = DB::table('users')->where('id', $this->user)->first();
        $draft = ProfileDraft::forOwner($profile, $user, 'teacher', [], 'hi');

        $this->assertSame($this->city, $draft['steps'][2]['data']['cityId']);
        $this->assertSame('कानपुर', $draft['steps'][2]['data']['city']);
    }

    public function test_changing_city_by_legacy_label_relinks_city_and_drops_stale_child_ids(): void
    {
        $otherCity = DB::table('cities')->insertGetId(['name' => 'Lucknow', 'state_id' => $this->state]);
        $district = DB::table('districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'name' => 'Old District']);
        $locality = DB::table('localities')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state,
            'city_id' => $this->city, 'district_id' => $district, 'name' => 'Old Locality']);
        DB::table('teacher_profiles')->where('user_id', $this->user)->update([
            'country_id' => $this->country, 'state_id' => $this->state, 'city_id' => $this->city,
            'district_id' => $district, 'locality_id' => $locality,
        ]);

        app(TeacherAddress::class)->store($this->user, ['city' => 'Lucknow']);

        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'city_id' => $otherCity]);
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'district_id' => null, 'locality_id' => null]);
    }

    public function test_maps_city_text_does_not_inherit_a_stale_other_city_id(): void
    {
        $lucknow = DB::table('cities')->insertGetId(['name' => 'Lucknow', 'state_id' => $this->state, 'country_id' => $this->country]);
        $payload = app(LocationIdentity::class)->forProfile(['countryCode' => 'IN', 'state' => 'Uttar Pradesh',
            'city' => 'Kasaya', 'cityId' => $lucknow], DB::table('teacher_profiles')->where('user_id', $this->user)->first());
        $this->assertSame('Kasaya', $payload['city']);
        $this->assertArrayNotHasKey('cityId', $payload);
        DB::transaction(fn () => app(TeacherAddress::class)->store($this->user, $payload));
        $kasaya = DB::table('cities')->where('state_id', $this->state)->where('name', 'Kasaya')->first();
        $this->assertNotNull($kasaya);
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'city' => 'Kasaya', 'city_id' => $kasaya->id]);
        $this->assertNotEquals($lucknow, $kasaya->id);
    }

    public function test_admin_location_id_update_keeps_existing_city_and_resolves_its_parents(): void
    {
        $this->withSession(['admin_authenticated' => true])->put('/admin/teachers/'.$this->user, [
            'full_name' => 'Location Test', 'email' => null, 'phone' => '1234567890', 'countryId' => $this->country,
        ])->assertRedirect('/admin/teachers');

        $this->assertDatabaseHas('teacher_profiles', [
            'user_id' => $this->user, 'country_id' => $this->country, 'state_id' => $this->state, 'city_id' => $this->city,
            'city' => 'कानपुर',
        ]);
    }

    public function test_owner_metrics_group_locations_by_id_and_keep_duplicate_queries_valid(): void
    {
        $firstDistrict = DB::table('districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'name' => 'Same label']);
        $secondDistrict = DB::table('districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'name' => 'Same label']);
        DB::table('teacher_profiles')->where('user_id', $this->user)->update(['district_id' => $firstDistrict]);
        $secondUser = DB::table('users')->insertGetId(['full_name' => 'Location Test', 'account_type' => 'teacher']);
        DB::table('teacher_profiles')->insert(['user_id' => $secondUser, 'city' => 'Kanpur', 'state' => 'Uttar Pradesh',
            'country' => 'India', 'district' => 'Same label', 'country_code' => 'IN', 'district_id' => $secondDistrict, 'lesson_modes' => '[]']);

        $metrics = app(OwnerMetrics::class);
        $this->assertSame(2, $metrics->snapshot()['districts']);
        $metrics->queues();
    }

    public function test_numeric_search_matches_location_identity_across_translated_labels(): void
    {
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Location tests']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Location course']);
        DB::table('users')->where('id', $this->user)->update(['phone_verified_at' => now(), 'profile_completed_at' => now()]);
        DB::table('teacher_profiles')->where('user_id', $this->user)->update(['is_profile_complete' => true, 'lesson_modes' => '["online"]']);
        DB::table('profile_subjects')->insert(['profile_type' => 'teacher', 'profile_id' => $this->user, 'subject_id' => $subject]);
        app(LocationIdentity::class)->store($this->user, [], 'teacher', true);
        foreach ([
            '/search/filter?mode=other-city&cityId='.$this->city,
            '/search/other-city?cityId='.$this->city,
            '/search/by-subject/'.$subject.'?tier=city&cityId='.$this->city,
            '/search/by-subject/'.$subject.'?tier=country&countryId='.$this->country,
        ] as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('results.0.user_id', $this->user)
                ->assertJsonPath('results.0.city_id', $this->city);
        }
    }

    public function test_republishing_same_location_keeps_existing_page_paths_and_count(): void
    {
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'SEO identity tests']);
        $subject = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Mathematics']);
        $locations = app(LocationIdentity::class);
        $locations->store($this->user, [], 'teacher', true);
        DB::table('users')->where('id', $this->user)->update(['phone_verified_at' => now(), 'profile_completed_at' => now()]);
        DB::table('teacher_profiles')->where('user_id', $this->user)->update(['is_profile_complete' => true]);
        DB::table('profile_subjects')->insert(['profile_type' => 'teacher', 'profile_id' => $this->user, 'subject_id' => $subject]);
        app(TeacherPublicPages::class)->publish($this->user);
        // Warm the existing publisher's legacy alias reservations before changing language.
        app(TeacherPublicPages::class)->publish($this->user);
        $before = DB::table('seo_directory_pages')->orderBy('id')->pluck('path')->all();
        $urls = DB::table('seo_urls')->orderBy('id')->pluck('path')->all();
        $data = $locations->forProfile(['cityId' => $this->city, 'city' => 'Kanpur'], DB::table('teacher_profiles')->where('user_id', $this->user)->first());
        app(TeacherAddress::class)->store($this->user, $data);
        app(TeacherPublicPages::class)->publish($this->user);
        $this->assertSame($before, DB::table('seo_directory_pages')->orderBy('id')->pluck('path')->all());
        $this->assertSame($urls, DB::table('seo_urls')->orderBy('id')->pluck('path')->all());
        $this->assertCount(1, $before);
        $page = app(SeoDirectory::class)->pages()->first();
        $this->assertSame(1, app(SeoDirectory::class)->matching($page)->count());
    }

    public function test_profile_api_accepts_ids_and_rejects_cross_country_mutation(): void
    {
        DB::table('users')->where('id', $this->user)->update(['phone_verified_at' => now(), 'profile_completed_at' => now()]);
        $auth = \Mockery::mock(AuthenticationService::class);
        $auth->shouldReceive('authenticate')->with('location-token')->andReturnUsing(fn () => (new AuthenticationService)->publicUser(DB::table('users')->where('id', $this->user)->first()));
        $this->app->instance(AuthenticationService::class, $auth);
        $this->withToken('location-token')->patchJson('/profiles/me/steps/3', ['cityId' => $this->city])
            ->assertOk()->assertJsonPath('profileDraft.steps.2.data.cityId', $this->city);
        $other = DB::table('countries')->insertGetId(['name' => 'Other', 'sortname' => 'ZZ', 'phonecode' => 999]);
        $this->patchJson('/profiles/me/steps/3', ['cityId' => $this->city, 'countryId' => $other])->assertUnprocessable();
        $this->assertDatabaseHas('teacher_profiles', ['user_id' => $this->user, 'country_id' => $this->country, 'city_id' => $this->city]);
    }

    public function test_new_subjects_reuse_the_existing_district_and_village_paths_for_the_same_ids(): void
    {
        $district = DB::table('districts')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'name' => 'English District']);
        $locality = DB::table('localities')->insertGetId(['country_id' => $this->country, 'state_id' => $this->state, 'city_id' => $this->city, 'district_id' => $district, 'name' => 'English Village']);
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Parent SEO paths']);
        $first = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Mathematics']);
        $second = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Physics']);
        DB::table('users')->where('id', $this->user)->update(['phone_verified_at' => now(), 'profile_completed_at' => now()]);
        DB::table('teacher_profiles')->where('user_id', $this->user)->update(['country_id' => $this->country, 'state_id' => $this->state, 'city_id' => $this->city, 'district_id' => $district, 'locality_id' => $locality, 'district' => 'पुराना जिला', 'locality' => 'पुराना गांव', 'is_profile_complete' => true]);
        DB::table('profile_subjects')->insert(['profile_type' => 'teacher', 'profile_id' => $this->user, 'subject_id' => $first]);
        app(TeacherPublicPages::class)->publish($this->user);
        $parent = DB::table('seo_location_pages')->where('kind', 'village')->value('path');
        $other = DB::table('users')->insertGetId(['full_name' => 'Second Teacher', 'account_type' => 'teacher', 'phone_verified_at' => now(), 'profile_completed_at' => now()]);
        DB::table('teacher_profiles')->insert(['user_id' => $other, 'country_id' => $this->country, 'state_id' => $this->state, 'city_id' => $this->city, 'district_id' => $district, 'locality_id' => $locality, 'city' => 'Kanpur', 'country' => 'India', 'state' => 'Uttar Pradesh', 'district' => 'English District', 'locality' => 'English Village', 'lesson_modes' => '[]', 'is_profile_complete' => true]);
        DB::table('profile_subjects')->insert(['profile_type' => 'teacher', 'profile_id' => $other, 'subject_id' => $second]);
        app(TeacherPublicPages::class)->publish($other);
        $this->assertSame(1, DB::table('seo_location_pages')->where('kind', 'district')->count());
        $this->assertSame(1, DB::table('seo_location_pages')->where('kind', 'village')->count());
        $this->assertStringStartsWith($parent.'/', DB::table('seo_location_pages')->where('subject_id', $second)->value('path'));
        $page = DB::table('seo_location_pages')->where('kind', 'village')->first();
        $providers = app(SeoDirectory::class)->locationProviders($page)->get(['provider_type', 'user_id']);
        $this->assertCount(2, $providers);
        $listedPage = app(SeoDirectory::class)->locationPages()->where('lp.kind', 'village')->first();
        $this->assertSame(2, (int) $listedPage->eligible_providers);
    }

    public function test_catalog_preview_rolls_back_and_reports_require_admin_access(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'reviewed_locations_');
        file_put_contents($file, json_encode([['type' => 'district', 'countryId' => $this->country, 'stateId' => $this->state, 'name' => 'Reviewed District']]));
        try {
            Artisan::call('locations:import', ['file' => $file]);
            $this->assertDatabaseMissing('districts', ['name' => 'Reviewed District']);
            $this->assertDatabaseMissing('business_activity', ['event' => 'location.district_registered']);
            Artisan::call('locations:import', ['file' => $file, '--apply' => true]);
            Artisan::call('locations:import', ['file' => $file, '--apply' => true]);
            $this->assertSame(1, DB::table('districts')->where('name', 'Reviewed District')->count());
        } finally {
            unlink($file);
        }
        $url = '/admin/reports/location-translations';
        $this->get($url)->assertRedirect('/admin/login');
        $this->get($url.'?export=1')->assertRedirect('/admin/login');
        $this->withSession(['admin_authenticated' => true])->get($url)->assertOk();
        $csv = $this->get($url.'?export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('कानपुर', $csv);
        $this->assertDatabaseHas('admin_report_access_log', ['report' => 'location-translations']);
    }

    public function test_catalog_import_assigns_a_city_country_once_and_records_history(): void
    {
        $city = DB::table('cities')->insertGetId(['name' => 'Reviewed country-only city']);
        $file = tempnam(sys_get_temp_dir(), 'reviewed_city_');
        file_put_contents($file, json_encode([['type' => 'city', 'id' => $city, 'countryId' => $this->country]]));
        try {
            Artisan::call('locations:import', ['file' => $file]);
            $this->assertDatabaseHas('cities', ['id' => $city, 'country_id' => null]);
            $this->assertDatabaseMissing('business_activity', ['event' => 'location.city_country_assigned', 'entity_id' => $city]);

            Artisan::call('locations:import', ['file' => $file, '--apply' => true]);
            Artisan::call('locations:import', ['file' => $file, '--apply' => true]);
            $this->assertDatabaseHas('cities', ['id' => $city, 'country_id' => $this->country]);
            $this->assertSame(1, DB::table('business_activity')->where('event', 'location.city_country_assigned')->where('entity_id', $city)->count());
        } finally {
            unlink($file);
        }
    }

    public function test_ambiguous_legacy_city_is_not_guessed(): void
    {
        DB::table('cities')->insert(['name' => 'Kanpur', 'state_id' => $this->state]);
        $data = app(LocationIdentity::class)->resolveLegacy(['country' => 'India', 'state' => 'Uttar Pradesh', 'city' => 'Kanpur']);
        $this->assertArrayNotHasKey('cityId', $data);
    }
}
