<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Infer\Extensions\ExpressionTypeInferExtension;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeHelper;
use Dedoc\Scramble\Support\TypeManagers\CursorPaginatorTypeManager;
use Dedoc\Scramble\Support\TypeManagers\LengthAwarePaginatorTypeManager;
use Dedoc\Scramble\Support\TypeManagers\PaginatorTypeManager;
use Dedoc\Scramble\Support\TypeManagers\ResourceCollectionTypeManager;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;

class InertiaExtension implements ExpressionTypeInferExtension
{
    public function getType(Expr $node, Scope $scope): ?Type
    {
        if ($node instanceof StaticCall && $node->class instanceof FullyQualified && $node->class->toString() === Inertia::class) {
            return $this->handleInertiaStaticCall($node, $scope);
        }

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
            $propsType = TypeHelper::getArgType($scope, $node->args, ['content', 1], new ArrayType());

            // Resolve references in all props (method calls, static calls, etc.)
            $propsType = $this->resolvePropsReferences($propsType, $scope);

            // Transform paginated collections
            $propsType = $this->transformPaginatedCollections($propsType, $scope);

            return new Generic(
                Response::class,
                [$propsType, new LiteralIntegerType(200)],
            );
        }

        if (in_array($node->name->toString(), ['lazy', 'defer', 'optional', 'scroll']) && count($node->args) > 0) {
            $argType = TypeHelper::getArgType($scope, $node->args, ['content', 0], new ArrayType());
            $resolvedType = ReferenceTypeResolver::getInstance()->resolve($scope, $argType);

            return $this->transformPaginatedCollection($resolvedType) ?? $argType;
        }

        return null;
    }

    private function transformPaginatedCollections(Type $type, Scope $scope): Type
    {
        if (! $type instanceof KeyedArrayType) {
            return $type;
        }

        $hasChanges = false;
        $transformedItems = [];

        foreach ($type->items as $item) {
            $resolvedValue = ReferenceTypeResolver::getInstance()->resolve($scope, $item->value);
            $paginationType = $this->transformPaginatedCollection($resolvedValue);

            if ($paginationType !== null) {
                $hasChanges = true;
                $newItem = new ArrayItemType_(
                    $item->key,
                    $paginationType,
                    $item->isOptional,
                    $item->shouldUnpack,
                    $item->keyType,
                );
                $newItem->mergeAttributes($item->attributes());
                $transformedItems[] = $newItem;
            } else {
                $transformedItems[] = $item;
            }
        }

        return $hasChanges ? new KeyedArrayType($transformedItems) : $type;
    }

    private function transformPaginatedCollection(Type $type): ?KeyedArrayType
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        if (! $type->isInstanceOf(ResourceCollection::class)) {
            return null;
        }

        $paginatorType = $this->getPaginatorType($type);
        if ($paginatorType === null) {
            return null;
        }

        $normalizedType = $type instanceof Generic ? $type : new Generic($type->name);
        $manager = ResourceCollectionTypeManager::make($normalizedType);
        $collectedType = $manager->getCollectedType();
        $dataType = new ArrayType($collectedType);

        return match ($paginatorType) {
            'cursor' => (new CursorPaginatorTypeManager())->getToArrayType($dataType),
            'length_aware' => (new LengthAwarePaginatorTypeManager())->getToArrayType($dataType),
            'simple' => (new PaginatorTypeManager())->getToArrayType($dataType),
        };
    }

    /**
     * Detect the pagination type from a ResourceCollection's template types.
     *
     * @return 'cursor'|'length_aware'|'simple'|null
     */
    private function getPaginatorType(ObjectType $type): ?string
    {
        if (! $type instanceof Generic) {
            return null;
        }

        $resourceType = $type->templateTypes[0] ?? null;

        if (! $resourceType instanceof ObjectType) {
            return null;
        }

        if ($resourceType->isInstanceOf(AbstractCursorPaginator::class)) {
            return 'cursor';
        }

        if ($resourceType->isInstanceOf(LengthAwarePaginator::class)) {
            return 'length_aware';
        }

        if ($resourceType->isInstanceOf(AbstractPaginator::class)) {
            return 'simple';
        }

        return null;
    }

    /**
     * Resolve all reference types in the props array.
     */
    private function resolvePropsReferences(Type $type, Scope $scope): Type
    {
        if (! $type instanceof KeyedArrayType) {
            return ReferenceTypeResolver::getInstance()->resolve($scope, $type);
        }

        $transformedItems = [];

        foreach ($type->items as $item) {
            $resolvedValue = ReferenceTypeResolver::getInstance()->resolve($scope, $item->value);

            $newItem = new ArrayItemType_(
                $item->key,
                $resolvedValue,
                $item->isOptional,
                $item->shouldUnpack,
                $item->keyType,
            );
            $newItem->mergeAttributes($item->attributes());

            $transformedItems[] = $newItem;
        }

        return new KeyedArrayType($transformedItems);
    }

    private function findInertiaStaticCall(MethodCall $methodCall): ?StaticCall
    {
        $current = $methodCall->var;

        while ($current instanceof MethodCall) {
            $current = $current->var;
        }

        if ($current instanceof StaticCall
            && $current->class instanceof FullyQualified
            && $current->class->toString() === Inertia::class) {
            return $current;
        }

        return null;
    }
}
