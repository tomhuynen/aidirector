<?php

declare(strict_types=1);

namespace App\Models\Policies;

use App\Models\Policies\Concerns\ListsAbilities;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UploadPolicy
{
    use HandlesAuthorization;
    use ListsAbilities;

    public const STORE = 'store';

    public function store(User $user)
    {
        return $user->can('admin.uploads.store');
    }
}
