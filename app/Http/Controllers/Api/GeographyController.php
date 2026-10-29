<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeographyController
{
    private const POPULAR_COUNTRY_CODES = ['IN', 'US', 'GB', 'ES', 'FR', 'SA', 'CN', 'PT', 'RU'];

    public function countries(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['sometimes', 'string', 'max:100']]);
        $countries = $this->search(DB::table('countries'), $data)
            ->orderByRaw("CASE WHEN UPPER(sortname) IN ('IN', 'US', 'GB', 'ES', 'FR', 'SA', 'CN', 'PT', 'RU') THEN 0 ELSE 1 END")
            ->orderBy('name')->orderBy('id')->get(['id', 'sortname', 'name', 'phonecode']);
        $countries->each(function (object $country): void {
            if (in_array(strtoupper((string) $country->sortname), self::POPULAR_COUNTRY_CODES, true)) {
                $country->is_popular = true;
            }
        });

        return response()->json(['countries' => $countries]);
    }

    public function states(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
        $countryId = (int) $data['country_id'];
        abort_unless(DB::table('countries')->where('id', $countryId)->exists(), 404, 'Country not found.');
        $states = $this->search(DB::table('states')->where('country_id', $countryId), $data)
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'country_id']);

        return response()->json(['country_id' => $countryId, 'states' => $states]);
    }

    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
        $stateId = (int) $data['state_id'];
        abort_unless(DB::table('states')->where('id', $stateId)->exists(), 404, 'State not found.');
        $cities = $this->search(DB::table('cities')->where('state_id', $stateId), $data)
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'state_id']);

        return response()->json([
            'state_id' => $stateId,
            'cities' => $cities,
        ]);
    }

    private function search(Builder $query, array $data): Builder
    {
        // Bind an escaped pattern so user-supplied % and _ remain literal.
        return $query->when(isset($data['search']) && $data['search'] !== '', function (Builder $query) use ($data): void {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['search']);
            $query->where('name', 'like', '%'.$term.'%');
        });
    }
}
