<?php

declare(strict_types=1);

namespace App\Console\Commands\Wayfinder;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ClearCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wayfinder:clear-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the Wayfinder route cache';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cachePath = storage_path('wayfinder-cache');

        if (! File::exists($cachePath)) {
            $this->components->info('Wayfinder cache does not exist.');

            return self::SUCCESS;
        }

        File::deleteDirectory($cachePath);

        $this->components->info('Wayfinder cache cleared successfully.');

        return self::SUCCESS;
    }
}
