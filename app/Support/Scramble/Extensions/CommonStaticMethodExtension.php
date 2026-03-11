<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\BooleanType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Dedoc\Scramble\Support\Type\VoidType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use ReflectionMethod;
use Throwable;

/**
 * Generic static method type inference for common patterns.
 *
 * Handles:
 * - Self-returning methods (current, make, getInstance, etc.)
 * - Eloquent model methods (find, first, get, all, etc.)
 * - Facade method resolution (Gate::allows -> bool)
 */
class CommonStaticMethodExtension implements StaticMethodReturnTypeExtension
{
    /**
     * Static methods that return an instance of the class they're called on.
     */
    private const SELF_RETURNING_METHODS = [
        'current',
        'make',
        'create',
        'getInstance',
        'instance',
        'factory',
        'new',
        'build',
        'of',
        'from',
    ];

    /**
     * Eloquent methods that return a single model (nullable).
     */
    private const ELOQUENT_NULLABLE_METHODS = [
        'find',
        'first',
        'firstWhere',
    ];

    /**
     * Eloquent methods that return a single model (non-nullable, throws on failure).
     */
    private const ELOQUENT_NON_NULLABLE_METHODS = [
        'findOrFail',
        'firstOrFail',
        'firstOrNew',
        'firstOrCreate',
        'updateOrCreate',
        'sole',
        'createOrFirst',
    ];

    /**
     * Eloquent methods that return a collection of models.
     */
    private const ELOQUENT_COLLECTION_METHODS = [
        'all',
        'get',
        'findMany',
    ];

    /**
     * Known facade method return types.
     * Only define overrides for methods that don't have proper return types in the underlying class.
     */
    private const FACADE_METHOD_TYPES = [
        'Illuminate\Support\Facades\Gate' => [
            'allows' => BooleanType::class,
            'denies' => BooleanType::class,
            'check' => BooleanType::class,
            'any' => BooleanType::class,
            'none' => BooleanType::class,
            'authorize' => VoidType::class,
        ],
    ];

    public function shouldHandle(string $name): bool
    {
        return true;
    }

    public function getStaticMethodReturnType(StaticMethodCallEvent $event): ?Type
    {
        $className = $event->getCallee();
        $methodName = $event->getName();

        // Check if it's a self-returning method
        if ($this->isSelfReturningMethod($methodName)) {
            return new ObjectType($className);
        }

        // Check if it's an Eloquent model method
        if ($type = $this->getEloquentMethodType($className, $methodName)) {
            return $type;
        }

        // Check if it's a facade with known types
        if ($type = $this->getFacadeMethodType($className, $methodName)) {
            return $type;
        }

        // Try to resolve facade to underlying class and get return type
        if ($type = $this->resolveFacadeMethodType($className, $methodName)) {
            return $type;
        }

        return null;
    }

    private function isSelfReturningMethod(string $methodName): bool
    {
        return in_array($methodName, self::SELF_RETURNING_METHODS, true);
    }

    private function getEloquentMethodType(string $className, string $methodName): ?Type
    {
        if (! is_a($className, Model::class, true)) {
            return null;
        }

        // Methods returning Model|null
        if (in_array($methodName, self::ELOQUENT_NULLABLE_METHODS, true)) {
            return Union::wrap([
                new ObjectType($className),
                new NullType(),
            ]);
        }

        // Methods returning Model (throws on failure)
        if (in_array($methodName, self::ELOQUENT_NON_NULLABLE_METHODS, true)) {
            return new ObjectType($className);
        }

        // Methods returning Model[] (Collection serializes to array)
        if (in_array($methodName, self::ELOQUENT_COLLECTION_METHODS, true)) {
            return new ArrayType(new ObjectType($className));
        }

        return null;
    }

    private function getFacadeMethodType(string $className, string $methodName): ?Type
    {
        $types = self::FACADE_METHOD_TYPES[$className] ?? null;

        if ($types === null) {
            return null;
        }

        $typeClass = $types[$methodName] ?? null;

        if ($typeClass === null) {
            return null;
        }

        return new $typeClass();
    }

    private function resolveFacadeMethodType(string $className, string $methodName): ?Type
    {
        if (! is_a($className, Facade::class, true)) {
            return null;
        }

        try {
            $accessor = $className::getFacadeRoot();

            if ($accessor === null) {
                return null;
            }

            $underlyingClass = get_class($accessor);
            $reflection = new ReflectionMethod($underlyingClass, $methodName);
            $returnType = $reflection->getReturnType();

            if ($returnType === null) {
                return null;
            }

            return $this->reflectionTypeToScrambleType($returnType);
        } catch (Throwable) {
            return null;
        }
    }

    private function reflectionTypeToScrambleType(\ReflectionType $type): ?Type
    {
        if (! $type instanceof \ReflectionNamedType) {
            return null;
        }

        $name = $type->getName();

        return match ($name) {
            'bool' => new BooleanType(),
            'void' => new VoidType(),
            default => null,
        };
    }
}
