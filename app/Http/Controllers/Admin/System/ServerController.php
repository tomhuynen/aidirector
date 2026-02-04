<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\System;

use App\Support\Admin\Server\Configuration;
use App\Support\Admin\Server\Health;
use App\Support\Admin\Server\Load;
use App\Support\Admin\Server\OsInternet;
use App\Support\Admin\Server\Php;
use App\Support\Admin\Server\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class ServerController
{
    public function index(Request $request)
    {
        return Inertia::render('system/server', [
            'configuration' => fn() => new Configuration()->toArray(),
            'health' => fn() => new Health()->toArray(),
            'osInternet' => Inertia::defer(fn() => new OsInternet()->toArray()),
            'php' => Inertia::defer(fn() => new Php()->toArray()),
            'storage' => Inertia::defer(fn() => new Storage()->toArray()),
            'load' => Inertia::defer(fn() => new Load()->toArray()),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'action' => ['required', 'string', Rule::in(['resetOpcache'])],
        ]);

        return match ($request->input('action')) {
            'resetOpcache' => $this->resetOpcache(),
            default => new BadRequestException(),
        };
    }

    private function resetOpcache()
    {
        if (opcache_reset()) {
            Artisan::call('cache:clear');

            return response()->noContent();
        }
    }
}
