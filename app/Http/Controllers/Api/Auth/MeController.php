<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Resources\Api\UserResource;
use Illuminate\Http\Request;

class MeController
{
    public function view(Request $request)
    {
        return UserResource::make($request->user());
    }
}
