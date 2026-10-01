<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Project;
use Illuminate\Queue\SerializesModels;

class ProjectDeleting
{
    use SerializesModels;

    public function __construct(public Project $project)
    {
        //
    }
}
