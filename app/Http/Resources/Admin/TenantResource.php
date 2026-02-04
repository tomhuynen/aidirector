<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\Concerns\AuthorizesResource;
use App\Models\Policies\TenantPolicy;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class TenantResource extends JsonResource
{
    use AuthorizesResource;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid,
            'name' => $this->name,
            'domain' => $this->domain,
            /** @var bool */
            'isCurrent' => $this->isCurrent(),
            'createdAt' => $this->created_at,
            'links' => $this->when($this->resource->exists, fn() => [
                'switch' => route('admin.tenants.switch', $this->resource),
                'update' => route('admin.tenants.update', $this->resource),
            ]),
            /** @var array<string, bool> */
            'can' => $this->when(! is_null($request->user()), fn() => $this->authorizations($request, TenantPolicy::abilities()), []),
        ];
    }
}
