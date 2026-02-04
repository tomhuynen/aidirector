<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Tenant::all()->eachCurrent(function (Tenant $tenant) {
            $users = User::factory()
                ->count(10)
                ->create([
                    'tenant_id' => $tenant->id,
                ]);

            $users->each(function (User $user) {
                $user->assignRole('user');
            });
        });
    }
}
