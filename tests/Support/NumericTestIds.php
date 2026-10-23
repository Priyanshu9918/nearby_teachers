<?php

namespace Tests\Support;

final class NumericTestIds
{
    private static int $next = 0;

    public static function next(): int
    {
        // Leave space for auto-incremented application records between fixtures.
        return self::$next += 100000;
    }
}
