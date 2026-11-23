<?php

namespace App\Console\Commands;

use App\Reporting\BusinessActivityRecorder;
use App\Services\LocationIdentity;
use App\Support\PublicLanguages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ImportLocationCatalog extends Command
{
    protected $signature = 'locations:import {file : Reviewed JSON array, at most 1000 records} {--apply : Commit validated catalog entries and translations}';

    protected $description = 'Import reviewed city assignments, district/locality names or translations without generating SEO pages';

    public function handle(LocationIdentity $locations): int
    {
        $path = $this->argument('file');
        if (! is_file($path) || filesize($path) > 1048576) {
            $this->error('Supply a readable JSON file smaller than 1 MB.');

            return self::FAILURE;
        }
        $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        validator(['rows' => $rows], ['rows' => ['required', 'array', 'max:1000'], 'rows.*' => ['required', 'array']])->validate();
        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                $data = validator($row, [
                    'type' => ['required', Rule::in(array_keys(LocationIdentity::TABLES))],
                    'id' => ['sometimes', 'integer', 'min:1'],
                    'language' => ['sometimes', Rule::in(PublicLanguages::codes())],
                    'name' => ['sometimes', 'required', 'string', 'max:160'],
                ] + LocationIdentity::rules())->validate();
                if (isset($data['language'])) {
                    validator($data, [
                        'type' => ['required', Rule::in(array_keys(LocationIdentity::TABLES))],
                        'id' => ['required', 'integer', 'min:1', 'exists:'.LocationIdentity::TABLES[$data['type']].',id'],
                        'language' => ['required', Rule::in(PublicLanguages::codes())],
                        'name' => ['required', 'string', 'max:160'],
                    ])->validate();
                    DB::table(LocationIdentity::TABLES[$data['type']])->where('id', $data['id'])->lockForUpdate()->first();
                    $key = ['location_type' => $data['type'], 'location_id' => $data['id'], 'language' => $data['language']];
                    $before = DB::table('location_translations')->where($key)->first();
                    DB::table('location_translations')->upsert([$key + ['name' => trim($data['name'])]], ['location_type', 'location_id', 'language'], ['name']);
                    $record = DB::table('location_translations')->where($key)->first();
                    if ($before === null || $before->name !== $record->name) {
                        app(BusinessActivityRecorder::class)->record('location.translation_changed', $record->id, null,
                            before: ['name' => $before->name ?? null], after: ['name' => $record->name, 'location_type' => $data['type'], 'location_id' => $data['id'], 'language' => $data['language']]);
                    }
                } elseif ($data['type'] === 'city') {
                    validator($data, [
                        'type' => ['required', Rule::in(['city'])],
                        'id' => ['required', 'integer', 'min:1', 'exists:cities,id'],
                        'countryId' => ['required', 'integer', 'min:1', 'exists:countries,id'],
                        'name' => ['prohibited'],
                        'language' => ['prohibited'],
                        'stateId' => ['prohibited'],
                        'cityId' => ['prohibited'],
                        'country' => ['prohibited'],
                    ])->validate();
                    $city = DB::table('cities')->where('id', $data['id'])->lockForUpdate()->first();
                    if ($city === null) {
                        throw new \InvalidArgumentException('City catalog entry not found.');
                    }
                    $state = $city->state_id === null ? null : DB::table('states')->where('id', $city->state_id)->lockForUpdate()->first();
                    if ($state !== null && (int) $state->country_id !== (int) $data['countryId']) {
                        throw new \InvalidArgumentException('The selected country does not own this city state.');
                    }
                    if ($city->country_id !== null && (int) $city->country_id !== (int) $data['countryId']) {
                        throw new \InvalidArgumentException('A city country assignment cannot be overwritten.');
                    }
                    if ($city->country_id === null) {
                        DB::table('cities')->where('id', $city->id)->update(['country_id' => $data['countryId']]);
                        app(BusinessActivityRecorder::class)->record('location.city_country_assigned', (int) $city->id, null,
                            before: ['country_id' => null], after: ['country_id' => (int) $data['countryId'], 'state_id' => $city->state_id, 'name' => $city->name]);
                    }
                } else {
                    validator($data, ['type' => [Rule::in(['district', 'subDistrict', 'locality'])], 'name' => ['required', 'string', 'max:160']] + LocationIdentity::rules())->validate();
                    validator($data, ['type' => [Rule::in(['district', 'subDistrict', 'locality'])], 'countryId' => ['required', 'integer'], 'id' => ['prohibited']])->validate();
                    if ($data['type'] === 'subDistrict' && ! isset($data['districtId']) && ! isset($data['cityId'])) {
                        throw new \InvalidArgumentException('A sub-district requires a districtId or cityId.');
                    }
                    $data = $locations->normalize($data);
                    $table = LocationIdentity::TABLES[$data['type']];
                    // Lock parent to serialize duplicate detection with other catalog imports.
                    DB::table('countries')->where('id', $data['countryId'])->lockForUpdate()->first();
                    $key = ['country_id' => $data['countryId'], 'state_id' => $data['stateId'] ?? null, 'name' => trim($data['name'])];
                    if ($data['type'] === 'district' || $data['type'] === 'subDistrict') {
                        $key['city_id'] = $data['cityId'] ?? null;
                    }
                    if ($data['type'] === 'subDistrict') {
                        $key['district_id'] = $data['districtId'] ?? null;
                    }
                    if ($data['type'] === 'locality') {
                        $key['district_id'] = $data['districtId'] ?? null;
                        $key['city_id'] = $data['cityId'] ?? null;
                    }
                    if (! DB::table($table)->where($key)->exists()) {
                        $id = DB::table($table)->insertGetId($key);
                        app(BusinessActivityRecorder::class)->record('location.'.$data['type'].'_registered', $id, null, after: $key);
                    }
                }
            }
            if ($this->option('apply')) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $error) {
            DB::rollBack();
            throw $error;
        }
        $this->info($this->option('apply') ? 'Catalog imported. No SEO pages generated.' : 'Validated preview; no changes committed.');

        return self::SUCCESS;
    }
}
