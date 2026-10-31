<?php

namespace App\Support;

final class SafetyReportCategory
{
    private const ALIASES = [
        'fraud' => 'fraud',
        'fraud or scam' => 'fraud',
        'fraud_or_scam' => 'fraud',
        'fake_profile' => 'fake_profile',
        'fake profile / not who they claim' => 'fake_profile',
        'fake profile/not who they claim' => 'fake_profile',
        'fake_profile_not_who_they_claim' => 'fake_profile',
        'inappropriate' => 'inappropriate',
        'inappropriate behavior' => 'inappropriate',
        'inappropriate_behavior' => 'inappropriate',
        'spam' => 'spam',
        'other' => 'other',
    ];

    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = mb_strtolower(trim($value), 'UTF-8');

        return self::ALIASES[$normalized] ?? $value;
    }
}
