<?php

declare(strict_types=1);

namespace App\Support\Widgets\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Action
{
    public function __construct(
        public ?string $title = null,
        public ?string $icon = null,
    ) {}
}
