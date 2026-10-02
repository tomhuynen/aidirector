<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('keeps the redis retry window longer than every AI job may run', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    $timeouts = collect(File::files(app_path('Jobs')))
        ->map(fn(SplFileInfo $file) => 'App\\Jobs\\' . $file->getBasename('.php'))
        ->filter(fn(string $class) => property_exists($class, 'timeout'))
        ->mapWithKeys(fn(string $class) => [$class => (new ReflectionClass($class))->getProperty('timeout')->getDefaultValue()])
        ->filter();

    expect($timeouts)->not->toBeEmpty();

    foreach ($timeouts as $class => $timeout) {
        expect($retryAfter)->toBeGreaterThan($timeout, "{$class} may run {$timeout}s, longer than the {$retryAfter}s retry window.");
    }
});
