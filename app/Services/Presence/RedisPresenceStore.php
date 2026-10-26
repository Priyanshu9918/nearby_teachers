<?php

namespace App\Services\Presence;

use Illuminate\Contracts\Redis\Factory;

final class RedisPresenceStore implements PresenceStore
{
    public function __construct(private readonly Factory $redis) {}

    public function update(int $userId, int $authVersion, string $deviceId, string $event, int $now): array
    {
        $base = $this->base($userId, $authVersion);

        return $this->redis->connection(config('presence.connection'))->eval(
            file_get_contents(resource_path('lua/presence-update.lua')),
            2, $base.':devices', $base.':active',
            $now, hash('sha256', $deviceId), $event,
            config('presence.idle_seconds'), config('presence.heartbeat_seconds'),
            config('presence.key_ttl_seconds'), config('presence.max_devices'),
        );
    }

    public function activeFor(array $versions, int $now): array
    {
        if ($versions === []) {
            return [];
        }
        $counts = $this->redis->connection(config('presence.connection'))->pipeline(function ($pipe) use ($versions, $now): void {
            foreach ($versions as $userId => $version) {
                $pipe->zcount($this->base($userId, $version).':active', '('.$now, '+inf');
            }
        });
        $flags = [];
        foreach (array_keys($versions) as $index => $userId) {
            $flags[$userId] = (int) $counts[$index] > 0;
        }

        return $flags;
    }

    private function base(int $userId, int $version): string
    {
        // Both Lua keys share a hash slot on Redis Cluster. Device identifiers
        // are hashed and scoped to the authenticated user, never global keys.
        return config('presence.prefix').'{'.$userId.':'.$version.'}';
    }
}
