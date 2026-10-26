<?php

namespace App\Providers;

use App\Broadcasting\SocketIoBroadcaster;
use App\Services\Presence\PresenceStore;
use App\Services\Presence\RedisPresenceStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PresenceStore::class, RedisPresenceStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Broadcast::extend('socketio', fn ($app, array $config) => new SocketIoBroadcaster($config));
        RateLimiter::for('presence', fn (Request $request) => Limit::perMinute(120)
            ->by('presence:'.($request->attributes->get('authenticated_user')?->id ?? $request->ip())));
        // The Socket.IO bridge shares an IP across clients; limit each bearer separately.
        RateLimiter::for('socket-auth', fn (Request $request) => Limit::perMinute(180)
            ->by(hash('sha256', (string) $request->bearerToken())));
        RateLimiter::for('chat-send', fn (Request $request) => Limit::perMinute(120)
            ->by(hash('sha256', (string) $request->bearerToken())));
        RateLimiter::for('email-code', fn (Request $request) => [
            Limit::perMinute(20)->by('email-code-ip:'.$request->ip()),
            Limit::perMinutes(10, 3)->by($request->path().':'.hash('sha256',
                $request->is('auth/forgot-password')
                    ? strtolower(trim(is_string($request->input('email')) ? $request->input('email') : ''))
                    : (string) $request->bearerToken()
            )),
        ]);
    }
}
