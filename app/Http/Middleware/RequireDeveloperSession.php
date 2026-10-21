<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireDeveloperSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('developer_authenticated') !== true) {
            return redirect()->route('developer.login');
        }

        return $next($request);
    }
}
