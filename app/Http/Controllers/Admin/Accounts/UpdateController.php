<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounts;

use App\Http\Resources\Admin\UserResource;
use App\Http\Resources\Admin\UserRoleResource;
use App\Models\Auth\Role;
use App\Models\Policies\UserPolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Admin\AccountInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UpdateController
{
    public function update(Request $request, User $account)
    {
        Gate::authorize(UserPolicy::UPDATE, $account);

        return Inertia::render('accounts/update', [
            'account' => fn() => UserResource::make($account),
            'roles' => fn() => UserRoleResource::collection($this->rolesForUser($request->user())),
        ]);
    }

    public function store(Request $request, User $account)
    {
        Gate::authorize(UserPolicy::UPDATE, $account);

        if (! $account->exists) {
            $this->prepareNewAccount($request, $account);
        }

        $user = $request->user();
        $roles = $this->rolesForUser($user)->pluck('name');

        $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:200', Rule::unique('users')->ignore($account->id)],
            'roles' => ['required', 'array'],
            'roles.*' => ['required', 'string', Rule::in($roles)],
        ]);

        $account->fill([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
        ]);

        $account->save();
        $account->syncRoles([$request->input('roles')]);

        if ($account->wasRecentlyCreated) {
            $account->notify(new AccountInvitation());
        }

        return redirect()->route('admin.accounts.view', $account);
    }

    private function prepareNewAccount(Request $request, User $account)
    {
        $account->tenant_id = Tenant::current()->id;
    }

    private function rolesForUser(User $user)
    {
        return Role::query()
            ->where('level', '<=', $user->maxRoleLevel)
            ->orderBy('level', 'desc')
            ->get();
    }
}
