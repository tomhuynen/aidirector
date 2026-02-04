<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class ActivitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::current();

        if (! $tenant) {
            return;
        }

        $users = $tenant->users;

        if (! $users) {
            return;
        }

        $users->each(function (User $user) use ($tenant) {
            Collection::times(
                50,
                fn() => activity()
                    ->performedOn($tenant)
                    ->causedBy($user)
                    ->event('seed')
                    ->withProperties([
                        'tenant' => $tenant->name,
                        'user' => [
                            'name' => $user->name,
                            'email' => $user->email,
                        ],
                    ])
                    ->log(fake()->sentence())
            );
        });
    }
}
