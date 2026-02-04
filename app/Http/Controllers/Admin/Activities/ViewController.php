<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Activities;

use App\Http\Resources\Admin\ActivityResource;
use App\Models\Activity;
use App\Models\Policies\ActivityPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ViewController
{
    public function view(Request $request, Activity $activity)
    {
        Gate::authorize(ActivityPolicy::VIEW, $activity);

        return Inertia::modal('activities/view', [
            'activity' => ActivityResource::make($activity),
            'links' => [
                'previous' => $this->resolveRoute($activity->previous()),
                'next' => $this->resolveRoute($activity->next()),
            ],
        ])->baseRoute('admin.activities.index');
    }

    private function resolveRoute(?Activity $activity = null): ?string
    {
        if (! $activity) {
            return null;
        }

        return route('admin.activities.view', $activity);
    }
}
