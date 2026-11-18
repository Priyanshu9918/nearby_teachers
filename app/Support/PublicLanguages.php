<?php

namespace App\Support;

use Illuminate\Http\Request;

final class PublicLanguages
{
    public static function all(): array
    {
        static $languages;

        return $languages ??= json_decode(file_get_contents(resource_path('languages.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }

    public static function supported(mixed $code): bool
    {
        return is_string($code) && in_array($code, self::codes(), true);
    }

    public static function resolve(Request $request, string $default = 'en'): string
    {
        foreach ([$request->query('lang'), $request->cookie('nt_lang')] as $code) {
            if (self::supported($code)) {
                return $code;
            }
        }
        foreach ($request->getLanguages() as $tag) {
            $code = strtolower(substr($tag, 0, 2));
            if (self::supported($code)) {
                return $code;
            }
        }

        return self::supported($default) ? $default : 'en';
    }

    public static function translations(string $language): array
    {
        $language = self::supported($language) ? $language : 'en';

        return json_decode(file_get_contents(base_path('src/i18n/'.$language.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
