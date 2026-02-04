<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Tenant;
use App\Models\User;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

class InitializeDevelopmentEnvironment
{
    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(MigrationsEnded $event)
    {
        if (! App::isLocal()) {
            return;
        }

        if (! Schema::hasTable('tenants')) {
            return;
        }

        if (Tenant::checkCurrent()) {
            return;
        }

        $tenant = Tenant::firstOrCreate([
            'name' => 'Root',
            'domain' => new Uri(Config::get('app.url'))->getHost(),
        ]);

        $user = User::firstOrCreate([
            'email' => $this->getGitConfig('user.email'),
        ], [
            'name' => $this->getGitConfig('user.name'),
            'password' => Hash::make('aabbccdd'),
            'tenant_id' => $tenant->id,
        ]);

        $user->assignRole('admin', 'tech-admin', 'user');
    }

    private function getGitConfig(string $key)
    {
        $value = Process::run(sprintf('git config --get %s', $key))->throw()->output();

        return trim($value);
    }
}
