<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reporting\ReportAccessLog;
use App\Services\SupportContactDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SupportContactController extends Controller
{
    public function show(SupportContactDetails $contact): JsonResponse
    {
        return response()->json(['contact' => $contact->publicPayload()])
            ->header('Cache-Control', 'no-store');
    }

    public function adminShow(Request $request, SupportContactDetails $contact): JsonResponse
    {
        app(ReportAccessLog::class)->record($request, 'support-contact-settings', 'api-view');

        return response()->json(['contact' => $contact->publicPayload()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, SupportContactDetails $contact): JsonResponse
    {
        $data = $request->validate(SupportContactDetails::rules());
        $admin = $request->attributes->get('authenticated_user');
        $contact->update($data, (string) $admin->id);
        app(ReportAccessLog::class)->record($request, 'support-contact-settings', 'api-update', '1');

        return response()->json(['contact' => $contact->publicPayload()])
            ->header('Cache-Control', 'no-store, private');
    }
}
