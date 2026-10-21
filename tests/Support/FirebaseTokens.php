<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

trait FirebaseTokens
{
    private static ?array $firebaseKeys = null;

    private function fakeFirebase(): void
    {
        if (self::$firebaseKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$firebaseKeys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }
        config(['cache.default' => 'array', 'services.nearby_teacher.firebase_project_id' => 'nearby-firebase-test']);
        Cache::forget('firebase.securetoken.certificates');
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/robot/v1/metadata/x509/*' => Http::response(['test-key' => self::$firebaseKeys['public']], 200, ['Cache-Control' => 'public, max-age=60'])]);
    }

    private function firebaseToken(array $overrides = []): string
    {
        return JWT::encode(array_replace([
            'aud' => 'nearby-firebase-test', 'iss' => 'https://securetoken.google.com/nearby-firebase-test',
            'sub' => 'firebase-google-user', 'iat' => now()->timestamp - 5, 'exp' => now()->timestamp + 3595,
            'auth_time' => now()->timestamp - 10, 'name' => 'Google Person', 'email' => 'google@example.test',
            'email_verified' => true, 'firebase' => ['sign_in_provider' => 'google.com'],
        ], $overrides), self::$firebaseKeys['private'], 'RS256', 'test-key');
    }
}
