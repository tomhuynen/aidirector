<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid,
            'event' => $this->event,
            'description' => $this->description,
            'subject' => [
                'id' => $this->subject->getRouteKey(),
                'type' => $this->subject->getMorphClass(),
            ],
            'causer' => [
                'id' => $this->causer?->getRouteKey(),
                'type' => $this->causer?->getMorphClass(),
                'name' => $this->causer->name ?? null,
                'email' => $this->causer->email ?? null,
            ],
            /** @var array<string, mixed> */
            'changes' => $this->changes,
            'createdAt' => $this->created_at,
        ];
    }
}
