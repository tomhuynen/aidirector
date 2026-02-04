<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Infer\Extensions\ExpressionTypeInferExtension;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeHelper;
use Illuminate\Http\Response;
use Inertia\Inertia;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;

class InertiaExtension implements ExpressionTypeInferExtension
{
    public function getType(Expr $node, Scope $scope): ?Type
    {
        // Handle direct static calls: Inertia::render() or Inertia::modal()
        if ($node instanceof StaticCall && $node->class instanceof FullyQualified && $node->class->toString() === Inertia::class) {
            return $this->handleInertiaStaticCall($node, $scope);
        }

        // Handle method calls on Inertia static calls: Inertia::modal()->baseRoute()
        if ($node instanceof MethodCall) {
            $inertiaCall = $this->findInertiaStaticCall($node);
            if ($inertiaCall) {
                return $this->handleInertiaStaticCall($inertiaCall, $scope);
            }
        }

        return null;
    }

    private function handleInertiaStaticCall(StaticCall $node, Scope $scope): ?Type
    {
        if (in_array($node->name->toString(), ['render', 'modal']) && count($node->args) > 1) {
            return new Generic(
                Response::class,
                [
                    TypeHelper::getArgType($scope, $node->args, ['content', 1], new ArrayType()),
                    new LiteralIntegerType(200),
                ],
            );
        }

        if (in_array($node->name->toString(), ['lazy', 'defer']) && count($node->args) > 0) {
            return TypeHelper::getArgType($scope, $node->args, ['content', 0], new ArrayType());
        }

        return null;
    }

    private function findInertiaStaticCall(MethodCall $methodCall): ?StaticCall
    {
        $current = $methodCall->var;

        // Traverse the chain to find the original Inertia static call
        while ($current instanceof MethodCall) {
            $current = $current->var;
        }

        // Check if we found an Inertia static call
        if ($current instanceof StaticCall
            && $current->class instanceof FullyQualified
            && $current->class->toString() === Inertia::class) {
            return $current;
        }

        return null;
    }
}
