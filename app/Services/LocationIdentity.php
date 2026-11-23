<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LocationIdentity
{
    public const TABLES = ['country' => 'countries', 'state' => 'states', 'city' => 'cities', 'district' => 'districts', 'subDistrict' => 'sub_districts', 'locality' => 'localities'];

    private const COLUMNS = ['subDistrict' => 'sub_district'];

    private static function column(string $field): string
    {
        return self::COLUMNS[$field] ?? $field;
    }

    public static function rules(): array
    {
        $rules = [];
        foreach (self::TABLES as $field => $table) {
            $rules[$field.'Id'] = ['sometimes', 'nullable', 'integer', 'min:1', 'exists:'.$table.',id'];
        }

        return $rules;
    }

    public static function columns(array $data): array
    {
        $ids = [];
        foreach (self::TABLES as $field => $_) {
            if (isset($data[$field.'Id'])) {
                $ids[self::column($field).'_id'] = (int) $data[$field.'Id'];
            }
        }

        return $ids;
    }

    public static function inputRules(): array
    {
        $rules = self::rules();
        foreach (self::TABLES as $field => $_) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:'.($field === 'locality' ? 160 : 120)];
        }
        $rules['countryCode'] = ['sometimes', 'nullable', 'string', 'size:2'];

        return $rules;
    }

    /** Resolve labels on the server; language preference never changes location identity. */
    public function normalize(array $data): array
    {
        $records = [];
        foreach (self::TABLES as $field => $table) {
            if (! isset($data[$field.'Id'])) {
                continue;
            }
            $record = DB::table($table)->where('id', $data[$field.'Id'])->first();
            if ($record === null) {
                throw ValidationException::withMessages([$field.'Id' => 'Unknown location.']);
            }
            if ($field === 'city' && $record->state_id === null && $record->country_id === null) {
                throw ValidationException::withMessages(['cityId' => 'This city requires a reviewed country assignment in the catalog.']);
            }
            $records[$field] = $record;
        }
        // Derive ancestors without interpreting translated labels.
        foreach (['locality', 'district', 'city', 'state'] as $field) {
            if (! isset($records[$field])) {
                continue;
            }
            $parents = match ($field) {
                'locality' => ['district', 'city', 'state', 'country'],
                'subDistrict' => ['district', 'city', 'state', 'country'],
                'district' => ['city', 'state', 'country'],
                'city' => ['state', 'country'],
                'state' => ['country'],
            };
            foreach ($parents as $parent) {
                $id = $records[$field]->{self::column($parent).'_id'} ?? null;
                if ($id === null && $field === 'city' && $parent === 'country') {
                    $id = $records['state']->country_id ?? null;
                }
                if ($id !== null && array_key_exists($parent.'Id', $data) && (int) ($data[$parent.'Id'] ?? 0) !== (int) $id) {
                    throw ValidationException::withMessages([$parent.'Id' => 'Location does not belong to the selected parent.']);
                }
                if ($id === null) {
                    continue;
                }
                if (isset($records[$parent]) && (int) $records[$parent]->id !== (int) $id) {
                    throw ValidationException::withMessages([$field.'Id' => 'Location hierarchy is inconsistent.']);
                }
                $records[$parent] = DB::table(self::TABLES[$parent])->where('id', $id)->first();
            }
        }
        foreach ($records as $field => $record) {
            $data[$field.'Id'] = (int) $record->id;
            $data[$field] = $record->name;
            if ($field === 'country') {
                $data['countryCode'] = strtoupper($record->sortname);
            }
        }

        return $data;
    }

    /** Link legacy names only when the parent-scoped match is unique. Never create catalog entries. */
    public function resolveLegacy(array $data): array
    {
        $data = $this->normalize($data);
        foreach (self::TABLES as $field => $table) {
            if (array_key_exists($field.'Id', $data) || (empty($data[$field]) && ! ($field === 'country' && ! empty($data['countryCode'])))) {
                continue;
            }
            if ($field === 'state' && ! isset($data['countryId'])) {
                continue;
            }
            if ($field === 'city' && ! isset($data['stateId']) && ! isset($data['countryId'])) {
                continue;
            }
            if (in_array($field, ['district', 'subDistrict', 'locality'], true) && ! isset($data['countryId'])) {
                continue;
            }
            $query = DB::table($table);
            if ($field === 'country' && ! empty($data['countryCode'])) {
                $query->where('sortname', strtoupper($data['countryCode']));
            } else {
                $name = trim(preg_replace('/\s+/u', ' ', $data[$field]));
                $query->where(function ($q) use ($field, $name): void {
                    $q->whereRaw('LOWER(TRIM(name)) = LOWER(?)', [$name])->orWhereIn('id', DB::table('location_translations')
                        ->select('location_id')->where('location_type', $field)->whereRaw('LOWER(TRIM(name)) = LOWER(?)', [$name]));
                });
            }
            $parents = match ($field) {
                'country' => [], 'state' => ['country'], 'city' => ['state'],
                'district' => ['country', 'state', 'city'], 'subDistrict' => ['country', 'state', 'district', 'city'], 'locality' => ['country', 'state', 'district', 'city'],
            };
            foreach ($parents as $parent) {
                if (isset($data[$parent.'Id'])) {
                    $column = self::column($parent).'_id';
                    if ($parent === 'city' && in_array($field, ['district', 'subDistrict', 'locality'], true)) {
                        $query->where(fn ($scope) => $scope->where($column, $data[$parent.'Id'])->orWhereNull($column));
                    } else {
                        $query->where($column, $data[$parent.'Id']);
                    }
                }
            }
            if ($field === 'city' && ! isset($data['stateId']) && isset($data['countryId'])) {
                $query->where(fn ($scope) => $scope->where('country_id', $data['countryId'])->orWhereIn('state_id', DB::table('states')->select('id')->where('country_id', $data['countryId'])));
            }
            $ids = $query->limit(2)->pluck('id');
            if ($ids->count() === 1) {
                $data[$field.'Id'] = (int) $ids[0];
                $data = $this->normalize($data);
            }
        }

        return $data;
    }

    public function forProfile(array $data, ?object $profile): array
    {
        $normalized = $this->resolveLegacy($data);
        if ($profile === null || $data === []) {
            return $normalized;
        }
        $old = [];
        foreach (self::TABLES as $field => $_) {
            $old[$field] = $profile->{self::column($field)} ?? null;
            if (isset($profile->{self::column($field).'_id'})) {
                $old[$field.'Id'] = $profile->{self::column($field).'_id'};
            }
        }
        $old['countryCode'] = $profile->country_code ?? null;
        $old = $this->resolveLegacy($old);
        foreach (self::TABLES as $field => $_) {
            if (isset($normalized[$field.'Id'], $old[$field.'Id']) && (int) $normalized[$field.'Id'] === (int) $old[$field.'Id'] && ! empty($profile->{self::column($field)})) {
                $normalized[$field] = $profile->{self::column($field)};
            }
        }

        return $normalized;
    }

    /** Create only explicitly submitted, unmatched names beneath verified catalog parents. */
    private function registerMissing(array $resolved, array $submitted): array
    {
        foreach (['city', 'district', 'subDistrict'] as $field) {
            if (! array_key_exists($field, $submitted) || array_key_exists($field.'Id', $submitted)
                || isset($resolved[$field.'Id']) || ! filled($submitted[$field])) {
                continue;
            }
            if ($field === 'city' && ! isset($resolved['stateId'], $resolved['countryId'])) {
                continue;
            }
            if ($field === 'district' && ! isset($resolved['countryId'], $resolved['stateId'])) {
                continue;
            }
            if ($field === 'subDistrict' && (! isset($resolved['countryId'])
                || (! isset($resolved['districtId']) && ! isset($resolved['cityId'])))) {
                continue;
            }
            $parentTable = $field === 'city' ? 'states' : (isset($resolved['cityId']) ? 'cities' : 'districts');
            $parentId = $field === 'city' ? $resolved['stateId'] : ($resolved['cityId'] ?? $resolved['districtId']);
            DB::table($parentTable)->where('id', $parentId)->lockForUpdate()->first();
            // A concurrent registration may have inserted the same name while waiting for the parent lock.
            $resolved = $this->resolveLegacy($resolved);
            if (isset($resolved[$field.'Id'])) {
                continue;
            }
            $name = trim(preg_replace('/\s+/u', ' ', $submitted[$field]));
            $existing = DB::table(self::TABLES[$field])
                ->where($field === 'city' ? 'state_id' : 'country_id', $field === 'city' ? $resolved['stateId'] : $resolved['countryId'])
                ->where(fn ($query) => $query->whereRaw('LOWER(TRIM(name)) = LOWER(?)', [$name])
                    ->orWhereIn('id', DB::table('location_translations')->select('location_id')
                        ->where('location_type', $field)->whereRaw('LOWER(TRIM(name)) = LOWER(?)', [$name])));
            if ($existing->exists()) {
                // An ambiguous existing name needs review; a duplicate would make it worse.
                continue;
            }
            $row = ['country_id' => $resolved['countryId'], 'name' => $name];
            if ($field === 'city') {
                $row['state_id'] = $resolved['stateId'];
            } else {
                $row['state_id'] = $resolved['stateId'] ?? null;
                $row['city_id'] = $resolved['cityId'] ?? null;
                if ($field === 'subDistrict') {
                    $row['district_id'] = $resolved['districtId'] ?? null;
                }
            }
            $id = DB::table(self::TABLES[$field])->insertGetId($row);
            app(BusinessActivityRecorder::class)->record('location.'.$field.'_registered', $id, null, after: $row);
            $resolved[$field.'Id'] = $id;
            $resolved = $this->normalize($resolved);
        }

        return $resolved;
    }

    public function store(int $userId, array $data, string $type, bool $idsOnly = false): void
    {
        $table = match ($type) {
            'teacher' => 'teacher_profiles', 'institute' => 'institute_profiles'
        };
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Save location identity inside the profile transaction.');
        }
        $profile = DB::table($table)->where('user_id', $userId)->lockForUpdate()->first();
        abort_if($profile === null, 404, 'Provider profile not found.');
        $snapshot = [];
        foreach (self::TABLES as $field => $_) {
            $snapshot[$field] = array_key_exists($field, $data) ? $data[$field] : $profile->{self::column($field)};
            if (array_key_exists($field.'Id', $data)) {
                $snapshot[$field.'Id'] = $data[$field.'Id'];
                if ($data[$field.'Id'] === null && ! array_key_exists($field, $data)) {
                    $snapshot[$field] = null;
                }
            } elseif (! array_key_exists($field, $data) && isset($profile->{self::column($field).'_id'})) {
                $snapshot[$field.'Id'] = $profile->{self::column($field).'_id'};
            }
        }
        $snapshot['countryCode'] = array_key_exists('countryCode', $data) ? $data['countryCode']
            : ((array_key_exists('country', $data) || (array_key_exists('countryId', $data) && $data['countryId'] === null))
                ? null : $profile->country_code);
        // A changed ancestor must not carry stale descendant IDs across locations.
        $changed = false;
        foreach (['country', 'state', 'city', 'district', 'subDistrict', 'locality'] as $field) {
            $labelChanged = array_key_exists($field, $data) && ! array_key_exists($field.'Id', $data)
                && trim((string) $data[$field]) !== trim((string) ($profile->{self::column($field)} ?? ''));
            if (! $idsOnly && $labelChanged) {
                unset($snapshot[$field.'Id']);
            }
            if (! $idsOnly && $changed && ! array_key_exists($field.'Id', $data)) {
                unset($snapshot[$field.'Id']);
            }
            if ($labelChanged || (array_key_exists($field.'Id', $data) && (int) $data[$field.'Id'] !== (int) ($profile->{self::column($field).'_id'} ?? 0))) {
                $changed = true;
            }
        }
        $resolved = $this->resolveLegacy($snapshot);
        if (! $idsOnly) {
            $resolved = $this->registerMissing($resolved, $data);
        }
        $updates = [];
        $before = [];
        $after = [];
        foreach (self::TABLES as $field => $_) {
            $column = self::column($field).'_id';
            $updates[$column] = $resolved[$field.'Id'] ?? null;
            $before[$column] = $profile->$column;
            $after[$column] = $updates[$column];
            // Keep existing SEO labels stable when only the UI language changes.
            if (! $idsOnly && (array_key_exists($field.'Id', $data) || (empty($profile->{self::column($field)}) && isset($resolved[$field.'Id'])))) {
                $updates[self::column($field)] = array_key_exists($field.'Id', $data) && $data[$field.'Id'] === null && ! array_key_exists($field, $data)
                    ? null : ($data[$field] ?? $resolved[$field]);
            }
        }
        if (! $idsOnly && isset($resolved['countryCode'])) {
            $updates['country_code'] = $resolved['countryCode'];
        }
        DB::table($table)->where('user_id', $userId)->update($updates);
        if ($before != $after) {
            app(BusinessActivityRecorder::class)->record($type.'.location_identity_changed', $userId, $userId, before: $before, after: $after);
        }
    }

    public static function display($rows, string $language): void
    {
        foreach (self::TABLES as $field => $_) {
            $ids = collect($rows)->pluck(self::column($field).'_id')->filter()->unique();
            if ($ids->isEmpty()) {
                continue;
            }
            $names = DB::table('location_translations')->where('location_type', $field)->where('language', $language)
                ->whereIn('location_id', $ids)->pluck('name', 'location_id');
            foreach ($rows as $row) {
                if (isset($row->{self::column($field).'_id'}) && isset($names[$row->{self::column($field).'_id'}])) {
                    $row->{self::column($field)} = $names[$row->{self::column($field).'_id'}];
                }
            }
        }
    }

    public static function labels(string $type, $rows, string $language): void
    {
        $names = DB::table('location_translations')->where('location_type', $type)->where('language', $language)
            ->whereIn('location_id', $rows->pluck('id'))->pluck('name', 'location_id');
        foreach ($rows as $row) {
            if (isset($names[$row->id])) {
                $row->canonical_name = $row->name;
                $row->name = $names[$row->id];
            }
        }
    }
}
