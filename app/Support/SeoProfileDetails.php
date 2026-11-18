<?php

namespace App\Support;

use libphonenumber\PhoneNumberUtil;
use Throwable;

final class SeoProfileDetails
{
    public static function address(object $profile): string
    {
        $parts = [];
        foreach (['address_text', 'locality', 'sub_district', 'district', 'city', 'state', 'postal_code', 'country'] as $field) {
            $value = trim((string) ($profile->$field ?? ''));
            if ($value !== '' && ! str_contains(mb_strtolower(implode(', ', $parts)), mb_strtolower($value))) {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }

    public static function maskPhone(?string $phone): ?string
    {
        if (! filled($phone)) {
            return null;
        }
        try {
            $util = PhoneNumberUtil::getInstance();
            $digits = $util->getNationalSignificantNumber($util->parse($phone));
        } catch (Throwable) {
            $digits = preg_replace('/\D/', '', $phone);
        }
        if ($digits === '') {
            return null;
        }

        return strlen($digits) > 7
            ? substr($digits, 0, 7).str_repeat('x', strlen($digits) - 7)
            : str_repeat('x', strlen($digits));
    }

    /** Drop the full phone number before handing public data to any view. */
    public static function prepare(object $profile): object
    {
        $profile->masked_phone = self::maskPhone($profile->contact_phone ?? null);
        unset($profile->contact_phone);

        return $profile;
    }
}
