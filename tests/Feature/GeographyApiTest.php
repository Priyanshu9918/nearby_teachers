<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GeographyApiTest extends TestCase
{
    private bool $transactionStarted = false;

    private int $country;

    private int $otherCountry;

    private int $firstState;

    private int $secondState;

    private int $foreignState;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('GEOGRAPHY_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set GEOGRAPHY_TEST_DATABASE to a disposable MySQL database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        // The imported SQL schema is separate from the legacy GeoNames migrations.
        // Create only these fixture tables in the explicitly selected test database.
        if (! Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('sortname', 3);
                $table->string('name');
                $table->integer('phonecode');
            });
        }
        if (! Schema::hasTable('states')) {
            Schema::create('states', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
                $table->unsignedInteger('country_id')->index();
            });
        }
        if (! Schema::hasTable('cities')) {
            Schema::create('cities', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
                $table->unsignedInteger('state_id')->index();
            });
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->country = DB::table('countries')->insertGetId(['sortname' => 'QX', 'name' => 'Geography country A', 'phonecode' => 999]);
        $this->otherCountry = DB::table('countries')->insertGetId(['sortname' => 'QY', 'name' => 'Geography country B', 'phonecode' => 998]);
        $this->firstState = DB::table('states')->insertGetId(['country_id' => $this->country, 'name' => 'First state']);
        $this->secondState = DB::table('states')->insertGetId(['country_id' => $this->country, 'name' => 'Second state']);
        $this->foreignState = DB::table('states')->insertGetId(['country_id' => $this->otherCountry, 'name' => 'Foreign state']);
        foreach ([
            [$this->firstState, 'Alpha'], [$this->firstState, 'Beta'],
            [$this->firstState, 'Rate 100%'], [$this->firstState, 'Rate 1000'],
            [$this->secondState, 'Other state city'], [$this->foreignState, 'Foreign city'],
        ] as [$state, $name]) {
            DB::table('cities')->insert(['state_id' => $state, 'name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_countries_returns_the_complete_imported_list_and_explicit_columns(): void
    {
        $countries = $this->getJson('/geo/countries')->assertOk()->json('countries');
        $this->assertCount(DB::table('countries')->count(), $countries);
        $this->assertContains($this->country, array_column($countries, 'id'));
        $this->assertContains($this->otherCountry, array_column($countries, 'id'));
        $this->getJson('/geo/countries?search=Geography%20country%20A')->assertOk()
            ->assertExactJson(['countries' => [['id' => $this->country, 'sortname' => 'QX', 'name' => 'Geography country A', 'phonecode' => 999]]]);
    }

    public function test_states_only_return_rows_for_the_selected_country(): void
    {
        $this->getJson('/geo/states?country_id='.$this->country)->assertOk()
            ->assertJsonPath('country_id', $this->country)->assertJsonCount(2, 'states')
            ->assertJsonPath('states.0.id', $this->firstState)->assertJsonPath('states.1.id', $this->secondState);
        $this->getJson('/geo/states?country_id='.$this->otherCountry)->assertOk()
            ->assertExactJson(['country_id' => $this->otherCountry, 'states' => [['id' => $this->foreignState, 'name' => 'Foreign state', 'country_id' => $this->otherCountry]]]);
        $this->getJson('/geo/states?country_id='.$this->country.'&search=Second')->assertOk()
            ->assertJsonCount(1, 'states')->assertJsonPath('states.0.id', $this->secondState);
    }

    public function test_parent_ids_are_required_validated_and_checked_for_existence(): void
    {
        foreach (['', '?country_id=', '?country_id=0', '?country_id=abc', '?country_id[]=1'] as $query) {
            $this->getJson('/geo/states'.$query)->assertUnprocessable()->assertJsonValidationErrors('country_id');
        }
        foreach (['', '?state_id=', '?state_id=-1', '?state_id=abc', '?state_id[]=1'] as $query) {
            $this->getJson('/geo/cities'.$query)->assertUnprocessable()->assertJsonValidationErrors('state_id');
        }
        $this->getJson('/geo/states?country_id=9007199254740991')->assertNotFound();
        $this->getJson('/geo/cities?state_id=9007199254740991')->assertNotFound();
    }

    public function test_cities_are_state_scoped_with_stable_bounded_pages(): void
    {
        $this->getJson('/geo/cities?state_id='.$this->firstState.'&perPage=1')->assertOk()
            ->assertJsonPath('state_id', $this->firstState)->assertJsonCount(1, 'cities')
            ->assertJsonPath('cities.0.name', 'Alpha')->assertJsonPath('cities.0.state_id', $this->firstState)
            ->assertJsonPath('pagination.total', 4)->assertJsonPath('pagination.lastPage', 4);
        $this->getJson('/geo/cities?state_id='.$this->firstState.'&perPage=1&page=2')->assertOk()->assertJsonPath('cities.0.name', 'Beta');
        $this->getJson('/geo/cities?state_id='.$this->secondState)->assertOk()->assertJsonCount(1, 'cities')->assertJsonPath('cities.0.name', 'Other state city');
        $this->getJson('/geo/cities?state_id='.$this->foreignState)->assertOk()->assertJsonCount(1, 'cities')->assertJsonPath('cities.0.name', 'Foreign city');
        $this->getJson('/geo/cities?state_id='.$this->firstState.'&perPage=101')->assertUnprocessable();
        $this->getJson('/geo/cities?state_id='.$this->firstState.'&page=0')->assertUnprocessable();
    }

    public function test_search_is_literal_and_cannot_escape_the_selected_state(): void
    {
        $this->getJson('/geo/cities?state_id='.$this->firstState.'&search=Foreign')->assertOk()->assertJsonCount(0, 'cities');
        $this->getJson('/geo/cities?state_id='.$this->firstState.'&search=100%25')->assertOk()
            ->assertJsonCount(1, 'cities')->assertJsonPath('cities.0.name', 'Rate 100%');
        $this->getJson('/geo/cities?state_id='.$this->firstState.'&search=Beta')->assertOk()->assertJsonCount(1, 'cities');
    }
}
