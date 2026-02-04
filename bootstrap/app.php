<?php

declare(strict_types=1);

use App\Http\Middleware\Admin\IdentifyTenant as IdentifyAdminTenant;
use App\Http\Middleware\Public\IdentifyTenant as IdentifyPublicTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        web: __DIR__ . '/../routes/web.php',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware
            ->prependToPriorityList(
                before: AuthenticatesRequests::class,
                prepend: IdentifyAdminTenant::class,
            )
            ->prependToPriorityList(
                before: AuthenticatesRequests::class,
                prepend: IdentifyPublicTenant::class,
            )
            ->validateCsrfTokens(except: [
                '/auth/logout',
            ])
            ->trustHosts(null, false);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->reportable(function (RequestException $e) {
            Log::error('API Request Failed', [
                'uri' => $e->response->effectiveUri(),
                'status' => $e->response->status(),
                'body' => $e->response->body(),
            ]);
        });
    })->create();
