<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class I18nController extends Controller
{
    private const LANGUAGES = ['en', 'hi', 'es', 'fr', 'ar', 'zh', 'pt', 'ru'];

    public function languages(): JsonResponse
    {
        $languages = [];
        foreach (self::LANGUAGES as $code) {
            $translations = $this->load($code);
            $meta = $translations['_meta'];
            $languages[] = [
                'code' => $code,
                'name' => $meta['name'],
                'nativeName' => $meta['nativeName'],
                'rtl' => $meta['rtl'],
            ];
        }

        return response()->json(['languages' => $languages]);
    }

    public function translations(string $lang): JsonResponse
    {
        $supported = in_array($lang, self::LANGUAGES, true);
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
