<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\Concerns\AuthorizesResource;
use App\Models\Policies\UserPolicy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
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
            'email' => $this->email,
            'roles' => UserRoleResource::collection($this->roles),
            'createdAt' => $this->created_at,
            'isMe' => $this->isMe(),
            'links' => $this->when($this->resource->exists, fn() => [
                'view' => route('admin.accounts.view', $this->resource),
                'update' => route('admin.accounts.update', $this->resource),
                'invite' => route('admin.accounts.invite', $this->resource),
            ]),
            /** @var array<string, bool> */
            'can' => $this->when(! is_null($request->user()), fn() => $this->authorizations($request, UserPolicy::abilities()), []),
        ];
    }
}
