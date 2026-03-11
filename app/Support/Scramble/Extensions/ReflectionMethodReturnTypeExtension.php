<?php

declare(strict_types=1);

namespace App\Support\Scramble\Extensions;

use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\BooleanType;
use Dedoc\Scramble\Support\Type\FloatType;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Dedoc\Scramble\Support\Type\VoidType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;

/**
 * Fallback extension that uses PHP reflection to infer method return types
 * when Scramble doesn't have the class in its index.
 */
class ReflectionMethodReturnTypeExtension implements MethodReturnTypeExtension
{
    public function shouldHandle(ObjectType $type): bool
    {
        return true;
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        // Only handle if Scramble doesn't already have the method definition
        $instance = $event->getInstance();
        if ($instance->getMethodDefinition($event->getName(), $event->scope) !== null) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($instance->name, $event->getName());
            $returnType = $reflection->getReturnType();

            if ($returnType !== null) {
                return $this->reflectionTypeToScrambleType($returnType);
            }

            // Fallback to docblock parsing
            return $this->getReturnTypeFromDocblock($reflection);
        } catch (Throwable) {
            return null;
        }
    }

    private function reflectionTypeToScrambleType(\ReflectionType $type): ?Type
    {
        if ($type instanceof ReflectionUnionType) {
            $types = array_filter(
                array_map(fn($t) => $this->reflectionTypeToScrambleType($t), $type->getTypes())
            );

            return $types ? Union::wrap($types) : null;
        }

        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        $name = $type->getName();

        $scrambleType = match ($name) {
            'int' => new IntegerType(),
            'float' => new FloatType(),
            'string' => new StringType(),
            'bool' => new BooleanType(),
            'array' => null, // Can't infer array shape from reflection alone
            'void' => new VoidType(),
            'null' => new NullType(),
            'mixed' => null,
            default => class_exists($name) || interface_exists($name) ? new ObjectType($name) : null,
        };

        if ($scrambleType && $type->allowsNull() && ! $scrambleType instanceof NullType && ! $scrambleType instanceof VoidType) {
            return Union::wrap([$scrambleType, new NullType()]);
        }

        return $scrambleType;
    }

    private function getReturnTypeFromDocblock(ReflectionMethod $reflection): ?Type
    {
        $docComment = $reflection->getDocComment();

        if ($docComment === false) {
            return null;
        }

        // Match @return type from docblock
        if (! preg_match('/@return\s+(\S+)/', $docComment, $matches)) {
            return null;
        }

        $typeString = $matches[1];

        // Handle union types (e.g., "string|null")
        if (str_contains($typeString, '|')) {
            $types = array_filter(
                array_map(fn($t) => $this->docblockTypeToScrambleType(trim($t)), explode('|', $typeString))
            );

            return $types ? Union::wrap($types) : null;
        }

        return $this->docblockTypeToScrambleType($typeString);
    }

    private function docblockTypeToScrambleType(string $type): ?Type
    {
        // Remove leading backslash
        $type = ltrim($type, '\\');

        return match ($type) {
            'int', 'integer' => new IntegerType(),
            'float', 'double' => new FloatType(),
            'string' => new StringType(),
            'bool', 'boolean' => new BooleanType(),
            'void' => new VoidType(),
            'null' => new NullType(),
            'array', 'mixed', 'object', 'resource', 'callable' => null,
            default => class_exists($type) || interface_exists($type) ? new ObjectType($type) : null,
        };
    }
}
