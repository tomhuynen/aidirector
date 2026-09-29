<?php

declare(strict_types=1);

use App\Support\EnvFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->basePath = sys_get_temp_dir() . '/core-init-' . uniqid();

    File::makeDirectory($this->basePath);
    File::put($this->basePath . '/.env.example', implode(PHP_EOL, [
        'APP_NAME=Blueprint',
        'APP_TITLE="Blueprint"',
        'APP_KEY=',
        'APP_URL=http://blueprint.test',
        'DB_DATABASE=blueprint',
    ]) . PHP_EOL);

    $this->app->useEnvironmentPath($this->basePath);

    // A fresh clone has no APP_KEY yet, which key:generate relies on to find the line to replace.
    Config::set('app.key', '');
    $this->app->bind(EnvFile::class, fn() => new EnvFile(
        path: $this->basePath . '/.env',
        examplePath: $this->basePath . '/.env.example',
    ));
});

afterEach(function () {
    File::deleteDirectory($this->basePath);
});

it('creates the env file from the example with the given answers and generates a key', function () {
    Process::fake();

    $this->artisan('app:core-init', ['--force' => true])
        ->expectsQuestion('Application name', 'My Project')
        ->expectsQuestion('Application title', 'My Project Admin')
        ->expectsQuestion('Application URL', 'https://my-project.test')
        ->expectsQuestion('Landlord database name', 'my_project')
        ->expectsOutputToContain('.env file created.')
        ->assertSuccessful();

    $contents = File::get($this->basePath . '/.env');

    expect($contents)
        ->toContain('APP_NAME="My Project"')
        ->toContain('APP_TITLE="My Project Admin"')
        ->toContain('APP_URL=https://my-project.test')
        ->toContain('DB_DATABASE=my_project')
        ->toMatch('/^APP_KEY=base64:.+$/m');
});

it('skips the env file when it already exists', function () {
    Process::fake();
    File::put($this->basePath . '/.env', 'APP_NAME=Existing' . PHP_EOL);

    $this->artisan('app:core-init', ['--force' => true])
        ->expectsOutputToContain('Git remotes and .env file already configured.')
        ->assertSuccessful();

    expect(File::get($this->basePath . '/.env'))->toBe('APP_NAME=Existing' . PHP_EOL);
});

it('configures the git remotes when the blueprint remote does not exist yet', function () {
    Process::fake([
        '*remote*show*blueprint*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('app:core-init', ['--force' => true])
        ->expectsQuestion('New project repository URL', 'git@github.com:vagebnd/my-project.git')
        ->expectsQuestion('Application name', 'My Project')
        ->expectsQuestion('Application title', 'My Project')
        ->expectsQuestion('Application URL', 'https://my-project.test')
        ->expectsQuestion('Landlord database name', 'my_project')
        ->assertSuccessful();

    Process::assertRan(['git', 'remote', 'set-url', '--push', 'origin', 'no-pushing']);
    Process::assertRan(['git', 'remote', 'rename', 'origin', 'blueprint']);
    Process::assertRan(['git', 'remote', 'add', 'origin', 'git@github.com:vagebnd/my-project.git']);
});

it('does nothing when the confirmation is declined', function () {
    Process::fake([
        '*remote*show*blueprint*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('app:core-init')
        ->expectsConfirmation('Are you sure you want to continue?', 'no')
        ->expectsOutputToContain('Configuration cancelled.')
        ->assertSuccessful();

    expect(File::exists($this->basePath . '/.env'))->toBeFalse();
    Process::assertNotRan(['git', 'remote', 'rename', 'origin', 'blueprint']);
});
