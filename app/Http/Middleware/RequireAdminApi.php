<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('authenticated_user');

        if ($user === null || ! $user->is_admin) {
            return new JsonResponse(['error' => 'Admin access required'], 403);
        }

        return $next($request);
    }
}
