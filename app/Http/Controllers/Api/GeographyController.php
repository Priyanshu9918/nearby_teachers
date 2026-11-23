<?php

namespace App\Http\Controllers\Api;

use App\Services\LocationIdentity;
use App\Support\PublicLanguages;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeographyController
{
    private const POPULAR_COUNTRY_CODES = ['IN', 'US', 'GB', 'CA', 'AE', 'BD', 'PK', 'BR', 'AU'];

    public function resolve(Request $request, LocationIdentity $locations): JsonResponse
    {
        $data = $request->validate(LocationIdentity::inputRules());
        $resolved = $locations->resolveLegacy($data);
        $result = [];
        foreach (LocationIdentity::TABLES as $field => $_) {
            $id = $resolved[$field.'Id'] ?? null;
            $result[$field] = [
                'id' => $id,
                'name' => $resolved[$field] ?? $data[$field] ?? null,
                'status' => $id !== null ? 'matched' : (filled($data[$field] ?? null) ? 'unresolved' : 'not_provided'),
            ];
        }

        return response()->json(['locations' => $result, 'countryCode' => $resolved['countryCode'] ?? $data['countryCode'] ?? null]);
    }

    public function countries(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['sometimes', 'string', 'max:100'], 'lang' => ['sometimes', 'string', 'in:'.implode(',', PublicLanguages::codes())]]);
        $countries = $this->search(DB::table('countries'), $data, 'country')
            ->orderBy('name')->orderBy('id')->get(['id', 'sortname', 'name', 'phonecode']);
        LocationIdentity::labels('country', $countries, $data['lang'] ?? 'en');
        $popular = collect();
        foreach (self::POPULAR_COUNTRY_CODES as $code) {
            foreach ($countries as $country) {
                if (strtoupper((string) $country->sortname) === $code) {
                    $entry = clone $country;
                    $entry->is_popular = true;
                    $popular->push($entry);
                }
            }
        }

        // Keep popular entries in the full alphabetical list as well.
        return response()->json(['countries' => $popular->concat($countries)->values()]);
    }

    public function states(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'search' => ['sometimes', 'string', 'max:100'],
            'lang' => ['sometimes', 'string', 'in:'.implode(',', PublicLanguages::codes())],
        ]);
        $countryId = (int) $data['country_id'];
        abort_unless(DB::table('countries')->where('id', $countryId)->exists(), 404, 'Country not found.');
        $states = $this->search(DB::table('states')->where('country_id', $countryId), $data, 'state')
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'country_id']);

        LocationIdentity::labels('state', $states, $data['lang'] ?? 'en');

        return response()->json(['country_id' => $countryId, 'states' => $states]);
    }

    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state_id' => ['required_without:country_id', 'nullable', 'integer', 'min:1', 'max:9007199254740991'],
            'country_id' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991', 'exists:countries,id'],
            'search' => ['sometimes', 'string', 'max:100'],
            'lang' => ['sometimes', 'string', 'in:'.implode(',', PublicLanguages::codes())],
        ]);
        abort_if(! isset($data['state_id']) && ! isset($data['country_id']), 422, 'Provide state_id or country_id.');
        $stateId = isset($data['state_id']) ? (int) $data['state_id'] : null;
        $countryId = isset($data['country_id']) ? (int) $data['country_id'] : null;
        if ($stateId !== null) {
            abort_unless(DB::table('states')->where('id', $stateId)->exists(), 404, 'State not found.');
        }
        if ($stateId !== null && $countryId !== null) {
            abort_unless(DB::table('states')->where('id', $stateId)->where('country_id', $countryId)->exists(), 422, 'State does not belong to this country.');
        }
        $query = DB::table('cities')->when($stateId !== null,
            fn (Builder $query) => $query->where('state_id', $stateId),
            fn (Builder $query) => $query->whereNull('state_id')->where('country_id', $countryId),
        );
        $query = $this->search($query, $data, 'city')->orderBy('name')->orderBy('id');
        $columns = ['id', 'name', 'state_id'];
        if ($stateId === null) {
            $columns[] = 'country_id';
        }
        $cities = $query->get($columns);

        LocationIdentity::labels('city', $cities, $data['lang'] ?? 'en');

        return response()->json([
            ...($stateId === null ? ['country_id' => $countryId] : ['state_id' => $stateId]),
            'cities' => $cities,
        ]);
    }

    public function districts(Request $request): JsonResponse
    {
        return $this->areas($request, 'district');
    }

    public function localities(Request $request): JsonResponse
    {
        return $this->areas($request, 'locality');
    }

    public function subDistricts(Request $request): JsonResponse
    {
        return $this->areas($request, 'subDistrict');
    }

    private function areas(Request $request, string $type): JsonResponse
    {
        $data = $request->validate([
            'state_id' => ['sometimes', 'integer', 'exists:states,id'],
            'country_id' => ['sometimes', 'integer', 'exists:countries,id'],
            'district_id' => ['sometimes', 'integer', 'exists:districts,id'],
            'city_id' => ['sometimes', 'integer', 'exists:cities,id'],
            'search' => ['sometimes', 'string', 'max:100'],
            'lang' => ['sometimes', 'string', 'in:'.implode(',', PublicLanguages::codes())],
        ]);
        $filters = $type === 'district' ? array_intersect_key($data, array_flip(['state_id', 'country_id', 'city_id']))
            : array_intersect_key($data, array_flip(['district_id', 'city_id']));
        abort_if($filters === [], 422, 'Provide a parent location ID.');
        if ($type === 'district' && isset($filters['state_id'], $filters['country_id'])) {
            abort_unless(DB::table('states')->where('id', $filters['state_id'])->where('country_id', $filters['country_id'])->exists(),
                422, 'State does not belong to this country.');
        }
        $query = DB::table(LocationIdentity::TABLES[$type]);
        foreach ($filters as $field => $id) {
            $query->where($field, (int) $id);
        }
        $columns = $type === 'district' ? ['id', 'name', 'country_id', 'state_id', 'city_id']
            : ['id', 'name', 'country_id', 'state_id', 'district_id', 'city_id'];
        $rows = $this->search($query, $data, $type)
            ->orderBy('name')->orderBy('id')->get($columns);
        LocationIdentity::labels($type, $rows, $data['lang'] ?? 'en');

        return response()->json([...array_map('intval', $filters), LocationIdentity::TABLES[$type] => $rows]);
    }

    private function search(Builder $query, array $data, string $type): Builder
    {
        // Bind an escaped pattern so user-supplied % and _ remain literal.
        return $query->when(isset($data['search']) && $data['search'] !== '', function (Builder $query) use ($data, $type): void {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['search']);
            $query->where(function ($names) use ($term, $type, $data): void {
                $names->where('name', 'like', '%'.$term.'%')->orWhereIn('id', DB::table('location_translations')->select('location_id')
                    ->where('location_type', $type)->where('language', $data['lang'] ?? 'en')->where('name', 'like', '%'.$term.'%'));
            });
        });
    }
}
