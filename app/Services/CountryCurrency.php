<?php

namespace App\Services;

class CountryCurrency
{
    private const COUNTRIES = [
        'IN' => 'INR', 'PK' => 'PKR', 'BD' => 'BDT', 'LK' => 'LKR', 'NP' => 'NPR',
        'AE' => 'AED', 'SA' => 'SAR', 'EG' => 'EGP', 'QA' => 'QAR', 'KW' => 'KWD',
        'BH' => 'BHD', 'OM' => 'OMR', 'JO' => 'JOD', 'LB' => 'LBP', 'IQ' => 'IQD',
        'MA' => 'MAD', 'DZ' => 'DZD', 'TN' => 'TND',
        'GB' => 'GBP', 'FR' => 'EUR', 'DE' => 'EUR', 'ES' => 'EUR', 'IT' => 'EUR',
        'PT' => 'EUR', 'NL' => 'EUR', 'BE' => 'EUR', 'IE' => 'EUR', 'AT' => 'EUR',
        'GR' => 'EUR', 'FI' => 'EUR', 'CH' => 'CHF', 'SE' => 'SEK', 'NO' => 'NOK',
        'DK' => 'DKK', 'PL' => 'PLN', 'CZ' => 'CZK', 'RU' => 'RUB', 'UA' => 'UAH',
        'RO' => 'RON', 'HU' => 'HUF',
        'US' => 'USD', 'CA' => 'CAD', 'MX' => 'MXN', 'BR' => 'BRL', 'AR' => 'ARS',
        'CO' => 'COP', 'CL' => 'CLP', 'PE' => 'PEN',
        'CN' => 'CNY', 'JP' => 'JPY', 'KR' => 'KRW', 'TW' => 'TWD', 'HK' => 'HKD',
        'SG' => 'SGD', 'MY' => 'MYR', 'TH' => 'THB', 'VN' => 'VND', 'PH' => 'PHP',
        'ID' => 'IDR', 'AU' => 'AUD', 'NZ' => 'NZD',
        'ZA' => 'ZAR', 'NG' => 'NGN', 'KE' => 'KES', 'GH' => 'GHS',
    ];

    public static function forCountry(?string $countryCode): ?string
    {
        if ($countryCode === null || trim($countryCode) === '') {
            return null;
        }

        return self::COUNTRIES[strtoupper($countryCode)] ?? 'USD';
    }
}
