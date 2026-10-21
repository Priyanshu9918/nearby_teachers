<?php

namespace App\Http\Middleware;

use App\Services\AuthenticationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AttachOptionalApiUser
{
    public function __construct(private readonly AuthenticationService $authentication) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token !== null) {
            try {
                $user = $this->authentication->authenticate($token);
                if ($user !== null
                    && $user->suspended_at === null
                    && ($user->is_admin || $user->phone_verified_at !== null)) {
                    $request->attributes->set('authenticated_user', $user);
                }
            } catch (Throwable $exception) {
                Log::warning('Optional API authentication failed; serving request anonymously.', [
                    'exception' => $exception::class,
                ]);
            }
        }

        return $next($request);
    }
}
