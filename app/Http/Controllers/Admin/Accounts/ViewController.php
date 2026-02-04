<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounts;

use App\Http\Resources\Admin\UserResource;
use App\Models\Policies\UserPolicy;
use App\Models\User;
use App\Support\Admin\Page;
use App\Support\Admin\Page\PageAction;
use App\Tables\Admin\Users\Logins;
use App\Tables\Admin\Users\Notifications;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ViewController
{
    public function view(User $account)
    {
        Gate::authorize(UserPolicy::VIEW, $account);

        Page::actions()->add(
            PageAction::make(__('Edit'), route('admin.accounts.update', $account))
                ->icon('Pencil')
                ->can(UserPolicy::UPDATE, $account)
        );

        return Inertia::render('accounts/view', [
            'account' => fn() => UserResource::make($account),
            'logins' => Inertia::defer(fn() => new Logins($account)),
            'notifications' => Inertia::optional(fn() => new Notifications($account)),
        ]);
    }
}
