<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WorldGeographyImporter
{
    public function import(string $directory): array
    {
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $path = $directory.'/world.jsonl.gz';
        if (! hash_equals($manifest['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Geography dataset checksum mismatch. No data was imported.');
        }

        return DB::transaction(function () use ($manifest, $path): array {
            $receipt = DB::table('geography_seed_runs')->where('catalog', 'world-v1')->first();
            if ($receipt) {
                if ($receipt->sha256 !== $manifest['sha256']) {
                    throw new RuntimeException('A different world catalog was already imported. Plan a catalog update explicitly.');
                }

                return ['already_imported' => true];
            }
            // A unique receipt also serializes concurrent imports. It rolls back on failure.
            DB::table('geography_seed_runs')->insert(['catalog' => 'world-v1', 'sha256' => $manifest['sha256'], 'completed_at' => now()]);
            foreach (['countries', 'states', 'cities'] as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('World import requires empty geography tables. Existing IDs/data were preserved; do not truncate live tables.');
                }
            }
            $handle = gzopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Cannot open world dataset.');
            }
            $counts = ['countries' => 0, 'states' => 0, 'cities' => 0];
            try {
                while (($line = gzgets($handle)) !== false) {
                    $country = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $countryId = DB::table('countries')->insertGetId([
                        'sortname' => $country['sortname'], 'name' => $country['name'], 'phonecode' => $country['phonecode'],
                    ]);
                    $counts['countries']++;
                    foreach ($country['states'] as $state) {
                        $stateId = DB::table('states')->insertGetId(['country_id' => $countryId, 'name' => $state['name']]);
                        $counts['states']++;
                        $counts['cities'] += $this->cities($state['cities'], $stateId);
                    }
                    // Preserve missing administrative divisions without fabricating a state.
                    $counts['cities'] += $this->cities($country['unassigned_cities'], null);
                }
                if (! gzeof($handle) || $counts !== $manifest['counts']) {
                    throw new RuntimeException('Incomplete world dataset. Import rolled back.');
                }
            } finally {
                gzclose($handle);
            }

            return $counts;
        });
    }

    private function cities(array $names, ?int $stateId): int
    {
        foreach (array_chunk($names, 1000) as $chunk) {
            DB::table('cities')->insert(array_map(fn (string $name): array => ['name' => $name, 'state_id' => $stateId], $chunk));
        }

        return count($names);
    }
}
