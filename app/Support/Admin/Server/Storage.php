<?php

declare(strict_types=1);

namespace App\Support\Admin\Server;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

class Storage implements Arrayable
{
    private function getAppStorageStats()
    {
        $ttl = Date::parse('+30 minutes');

        return Cache::remember(__METHOD__, $ttl, fn() => collect([
            $this->getDirectoryStats(storage_path('framework/views'), 'view cache'),
            $this->getDatabaseSize('sessions', 'sessions'),
            $this->getDatabaseSize('cache', 'cache'),
            $this->getDirectoryStats(storage_path('logs'), 'logs'),
            $this->getDirectoryStats(storage_path('tmp'), 'temporary files'),
        ])->filter()->values());
    }

    private function getDatabaseSize(string $dbName, string $name)
    {
        $config = config('database.connections.' . $dbName);
        if (empty($config) || $config['driver'] !== 'sqlite') {
            return;
        }

        $path = $config['database'];
        if (! file_exists($path)) {
            return;
        }

        return (object) [
            'name' => $name,
            'size' => filesize($path),
        ];
    }

    private function getDirectoryStats(string $path, string $name)
    {
        if (! file_exists($path)) {
            return;
        }

        $files = File::files($path, false);
        $size = collect($files)->sum(fn($v) => $v->getSize());

        return (object) [
            'name' => $name,
            'size' => $size,
            'fileCount' => count($files),
        ];
    }

    public function toArray()
    {
        return [
            'appStorage' => $this->getAppStorageStats(),
        ];
    }
}
