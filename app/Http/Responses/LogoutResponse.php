<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Support\FrontendRedirect;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        /** @var Request $request */
        return redirect(FrontendRedirect::loginFor($request));
    }
}
