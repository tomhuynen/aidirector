<?php

declare(strict_types=1);

namespace App\Support\Widgets\Abstracts;

use App\Http\Resources\Admin\WidgetResource;
use App\Support\Widgets\Attributes\Action;
use App\Support\Widgets\WidgetIdentifier;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

abstract class Widget implements Arrayable
{
    final public function __construct(
        protected array $config = [],
    ) {
        if (! $this->shouldRender()) {
            return;
        }

        $this->setup();
    }

    abstract public function componentName(): string;

    abstract public function name(): string;

    abstract public function data(): array;

    protected function setup(): void {}

    public function title(): string
    {
        return __(Str::headline($this->name()));
    }

    public function description(): ?string
    {
        return null;
    }

    public function shouldRender(): bool
    {
        return true;
    }

    public function identifier(): string
    {
        return WidgetIdentifier::encode(static::class);
    }

    public function execute(string $action, array $params = [])
    {
        if (! method_exists($this, $action)) {
            throw new InvalidArgumentException("Method {$action} does not exist in widget " . static::class);
        }

        return $this->{$action}(...$params);
    }

    public function actions(): array
    {
        $reflection = new ReflectionClass($this);
        $actions = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->shouldSkipMethod($method)) {
                continue;
            }

            $actionAttributes = $method->getAttributes(Action::class);
            if (! empty($actionAttributes)) {
                $action = $actionAttributes[0]->newInstance();
                $actions[] = $this->buildActionInfo($method, $action);
            }
        }

        return $actions;
    }

    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier(),
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'component' => $this->componentName(),
            'data' => $this->data(),
            'actions' => $this->actions(),
        ];
    }

    protected function shouldSkipMethod(ReflectionMethod $method): bool
    {
        return $method->isConstructor()
            || $method->isDestructor()
            || $method->isAbstract()
            || $method->getDeclaringClass()->getName() !== static::class
            || str_starts_with($method->getName(), '__');
    }

    protected function buildActionInfo(ReflectionMethod $method, Action $action): array
    {
        return [
            'name' => $action->title ?? Str::headline($method->getName()),
            'icon' => $action->icon ? Str::pascal($action->icon) : null,
            'method' => $method->getName(),
            'parameters' => collect($method->getParameters())->map(fn(ReflectionParameter $parameter) => [
                'name' => $parameter->getName(),
                'type' => (string) $parameter->getType(),
                'optional' => $parameter->isOptional(),
            ]),
            'return_type' => (string) $method->getReturnType(),
            'action' => $action,
        ];
    }

    public static function make(...$args): ?WidgetResource
    {
        $instance = new static(...$args);

        if (! $instance->shouldRender()) {
            return null;
        }

        return WidgetResource::make($instance);
    }
}
