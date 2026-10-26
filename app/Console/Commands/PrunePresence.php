<?php

namespace App\Console\Commands;

use App\Services\Presence\DatabasePresenceStore;
use Illuminate\Console\Command;

final class PrunePresence extends Command
{
    protected $signature = 'presence:prune {--batch=1000} {--batches=10}';

    protected $description = 'Delete expired transient presence devices in bounded indexed batches';

    public function handle(DatabasePresenceStore $store): int
    {
        foreach (['batch' => 5000, 'batches' => 100] as $option => $maximum) {
            $value = (string) $this->option($option);
            if (! ctype_digit($value) || (int) $value < 1 || (int) $value > $maximum) {
                $this->components->error("--{$option} must be between 1 and {$maximum}.");

                return self::INVALID;
            }
        }
        $deleted = $store->prune(now()->timestamp, (int) $this->option('batch'), (int) $this->option('batches'));
        $this->components->info("Removed {$deleted} expired presence devices.");

        return self::SUCCESS;
    }
}
