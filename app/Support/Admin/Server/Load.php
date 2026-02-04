<?php

declare(strict_types=1);

namespace App\Support\Admin\Server;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

class Load implements Arrayable
{
    private function getCpuCount()
    {
        $command = new ExecutableFinder()->find($this->isDarwin() ? 'sysctl' : 'nproc');

        if ($this->isDarwin()) {
            $command .= ' -n hw.ncpu';
        }

        $result = Process::run($command)->throw();

        return (int) trim($result->output());
    }

    private function getResults()
    {
        $path = '/var/log/loadavg-history.log';

        if (! file_exists($path) || ! is_readable($path)) {
            return [];
        }

        return collect(file($path))
            ->slice(-1440) // max 1 day of results
            ->map(function ($line) {
                $parts = explode(',', trim($line));

                return (object) [
                    'timestamp' => $parts[0],
                    'values' => [
                        (float) $parts[1],
                        (float) $parts[2],
                        (float) $parts[3],
                    ],
                ];
            })
            ->values();
    }

    private function isDarwin()
    {
        return PHP_OS === 'Darwin';
    }

    public function toArray()
    {
        return [
            'cpuCount' => $this->getCpuCount(),
            'results' => $this->getResults(),
        ];
    }
}
