<?php

declare(strict_types=1);

namespace App\Support\Admin\Server;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

class Php implements Arrayable
{
    private function getPHPVersion()
    {
        return phpversion() . ' (' . php_sapi_name() . ')';
    }

    private function getPhpReleaseInfo()
    {
        $url = vsprintf('https://phpreleases.com/api/releases/%s', [
            phpversion(),
        ]);

        try {
            $response = Http::asJson()->get($url);
        } catch (ConnectionException) {
            return;
        }

        if (! $response->successful()) {
            return;
        }

        $json = $response->json();
        $data = Arr::get($json, 'provided', []);

        if (! array_key_exists('active_support_until', $data)) {
            return;
        }

        $activeSupportUntil = Date::parse($data['active_support_until']);
        $securitySupportUntil = Date::parse($data['security_support_until']);

        return (object) [
            'activeUntil' => $activeSupportUntil,
            'securityUntil' => $securitySupportUntil,
            'patchAvailable' => $data['needs_patch'],
        ];
    }

    private function getOPcacheDetails()
    {
        if (! function_exists('opcache_get_status')) {
            return false;
        }

        $status = opcache_get_status(false);
        if ($status === false) {
            return $status;
        }

        return [
            'enabled' => true,
            'usedMemory' => Arr::get($status, 'memory_usage.used_memory'),
        ];
    }

    private function getMaxUploadSize(): int
    {
        $post = $this->getBytes(ini_get('post_max_size'));
        $upload = $this->getBytes(ini_get('upload_max_filesize'));

        return min($post, $upload);
    }

    private function getMemoryLimit()
    {
        return $this->getBytes(ini_get('memory_limit'));
    }

    private function getMaxExecutionTime()
    {
        return (int) ini_get('max_execution_time');
    }

    private function getBytes($str)
    {
        $val = trim((string) $str);
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int) $val;

        switch ($last) {
            case 'g':
                $val *= 1024;
                // no break
            case 'm':
                $val *= 1024;
                // no break
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    public function toArray(): array
    {
        $ttl = Date::tomorrow()->endOfDay();

        return Cache::remember(__METHOD__, $ttl, fn() => [
            'version' => $this->getPHPVersion(),
            /** @var array{activeUntil: string, securityUntil: string, patchAvailable: bool} */
            'releaseInfo' => $this->getPhpReleaseInfo(),
            /** @var array{enabled: bool, usedMemory: int} */
            'opcache' => $this->getOPcacheDetails(),
            'maxUpload' => $this->getMaxUploadSize(),
            'maxExecution' => $this->getMaxExecutionTime(),
            'memoryLimit' => $this->getMemoryLimit(),
        ]);
    }
}
