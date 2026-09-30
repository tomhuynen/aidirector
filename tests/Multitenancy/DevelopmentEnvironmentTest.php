<?php

declare(strict_types=1);

use App\Listeners\InitializeDevelopmentEnvironment;
use App\Models\Director;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;

function runDevelopmentEnvironmentListener(): void
{
    Process::fake([
        'git config --get user.email' => Process::result('dev@example.com'),
        'git config --get user.name' => Process::result('Dev User'),
    ]);

    app(InitializeDevelopmentEnvironment::class)->handle(new MigrationsEnded('up'));
}

test('a fresh local environment gets a root tenant, a user and a director', function () {
    $this->app['env'] = 'local';

    runDevelopmentEnvironmentListener();

    $tenant = Tenant::where('name', 'Root')->first();
    $user = User::where('email', 'dev@example.com')->first();

    expect($tenant)->not->toBeNull()
        ->and($tenant->domain)->toBe(parse_url(config('app.url'), PHP_URL_HOST))
        ->and($user)->not->toBeNull()
        ->and($user->tenant_id)->toBe($tenant->id);

    $director = $tenant->execute(fn() => Director::where('email', 'dev@example.com')->first());

    expect($director)->not->toBeNull()
        ->and($director->name)->toBe('Dev User')
        ->and($director->email_verified_at)->not->toBeNull()
        ->and(Hash::check('aabbccdd', $director->password))->toBeTrue();
});

test('running the listener twice does not duplicate the director', function () {
    $this->app['env'] = 'local';

    runDevelopmentEnvironmentListener();
    runDevelopmentEnvironmentListener();

    $tenant = Tenant::where('name', 'Root')->first();

    expect($tenant->execute(fn() => Director::where('email', 'dev@example.com')->count()))->toBe(1);
});

test('nothing is created outside the local environment', function () {
    runDevelopmentEnvironmentListener();

    expect(Tenant::where('name', 'Root')->exists())->toBeFalse()
        ->and(User::where('email', 'dev@example.com')->exists())->toBeFalse();
});
