<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounts;

use App\Models\Policies\UserPolicy;
use App\Models\User;
use App\Notifications\Admin\AccountInvitation;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class InviteController
{
    public function invite(User $account)
    {
        Gate::authorize(UserPolicy::INVITE, $account);

        $account->notify(new AccountInvitation());

        return response(null, Response::HTTP_ACCEPTED);
    }
}
