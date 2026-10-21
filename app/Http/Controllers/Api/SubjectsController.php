<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SubjectsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $rows = DB::table('subject_domains')
            ->leftJoin('subjects', 'subjects.domain_id', '=', 'subject_domains.id')
            ->select(
                'subject_domains.id as domain_id',
                'subject_domains.name as domain_name',
                'subject_domains.icon as domain_icon',
                'subject_domains.sort_order',
                'subjects.id as subject_id',
                'subjects.name as subject_name',
                'subjects.is_bsl'
            )
            ->orderBy('subject_domains.sort_order')
            ->orderBy('subject_domains.id')
            ->orderBy('subjects.name')
            ->get();

        $domains = [];
        $featured = [];
        foreach ($rows as $row) {
            if (! isset($domains[$row->domain_id])) {
                $domains[$row->domain_id] = [
                    'id' => (int) $row->domain_id,
                    'name' => $row->domain_name,
                    'icon' => $row->domain_icon,
                    'subjects' => [],
                ];
            }
            if ($row->subject_id === null) {
                continue;
            }

            $subject = [
                'id' => (int) $row->subject_id,
                'name' => $row->subject_name,
                'domain_id' => (int) $row->domain_id,
                'is_bsl' => (bool) $row->is_bsl,
            ];
            $domains[$row->domain_id]['subjects'][] = $subject;

            if ($subject['is_bsl']) {
                $featured[] = [
                    'id' => $subject['id'],
                    'name' => $subject['name'],
                    'domain_id' => $subject['domain_id'],
                ];
            }
        }

        usort($featured, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        $virtualDomain = [
            'id' => 'instant-teacher-available',
            'name' => 'Instant Teacher Available',
            'icon' => 'ti-bolt',
            'isVirtual' => true,
            'subjects' => $featured,
        ];

        return response()->json([
            'bslFeatured' => $featured,
            'domains' => [$virtualDomain, ...array_values($domains)],
        ]);
    }
}
