<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;

class HomeController
{
    public function index()
    {
        return Inertia::render('home/index');
    }
}
