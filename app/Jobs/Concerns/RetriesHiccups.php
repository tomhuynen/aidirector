<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Tries a job again a little later when a model provider had a short
 * hiccup, such as too many requests, a server error, a model over
 * capacity or a dropped connection; any other failure fails the job
 * straight away.
 */
trait RetriesHiccups
{
    /** How often a job is tried in all when a provider keeps hiccuping. */
    public int $tries = 3;

    /**
     * Runs the work, and on a hiccup puts the job back to run again later.
     *
     * @param  callable(): mixed  $work
     */
    protected function retryingHiccups(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            if (self::isHiccup($exception) && $this->attempts() < $this->tries) {
                $this->release(20 * $this->attempts());

                return;
            }

            $this->fail($exception);
        }
    }

    public static function isHiccup(Throwable $exception): bool
    {
        return match (true) {
            $exception instanceof RequestException => $exception->response->status() === 429 || $exception->response->serverError(),
            $exception instanceof ConnectionException => true,
            preg_match('/over capacity|retry shortly|temporarily unavailable/i', $exception->getMessage()) === 1 => true,
            default => $exception->getPrevious() !== null && self::isHiccup($exception->getPrevious()),
        };
    }
}
