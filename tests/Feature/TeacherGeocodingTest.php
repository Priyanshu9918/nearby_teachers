<?php

namespace Tests\Feature;

use App\Reporting\BusinessActivityRecorder;
use App\Services\AuthenticationService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\NumericTestIds;
use Tests\TestCase;

class TeacherGeocodingTest extends TestCase
{
    private bool $transactionStarted = false;

    private int $userId;

    private int $subjectId;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('ONBOARDING_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set ONBOARDING_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
            'database.connections.mysql.database' => $database, 'services.google_geocoding.enabled' => true,
            'services.google_geocoding.key' => 'fictional-geocoding-secret']);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->userId = NumericTestIds::next();
        DB::table('users')->insert(['id' => $this->userId, 'account_type' => 'learner',
            'full_name' => 'New user', 'phone_verified_at' => now()]);
        $domain = DB::table('subject_domains')->insertGetId(['name' => 'Geocoding test']);
        $this->subjectId = DB::table('subjects')->insertGetId(['domain_id' => $domain, 'name' => 'Maths']);
        $auth = \Mockery::mock(AuthenticationService::class);
        $auth->shouldReceive('authenticate')->with('location-test')->andReturnUsing(fn () => (new AuthenticationService)->publicUser(DB::table('users')->where('id', $this->userId)->first()));
        $this->app->instance(AuthenticationService::class, $auth);
        $this->withToken('location-test');
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertSame(1, DB::transactionLevel(), 'HTTP lookup must happen outside the source transaction and its locks.');

            return Http::response($this->googleResult());
        });
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function googleResult(): array
    {
        $component = fn ($name, $type, $short = null) => ['long_name' => $name, 'short_name' => $short ?? $name, 'types' => [$type, 'political']];

        return ['status' => 'OK', 'sensitive_debug' => 'must-not-store', 'results' => [[
            'place_id' => 'fictional-place', 'formatted_address' => 'House 7, Ward Four, Sample Locality, 122001',
            'types' => ['street_address'], 'geometry' => ['location' => ['lat' => 99, 'lng' => 99]],
            'address_components' => [
                $component('7', 'street_number'), $component('Sample Lane', 'route'),
                $component('Ward Four', 'sublocality_level_2'), $component('Sample Locality', 'locality'),
                $component('Sample District', 'administrative_area_level_2'),
                $component('Haryana', 'administrative_area_level_1'), $component('India', 'country', 'IN'),
                $component('122001', 'postal_code'),
            ],
        ], [
            'place_id' => 'alternate-place', 'formatted_address' => 'Other postcode area', 'types' => ['postal_code'],
            'address_components' => [$component('999999', 'postal_code')],
        ]]];
    }

    private function register(array $extra = []): TestResponse
    {
        return $this->postJson('/auth/register', array_replace([
            'accountType' => 'teacher', 'fullName' => 'Asha Sharma', 'email' => 'asha-geocoding@example.test',
            'password' => 'a-secure-password', 'city' => 'Delhi', 'country' => 'India', 'primarySubjects' => [$this->subjectId],
            'termsAccepted' => true, 'acknowledgeTeachersNotBackgroundChecked' => true,
            'latitude' => 28.4595, 'longitude' => 77.0266,
        ], $extra));
    }

    private function profile(): object
    {
        return DB::table('teacher_profiles')->where('user_id', $this->userId)->first();
    }

    public function test_registration_stores_private_structured_addresses_and_minimal_history(): void
    {
        $result = $this->register()->assertOk()->assertJsonPath('draft.locationDetails.status', 'ok')
            ->assertJsonPath('draft.locationDetails.postal_code', '122001')->json();
        $profile = $this->profile();
        $details = json_decode($profile->location_details, true);
        $this->assertSame(28.4595, (float) $profile->latitude);
        $this->assertSame('Delhi', $profile->city, 'Google suggestions must not silently replace the user-entered city.');
        $this->assertSame('Ward Four', $details['address_values']['sublocality_level_2']);
        $this->assertSame('IN', $details['address_values']['country_code']);
        $this->assertNull($details['village']);
        $this->assertNull($details['police_station']);
        $this->assertCount(2, $details['addresses']);
        $this->assertArrayNotHasKey('geometry', $details['addresses'][0]);
        $this->assertStringNotContainsString('fictional-geocoding-secret', $profile->location_details);
        $this->assertStringNotContainsString('must-not-store', $profile->location_details);
        $event = DB::table('business_activity')->where('event', 'teacher.location_updated')->where('entity_id', $this->userId)->first();
        $this->assertSame(['has_coordinates' => true, 'geocoding_status' => 'ok'], json_decode($event->after_data, true));
        $this->assertStringNotContainsString('Ward Four', $event->after_data);
        Http::assertSentCount(1);
        $this->withoutHeader('Authorization')->getJson('/profiles/teacher/'.$this->userId)
            ->assertOk()->assertJsonMissingPath('profile.location_details')->assertDontSee('House 7');
        $this->assertNull($result['teacherProfileUrl'], 'SEO profile pages are created after the teacher completes the six-step profile.');
    }

    public function test_profile_and_onboarding_updates_replace_then_clear_location_without_stale_addresses(): void
    {
        $this->register()->assertOk();
        $this->putJson('/profiles/me', ['bio' => 'Patient teacher'])->assertOk();
        Http::assertSentCount(1);
        Http::swap(new Factory);
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);
        $this->putJson('/profiles/me', ['latitude' => 0, 'longitude' => 0])->assertOk()->assertJsonPath('locationDetails.status', 'zero_results');
        $this->assertSame(0.0, (float) $this->profile()->latitude);
        $details = json_decode($this->profile()->location_details, true);
        $this->assertSame('zero_results', $details['status']);
        $this->assertArrayNotHasKey('addresses', $details);
        $this->patchJson('/auth/onboarding', ['latitude' => null, 'longitude' => null])->assertOk()
            ->assertJsonPath('draft.locationDetails', null)->assertJsonPath('draft.latitude', null);
        $this->assertNull($this->profile()->location_details);
        $this->assertNull($this->profile()->longitude);
    }

    public function test_invalid_pairs_ranges_and_invalid_registration_do_not_call_google(): void
    {
        foreach ([['latitude' => null], ['longitude' => null], ['latitude' => 91], ['longitude' => -181], ['email' => 'invalid']] as $invalid) {
            $this->register($invalid)->assertUnprocessable();
        }
        Http::assertNothingSent();
        $this->assertFalse(DB::table('teacher_profiles')->where('user_id', $this->userId)->exists());
    }

    public function test_missing_configuration_preserves_gps_and_allows_registration(): void
    {
        config(['services.google_geocoding.key' => null]);
        $this->register()->assertOk()->assertJsonPath('draft.locationDetails.status', 'not_configured');
        Http::assertNothingSent();
        $this->assertSame(77.0266, (float) $this->profile()->longitude);
    }

    public function test_location_and_registration_roll_back_if_activity_recording_fails(): void
    {
        $this->app->instance(BusinessActivityRecorder::class, new class
        {
            public function record(...$arguments): never
            {
                throw new \RuntimeException('Activity unavailable');
            }
        });
        $this->register()->assertStatus(500);
        $this->assertFalse(DB::table('teacher_profiles')->where('user_id', $this->userId)->exists());
        $this->assertNull(DB::table('users')->where('id', $this->userId)->value('profile_completed_at'));
    }

    public function test_admin_report_filters_and_streamed_exports_include_location_details(): void
    {
        $this->register()->assertOk();
        $url = '/admin/reports/teacher-profiles?user='.$this->userId.'&q=122001';
        $this->withoutHeader('Authorization')->get($url.'&export=1')->assertRedirect('/admin/login');
        $this->withSession(['admin_authenticated' => true])->get($url)->assertOk()->assertSee('location details')->assertSee('Ward Four');
        $csv = $this->get($url.'&export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('location_details', $csv);
        $this->assertStringContainsString('122001', $csv);
        $this->assertStringNotContainsString('fictional-geocoding-secret', $csv);
        $this->assertGreaterThanOrEqual(2, DB::table('admin_report_access_log')->where('report', 'teacher-profiles')->count());
    }
}
