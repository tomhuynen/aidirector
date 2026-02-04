<?php

declare(strict_types=1);

namespace App\Support\Admin\Server;

use Carbon\CarbonInterval;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Uri;

class OsInternet implements Arrayable
{
    private function getOperatingSystem()
    {
        if ($this->isDarwin()) {
            $command = 'sw_vers -productVersion';
            $prefix = 'MacOS';
        } else {
            $command = 'cat /etc/issue';
            $prefix = '';
        }

        $result = Process::run($command);

        if (! $result->successful()) {
            return PHP_OS;
        }

        $lines = explode(PHP_EOL, $result->output());
        $info = array_shift($lines);
        $info = strtok($info, '\\');

        return trim($prefix . ' ' . $info);
    }

    private function getHostname()
    {
        return trim(Process::run('hostname')->output());
    }

    private function getCertificateDetails()
    {
        $domain = Uri::to('/')->host();

        $sslOptions = [
            'capture_peer_cert' => true,
            'capture_peer_cert_chain' => false,
            'SNI_enabled' => true,
            'peer_name' => $domain,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'follow_location' => 1,
        ];

        $streamContext = stream_context_create([
            'socket' => [],
            'ssl' => $sslOptions,
        ]);

        $client = @stream_socket_client(
            "ssl://{$domain}:443",
            $errorNumber,
            $errorDescription,
            30,
            STREAM_CLIENT_CONNECT,
            $streamContext
        );

        if (! empty($errorDescription)) {
            return;
        }

        if (! $client) {
            return;
        }

        $response = stream_context_get_params($client);

        $cert = $response['options']['ssl']['peer_certificate'];
        $certificate = openssl_x509_parse($cert);

        return [
            'subject' => Arr::get($certificate, 'subject.CN'),
            'issuer' => Arr::get($certificate, 'issuer.CN'),
            'organization' => Arr::get($certificate, 'issuer.O'),
            'validTo' => Date::createFromTimestamp($certificate['validTo_time_t']),
        ];
    }

    private function getServerIP()
    {
        $services = [
            'https://gardiaan.jules.nl/ip',
            'https://icanhazip.com',
            'https://ipinfo.io/ip',
            'https://ifconfig.co',
            'https://ipecho.net/plain',
        ];

        foreach ($services as $service) {
            $result = Process::run('curl -4 --silent --max-time 5 ' . escapeshellarg($service));

            if ($result->successful()) {
                return trim($result->output());
            }
        }
    }

    private function isDarwin()
    {
        return PHP_OS === 'Darwin';
    }

    public function toArray()
    {
        return Cache::flexible(__METHOD__, [CarbonInterval::day(), CarbonInterval::days(15)], fn() => [
            'os' => $this->getOperatingSystem(),
            'hostname' => $this->getHostname(),
            'certificate' => $this->getCertificateDetails(),
            'ip' => $this->getServerIP(),
        ]);
    }
}
