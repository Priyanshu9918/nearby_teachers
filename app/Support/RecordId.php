<?php

namespace App\Support;

final class RecordId
{
    public const ROUTE_PATTERN = '[1-9][0-9]{0,15}';

    public static function valid(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
            && strlen((string) $value) <= 16
            && (int) $value <= 9007199254740991;
    }
}
