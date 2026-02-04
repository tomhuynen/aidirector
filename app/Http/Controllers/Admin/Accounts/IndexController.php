<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounts;

use App\Models\Policies\UserPolicy;
use App\Models\User;
use App\Support\Admin\Page;
use App\Tables\Admin\Users;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class IndexController
{
    public function index()
    {
        Gate::authorize(UserPolicy::INDEX, User::class);

        Page::actions()->index('accounts', User::class, UserPolicy::CREATE);

        return Inertia::render('accounts/index', [
            'accounts' => Users::make(),
        ]);
    }
}
