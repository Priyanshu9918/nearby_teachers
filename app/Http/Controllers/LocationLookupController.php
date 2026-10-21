<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class LocationLookupController extends Controller
{
    public function reverse(Request $request): JsonResponse
    {
        $coordinates = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $key = 'location-lookup:'.sha1((string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return response()->json([
                'message' => 'Please wait a moment before looking up another location.',
                'retryAfterSeconds' => RateLimiter::availableIn($key),
            ], 429);
        }
        RateLimiter::hit($key, 1);

        try {
            $response = Http::acceptJson()
                ->withUserAgent('NearbyTeacherLocationLookup/1.0 ('.config('app.url').')')
                ->connectTimeout(4)
                ->timeout(10)
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'format' => 'jsonv2',
                    'lat' => $coordinates['latitude'],
                    'lon' => $coordinates['longitude'],
                    'addressdetails' => 1,
                    'namedetails' => 1,
                    'extratags' => 1,
                    'zoom' => 18,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('OpenStreetMap reverse geocoding request failed.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'The location service could not be reached. Please try again later.',
            ], 503);
        }

        if ($response->status() === 404) {
            return response()->json([
                'message' => 'No address details were found for these coordinates.',
            ], 404);
        }

        if (! $response->successful()) {
            Log::warning('OpenStreetMap reverse geocoding returned an unsuccessful response.', [
                'status' => $response->status(),
            ]);

            return response()->json([
                'message' => 'The location service returned an error. Please try again later.',
            ], 502);
        }

        $details = $response->json();
        if (! is_array($details) || ! isset($details['display_name'], $details['address'])) {
            Log::warning('OpenStreetMap reverse geocoding returned an invalid response shape.');

            return response()->json([
                'message' => 'The location service returned incomplete address details.',
            ], 502);
        }

        return response()->json(['location' => $details]);
    }
}
