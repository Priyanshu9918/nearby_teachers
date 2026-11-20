<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class MeasureSearchTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $connection = DB::connection();
        $queryTimeBefore = $connection->totalQueryDuration();

        $response = $next($request);
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $queryTime = $connection->totalQueryDuration() - $queryTimeBefore;
        $response->headers->set('Server-Timing', sprintf('search;dur=%.2F, db;dur=%.2F', $elapsed, $queryTime));

        return $response;
    }
}
