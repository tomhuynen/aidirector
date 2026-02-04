<?php

declare(strict_types=1);

namespace App\Support\Login;

use Illuminate\Support\Fluent;

class IpInfoDetails extends Fluent
{
    private static $defaults = [
        'value' => null,
        'countryCode' => null,
        'countryFlag' => null,
        'countryFlagImage' => null,
        'organization' => null,
        'geoLocation' => null,
        'asn' => null,
        'timezone' => null,
        'bogon' => false,
    ];

    public function __construct($attributes = [])
    {
        foreach (self::$defaults as $key => $value) {
            $this->attributes[$key] = $value;
        }

        if (empty($attributes)) {
            return;
        }

        $this->attributes['value'] = $attributes['ip'];

        $map = ['timezone', 'bogon'];
        foreach ($map as $key) {
            if (isset($attributes[$key])) {
                $this->attributes[$key] = $attributes[$key];
            }
        }

        if (isset($attributes['country'])) {
            $this->attributes['countryCode'] = strtoupper((string) $attributes['country']);
            $this->attributes['countryFlag'] = $this->getCountryFlag($attributes['country']);
            $this->attributes['countryFlagImage'] = $this->getCountryFlagUrl($attributes['country']);
        }

        if (isset($attributes['org'])) {
            $this->attributes['asn'] = $this->getAsnFromOrg($attributes['org']);
            $this->attributes['organization'] = $this->getOrganizationFromOrg($attributes['org']);
        }

        if (isset($attributes['loc'])) {
            $location = is_array($attributes['loc'])
                ? $attributes['loc']
                : explode(',', (string) $attributes['loc']);

            $this->attributes['geoLocation'] = [
                'latitude' => (float) $location[0],
                'longitude' => (float) $location[1],
            ];
        }
    }

    private function getCountryFlag(string $countryCode)
    {
        $unicodePrefix = "\xF0\x9F\x87";
        $unicodeAddition = 0x65;
        $letters = str_split(strtoupper($countryCode));

        $emoji = $unicodePrefix . chr(ord($letters[0]) + $unicodeAddition)
               . $unicodePrefix . chr(ord($letters[1]) + $unicodeAddition);

        return $emoji;
    }

    private function getCountryFlagUrl(string $countryCode)
    {
        return sprintf(
            'https://cdn.ipinfo.io/static/images/countries-flags/%s.svg',
            strtoupper($countryCode)
        );
    }

    private function getAsnFromOrg(string $value)
    {
        preg_match_all('/^AS(\d+)\s/', $value, $matches);
        if (count($matches[0])) {
            return trim($matches[0][0]);
        }
    }

    private function getOrganizationFromOrg(string $value)
    {
        $asn = $this->getAsnFromOrg($value);
        if (empty($asn)) {
            return $value;
        }

        return trim(substr($value, strlen((string) $asn)));
    }
}
