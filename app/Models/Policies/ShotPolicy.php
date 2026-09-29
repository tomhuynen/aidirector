<?php

declare(strict_types=1);

namespace App\Models\Policies;

use App\Models\Policies\Concerns\ListsAbilities;
use App\Models\Project;
use App\Models\Shot;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ShotPolicy
{
    use HandlesAuthorization;
    use ListsAbilities;

    public const VIEW = 'view';

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DESTROY = 'destroy';

    public function view(User $user, Shot $shot): bool
    {
        return $shot->project?->isOwnedBy($user) ?? false;
    }

    public function create(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }

    public function update(User $user, Shot $shot): bool
    {
        return $shot->project?->isOwnedBy($user) ?? false;
    }

    public function destroy(User $user, Shot $shot): bool
    {
        return $shot->project?->isOwnedBy($user) ?? false;
    }
}
