<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Type\FunctionType;
use Dedoc\Scramble\Support\Type\Type;

class CallbackExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type|string $type): bool
    {
        return $type instanceof FunctionType;
    }

    /**
     * @param FunctionType $type
     */
    public function toSchema(Type $type)
    {
        $returnType = $type->getReturnType();

        return $this->openApiTransformer->transform($returnType);
    }
}
