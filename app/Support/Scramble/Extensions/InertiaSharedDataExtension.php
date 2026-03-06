<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\FunctionType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Type;
use Inertia\Middleware as InertiaMiddleware;

class InertiaSharedDataExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $middlewareClass = $this->findInertiaMiddleware($routeInfo);
        if (! $middlewareClass) {
            return;
        }

        $sharedType = $this->inferSharedDataType($middlewareClass);
        if (! $sharedType instanceof KeyedArrayType || $sharedType->isList) {
            return;
        }

        $resolvedType = $this->unwrapClosures($sharedType);
        $openApiType = $this->openApiTransformer->transform($resolvedType);

        if (! $openApiType instanceof ObjectType) {
            return;
        }

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response || $response->code !== 200) {
                continue;
            }

            if (! isset($response->content['application/json'])) {
                continue;
            }

            $schema = $response->content['application/json'];
            if (! $schema->type instanceof ObjectType) {
                continue;
            }

            foreach ($openApiType->properties as $name => $type) {
                if (! $schema->type->hasProperty($name)) {
                    $schema->type->addProperty($name, $type);
                }
            }

            $schema->type->addRequired($openApiType->required);
        }
    }

    private function findInertiaMiddleware(RouteInfo $routeInfo): ?string
    {
        foreach ($routeInfo->route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && is_subclass_of($middleware, InertiaMiddleware::class)) {
                return $middleware;
            }
        }

        return null;
    }

    private function inferSharedDataType(string $middlewareClass): ?Type
    {
        $classDef = $this->infer->analyzeClass($middlewareClass);
        $methodDef = $classDef->getMethodDefinition('share');

        return $methodDef?->type->returnType;
    }

    /**
     * Unwrap closures in a KeyedArrayType to their return types,
     * since Inertia resolves closures at runtime before sending data.
     */
    private function unwrapClosures(KeyedArrayType $type): KeyedArrayType
    {
        $items = array_map(function (ArrayItemType_ $item) {
            $value = $item->value;

            if ($value instanceof FunctionType) {
                $value = $value->getReturnType();
            }

            if ($value instanceof KeyedArrayType && ! $value->isList) {
                $value = $this->unwrapClosures($value);
            }

            return new ArrayItemType_(
                key: $item->key,
                value: $value,
                isOptional: $item->isOptional,
            );
        }, $type->items);

        // Filter out non-string keys (e.g., spread of parent::share())
        $items = array_values(array_filter($items, fn(ArrayItemType_ $item) => is_string($item->key) && $item->key !== ''));

        return new KeyedArrayType($items);
    }
}
