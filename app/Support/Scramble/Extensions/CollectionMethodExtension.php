<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\TemplateType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Support\Collection;

/**
 * Resolves return types for Collection::all() and Collection::toArray().
 *
 * Without this, these methods return array<TKey, TValue> where TKey
 * stays as an unresolved TemplateType, causing arrays to be typed
 * as objects (dictionary) in OpenAPI instead of lists.
 */
class CollectionMethodExtension implements MethodReturnTypeExtension
{
    public function shouldHandle(ObjectType $type): bool
    {
        return $type->isInstanceOf(Collection::class);
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        if (! in_array($event->name, ['all', 'toArray'])) {
            return null;
        }

        if (! $event->instance instanceof Generic || count($event->instance->templateTypes) < 2) {
            return null;
        }

        $keyType = $event->instance->templateTypes[0];
        $valueType = $event->instance->templateTypes[1];

        return new ArrayType(
            value: $valueType,
            key: $keyType instanceof TemplateType ? new IntegerType() : $keyType,
        );
    }
}
