<?php

namespace App\Services;

use App\Reporting\BusinessActivityRecorder;
use App\Support\RecordId;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\UniqueConstraintViolationException;
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
        unset($user->password_hash, $user->parental_consent_token);

        return $user;
    }

    public function issueOtpToken(int $userId, int $authVersion = 0): string
    {
        $secret = (string) config('services.nearby_teacher.jwt_secret');

        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET must be configured before issuing login tokens.');
        }

        return JWT::encode([
            'sub' => (string) $userId,
            'iss' => 'nearby-teacher-otp',
            'iat' => now()->timestamp,
            'exp' => now()->addDays(30)->timestamp,
            'ver' => $authVersion,
        ], $secret, 'HS256');
    }

    public function issueSessionToken(int $userId, int $authVersion = 0): string
    {
        $secret = (string) config('services.nearby_teacher.jwt_secret');
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET must be configured before issuing login tokens.');
        }

        $now = now()->timestamp;

        return JWT::encode([
            'sub' => (string) $userId,
            'iss' => 'nearby-teacher-auth',
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => now()->addDays(30)->timestamp,
            'ver' => $authVersion,
        ], $secret, 'HS256');
    }

    public function verifyFirebaseToken(string $token): ?int
    {
        $claims = $this->verifyFirebaseClaims($token);
        if ($claims === null) {
            return null;
        }

        // Raw Firebase compatibility must use the same verified Google identity rules.
        return $this->resolveGoogleUser($claims)?->id;
    }

    public function firebaseProjectId(): ?string
    {
        $projectId = config('services.nearby_teacher.firebase_project_id');
        if (! is_string($projectId) || trim($projectId) === '') {
            $projectId = $this->serviceAccount()['project_id'] ?? null;
        }

        return is_string($projectId) && trim($projectId) !== '' ? trim($projectId) : null;
    }

    public function firebaseCertificates(): array
    {
        $cached = Cache::get('firebase.securetoken.certificates');
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }
        try {
            $response = Http::timeout(5)->get(self::FIREBASE_CERTIFICATES_URL)->throw();
            $certificates = $response->json();
            if (! is_array($certificates) || $certificates === [] || count(array_filter($certificates, 'is_string')) !== count($certificates)) {
                throw new RuntimeException('Firebase returned invalid verification certificates.');
            }
            preg_match('/max-age=(\d+)/i', $response->header('Cache-Control'), $matches);
            $ttl = isset($matches[1]) ? min((int) $matches[1], 86400) : 300;
            if ($ttl > 0) {
                Cache::put('firebase.securetoken.certificates', $certificates, $ttl);
            }

            return $certificates;
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            throw new RuntimeException('Firebase token verification is temporarily unavailable.', 0, $exception);
        }
    }

    public function verifyFirebaseClaims(string $token): ?object
    {
        try {
            if (strlen($token) > 16384) {
                return null;
            }
            $segments = explode('.', $token);
            if (count($segments) !== 3) {
                return null;
            }
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true, 512, JSON_THROW_ON_ERROR);
            $keyId = $header['kid'] ?? null;
            if (($header['alg'] ?? null) !== 'RS256' || ! is_string($keyId) || $keyId === '' || strlen($keyId) > 256) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }
        $projectId = $this->firebaseProjectId();
        if ($projectId === null) {
            throw new RuntimeException('FIREBASE_PROJECT_ID is not configured.');
        }
        $certificates = $this->firebaseCertificates();
        try {
            if (! isset($certificates[$keyId])) {
                return null;
            }
            $claims = JWT::decode($token, new Key($certificates[$keyId], 'RS256'));
        } catch (Throwable) {
            return null;
        }
        $now = now()->timestamp;
        if (($claims->aud ?? null) !== $projectId
            || ($claims->iss ?? null) !== 'https://securetoken.google.com/'.$projectId
            || ! is_string($claims->sub ?? null) || $claims->sub === '' || strlen($claims->sub) > 128
            || ! is_int($claims->iat ?? null) || ! is_int($claims->exp ?? null) || ! is_int($claims->auth_time ?? null)
            || $claims->iat > $now || $claims->exp <= $now || $claims->exp <= $claims->iat
            || $claims->exp - $claims->iat > 3600 || $claims->auth_time > $now || $claims->auth_time < 0) {
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
            || ! RecordId::valid($claims->sub)) {
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
        if (($claims->firebase->sign_in_provider ?? null) !== 'google.com'
            || ($claims->email_verified ?? false) !== true
            || ! is_string($claims->sub ?? null) || $claims->sub === ''
            || ! is_string($claims->email ?? null)
            || filter_var($claims->email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        try {
            return DB::transaction(fn () => $this->resolveGoogleIdentity($claims), 3);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first sign-in may have created/linked this identity already.
            return DB::transaction(fn () => $this->resolveGoogleIdentity($claims), 3);
        }
    }

    private function resolveGoogleIdentity(object $claims): ?object
    {
        $googleId = $claims->sub;
        $email = strtolower(trim($claims->email));
        $user = DB::table('users')->where('google_id', $googleId)->lockForUpdate()->first();
        if ($user !== null) {
            if ($user->suspended_at !== null) {
                return $user;
            }
            if (! $this->firebaseSessionAllowed($user, $claims)) {
                return null;
            }
            $emailOwner = DB::table('users')->where('email', $email)->where('id', '!=', $user->id)->lockForUpdate()->first();
            if ($emailOwner !== null) {
                return null;
            }
            DB::table('users')->where('id', $user->id)->update([
                'email' => $email, 'email_verified_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('users')->where('id', $user->id)->first();
        }
        $user = DB::table('users')->where('email', $email)->lockForUpdate()->first();
        if ($user !== null) {
            if ($user->suspended_at !== null) {
                return $user;
            }
            if (! $this->firebaseSessionAllowed($user, $claims)) {
                return null;
            }
            if ($user->google_id !== null && $user->google_id !== $googleId) {
                return null;
            }
            DB::table('users')->where('id', $user->id)->update([
                'google_id' => $googleId, 'email_verified_at' => now(), 'updated_at' => now(),
            ]);
            app(BusinessActivityRecorder::class)->record('identity.google_linked', $user->id, $user->id,
                before: ['linked' => false], after: ['linked' => true]);

            return DB::table('users')->where('id', $user->id)->first();
        }
        $name = $claims->name ?? null;
        $id = DB::table('users')->insertGetId(['account_type' => 'learner',
            'full_name' => is_string($name) && trim($name) !== '' ? Str::limit(trim($name), 160, '') : 'New user',
            'email' => $email, 'google_id' => $googleId, 'email_verified_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(BusinessActivityRecorder::class)->record('identity.google_linked', $id, $id,
            before: ['linked' => false], after: ['linked' => true]);

        return DB::table('users')->where('id', $id)->first();
    }

    private function serviceAccount(): array
    {
        $json = config('services.nearby_teacher.firebase_service_account_json');
        $path = config('services.nearby_teacher.firebase_service_account_path');
        if (is_string($path) && trim($path) !== '') {
            $file = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException('Firebase service account file is missing or unreadable.');
            }
            $json = file_get_contents($file);
            if ($json === false) {
                throw new RuntimeException('Firebase service account file could not be read.');
            }
        }

        if (! is_string($json) || $json === '') {
            return [];
        }

        try {
            $account = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Firebase service account is not valid JSON.');
        }

        if (! is_array($account) || array_is_list($account)) {
            throw new RuntimeException('FIREBASE_SERVICE_ACCOUNT_JSON must contain a JSON object.');
        }

        return $account;
    }
}
