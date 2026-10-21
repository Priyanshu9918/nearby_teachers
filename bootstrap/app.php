<?php

use App\Http\Middleware\RequireApiAuthentication;
use App\Http\Middleware\TrackAuthActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['api', RequireApiAuthentication::class]]
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [TrackAuthActivity::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
