<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Dashboard;

use App\Support\Widgets\UpdatesWidget;
use Inertia\Inertia;

class IndexController
{
    public function index()
    {
        return Inertia::render('dashboard/index', [
            'widgets' => [
                // UpdatesWidget::make(),
            ],
        ]);
    }
}
