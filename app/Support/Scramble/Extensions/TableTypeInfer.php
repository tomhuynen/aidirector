<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Type\ObjectType as TypeObjectType;
use Dedoc\Scramble\Support\Type\Type;
use InertiaUI\Table\Table;

class TableTypeInfer extends TypeToSchemaExtension
{
    public function shouldHandle(Type|string $type): bool
    {
        if (is_string($type)) {
            return is_a($type, Table::class, true);
        }

        return $type instanceof TypeObjectType
            && $type->isInstanceOf(Table::class);
    }

    public function toSchema(Type $type): Reference
    {
        $components = $this->openApiTransformer->getComponents();

        if (! $components->hasSchema('TableResource')) {
            $components->addSchema('TableResource', Schema::fromType(new ObjectType()));
        }

        return $components->getSchemaReference('TableResource');
    }
}
