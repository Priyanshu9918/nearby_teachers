<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PublicLanguages;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class I18nController extends Controller
{
    public function languages(): JsonResponse
    {
        return response()->json(['languages' => PublicLanguages::all()]);
    }

    public function translations(string $lang): JsonResponse
    {
        $supported = in_array($lang, PublicLanguages::codes(), true);
        $served = $supported ? $lang : 'en';

        return response()->json([
            'requested' => $lang,
            'served' => $served,
            'translations' => $this->load($served),
        ]);
    }

    private function load(string $language): array
    {
        $path = base_path("src/i18n/{$language}.json");
        $translations = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        return $translations;
    }
}
