<?php

namespace App\Support;

final class SafetyReportTarget
{
    private const TYPES = ['user', 'post', 'comment', 'message'];

    /** @return array{0: ?string, 1: ?int} */
    public static function normalize(mixed $type, mixed $id): array
    {
        $type = is_string($type) ? strtolower(trim($type)) : null;
        if (! in_array($type, self::TYPES, true) || ! RecordId::valid($id)) {
            return [null, null];
        }

        return [$type, (int) $id];
    }
}
