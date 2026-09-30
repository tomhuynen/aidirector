<?php

declare(strict_types=1);

namespace App\Models\Policies\Public;

use App\Models\Director;
use App\Models\Policies\Concerns\ListsAbilities;
use Illuminate\Auth\Access\HandlesAuthorization;

class UploadPolicy
{
    use HandlesAuthorization;
    use ListsAbilities;

    public const STORE = 'store';

    public function store(Director $director): bool
    {
        return true;
    }
}
