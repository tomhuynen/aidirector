<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\StyleOption;
use Illuminate\Queue\SerializesModels;

class StyleOptionDeleting
{
    use SerializesModels;

    public function __construct(public StyleOption $option)
    {
        //
    }
}
