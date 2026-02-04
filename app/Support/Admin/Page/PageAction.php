<?php

declare(strict_types=1);

namespace App\Support\Admin\Page;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements Arrayable<string, mixed>
 */
class PageAction implements Arrayable
{
    protected ?string $permission = null;

    protected Model|string|null $model = null;

    public function __construct(
        public string $title,
        public string $action,
        public ?string $icon = null,
        public bool $disabled = false,
    ) {}

    public static function make(string $title, string $action): self
    {
        return new self($title, $action);
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function disabled(bool $disabled = true): self
    {
        $this->disabled = $disabled;

        return $this;
    }

    /**
     * Require permission - action is filtered out if user lacks it.
     */
    public function can(string $permission, Model|string|null $model = null): self
    {
        $this->permission = $permission;
        $this->model = $model;

        return $this;
    }

    public function isAuthorized(?Authenticatable $user): bool
    {
        if ($this->permission === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $this->model !== null
            ? $user->can($this->permission, $this->model)
            : $user->can($this->permission);
    }

    /**
     * @return array{title: string, action: string, icon: string|null, disabled: bool}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'action' => $this->action,
            'icon' => $this->icon,
            'disabled' => $this->disabled,
        ];
    }
}
