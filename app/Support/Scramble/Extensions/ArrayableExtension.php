<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

class ArrayableExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type|string $type): bool
    {
        if (is_string($type)) {
            return is_a($type, Arrayable::class, true);
        }

        return $type instanceof ObjectType
            && $type->isInstanceOf(Model::class) === false
            && $type->isInstanceOf(Arrayable::class);
    }

    public function toSchema(Type $type)
    {
        if (! $type->getMethodDefinition('toArray')) {
            return new UnknownType();
        }

        $array = $type->getMethodDefinition('toArray')
            ->type
            ->getReturnType();

        return $this->openApiTransformer->transform($array);
    }
}
