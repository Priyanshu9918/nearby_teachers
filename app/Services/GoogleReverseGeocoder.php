<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class GoogleReverseGeocoder
{
    public function lookup(float $latitude, float $longitude): array
    {
        $details = ['version' => 1, 'provider' => 'google', 'status' => 'not_configured',
            'checked_at' => now()->toIso8601String(), 'attribution' => 'Google Maps'];
        if (! config('services.google_geocoding.enabled') || ! filled(config('services.google_geocoding.key'))) {
            return $details;
        }

        // No retries: each explicitly submitted coordinate pair makes at most one billable request.
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => $latitude.','.$longitude, 'language' => 'en',
                    'key' => config('services.google_geocoding.key'),
                ]);
        } catch (ConnectionException) {
            return array_replace($details, ['status' => 'unavailable']);
        }
        if (! $response->successful()) {
            return array_replace($details, ['status' => 'unavailable']);
        }
        $body = $response->json();
        if (! is_array($body)) {
            return array_replace($details, ['status' => 'invalid_response']);
        }
        $status = $body['status'] ?? null;
        if ($status !== 'OK') {
            $known = ['ZERO_RESULTS', 'OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT', 'REQUEST_DENIED', 'INVALID_REQUEST', 'UNKNOWN_ERROR'];

            return array_replace($details, ['status' => in_array($status, $known, true) ? strtolower($status) : 'invalid_response']);
        }
        if (! is_array($body['results'] ?? null) || $body['results'] === []) {
            return array_replace($details, ['status' => 'invalid_response']);
        }
        $addresses = [];
        foreach (array_slice($body['results'], 0, 20) as $result) {
            if (! is_array($result) || ! is_array($result['address_components'] ?? null)) {
                continue;
            }
            $components = [];
            foreach (array_slice($result['address_components'], 0, 64) as $component) {
                if (! is_array($component) || ! is_string($component['long_name'] ?? null)) {
                    continue;
                }
                $components[] = ['long_name' => mb_substr($component['long_name'], 0, 1000),
                    'short_name' => is_string($component['short_name'] ?? null) ? mb_substr($component['short_name'], 0, 1000) : null,
                    'types' => $this->types($component['types'] ?? [])];
            }
            if ($components === []) {
                continue;
            }
            $addresses[] = [
                'place_id' => is_string($result['place_id'] ?? null) ? mb_substr($result['place_id'], 0, 512) : null,
                'formatted_address' => is_string($result['formatted_address'] ?? null) ? mb_substr($result['formatted_address'], 0, 4000) : null,
                'types' => $this->types($result['types'] ?? []), 'components' => $components,
            ];
        }
        if ($addresses === []) {
            return array_replace($details, ['status' => 'invalid_response']);
        }
        $values = [];
        foreach ($addresses[0]['components'] as $component) {
            foreach ($component['types'] as $type) {
                if ($type !== 'political' && ! isset($values[$type])) {
                    $values[$type] = $component['long_name'];
                }
            }
            if (in_array('country', $component['types'], true)) {
                $values['country_code'] = $component['short_name'];
            }
        }

        return array_replace($details, ['status' => 'ok', 'place_id' => $addresses[0]['place_id'],
            'formatted_address' => $addresses[0]['formatted_address'],
            // A locality is not necessarily a village, and a nearby police POI is not a jurisdiction.
            'village' => $values['village'] ?? null, 'police_station' => $values['police_station'] ?? null,
            'postal_code' => $values['postal_code'] ?? null, 'address_values' => $values, 'addresses' => $addresses]);
    }

    private function types(mixed $types): array
    {
        return is_array($types) ? array_values(array_slice(array_filter($types,
            fn ($type) => is_string($type) && preg_match('/^[a-z0-9_]{1,80}$/D', $type)), 0, 20)) : [];
    }
}
