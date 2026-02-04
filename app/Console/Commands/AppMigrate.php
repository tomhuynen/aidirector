<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class AppMigrate extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate {--force : Force the operation to run when in production} {--fresh : Drop all tables and re-run all migrations} {--seed : Seed the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run migrations for landlord and tenants.';

    protected $migrateAction = 'migrate';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return Command::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->migrateAction = 'migrate:fresh';
            $this->removeTenants();
        }

        $this->migrateCache();
        $this->migrateSessions();

        $this->migrateLandlord();
        $this->migrateTenants();
    }

    private function removeTenants()
    {
        if (! Schema::connection('landlord')->hasTable('tenants')) {
            return;
        }

        Tenant::all()->eachCurrent(function (Tenant $tenant) {
            Schema::dropDatabaseIfExists($tenant->getDatabaseName());
            // TODO: Delete associated storage
        });
    }

    private function migrateLandlord()
    {
        $this->info('Migrate landlord');
        Artisan::call($this->migrateAction, [
            '--path' => 'database/migrations/landlord',
            '--database' => 'landlord',
            '--force' => true,
            '--seed' => $this->option('seed') ? true : false,
        ], $this->output);
    }

    private function migrateTenants()
    {
        if (Tenant::count() === 0) {
            return;
        }

        $this->info('Migrate tenants');

        Tenant::all()->eachCurrent(function (Tenant $tenant) {
            $tenantMigrateCommand = trim(vsprintf('%s --database=tenant --force --path=%s %s', [
                $this->migrateAction,
                'database/migrations/tenant',
                $this->option('seed') ? '--seed' : '',
            ]));

            $command = vsprintf('tenants:artisan "%s" --tenant=%d', [
                $tenantMigrateCommand,
                $tenant->id,
            ]);

            Artisan::call($command, [], $this->output);
        });
    }

    private function migrateSessions()
    {
        $path = Config::get('database.connections.sessions.database');

        if (! file_exists($path)) {
            touch($path);
        }

        $this->info('Migrate sessions');
        Artisan::call('migrate', [
            '--path' => 'database/migrations/sessions',
            '--database' => 'sessions',
        ]);
    }

    private function migrateCache()
    {
        $path = Config::get('database.connections.cache.database');

        if (! file_exists($path)) {
            touch($path);
        }

        $this->info('Migrate cache');
        Artisan::call('migrate', [
            '--path' => 'database/migrations/cache',
            '--database' => 'cache',
        ]);
    }
}
