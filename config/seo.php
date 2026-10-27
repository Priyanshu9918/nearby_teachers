<?php

return [
    'public_url' => env('SEO_PUBLIC_URL', 'http://157.245.125.79/nearby_teachers/public'),
    'city_slugs' => [
        'कानपुर' => 'kanpur', 'नई दिल्ली' => 'new-delhi', 'दिल्ली' => 'delhi',
        '東京' => 'tokyo', '大阪' => 'osaka', '北京' => 'beijing', '上海' => 'shanghai',
        'москва' => 'moscow', 'санкт-петербург' => 'saint-petersburg',
        'دبي' => 'dubai', 'أبو ظبي' => 'abu-dhabi',
    ],
    // Explicit country defaults; never infer a teacher's country from IP or a city name.
    'countries' => [
        'in' => ['name' => 'India', 'language' => 'en', 'languages' => ['en', 'hi', 'bn', 'ur'], 'aliases' => ['india', 'bharat', 'भारत', 'ভারত', 'بھارت'], 'style' => 'tuition'],
        'gb' => ['name' => 'United Kingdom', 'language' => 'en', 'languages' => ['en'], 'aliases' => ['united kingdom', 'uk', 'great britain', 'england'], 'style' => 'tutor'],
        'us' => ['name' => 'United States', 'language' => 'en', 'languages' => ['en'], 'aliases' => ['united states', 'usa', 'united states of america'], 'style' => 'tutoring'],
        'es' => ['name' => 'España', 'language' => 'es', 'languages' => ['es', 'en'], 'aliases' => ['spain', 'espana', 'españa'], 'style' => 'tutor'],
        'ae' => ['name' => 'الإمارات العربية المتحدة', 'language' => 'ar', 'languages' => ['ar', 'en'], 'aliases' => ['united arab emirates', 'uae'], 'style' => 'tutor'],
        'ru' => ['name' => 'Россия', 'language' => 'ru', 'languages' => ['ru'], 'aliases' => ['russia', 'russian federation'], 'style' => 'tutor'],
        'cn' => ['name' => '中国', 'language' => 'zh', 'languages' => ['zh'], 'aliases' => ['china'], 'style' => 'tutor'],
        'jp' => ['name' => '日本', 'language' => 'ja', 'languages' => ['ja'], 'aliases' => ['japan'], 'style' => 'tutor'],
        'bd' => ['name' => 'বাংলাদেশ', 'language' => 'bn', 'languages' => ['bn'], 'aliases' => ['bangladesh'], 'style' => 'tuition'],
        'pk' => ['name' => 'پاکستان', 'language' => 'ur', 'languages' => ['ur'], 'aliases' => ['pakistan'], 'style' => 'tuition'],
        'fr' => ['name' => 'France', 'language' => 'fr', 'languages' => ['fr'], 'aliases' => ['france'], 'style' => 'tutor'],
        'pt' => ['name' => 'Portugal', 'language' => 'pt', 'languages' => ['pt'], 'aliases' => ['portugal'], 'style' => 'tutor'],
        'br' => ['name' => 'Brasil', 'language' => 'pt', 'languages' => ['pt'], 'aliases' => ['brazil', 'brasil'], 'style' => 'tutor'],
        'ca' => ['name' => 'Canada', 'language' => 'en', 'languages' => ['en'], 'aliases' => ['canada'], 'style' => 'tutor'],
        'au' => ['name' => 'Australia', 'language' => 'en', 'languages' => ['en'], 'aliases' => ['australia'], 'style' => 'tutor'],
    ],
];
