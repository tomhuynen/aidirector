<?php

declare(strict_types=1);

use App\Jobs\GenerateKeyframeOption;
use App\Support\Images\OpenRouterImageClient;

arch()->preset()->php();
arch()->preset()->security();

it('fails a job that runs out of time at once, so what waits for it never hangs', function () {
    $lingering = collect(glob(app_path('Jobs/*.php')))
        ->map(fn(string $file) => 'App\\Jobs\\' . basename($file, '.php'))
        ->filter(fn(string $job) => property_exists($job, 'timeout'))
        ->reject(fn(string $job) => (new ReflectionClass($job))->getProperty('failOnTimeout')->getDefaultValue() === true)
        ->values()
        ->all();

    expect($lingering)->toBe([]);
});

it('gives a keyframe option the time to time out its slowest call before the job runs out', function () {
    expect((new ReflectionClass(GenerateKeyframeOption::class))->getProperty('timeout')->getDefaultValue())
        ->toBeGreaterThan(OpenRouterImageClient::TIMEOUT + 60);
});
