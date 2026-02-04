<?php

declare(strict_types=1);

namespace App\Support\Admin\Server;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Contracts\Support\Arrayable;

class Configuration implements Arrayable
{
    private function checkDebugMode()
    {
        return config('app.debug');
    }

    private function checkSecureCookie()
    {
        return in_array(config('session.secure'), [true, null], true);
    }

    /** @return array{current: string, configured: string, match:bool} */
    private function checkSchemeMatch(): array
    {
        $current = new Uri(request()->url());
        $configured = new Uri(config('app.url'));

        return [
            'current' => $current->getScheme(),
            'configured' => $configured->getScheme(),
            'match' => $current->getScheme() === $configured->getScheme(),
        ];
    }

    private function checkCachedRoutes()
    {
        return file_exists(app()->getCachedRoutesPath());
    }

    private function checkCachedConfig()
    {
        return file_exists(app()->getCachedConfigPath());
    }

    private function checkCachedEvents()
    {
        return file_exists(app()->getCachedEventsPath());
    }

    private function checkPathSetting()
    {
        return array_filter(explode(\PATH_SEPARATOR, (string) getenv('PATH')));
    }

    private function checkComposerDev()
    {
        $path = base_path('vendor/composer/installed.json');
        $installed = json_decode(file_get_contents($path), true);

        return $installed['dev'] !== false;
    }

    private function checkBackupEncryption()
    {
        return ! empty(config('backup.backup.password'));
    }

    public function toArray()
    {
        return [
            'debug' => $this->checkDebugMode(),
            'scheme' => $this->checkSchemeMatch(),
            'secureCookie' => $this->checkSecureCookie(),
            'cachedConfig' => $this->checkCachedConfig(),
            'cachedRoutes' => $this->checkCachedRoutes(),
            'cachedEvents' => $this->checkCachedEvents(),
            'path' => $this->checkPathSetting(),
            'composerDev' => $this->checkComposerDev(),
            'backupEncryption' => $this->checkBackupEncryption(),
        ];
    }
}
