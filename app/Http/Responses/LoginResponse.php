<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Support\FrontendRedirect;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        /** @var Request $request */
        return redirect()->intended(FrontendRedirect::homeFor($request));
    }
}
