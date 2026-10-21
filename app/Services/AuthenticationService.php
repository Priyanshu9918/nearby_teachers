<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AuthenticationService
{
    private const FIREBASE_CERTIFICATES_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    public function authenticate(string $token): ?object
    {
        $claims = $this->verifyOwnToken($token);
        if ($claims !== null) {
            $user = DB::table('users')->where('id', $claims->sub)->first();
            if ($user === null || (int) ($claims->ver ?? 0) !== (int) ($user->auth_version ?? 0)) {
                return null;
            }

            return $this->publicUser($user);
        }
        $userId = $this->verifyFirebaseToken($token);

        if ($userId === null) {
            return null;
        }

        return $this->publicUser(DB::table('users')->where('id', $userId)->first());
    }

    public function publicUser(?object $user): ?object
    {
        if ($user === null) {
            return null;
        }

        $user->password_set = (bool) ($user->password_set ?? filled($user->password_hash ?? null));
        unset($user->password_hash);

        return $user;
    }

    public function issueOtpToken(string $userId, int $authVersion = 0): string
    {
        $secret = (string) config('services.nearby_teacher.jwt_secret');

        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET must be configured before issuing login tokens.');
        }

        return JWT::encode([
            'sub' => $userId,
            'iss' => 'nearby-teacher-otp',
            'iat' => now()->timestamp,
            'exp' => now()->addDays(30)->timestamp,
            'ver' => $authVersion,
        ], $secret, 'HS256');
    }

    public function issueSessionToken(string $userId, int $authVersion = 0): string
    {
        $secret = (string) config('services.nearby_teacher.jwt_secret');
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET must be configured before issuing login tokens.');
        }

        $now = now()->timestamp;

        return JWT::encode([
            'sub' => $userId,
            'iss' => 'nearby-teacher-auth',
            'iat' => $now,
            'exp' => now()->addDays(30)->timestamp,
            'ver' => $authVersion,
        ], $secret, 'HS256');
    }

    public function verifyFirebaseToken(string $token): ?string
    {
        $claims = $this->verifyFirebaseClaims($token);
        if ($claims === null) {
            return null;
        }

        return $this->resolveFirebaseUserId($claims);
    }

    public function verifyFirebaseClaims(string $token): ?object
    {
        $serviceAccount = $this->serviceAccount();
        $projectId = $serviceAccount['project_id'] ?? null;

        if (! is_string($projectId) || $projectId === '') {
            return null;
        }

        try {
            $certificates = Cache::remember(
                'firebase.securetoken.certificates',
                now()->addMinutes(50),
                static function (): array {
                    $response = Http::timeout(5)->get(self::FIREBASE_CERTIFICATES_URL);
                    $response->throw();
                    $certificates = $response->json();

                    if (! is_array($certificates)) {
                        throw new RuntimeException('Firebase returned an invalid token certificate response.');
                    }

                    return $certificates;
                }
            );
        } catch (ConnectionException $exception) {
            report($exception);

            throw new RuntimeException('Firebase token verification is temporarily unavailable.', 0, $exception);
        } catch (RequestException $exception) {
            report($exception);

            throw new RuntimeException('Firebase token verification is temporarily unavailable.', 0, $exception);
        }

        try {
            $segments = explode('.', $token);
            if (count($segments) !== 3) {
                return null;
            }
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true, 512, JSON_THROW_ON_ERROR);
            $keyId = $header['kid'] ?? null;
            if (! is_string($keyId) || ! isset($certificates[$keyId]) || ! is_string($certificates[$keyId])) {
                return null;
            }
            $claims = JWT::decode($token, new Key($certificates[$keyId], 'RS256'));
        } catch (Throwable) {
            return null;
        }

        $now = now()->timestamp;
        $issuer = 'https://securetoken.google.com/'.$projectId;

        if (($claims->aud ?? null) !== $projectId
            || ($claims->iss ?? null) !== $issuer
            || ! is_string($claims->sub ?? null)
            || $claims->sub === ''
            || strlen($claims->sub) > 128
            || ! isset($claims->iat, $claims->exp)
            || $claims->iat > $now
            || $claims->exp <= $now
            || $claims->exp - $claims->iat > 3600) {
            return null;
        }

        return $claims;
    }

    private function verifyOwnToken(string $token): ?object
    {
        $secret = (string) config('services.nearby_teacher.jwt_secret');

        if ($secret === '') {
            return null;
        }

        try {
            $claims = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (Throwable) {
            return null;
        }

        if (! in_array($claims->iss ?? null, ['nearby-teacher-otp', 'nearby-teacher-auth'], true)
            || ! is_string($claims->sub ?? null)
            || ! Str::isUuid($claims->sub)) {
            return null;
        }

        return $claims;
    }

    private function firebaseSessionAllowed(object $user, object $claims): bool
    {
        return ($user->auth_tokens_invalid_before ?? null) === null
            || (int) ($claims->auth_time ?? $claims->iat ?? 0) > strtotime($user->auth_tokens_invalid_before);
    }

    public function resolveGoogleUser(object $claims): ?object
    {
        $googleId = $claims->sub ?? null;
        $email = strtolower(trim((string) ($claims->email ?? '')));
        if (! is_string($googleId) || $googleId === '' || $email === '') {
            return null;
        }

        $user = DB::table('users')->where('google_id', $googleId)->first();
        if ($user !== null) {
            if (! $this->firebaseSessionAllowed($user, $claims)) {
                return null;
            }
            $emailOwner = DB::table('users')->where('email', $email)->where('id', '!=', $user->id)->exists();
            if ($emailOwner) {
                return null;
            }
            DB::table('users')->where('id', $user->id)->update([
                'email' => $email,
                'email_verified_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('users')->where('id', $user->id)->first();
        }

        $user = DB::table('users')->where('email', $email)->first();
        if ($user !== null) {
            if (! $this->firebaseSessionAllowed($user, $claims)) {
                return null;
            }
            if ($user->google_id !== null && $user->google_id !== $googleId) {
                return null;
            }
            DB::table('users')->where('id', $user->id)->update([
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('users')->where('id', $user->id)->first();
        }

        $id = (string) Str::uuid();
        $name = $claims->name ?? null;
        DB::table('users')->insert([
            'id' => $id,
            'account_type' => 'learner',
            'full_name' => is_string($name) && trim($name) !== '' ? trim($name) : 'New user',
            'email' => $email,
            'google_id' => $googleId,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('users')->where('id', $id)->first();
    }

    private function serviceAccount(): array
    {
        $json = config('services.nearby_teacher.firebase_service_account_json');

        if (! is_string($json) || $json === '') {
            return [];
        }

        try {
            $account = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('FIREBASE_SERVICE_ACCOUNT_JSON is not valid JSON.', 0, $exception);
        }

        if (! is_array($account)) {
            throw new RuntimeException('FIREBASE_SERVICE_ACCOUNT_JSON must contain a JSON object.');
        }

        return $account;
    }

    private function resolveFirebaseUserId(object $claims): ?string
    {
        $firebase = $claims->firebase ?? null;
        $provider = is_object($firebase) ? ($firebase->sign_in_provider ?? null) : null;
        $googleId = $provider === 'google.com' ? ($claims->sub ?? null) : null;
        $phone = $claims->phone_number ?? null;
        $email = $claims->email ?? null;
        $phoneVerifiedAt = $provider === 'phone' && is_string($phone) && $phone !== '' ? now() : null;

        if (! is_string($googleId) && ! is_string($phone) && ! is_string($email)) {
            return null;
        }

        $user = DB::table('users')
            ->where(function ($query) use ($googleId, $phone, $email): void {
                if (is_string($googleId)) {
                    $query->orWhere('google_id', $googleId);
                }
                if (is_string($phone) && $phone !== '') {
                    $query->orWhere('phone', $phone);
                }
                if (is_string($email) && $email !== '') {
                    $query->orWhere('email', $email);
                }
            })
            ->first();

        if ($user !== null) {
            if (! $this->firebaseSessionAllowed($user, $claims)) {
                return null;
            }
            if ($phoneVerifiedAt !== null && $user->phone_verified_at === null) {
                DB::table('users')->where('id', $user->id)->update([
                    'phone_verified_at' => $phoneVerifiedAt,
                    'updated_at' => now(),
                ]);
            }

            return $user->id;
        }

        $userId = (string) Str::uuid();
        $name = $claims->name ?? 'New user';
        $now = now();

        DB::table('users')->insert([
            'id' => $userId,
            'account_type' => 'learner',
            'full_name' => is_string($name) && $name !== '' ? $name : 'New user',
            'email' => is_string($email) && $email !== '' ? $email : null,
            'phone' => is_string($phone) && $phone !== '' ? $phone : null,
            'phone_verified_at' => $phoneVerifiedAt,
            'google_id' => is_string($googleId) ? $googleId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }
}
