<?php

declare(strict_types=1);

namespace App\Models\Policies\Public;

use App\Models\Director;
use App\Models\Policies\Concerns\ListsAbilities;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Auth\Access\HandlesAuthorization;

class ShotPolicy
{
    use HandlesAuthorization;
    use ListsAbilities;

    public const VIEW = 'view';

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DESTROY = 'destroy';

    public function view(Director $director, Shot $shot): bool
    {
        return $shot->project?->isOwnedBy($director) ?? false;
    }

    public function create(Director $director, Project $project): bool
    {
        return $project->isOwnedBy($director);
    }

    public function update(Director $director, Shot $shot): bool
    {
        return $shot->project?->isOwnedBy($director) ?? false;
    }

    public function destroy(Director $director, Shot $shot): bool
    {
        return $shot->project?->isOwnedBy($director) ?? false;
    }
}
