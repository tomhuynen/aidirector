<?php

declare(strict_types=1);

namespace App\Support\Admin\Server;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Spatie\Multitenancy\Landlord;

class Health implements Arrayable
{
    private function getLastScheduleRuntime()
    {
        $timestamp = Landlord::execute(fn() => Cache::get('system:scheduler:timestamp'));

        if (empty($timestamp)) {
            return;
        }

        return Date::createFromTimestamp($timestamp);
    }

    public function toArray()
    {
        return [
            'cron' => $this->getLastScheduleRuntime(),
            'healthCheckToken' => config('blauwdruk.healthcheck.token'),
        ];
    }
}
