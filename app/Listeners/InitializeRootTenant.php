<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Tenant;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class InitializeRootTenant
{
    /**
     * Handle the event.
     */
    public function handle(MigrationsEnded $event): void
    {
        if (App::runningUnitTests()) {
            return;
        }

        if (Tenant::checkCurrent()) {
            return;
        }

        if (! Schema::hasTable('tenants')) {
            return;
        }

        Tenant::firstOrCreate([
            'name' => 'Root',
            'domain' => new Uri(Config::get('app.url'))->getHost(),
        ]);
    }
}
