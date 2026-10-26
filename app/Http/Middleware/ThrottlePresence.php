<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePresence
{
    public function handle(Request $request, Closure $next): Response
    {
        // Laravel prioritizes its native throttle before custom JWT middleware.
        // Invoke it here, after route-group authentication, to key by user rather
        // than a shared IP or a single bearer/device.
        abort_unless($request->attributes->get('authenticated_user') !== null, 401);

        return app(ThrottleRequests::class)->handle($request, $next, 'presence');
    }
}
