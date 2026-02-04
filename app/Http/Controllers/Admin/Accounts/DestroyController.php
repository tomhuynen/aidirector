<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounts;

use App\Models\Policies\UserPolicy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DestroyController
{
    public function destroy(Request $request, User $account)
    {
        Gate::authorize(UserPolicy::DESTROY, $account);

        $account->delete();

        return redirect()->route('admin.accounts.index');
    }
}
