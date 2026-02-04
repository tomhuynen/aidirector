<?php

declare(strict_types=1);

namespace App\Support\Login;

use DeviceDetector\DeviceDetector;
use Illuminate\Support\Facades\Cache;

class UserAgent
{
    private function __construct() {}

    public static function getDetails(string $userAgent)
    {
        $key = 'useragent:details:' . hash('xxh3', $userAgent);
        $details = Cache::rememberForever($key, fn() => self::parseUserAgent($userAgent));

        return new UserAgentDetails($details);
    }

    private static function parseUserAgent(string $userAgent)
    {
        $parser = new DeviceDetector($userAgent);
        $parser->parse();

        $client = $parser->getClient() ?? [];
        $os = $parser->getOs() ?? [];

        return array_filter([
            'value' => $userAgent,
            'isBot' => $parser->isBot(),
            'clientFamily' => $client['family'] ?? $client['name'] ?? null,
            'clientVersion' => $client['version'] ?? null,
            'osName' => $os['name'] ?? null,
            'osVersion' => $os['version'] ?? null,
            'deviceType' => $parser->getDevice(),
            'deviceName' => $parser->getDeviceName(),
        ]);
    }
}
