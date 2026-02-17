<?php

declare(strict_types=1);

namespace App\Support\Admin\Page;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @implements Arrayable<string, mixed>
 */
class BreadcrumbItem implements Arrayable
{
    public function __construct(
        public string $title,
        public ?string $href = null,
    ) {}

    public static function make(string $title, ?string $href = null): self
    {
        return new self($title, $href);
    }

    /**
     * Create from route segment with auto-inferred title.
     */
    public static function fromSegment(string $segment, string $routeName): self
    {
        return new self(
            title: __(Str::headline($segment)),
            href: route($routeName),
        );
    }

    /**
     * Create from model with name/title attribute.
     *
     * @param  array<string, mixed>  $routeParameters
     */
    public static function fromModel(Model $model, string $routeName, array $routeParameters = []): self
    {
        return new self(
            title: $model->name ?? $model->title ?? (string) $model->getKey(),
            href: route($routeName, $routeParameters ?: $model),
        );
    }

    /**
     * @return array{title: string, href: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'href' => $this->href,
        ];
    }
}
