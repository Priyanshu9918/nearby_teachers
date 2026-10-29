<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeographyApiTest extends TestCase
{
    private bool $transactionStarted = false;

    private int $firstState;

    private int $secondState;

    private int $foreignState;

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
        DB::table('countries')->insert([
            ['code' => 'QX', 'alpha3' => 'QXX', 'name' => 'Geography test country A'],
            ['code' => 'QY', 'alpha3' => 'QYY', 'name' => 'Geography test country B'],
        ]);
        $this->firstState = DB::table('states')->insertGetId(['country_code' => 'QX', 'code' => 'AA', 'name' => 'First state']);
        $this->secondState = DB::table('states')->insertGetId(['country_code' => 'QX', 'code' => 'BB', 'name' => 'Second state']);
        $this->foreignState = DB::table('states')->insertGetId(['country_code' => 'QY', 'code' => 'AA', 'name' => 'Same code abroad']);
        DB::table('states')->insert(['country_code' => 'QY', 'code' => 'CC', 'name' => 'Foreign-only state']);
        foreach ([
            [910000000001, 'QX', $this->firstState, 'Alpha', 100],
            [910000000002, 'QX', $this->firstState, 'Beta', 50],
            [910000000003, 'QX', $this->secondState, 'Other state city', 200],
            [910000000004, 'QY', $this->foreignState, 'Foreign city', 300],
            [910000000005, 'QX', null, 'Unassigned city', 400],
            [910000000006, 'QX', $this->foreignState, 'Mismatched parent', 500],
        ] as [$id, $country, $state, $name, $population]) {
            DB::table('cities')->insert(['geoname_id' => $id, 'country_code' => $country, 'state_id' => $state,
                'name' => $name, 'ascii_name' => $name, 'population' => $population]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_countries_returns_the_full_stored_list_without_a_default_search_filter(): void
    {
        $response = $this->getJson('/geo/countries')->assertOk();
        $this->assertCount(DB::table('countries')->count(), $response->json('countries'));
        $codes = array_column($response->json('countries'), 'code');
        $this->assertContains('QX', $codes);
        $this->assertContains('QY', $codes);
        $this->getJson('/geo/countries?search=Geography%20test%20country%20A')->assertOk()
            ->assertJsonCount(1, 'countries')->assertJsonPath('countries.0.code', 'QX');
    }

    public function test_states_are_country_scoped_and_return_all_without_search(): void
    {
        $this->getJson('/geo/countries/qx/states')->assertOk()->assertJsonPath('countryCode', 'QX')
            ->assertJsonCount(2, 'states')->assertJsonPath('states.0.id', $this->firstState)->assertJsonPath('states.1.id', $this->secondState);
        $this->getJson('/geo/countries/QX/states?search=Second')->assertOk()->assertJsonCount(1, 'states')->assertJsonPath('states.0.code', 'BB');
    }

    public function test_cities_require_a_state_belonging_to_the_selected_country(): void
    {
        $this->getJson('/geo/countries/QX/cities')->assertUnprocessable()->assertJsonValidationErrors('stateCode');
        $this->getJson('/geo/countries/QX/cities?stateCode=')->assertUnprocessable();
        $this->getJson('/geo/countries/QX/cities?stateCode=CC')->assertNotFound();
        $this->getJson('/geo/countries/QX/cities?stateCode=UNKNOWN')->assertNotFound();
        $this->getJson('/geo/countries/QZ/states')->assertNotFound();
        $this->getJson('/geo/countries/QZ/cities?stateCode=AA')->assertNotFound();
    }

    public function test_cities_only_return_the_selected_country_and_state_with_pagination(): void
    {
        $this->getJson('/geo/countries/qx/cities?stateCode=aa&perPage=1')->assertOk()
            ->assertJsonPath('countryCode', 'QX')->assertJsonPath('stateCode', 'AA')
            ->assertJsonCount(1, 'cities')->assertJsonPath('cities.0.name', 'Alpha')
            ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.lastPage', 2);
        $this->getJson('/geo/countries/QX/cities?stateCode=AA&perPage=1&page=2')->assertOk()->assertJsonPath('cities.0.name', 'Beta');
        $this->getJson('/geo/countries/QX/cities?stateCode=BB')->assertOk()->assertJsonCount(1, 'cities')->assertJsonPath('cities.0.name', 'Other state city');
        $this->getJson('/geo/countries/QY/cities?stateCode=AA')->assertOk()->assertJsonCount(1, 'cities')->assertJsonPath('cities.0.name', 'Foreign city');
    }

    public function test_city_search_stays_within_the_state_and_pagination_is_bounded(): void
    {
        $this->getJson('/geo/countries/QX/cities?stateCode=AA&search=Beta')->assertOk()->assertJsonCount(1, 'cities')->assertJsonPath('cities.0.name', 'Beta');
        $this->getJson('/geo/countries/QX/cities?stateCode=AA&search=Foreign')->assertOk()->assertJsonCount(0, 'cities');
        $this->getJson('/geo/countries/QX/cities?stateCode=AA&perPage=101')->assertUnprocessable();
        $this->getJson('/geo/countries/QX/cities?stateCode=AA&page=0')->assertUnprocessable();
    }
}
