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
        // Temporarily disabled: generate SEO URLs only on profile publication/updates.
        // $count = $directory->sync();
        $this->info('SEO sync is temporarily disabled. URLs generate on profile publication or update.');

        return self::SUCCESS;
    }
}
