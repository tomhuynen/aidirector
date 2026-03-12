<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Infer\Extensions\Event\PropertyFetchEvent;
use Dedoc\Scramble\Infer\Extensions\PropertyTypeExtension;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves custom Castable attribute types on Eloquent models.
 *
 * Scramble's built-in ModelExtension doesn't support custom castables,
 * so `$tenant->branding` (cast to TenantBranding::class) would be UnknownType.
 * This extension checks if the cast class implements Castable and returns
 * the proper ObjectType, allowing further method calls (like toArray()) to be inferred.
 */
class CastablePropertyExtension implements PropertyTypeExtension
{
    public function shouldHandle(ObjectType $type): bool
    {
        return $type->isInstanceOf(Model::class);
    }

    public function getPropertyType(PropertyFetchEvent $event): ?Type
    {
        $modelClass = $event->getInstance()->name;

        if (! is_a($modelClass, Model::class, true)) {
            return null;
        }

        /** @var Model $model */
        $model = new $modelClass();
        $casts = $model->getCasts();
        $property = $event->name;

        if (! isset($casts[$property])) {
            return null;
        }

        $castClass = $casts[$property];

        if (! is_a($castClass, Castable::class, true)) {
            return null;
        }

        return new ObjectType($castClass);
    }
}
