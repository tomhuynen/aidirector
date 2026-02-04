<?php

declare(strict_types=1);

namespace App\Models\Policies;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ActivityPolicy
{
    use HandlesAuthorization;

    public const INDEX = 'index';

    public const VIEW = 'view';

    public function index(User $user)
    {
        return $user->can('admin.activities.view');
    }

    public function view(User $user, Activity $activity)
    {
        return $user->can('admin.activities.view');
    }
}
