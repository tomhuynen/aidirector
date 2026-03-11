<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Infer\Definition\FunctionLikeAstDefinition;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\TemplateType;
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

        // When the inferred return type has unresolved template keys (e.g., from
        // Collection::all()), prefer the PHPDoc declaration type which is typically
        // correct. Without this, ArrayType(key: TemplateType) generates an object
        // schema instead of an array schema in OpenAPI.
        if ($this->hasUnresolvedTemplateKeys($toArrayReturnType)) {
            $methodDef = $classDefinition->getMethodDefinition('toArray');

            if ($methodDef instanceof FunctionLikeAstDefinition) {
                $declarationType = $methodDef->getDeclarationDefinition()?->getReturnType();

                if ($declarationType !== null) {
                    $toArrayReturnType = $declarationType;
                }
            }
        }

        return $this->openApiTransformer->transform($toArrayReturnType);
    }

    private function hasUnresolvedTemplateKeys(Type $type): bool
    {
        if ($type instanceof ArrayType && $type->key instanceof TemplateType) {
            return true;
        }

        if ($type instanceof KeyedArrayType) {
            foreach ($type->items as $item) {
                if ($this->hasUnresolvedTemplateKeys($item->value)) {
                    return true;
                }
            }
        }

        return false;
    }
}
