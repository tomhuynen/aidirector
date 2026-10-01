<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Element;
use Illuminate\Queue\SerializesModels;

class ElementDeleting
{
    use SerializesModels;

    public function __construct(public Element $element)
    {
        //
    }
}
