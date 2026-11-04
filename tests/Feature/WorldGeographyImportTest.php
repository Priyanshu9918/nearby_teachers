<?php

namespace Tests\Feature;

use App\Services\WorldGeographyImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class WorldGeographyImportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('GEOGRAPHY_TEST_DATABASE');
        if (! $database) {
            $this->markTestSkipped('Set GEOGRAPHY_TEST_DATABASE to an empty disposable database ending in _test.');
        }
        $this->assertStringEndsWith('_test', $database);
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        foreach (['countries', 'states', 'cities', 'geography_seed_runs'] as $table) {
            $this->assertFalse(Schema::hasTable($table), 'Use an empty dedicated test database.');
        }
        (require database_path('migrations/2026_11_04_000001_prepare_world_geography_seeding.php'))->up();
        $this->directory = sys_get_temp_dir().'/world-geography-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $country = ['sortname' => 'IN', 'name' => 'India', 'phonecode' => 91, 'states' => [['name' => 'Delhi', 'cities' => ['Delhi', 'New Delhi']]], 'unassigned_cities' => []];
        file_put_contents($this->directory.'/world.jsonl.gz', gzencode(json_encode($country)."\n"));
        $this->manifest(['countries' => 1, 'states' => 1, 'cities' => 2]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            foreach (['cities', 'states', 'countries', 'geography_seed_runs'] as $table) {
                Schema::dropIfExists($table);
            }
            unlink($this->directory.'/world.jsonl.gz');
            unlink($this->directory.'/manifest.json');
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_import_generates_parent_ids_and_repeat_is_a_no_op(): void
    {
        $importer = app(WorldGeographyImporter::class);
        $this->assertSame(['countries' => 1, 'states' => 1, 'cities' => 2], $importer->import($this->directory));
        $state = DB::table('states')->first();
        $this->assertEquals(DB::table('countries')->value('id'), $state->country_id);
        $this->assertEquals($state->id, DB::table('cities')->value('state_id'));
        $this->assertSame(['already_imported' => true], $importer->import($this->directory));
        $this->assertSame(2, DB::table('cities')->count());
    }

    public function test_existing_catalog_is_preserved(): void
    {
        $id = DB::table('countries')->insertGetId(['sortname' => 'GB', 'name' => 'Existing', 'phonecode' => 44]);
        try {
            app(WorldGeographyImporter::class)->import($this->directory);
            $this->fail('Expected existing data guard.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('requires empty', $e->getMessage());
        }
        $this->assertEquals($id, DB::table('countries')->value('id'));
        $this->assertSame(1, DB::table('countries')->count());
        $this->assertSame(0, DB::table('geography_seed_runs')->count());
    }

    public function test_invalid_counts_roll_back_every_table_and_receipt(): void
    {
        $this->manifest(['countries' => 1, 'states' => 1, 'cities' => 3]);
        try {
            app(WorldGeographyImporter::class)->import($this->directory);
            $this->fail('Expected count mismatch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Incomplete', $e->getMessage());
        }
        foreach (['countries', 'states', 'cities', 'geography_seed_runs'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_checksum_failure_writes_nothing(): void
    {
        file_put_contents($this->directory.'/world.jsonl.gz', 'corrupted');
        $this->expectExceptionMessage('checksum mismatch');
        app(WorldGeographyImporter::class)->import($this->directory);
    }

    private function manifest(array $counts): void
    {
        file_put_contents($this->directory.'/manifest.json', json_encode(['sha256' => hash_file('sha256', $this->directory.'/world.jsonl.gz'), 'counts' => $counts]));
    }
}
