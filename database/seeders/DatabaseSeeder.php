<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Database\Seeders\Landlord\UserSeeder;
use Database\Seeders\Tenant\ActivitySeeder;
use Database\Seeders\Tenant\DamenProjectSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Tenant::checkCurrent()
            ? $this->runTenantSeeders()
            : $this->runLandlordSeeders();
    }

    private function runTenantSeeders()
    {
        $this->call([
            ActivitySeeder::class,
            DamenProjectSeeder::class,
        ]);
    }

    private function runLandlordSeeders()
    {
        $this->call([
            UserSeeder::class,
        ]);
    }
}
