<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Decides whether a failed call to a model provider is worth trying again:
 * a dropped connection, a busy gateway or a rate limit, not a bad request.
 */
class TransientHttpFailure
{
    /** How often a call is tried before the failure stands. */
    public const ATTEMPTS = 3;

    /** The wait between attempts, in milliseconds. */
    public const WAIT_MS = 3000;

    public static function retryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && ($exception->response->serverError() || $exception->response->status() === 429);
    }
}
