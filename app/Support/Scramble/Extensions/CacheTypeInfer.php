<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Infer\Extensions\ExpressionTypeInferExtension;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Support\Type\FunctionType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Support\Facades\Cache;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

class CacheTypeInfer implements ExpressionTypeInferExtension
{
    public function getType(Expr $node, Scope $scope): ?Type
    {
        // Handle Cache::remember() calls
        if (
            $node instanceof Expr\StaticCall
            && $node->class instanceof Name
            && $node->class->toString() === Cache::class
            && $node->name instanceof Identifier
            && in_array($node->name->toString(), ['remember', 'flexible'])
        ) {
            // Get the type of the closure (third argument)
            if (isset($node->args[2])) {
                $closureType = $scope->getType($node->args[2]->value);

                if ($closureType instanceof FunctionType) {
                    return $closureType->getReturnType();
                }
            }
        }

        // Handle Cache::get() calls
        if (
            $node instanceof Expr\StaticCall
            && $node->class instanceof Name
            && $node->class->toString() === 'Illuminate\Support\Facades\Cache'
            && $node->name instanceof Identifier
            && in_array($node->name->toString(), ['rememberForever'])
        ) {
            // Get the default value type (second argument) if provided
            if (isset($node->args[1])) {
                $closureType = $scope->getType($node->args[1]->value);

                if ($closureType instanceof FunctionType) {
                    return $closureType->getReturnType();
                }
            }

            // If no default value, return mixed type
            return new ObjectType('mixed');
        }

        return null;
    }
}
