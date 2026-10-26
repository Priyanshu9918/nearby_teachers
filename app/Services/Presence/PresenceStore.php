<?php

namespace App\Services\Presence;

interface PresenceStore
{
    /** @return array{0: int, 1: int} Result code and aggregate active flag. */
    public function update(int $userId, int $authVersion, string $deviceId, string $event, int $now): array;

    /** @param array<int, int> $versions User ID => current authentication version.
     * @return array<int, bool>
     */
    public function activeFor(array $versions, int $now): array;
}
