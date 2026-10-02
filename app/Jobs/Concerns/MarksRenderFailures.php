<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Str;
use Throwable;

/**
 * For jobs that draw an image onto a model with a `rendering` flag and a
 * `render_error` message. A failure only counts while the model is still
 * rendering, so a stale attempt can never overwrite a render that succeeded,
 * and the message says why it failed.
 */
trait MarksRenderFailures
{
    protected function markRenderFailed(Model $model, string $message, ?Throwable $exception): void
    {
        $reason = match (true) {
            $exception instanceof MaxAttemptsExceededException => __('It ran longer than the queue allows and was stopped.'),
            $exception !== null => Str::limit($exception->getMessage(), 300),
            default => null,
        };

        $model->newQuery()
            ->whereKey($model->getKey())
            ->where('rendering', true)
            ->update([
                'rendering' => false,
                'render_error' => $reason === null ? $message : "{$message} {$reason}",
            ]);
    }
}
