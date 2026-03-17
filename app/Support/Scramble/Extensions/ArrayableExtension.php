<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Infer\Definition\FunctionLikeAstDefinition;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
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
        if (! $type instanceof ObjectType) {
            return;
        }

        $classDefinition = $this->infer->analyzeClass($type->name);

        $toArrayReturnType = $type->getMethodReturnType('toArray');

        // When the inferred return type has non-integer keys on an ArrayType
        // (e.g., from Collection::all() resolving template keys to string),
        // prefer the PHPDoc declaration type which typically declares list<>.
        // Without this, ArrayType(key: StringType) generates an object schema
        // instead of an array schema in OpenAPI.
        if ($this->hasNonIntegerArrayKeys($toArrayReturnType)) {
            $methodDef = $classDefinition->getMethodDefinition('toArray');

            if ($methodDef instanceof FunctionLikeAstDefinition) {
                $declarationType = $methodDef->getDeclarationDefinition()?->getReturnType();

                if ($declarationType !== null) {
                    return $this->openApiTransformer->transform($declarationType);
                }
            }
        }

        return $this->openApiTransformer->transform($toArrayReturnType);
    }

    /**
     * Check if a type contains ArrayType nodes with non-integer keys.
     * This catches both unresolved TemplateType keys and resolved StringType
     * keys from Collection chains that should actually be list<> (int keys).
     */
    private function hasNonIntegerArrayKeys(Type $type): bool
    {
        if ($type instanceof ArrayType) {
            return ! ($type->key instanceof LiteralIntegerType);
        }

        if ($type instanceof KeyedArrayType) {
            foreach ($type->items as $item) {
                if ($this->hasNonIntegerArrayKeys($item->value)) {
                    return true;
                }
            }
        }

        return false;
    }
}
