<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SafetyReportCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SafetyReportsController extends Controller
{
    public function store(Request $request, SafetyReportCreator $reports): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        abort_unless($user !== null && in_array($user->account_type, ['learner', 'teacher', 'institute'], true), 403, 'User account required.');

        $data = $request->validate([
            'targetType' => ['required', 'in:user,post,comment,message'],
            'targetId' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'reasonCategory' => ['required', 'in:fraud,fake_profile,inappropriate,spam,other'],
            'additionalDetails' => ['sometimes', 'nullable', 'string', 'max:500'],
            'evidenceLink' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'screenshot' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $details = isset($data['additionalDetails']) ? trim($data['additionalDetails']) : null;
        $report = $reports->create(
            (int) $user->id,
            $data['targetType'],
            (int) $data['targetId'],
            $data['reasonCategory'],
            $details === '' ? null : $details,
            $data['evidenceLink'] ?? null,
            $data['screenshot'] ?? null,
        );

        return response()->json(['report' => [
            'id' => $report->id,
            'reporterId' => $report->reporter_id,
            'targetType' => $report->target_type,
            'targetId' => $report->target_id,
            'reasonCategory' => $report->reason_category,
            'additionalDetails' => $report->reason,
            'evidenceLink' => $report->evidence_link,
            'screenshotAttached' => (bool) $report->screenshot_available,
            'status' => $report->status,
            'createdAt' => $report->created_at,
        ]], 201);
    }
}
