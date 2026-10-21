<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TrackAuthActivity
{
    private const EVENTS = [
        'auth/login' => 'email_login',
        'auth/google' => 'google_login',
        'auth/otp/verify' => 'otp_verification',
        'auth/register' => 'registration_completed',
        'auth/onboarding/complete' => 'registration_completed',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $event = self::EVENTS[$request->route()?->uri() ?? ''] ?? null;
        if (! $request->isMethod('POST') || $event === null || ! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);
        $successful = $response->isSuccessful();
        $userId = $successful ? data_get($data, 'user.id') : null;
        $email = $request->input('email');
        try {
            DB::table('auth_activity')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => is_string($userId) && Str::isUuid($userId) ? $userId : null,
                'identifier' => is_string($email) ? Str::limit(strtolower(trim($email)), 255, '') : null,
                'event' => $event,
                'successful' => $successful,
                'status_code' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit($request->userAgent() ?? '', 1000, ''),
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            // A reporting outage must not turn a successful login into a failed request.
            Log::warning('Authentication activity could not be recorded. Check reporting migrations and database availability.');
        }

        return $response;
    }
}
