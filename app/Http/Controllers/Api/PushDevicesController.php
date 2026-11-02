<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PushDeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PushDevicesController extends Controller
{
    public function register(Request $request, PushDeviceRegistry $devices): JsonResponse
    {
        $data = $request->validate([
            'firebaseInstallationId' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,128}$/D'],
            'notificationsEnabled' => ['required', 'accepted'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'locationConsent' => ['sometimes', 'boolean'],
            'locationCity' => ['prohibited_unless:locationConsent,1', 'nullable', 'string', 'max:120'],
            'locationState' => ['prohibited_unless:locationConsent,1', 'nullable', 'string', 'max:120'],
            'locationCountry' => ['prohibited_unless:locationConsent,1', 'nullable', 'string', 'max:120'],
        ]);
        if (($data['locationConsent'] ?? false)
            && ! filled($data['locationCity'] ?? null)
            && ! filled($data['locationState'] ?? null)
            && ! filled($data['locationCountry'] ?? null)) {
            throw ValidationException::withMessages(['locationCity' => 'Provide at least one coarse location field when location consent is enabled.']);
        }
        $user = $request->attributes->get('authenticated_user');

        $result = $devices->register($user, $data);

        return response()->json($result, 200)->header('Cache-Control', 'no-store');
    }

    public function unregister(Request $request, PushDeviceRegistry $devices): JsonResponse
    {
        $data = $request->validate([
            'firebaseInstallationId' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,128}$/D'],
        ]);
        $devices->unregister($request->attributes->get('authenticated_user'), $data['firebaseInstallationId']);

        return response()->json(['registered' => false])->header('Cache-Control', 'no-store');
    }
}
