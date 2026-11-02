<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class FirebaseMessagingClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function assertConfigured(): void
    {
        if (! (bool) config('services.nearby_teacher.firebase_messaging_enabled')) {
            throw new RuntimeException('Firebase Cloud Messaging is disabled.');
        }
        $account = $this->serviceAccount();
        if ($this->projectId($account) === null
            || ! is_string($account['client_email'] ?? null)
            || trim((string) ($account['client_email'] ?? '')) === ''
            || ! is_string($account['private_key'] ?? null)
            || trim((string) ($account['private_key'] ?? '')) === '') {
            throw new RuntimeException('Firebase Cloud Messaging credentials are incomplete.');
        }
    }

    /** @param array<string, array{fid: string, device: object}> $recipients */
    public function sendBatch(array $recipients, object $campaign): array
    {
        if ($recipients === []) {
            return [];
        }
        $this->assertConfigured();
        $projectId = $this->projectId($this->serviceAccount());
        if ($projectId === null) {
            throw new RuntimeException('Firebase Cloud Messaging project is not configured.');
        }
        $accessToken = $this->accessToken();
        $campaignData = is_string($campaign->data ?? null) ? json_decode($campaign->data, true) : null;
        $payload = [
            'message' => [
                'notification' => ['title' => $campaign->title, 'body' => $campaign->body],
                'data' => ['campaign_id' => (string) $campaign->id] + (is_array($campaignData) ? $campaignData : []),
            ],
        ];
        $endpoint = 'https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send';
        try {
            $responses = Http::pool(function (Pool $pool) use ($recipients, $accessToken, $endpoint, $payload): array {
                $requests = [];
                foreach ($recipients as $deviceId => $recipient) {
                    $message = $payload;
                    // FCM's HTTP v1 field remains named `token`; it accepts current FIDs too.
                    $message['message']['token'] = $recipient['fid'];
                    $requests[$deviceId] = $pool->as($deviceId)->withToken($accessToken)
                        ->acceptJson()->timeout(15)->post($endpoint, $message);
                }

                return $requests;
            });
        } catch (ConnectionException) {
            // A timed-out send might have reached FCM. Do not blindly retry and duplicate it.
            return array_fill_keys(array_keys($recipients), ['status' => 'failed', 'error_code' => 'FCM_RESULT_UNKNOWN']);
        }

        $results = [];
        foreach ($recipients as $deviceId => $_recipient) {
            $response = $responses[$deviceId] ?? null;
            if ($response instanceof Throwable) {
                $results[$deviceId] = ['status' => 'failed', 'error_code' => 'FCM_CONNECTION_ERROR'];
            } elseif ($response instanceof Response && $response->successful()) {
                $results[$deviceId] = ['status' => 'accepted', 'message_id' => $response->json('name')];
            } else {
                $httpStatus = $response instanceof Response ? $response->status() : 0;
                $providerCode = $response instanceof Response ? $this->providerErrorCode($response) : null;
                $results[$deviceId] = [
                    'status' => 'failed',
                    'error_code' => $providerCode ?? ($httpStatus > 0 ? 'FCM_HTTP_'.$httpStatus : 'FCM_CONNECTION_ERROR'),
                    'invalid_fid' => $httpStatus === 404 && $providerCode === 'UNREGISTERED',
                ];
            }
        }

        return $results;
    }

    private function accessToken(): string
    {
        $account = $this->serviceAccount();
        $this->assertConfigured();
        $cacheKey = 'firebase.messaging.oauth_token.'.hash('sha256', $this->projectId($account).'|'.$account['client_email']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try {
                return Crypt::decryptString($cached);
            } catch (Throwable) {
                Cache::forget($cacheKey);
            }
        }
        $now = now()->timestamp;
        try {
            $assertion = JWT::encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $account['private_key'], 'RS256');
            $response = Http::asForm()->acceptJson()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('Firebase OAuth token request was rejected.');
            }
            $token = $response->json('access_token');
            $ttl = max(60, min(3500, (int) $response->json('expires_in', 3600) - 120));
            Cache::put($cacheKey, Crypt::encryptString($token), $ttl);

            return $token;
        } catch (Throwable $exception) {
            // Do not include the JWT assertion, access token or service-account data in logs/errors.
            throw new RuntimeException('Firebase Cloud Messaging authentication failed.');
        }
    }

    private function serviceAccount(): array
    {
        $json = config('services.nearby_teacher.firebase_service_account_json');
        $path = config('services.nearby_teacher.firebase_service_account_path');
        if (is_string($path) && trim($path) !== '') {
            $file = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException('Firebase service-account file is unavailable.');
            }
            $json = file_get_contents($file);
        }
        if (! is_string($json) || trim($json) === '') {
            throw new RuntimeException('Firebase service-account credentials are not configured.');
        }
        try {
            $account = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Firebase service-account credentials are invalid.');
        }
        if (! is_array($account) || array_is_list($account)) {
            throw new RuntimeException('Firebase service-account credentials are invalid.');
        }

        return $account;
    }

    private function projectId(array $account): ?string
    {
        $projectId = config('services.nearby_teacher.firebase_project_id') ?: ($account['project_id'] ?? null);

        return is_string($projectId) && trim($projectId) !== '' ? trim($projectId) : null;
    }

    private function providerErrorCode(Response $response): ?string
    {
        $error = $response->json('error');
        if (! is_array($error)) {
            return null;
        }
        $code = $error['status'] ?? null;
        foreach (($error['details'] ?? []) as $detail) {
            if (is_array($detail) && str_ends_with((string) ($detail['@type'] ?? ''), 'FcmError')) {
                $code = $detail['errorCode'] ?? $code;
                break;
            }
        }
        if (! is_string($code)) {
            return null;
        }

        return preg_match('/^[A-Z0-9_]{1,100}$/D', $code) ? $code : null;
    }
}
