<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use App\Enums\Traits\EnumHelpers;
use Dedoc\Scramble\Infer\Extensions\ExpressionTypeInferExtension;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Support\Collection;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;

/**
 * Helps Scramble infer return types for methods provided by the EnumHelpers trait.
 *
 * Without this extension, Scramble cannot resolve `description()`, `toArray()`,
 * `asSelectArray()`, or `collect()` on enums that use the trait.
 */
class EnumHelpersExtension implements ExpressionTypeInferExtension
{
    public function getType(Expr $node, Scope $scope): ?Type
    {
        if (! $node instanceof Expr\MethodCall && ! $node instanceof Expr\StaticCall) {
            return null;
        }

        if (! $node->name instanceof Identifier) {
            return null;
        }

        $callerType = $node instanceof Expr\StaticCall
            ? ($node->class instanceof \PhpParser\Node\Name ? new ObjectType($node->class->toString()) : null)
            : $scope->getType($node->var);

        if (! $callerType instanceof ObjectType) {
            return null;
        }

        if (! $this->usesEnumHelpers($callerType->name)) {
            return null;
        }

        return match ($node->name->toString()) {
            'description' => new StringType(),
            'toArray' => new KeyedArrayType([
                new ArrayItemType_('value', new StringType()),
                new ArrayItemType_('label', new StringType()),
            ]),
            'asSelectArray' => new KeyedArrayType([]),
            'collect' => new Generic(Collection::class, [new ObjectType($callerType->name)]),
            default => null,
        };
    }

    private function usesEnumHelpers(string $className): bool
    {
        if (! class_exists($className) && ! enum_exists($className)) {
            return false;
        }

        return in_array(EnumHelpers::class, class_uses_recursive($className));
    }
}
