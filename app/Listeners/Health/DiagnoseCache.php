<?php

declare(strict_types=1);

namespace App\Listeners\Health;

use App\Exceptions\HealthException;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DiagnoseCache
{
    public function handle(DiagnosingHealth $event)
    {
        $value = Str::random(32);
        $key = 'healthcheck.cache';

        Cache::put($key, $value, 1);

        if (Cache::get($key) !== $value) {
            throw new HealthException('Cache: invalid result.');
        }
    }
}
