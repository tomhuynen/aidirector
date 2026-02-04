<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Disk;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\Config\Config;

class AppBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run backups for landlord and all tenants.';

    /**
     * Execute the console command.
     */
    public function handle(Config $config)
    {
        $this->backupLandlord($config);
        $this->backupTenants($config);
    }

    private function backupLandlord(Config $config)
    {
        $config->backup->source->databases = ['landlord'];
        $config->backup->destination->filenamePrefix = 'landlord/';

        Tenant::first()->makeCurrent();

        Artisan::call('backup:run', [], $this->output);
    }

    private function backupTenants(Config $config)
    {
        if (Tenant::count() === 0) {
            return;
        }

        Tenant::all()->eachCurrent(function (Tenant $tenant) use ($config) {
            $config->backup->source->databases = ['tenant'];
            $config->backup->destination->disks = [Disk::TENANT_BACKUP->value];

            Artisan::call('backup:run', [], $this->output);
        });
    }
}
