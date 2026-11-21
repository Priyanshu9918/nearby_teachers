<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PublicLanguages;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SubjectsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $request = request();
        $user = $request->attributes->get('authenticated_user');
        $requestedLanguage = $user?->preferred_language ?? $request->query('lang', 'en');
        $language = PublicLanguages::supported($requestedLanguage) ? $requestedLanguage : 'en';
        $translations = $language === 'en' ? [] : $this->translations($language);

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
                    'name' => $this->translate($row->domain_name, $translations),
                    'icon' => $row->domain_icon,
                    'subjects' => [],
                ];
            }
            if ($row->subject_id === null) {
                continue;
            }

            $subject = [
                'id' => (int) $row->subject_id,
                'name' => $this->translate($row->subject_name, $translations),
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
            'name' => $this->translate('Instant Teacher Available', $translations),
            'icon' => 'ti-bolt',
            'isVirtual' => true,
            'subjects' => $featured,
        ];

        return response()->json([
            'language' => $language,
            'bslFeatured' => $featured,
            'domains' => [$virtualDomain, ...array_values($domains)],
        ]);
    }

    private function translations(string $language): array
    {
        $path = resource_path('translations/subjects/'.$language.'.json');
        $custom = File::exists($path)
            ? json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR)
            : [];

        $seoPath = resource_path('seo/subjects.json');
        if (! File::exists($seoPath)) {
            return $custom;
        }

        $catalog = json_decode(File::get($seoPath), true, flags: JSON_THROW_ON_ERROR);
        foreach ($catalog as $entry) {
            $english = $entry['en'] ?? null;
            $localized = $entry[$language] ?? null;
            if (is_string($english) && is_string($localized) && $localized !== $english) {
                $custom[$english] ??= $localized;
            }
        }

        return $custom;
    }

    private function translate(string $name, array $translations): string
    {
        return $translations[$name] ?? $name;
    }
}
