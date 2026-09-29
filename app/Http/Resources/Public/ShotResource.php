<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Http\Resources\Concerns\AuthorizesResource;
use App\Models\Policies\ShotPolicy;
use App\Models\Shot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Shot */
class ShotResource extends JsonResource
{
    use AuthorizesResource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid,
            /** @var int */
            'position' => $this->position,
            'title' => $this->title,
            'subject' => $this->subject,
            'action' => $this->action,
            'takeaway' => $this->takeaway,
            /** @var string|null */
            'notes' => $this->notes,
            'status' => $this->status,
            'statusLabel' => $this->status->description(),
            /** @var string|null */
            'purposeOverride' => $this->purpose_override,
            /** @var string|null */
            'aspectRatioOverride' => $this->aspect_ratio_override,
            /** @var int|null */
            'duration' => $this->duration,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
            'links' => $this->when($this->resource->exists, fn() => [
                'view' => route('public.shots.view', [$this->project, $this->resource]),
                'update' => route('public.shots.update', [$this->project, $this->resource]),
                'destroy' => route('public.shots.destroy', [$this->project, $this->resource]),
            ]),
            /** @var array<string, bool> */
            'can' => $this->when(! is_null($request->user()), fn() => $this->authorizations($request, ShotPolicy::abilities(ShotPolicy::CREATE)), []),
        ];
    }
}
