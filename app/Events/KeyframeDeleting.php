<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Keyframe;
use Illuminate\Queue\SerializesModels;

class KeyframeDeleting
{
    use SerializesModels;

    public function __construct(public Keyframe $keyframe)
    {
        //
    }
}
