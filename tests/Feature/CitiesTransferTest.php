<?php

namespace Tests\Feature;

use App\Services\GeographyTransfer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CitiesTransferTest extends TestCase
{
    private array $temporaryTables = [];

    private string $path;

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
        foreach (['countries', 'states', 'cities'] as $table) {
            $ddl = array_values((array) DB::selectOne('SHOW CREATE TABLE '.$table))[1];
            $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
            $ddl = preg_replace('/^\s*CONSTRAINT[^\n]*\n/m', '', $ddl);
            $ddl = str_replace(",\n)", "\n)", $ddl);
            DB::statement($ddl);
            $this->temporaryTables[] = $table;
        }
        $this->path = sys_get_temp_dir().'/cities-transfer-'.Str::random(16).'.jsonl.gz';
        DB::table('countries')->insert(['code' => 'IN', 'alpha3' => 'IND', 'name' => 'India']);
        DB::table('states')->insert(['id' => 111, 'country_code' => 'IN', 'code' => 'UP', 'name' => 'Uttar Pradesh']);
        DB::table('cities')->insert(['geoname_id' => 12345, 'country_code' => 'IN', 'state_id' => 111, 'name' => 'Test City', 'population' => 10]);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryTables) as $table) {
            DB::statement('DROP TEMPORARY TABLE '.$table);
        }
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_cities_only_round_trip_maps_server_state_ids_and_preserves_parents(): void
    {
        $transfer = app(GeographyTransfer::class);
        $this->assertSame(['cities' => 1], $transfer->export($this->path));
        $lines = gzfile($this->path);
        $record = json_decode($lines[1], true);
        $this->assertSame('UP', $record['state_code']);
        $this->assertArrayNotHasKey('state_id', $record);
        DB::table('cities')->delete();
        DB::table('states')->delete();
        DB::table('states')->insert(['id' => 999, 'country_code' => 'IN', 'code' => 'UP', 'name' => 'Server State']);
        $this->assertSame(['cities' => 1], $transfer->import($this->path));
        $this->assertDatabaseHas('cities', ['geoname_id' => 12345, 'state_id' => 999, 'name' => 'Test City']);
        $this->assertDatabaseHas('states', ['id' => 999, 'name' => 'Server State']);
        $this->assertSame(1, DB::table('countries')->count());
        $this->assertSame(1, DB::table('states')->count());
    }

    public function test_existing_cities_are_not_deleted_or_overwritten(): void
    {
        $transfer = app(GeographyTransfer::class);
        $transfer->export($this->path);
        try {
            $transfer->import($this->path);
            $this->fail('Must refuse existing cities.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('must be empty', $e->getMessage());
        }
        $this->assertSame(1, DB::table('cities')->count());
    }

    public function test_missing_server_state_prevents_import(): void
    {
        $transfer = app(GeographyTransfer::class);
        $transfer->export($this->path);
        DB::table('cities')->delete();
        DB::table('states')->delete();
        try {
            $transfer->import($this->path);
            $this->fail('Must reject missing state mapping.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Missing server state', $e->getMessage());
        }
        $this->assertSame(0, DB::table('cities')->count());
        $this->assertSame(1, DB::table('countries')->count());
    }

    public function test_invalid_trailer_rolls_back_inserted_cities(): void
    {
        $transfer = app(GeographyTransfer::class);
        $transfer->export($this->path);
        $lines = gzfile($this->path);
        $lines[2] = json_encode(['end' => true, 'counts' => ['cities' => 2]])."\n";
        $handle = gzopen($this->path, 'wb');
        gzwrite($handle, implode('', $lines));
        gzclose($handle);
        DB::table('cities')->delete();
        try {
            $transfer->import($this->path);
            $this->fail('Must reject incorrect trailer.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('do not match', $e->getMessage());
        }
        $this->assertSame(0, DB::table('cities')->count());
    }
}
