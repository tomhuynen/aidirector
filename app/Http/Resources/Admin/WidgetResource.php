<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Support\Widgets\Abstracts\Widget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Widget */
class WidgetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'identifier' => $this->identifier(),
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'component' => $this->componentName(),
            /** @var array<string, mixed> */
            'data' => $this->data(),
            /**
             * @var array{
             *  name: string,
             *  icon: string,
             *  method: string,
             *  parameters: array{
             *    name: string,
             *    type: string,
             *    optional: bool,
             *  }[],
             *  return_type: string,
             *  action: array<string, mixed>
             * }[]
             */
            'actions' => $this->actions(),
        ];
    }
}
