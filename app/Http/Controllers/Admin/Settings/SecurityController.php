<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Tables\Admin\Users\Passkeys;
use App\Tables\Admin\Users\Sessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;

class SecurityController
{
    public function view(Request $request)
    {
        return Inertia::render('settings/security', [
            'passkeys' => Passkeys::make(),
            'sessions' => $this->listSessions($request),
        ]);
    }

    private function listSessions(Request $request): ?Sessions
    {
        if (Session::getDefaultDriver() !== 'database') {
            return null;
        }

        return Sessions::make();
    }
}
