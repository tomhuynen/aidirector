<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Keyframe;
use App\Models\Shot;
use Closure;

/**
 * For jobs that draw or render from a shot's plan. The job remembers which
 * version of the plan it was queued for; once the director reopens the plan,
 * it is skipped, and a running one stops at its next check instead of
 * overwriting the new plan with work for the old one.
 */
trait FollowsPlan
{
    public ?int $planShotId = null;

    public int $planVersion = 0;

    protected function followPlan(Shot|Keyframe $model): void
    {
        $this->planShotId = $model instanceof Shot ? (int) $model->getKey() : (int) $model->shot_id;
        $this->planVersion = $model instanceof Shot
            ? (int) $model->plan_version
            : (int) Shot::query()->whereKey($this->planShotId)->value('plan_version');
    }

    /**
     * Whether the plan was reopened since the job was queued.
     */
    public function planReplaced(): bool
    {
        return $this->planShotId !== null
            && (int) Shot::query()->whereKey($this->planShotId)->value('plan_version') !== $this->planVersion;
    }

    /**
     * @return list<Closure(object, Closure): mixed>
     */
    public function middleware(): array
    {
        return [fn(object $job, Closure $next) => $this->planReplaced() ? null : $next($job)];
    }
}
