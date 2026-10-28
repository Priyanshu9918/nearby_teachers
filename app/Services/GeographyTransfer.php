<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GeographyTransfer
{
    private const COLUMNS = ['geoname_id', 'country_code', 'state_code', 'name', 'ascii_name', 'latitude', 'longitude', 'population', 'feature_code', 'created_at', 'updated_at'];

    public function export(string $path): array
    {
        if (file_exists($path)) {
            throw new RuntimeException('Output already exists. Choose a new path.');
        }
        $handle = gzopen($path, 'wb6');
        if ($handle === false) {
            throw new RuntimeException('Cannot create export file.');
        }
        try {
            return DB::transaction(function () use ($handle): array {
                $counts = ['cities' => DB::table('cities')->count()];
                $this->write($handle, ['format' => 'nearby-cities', 'version' => 1, 'counts' => $counts]);
                $columns = array_map(fn ($column) => $column === 'state_code' ? 's.code as state_code' : 'c.'.$column, self::COLUMNS);
                $query = DB::table('cities as c')->leftJoin('states as s', function ($join): void {
                    $join->on('s.id', '=', 'c.state_id')->on('s.country_code', '=', 'c.country_code');
                })->select($columns);
                $written = 0;
                foreach ($query->lazyById(2000, 'c.geoname_id', 'geoname_id') as $row) {
                    $this->write($handle, (array) $row);
                    $written++;
                }
                if ($written !== $counts['cities']) {
                    throw new RuntimeException('Catalog changed during export. Export again.');
                }
                $this->write($handle, ['end' => true, 'counts' => $counts]);

                return $counts;
            });
        } catch (\Throwable $e) {
            gzclose($handle);
            unlink($path);
            throw $e;
        } finally {
            if (is_resource($handle)) {
                gzclose($handle);
            }
        }
    }

    /** Countries/states are read-only; map their server IDs by country and state codes. */
    public function import(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Import file is not readable.');
        }
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open import file.');
        }
        try {
            return DB::transaction(function () use ($handle): array {
                if (DB::table('cities')->exists()) {
                    throw new RuntimeException('Cities table must be empty. Existing data was not changed.');
                }
                $countries = array_fill_keys(DB::table('countries')->pluck('code')->all(), true);
                $states = [];
                foreach (DB::table('states')->get(['id', 'country_code', 'code']) as $state) {
                    $states[$state->country_code][$state->code] = $state->id;
                }
                $header = $this->read($handle);
                if (($header['format'] ?? null) !== 'nearby-cities' || ($header['version'] ?? null) !== 1
                    || ! is_int($header['counts']['cities'] ?? null) || $header['counts']['cities'] < 0) {
                    throw new RuntimeException('Invalid cities export header.');
                }
                $expected = $header['counts'];
                $counts = ['cities' => 0];
                $batch = [];
                while (($record = $this->read($handle)) !== null) {
                    if (($record['end'] ?? false) === true) {
                        if ($batch !== []) {
                            DB::table('cities')->insert($batch);
                        }
                        if ($counts !== $expected || ($record['counts'] ?? null) !== $expected || $this->read($handle) !== null) {
                            throw new RuntimeException('Export counts/trailer do not match.');
                        }

                        return $counts;
                    }
                    if (count($record) !== count(self::COLUMNS) || array_diff(array_keys($record), self::COLUMNS) !== []) {
                        throw new RuntimeException('Invalid city columns.');
                    }
                    foreach ($record as $value) {
                        if (! is_scalar($value) && $value !== null) {
                            throw new RuntimeException('Invalid city value.');
                        }
                    }
                    $country = $record['country_code'];
                    $state = $record['state_code'];
                    if (! is_string($country) || ! isset($countries[$country])) {
                        throw new RuntimeException('Missing server country: '.(is_scalar($country) ? $country : 'invalid'));
                    }
                    if ($state !== null && (! is_string($state) || ! isset($states[$country][$state]))) {
                        throw new RuntimeException('Missing server state for '.$country.'. Import rolled back.');
                    }
                    $record['state_id'] = $state === null ? null : $states[$country][$state];
                    unset($record['state_code']);
                    $counts['cities']++;
                    if ($counts['cities'] > $expected['cities']) {
                        throw new RuntimeException('Too many city records.');
                    }
                    $batch[] = $record;
                    if (count($batch) >= 1000) {
                        DB::table('cities')->insert($batch);
                        $batch = [];
                    }
                }
                throw new RuntimeException('Incomplete export: missing final trailer. Import rolled back.');
            });
        } finally {
            gzclose($handle);
        }
    }

    private function write($handle, array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        if (gzwrite($handle, $line) !== strlen($line)) {
            throw new RuntimeException('Could not write complete export record.');
        }
    }

    private function read($handle): ?array
    {
        $line = gzgets($handle, 1048576);
        if ($line === false) {
            if (! gzeof($handle)) {
                throw new RuntimeException('Cannot read compressed export.');
            }

            return null;
        }
        if (! str_ends_with($line, "\n")) {
            throw new RuntimeException('Truncated or oversized export record.');
        }
        $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($record)) {
            throw new RuntimeException('Invalid export record.');
        }

        return $record;
    }
}
