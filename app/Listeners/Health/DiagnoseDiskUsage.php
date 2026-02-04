<?php

declare(strict_types=1);

namespace App\Listeners\Health;

use App\Exceptions\HealthException;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Process;

class DiagnoseDiskUsage
{
    public function handle(DiagnosingHealth $event)
    {
        $output = Process::run('df -P .')->output();

        preg_match_all('/(\d*)%/', $output, $matches);
        $percentage = (int) current($matches[1]);

        if ($percentage > 90) {
            throw new HealthException('DiskUsage: disk is at ' . $percentage . '% capacity.');
        }
    }
}
