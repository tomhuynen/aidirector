<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Activities;

use App\Models\Activity;
use App\Models\Policies\ActivityPolicy;
use App\Tables\Admin\Activities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class IndexController
{
    public function index(Request $request)
    {
        Gate::authorize(ActivityPolicy::INDEX, Activity::class);

        return Inertia::render('activities/index', [
            'activities' => Activities::make(),
        ]);
    }
}
