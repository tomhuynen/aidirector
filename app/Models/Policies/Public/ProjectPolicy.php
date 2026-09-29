<?php

declare(strict_types=1);

namespace App\Models\Policies\Public;

use App\Models\Director;
use App\Models\Policies\Concerns\ListsAbilities;
use App\Models\Project;
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

    public function index(Director $director): bool
    {
        return true;
    }

    public function view(Director $director, Project $project): bool
    {
        return $project->isOwnedBy($director);
    }

    public function create(Director $director): bool
    {
        return true;
    }

    public function update(Director $director, Project $project): bool
    {
        if (! $project->exists) {
            return $this->create($director);
        }

        return $project->isOwnedBy($director);
    }

    public function destroy(Director $director, Project $project): bool
    {
        return $project->isOwnedBy($director);
    }
}
