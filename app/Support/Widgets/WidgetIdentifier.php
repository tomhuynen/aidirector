<?php

declare(strict_types=1);

namespace App\Support\Widgets;

use App\Support\Widgets\Abstracts\Widget;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

class WidgetIdentifier
{
    public static function encode(string $identifier): string
    {
        return Crypt::encrypt($identifier);
    }

    public static function decode(string $identifier): string
    {
        try {
            return Crypt::decrypt($identifier);
        } catch (DecryptException $e) {
            throw new InvalidArgumentException('Invalid widget identifier');
        }
    }

    public static function resolve(string $identifier): string
    {
        $fqcn = self::decode($identifier);

        if (! class_exists($fqcn)) {
            throw new InvalidArgumentException("Widget class '{$fqcn}' not found");
        }

        if (! is_subclass_of($fqcn, Widget::class)) {
            throw new InvalidArgumentException("Widget class '{$fqcn}' must extend " . Widget::class);
        }

        return $fqcn;
    }

    public static function make(string $identifier, ?array $config = []): Widget
    {
        $fqcn = self::resolve($identifier);

        return new $fqcn($config);
    }
}
