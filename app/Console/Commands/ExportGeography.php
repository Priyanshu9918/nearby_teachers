<?php

namespace App\Console\Commands;

use App\Services\GeographyTransfer;
use Illuminate\Console\Command;

class ExportGeography extends Command
{
    protected $signature = 'geography:export-cities {path : Destination .jsonl.gz file}';

    protected $description = 'Export only cities as compressed JSON Lines with portable country/state codes';

    public function handle(GeographyTransfer $transfer): int
    {
        $counts = $transfer->export($this->argument('path'));
        $this->info(json_encode($counts, JSON_THROW_ON_ERROR));
        $this->line('SHA256: '.hash_file('sha256', $this->argument('path')));

        return self::SUCCESS;
    }
}
