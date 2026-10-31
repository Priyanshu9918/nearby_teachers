<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SafetyReportCreator
{
    private const TARGET_TABLES = [
        'user' => 'users',
        'post' => 'posts',
        'comment' => 'post_comments',
        'message' => 'messages',
    ];

    public function create(
        int $reporterId,
        ?string $targetType,
        ?int $targetId,
        string $reasonCategory,
        ?string $details = null,
        ?string $evidenceLink = null,
        ?UploadedFile $screenshot = null,
    ): object {
        if (! isset(self::TARGET_TABLES[$targetType ?? '']) || $targetId === null) {
            $targetType = null;
            $targetId = null;
        }
        $targetTable = $targetType === null ? null : self::TARGET_TABLES[$targetType];
        $storedPath = $screenshot?->store('reports', 'local');
        if ($screenshot !== null && (! is_string($storedPath) || $storedPath === '')) {
            throw new \RuntimeException('Report screenshot could not be stored.');
        }

        try {
            return DB::transaction(function () use (
                $reporterId, $targetType, $targetId, $targetTable, $reasonCategory, $details, $evidenceLink, $screenshot, $storedPath
            ): object {
                if ($targetTable !== null && $targetId !== null) {
                    if ($targetType === 'message') {
                        $target = DB::table('messages as m')
                            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
                            ->where('m.id', $targetId)
                            ->where('m.sender_id', '!=', $reporterId)
                            ->where(fn ($query) => $query->where('c.learner_id', $reporterId)
                                ->orWhere('c.provider_id', $reporterId))
                            ->lockForUpdate()
                            ->first(['m.id']);
                    } else {
                        $target = DB::table($targetTable)->where('id', $targetId)->lockForUpdate()->first(['id']);
                    }

                    if ($target === null || ($targetType === 'user' && $targetId === $reporterId)) {
                        $targetType = null;
                        $targetId = null;
                    }
                }

                $reportId = DB::table('reports')->insertGetId([
                    'reporter_id' => $reporterId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'reason_category' => $reasonCategory,
                    'reason' => $details,
                    'evidence_link' => $evidenceLink,
                    'screenshot_path' => $storedPath,
                    'status' => 'open',
                    'created_at' => now(),
                ]);

                app(BusinessActivityRecorder::class)->record(
                    'safety_report.submitted',
                    (string) $reportId,
                    $reporterId,
                    $targetType === 'user' ? $targetId : null,
                    after: [
                        'target_type' => $targetType,
                        'reason_category' => $reasonCategory,
                        'status' => 'open',
                        'has_screenshot' => $screenshot !== null,
                        'has_evidence_link' => $evidenceLink !== null,
                    ],
                );

                $report = DB::table('reports')->where('id', $reportId)->first([
                    'id', 'reporter_id', 'target_type', 'target_id', 'reason_category', 'reason',
                    'evidence_link', 'status', 'created_at',
                ]);
                $report->screenshot_available = $screenshot !== null;

                return $report;
            }, 3);
        } catch (Throwable $error) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $error;
        }
    }
}
