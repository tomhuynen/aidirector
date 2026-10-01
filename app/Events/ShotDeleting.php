<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Shot;
use Illuminate\Queue\SerializesModels;

class ShotDeleting
{
    use SerializesModels;

    public function __construct(public Shot $shot)
    {
        //
    }
}
