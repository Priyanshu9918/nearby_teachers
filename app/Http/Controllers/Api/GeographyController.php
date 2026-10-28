<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeographyController
{
    public function countries(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['sometimes', 'string', 'max:100']]);
        $countries = DB::table('countries')->when($data['search'] ?? null, function (Builder $query, string $term): void {
            $query->where('name', 'like', '%'.$this->escapeLike($term).'%');
        })->orderBy('name')->get(['code', 'alpha3', 'name']);

        return response()->json(['countries' => $countries]);
    }

    public function states(Request $request, string $countryCode): JsonResponse
    {
        $countryCode = strtoupper($countryCode);
        abort_unless(DB::table('countries')->where('code', $countryCode)->exists(), 404);
        $data = $request->validate(['search' => ['sometimes', 'string', 'max:100']]);
        $states = DB::table('states')->where('country_code', $countryCode)
            ->when($data['search'] ?? null, function (Builder $query, string $term): void {
                $query->where('name', 'like', '%'.$this->escapeLike($term).'%');
            })->orderBy('name')->get(['id', 'code', 'name', 'type']);

        return response()->json(['countryCode' => $countryCode, 'states' => $states]);
    }

    public function cities(Request $request, string $countryCode): JsonResponse
    {
        $countryCode = strtoupper($countryCode);
        abort_unless(DB::table('countries')->where('code', $countryCode)->exists(), 404);
        $data = $request->validate([
            'stateCode' => ['sometimes', 'string', 'max:32'],
            'search' => ['sometimes', 'string', 'min:2', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = DB::table('cities as c')->leftJoin('states as s', 's.id', '=', 'c.state_id')
            ->where('c.country_code', $countryCode)
            ->when($data['stateCode'] ?? null, fn (Builder $query, string $code) => $query->where('s.code', $code))
            ->when($data['search'] ?? null, function (Builder $query, string $term): void {
                $pattern = '%'.$this->escapeLike($term).'%';
                $query->where(fn (Builder $nested) => $nested->where('c.name', 'like', $pattern)->orWhere('c.ascii_name', 'like', $pattern));
            });
        $perPage = (int) ($data['perPage'] ?? 50);
        $cities = $query->orderByDesc('c.population')->orderBy('c.name')->paginate($perPage, [
            'c.geoname_id as id', 'c.name', 'c.ascii_name as asciiName', 's.code as stateCode',
            's.name as stateName', 'c.latitude', 'c.longitude', 'c.population',
        ], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'countryCode' => $countryCode,
            'cities' => $cities->items(),
            'pagination' => ['page' => $cities->currentPage(), 'perPage' => $cities->perPage(), 'total' => $cities->total(), 'lastPage' => $cities->lastPage()],
            'source' => 'GeoNames',
        ]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
