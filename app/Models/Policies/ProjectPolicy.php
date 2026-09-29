<?php

declare(strict_types=1);

namespace App\Models\Policies;

use App\Models\Policies\Concerns\ListsAbilities;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectPolicy
{
    use HandlesAuthorization;
    use ListsAbilities;

    public const INDEX = 'index';

    public const VIEW = 'view';

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DESTROY = 'destroy';

    public function index(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Project $project): bool
    {
        if (! $project->exists) {
            return $this->create($user);
        }

        return $project->isOwnedBy($user);
    }

    public function destroy(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }
}
