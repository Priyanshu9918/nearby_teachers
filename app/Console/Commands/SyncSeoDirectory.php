<?php

namespace App\Console\Commands;

use App\Services\SeoDirectory;
use Illuminate\Console\Command;

class SyncSeoDirectory extends Command
{
    protected $signature = 'seo:sync';

    protected $description = 'Register current teacher and institute URLs and their eligible directory pages';

    public function handle(SeoDirectory $directory): int
    {
        $count = $directory->sync();
        $this->info('Processed '.$count.' teacher and institute profiles. Only eligible profiles with supported countries are published.');

        return self::SUCCESS;
    }
}
