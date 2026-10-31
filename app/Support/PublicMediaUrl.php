<?php

namespace App\Support;

final class PublicMediaUrl
{
    public static function fromStoragePath(string $path): string
    {
        return url('/storage/'.ltrim($path, '/'));
    }

    public static function resolve(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return $value;
        }
        $host = trim(strtolower($parts['host'] ?? ''), '[]');
        if ($host !== '' && ! in_array($host, ['localhost', 'localhost.localdomain', '127.0.0.1', '::1'], true)) {
            return $value;
        }

        $path = $parts['path'] ?? $value;
        if (preg_match('#(?:^|/)storage/(.+)$#', $path, $matches) === 1) {
            $resolved = self::fromStoragePath($matches[1]);
        } elseif (preg_match('#^(?:/)?(?:student|teacher|institute)-photos/.+$#', $path) === 1) {
            $resolved = self::fromStoragePath(ltrim($path, '/'));
        } else {
            return $value;
        }

        if (isset($parts['query'])) {
            $resolved .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $resolved .= '#'.$parts['fragment'];
        }

        return $resolved;
    }
}
