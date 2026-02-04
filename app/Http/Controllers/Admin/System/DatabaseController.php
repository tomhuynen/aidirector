<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\System;

use App\Support\Admin\Server\Backup;
use App\Support\Admin\Server\Database;
use Inertia\Inertia;

class DatabaseController
{
    public function index()
    {
        return Inertia::render('system/database', [
            'databaseInfo' => fn() => new Database(),
            'backupInfo' => fn() => new Backup(),
        ]);
    }

    public function download(string $name)
    {
        $url = new Backup()->download($name);

        return redirect($url);
    }
}
