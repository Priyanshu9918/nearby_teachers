<?php

namespace App\Services;

use Closure;

final class YouTubeProfileLink
{
    public static function rules(): array
    {
        return ['sometimes', 'nullable', 'url:http,https', 'max:2048',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::valid($value)) {
                    $fail('Enter a valid YouTube channel or video URL.');
                }
            },
        ];
    }

    public static function valid(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 2048 || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        $url = parse_url($value);
        $host = strtolower($url['host'] ?? '');

        return in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            && ! isset($url['user']) && ! isset($url['pass'])
            && ($host === 'youtube.com' || str_ends_with($host, '.youtube.com') || $host === 'youtu.be');
    }
}
