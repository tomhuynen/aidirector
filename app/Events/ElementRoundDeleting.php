<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ElementRound;
use Illuminate\Queue\SerializesModels;

class ElementRoundDeleting
{
    use SerializesModels;

    public function __construct(public ElementRound $round)
    {
        //
    }
}
