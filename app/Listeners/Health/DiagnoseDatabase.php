<?php

declare(strict_types=1);

namespace App\Listeners\Health;

use App\Exceptions\HealthException;
use Exception;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

class DiagnoseDatabase
{
    public function handle(DiagnosingHealth $event)
    {
        try {
            DB::table('tenants')
                ->limit(1)
                ->first();
        } catch (Exception $e) {
            $message = $e->getMessage();
            $type = $e::class;

            throw new HealthException('Database: (' . $type . '): ' . $message, 0, $e);
        }
    }
}
