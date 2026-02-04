<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Disk;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\Config\Config;

class AppBackupClean extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:backup:clean';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove all backups older than specified number of days in config.';

    /**
     * Execute the console command.
     */
    public function handle(Config $config)
    {
        $this->handleLandlord($config);
        $this->handleTenants($config);
    }

    private function handleLandlord(Config $config)
    {
        $config->backup->source->databases = ['landlord'];
        $config->backup->destination->filenamePrefix = 'landlord/';

        Tenant::first()->makeCurrent();

        Artisan::call('backup:clean', [], $this->output);
    }

    private function handleTenants(Config $config)
    {
        if (Tenant::count() === 0) {
            return;
        }

        Tenant::all()->eachCurrent(function (Tenant $tenant) use ($config) {
            $config->backup->source->databases = ['tenant'];
            $config->backup->destination->disks = [Disk::TENANT_BACKUP->value];

            Artisan::call('backup:clean', [], $this->output);
        });
    }
}
