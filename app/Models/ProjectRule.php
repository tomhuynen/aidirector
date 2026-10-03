<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectRuleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * A rule learned from corrections that kept coming back in a project, such as
 * "people stand within two steps of the hazard". Suggested first; once the
 * director confirms it, every new plan, image and check follows it.
 */
class ProjectRule extends Model
{
    use HasSqids;
    use UsesTenantConnection;

    protected $guarded = [];

    /**
     * @return array{status: 'App\Enums\ProjectRuleStatus'}
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectRuleStatus::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
