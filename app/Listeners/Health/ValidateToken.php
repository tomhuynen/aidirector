<?php

declare(strict_types=1);

namespace App\Listeners\Health;

use Illuminate\Foundation\Events\DiagnosingHealth;

class ValidateToken
{
    public function handle(DiagnosingHealth $event)
    {
        if (empty(config('blueprint.healthcheck.token'))) {
            return;
        }

        $token = request()->input('token');
        if (empty($token) || ! hash_equals(config('blueprint.healthcheck.token'), $token)) {
            abort(404, 'Token mismatch');
        }
    }
}
