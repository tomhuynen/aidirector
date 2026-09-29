<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Enums\ShotStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Shot extends Model
{
    /** @use HasFactory<\Database\Factories\ShotFactory> */
    use HasFactory;
    use HasSqids;
    use UsesTenantConnection;

    protected $guarded = [];

    protected $attributes = [
        'status' => ShotStatus::DRAFT->value,
    ];

    /**
     * @return array{
     *  status: 'App\Enums\ShotStatus',
     *  purpose_override: 'App\Enums\ProjectPurpose',
     *  aspect_ratio_override: 'App\Enums\AspectRatio',
     * }
     */
    protected function casts(): array
    {
        return [
            'status' => ShotStatus::class,
            'purpose_override' => ProjectPurpose::class,
            'aspect_ratio_override' => AspectRatio::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function purpose(): ProjectPurpose
    {
        return $this->purpose_override ?? $this->project->purpose;
    }

    public function aspectRatio(): AspectRatio
    {
        return $this->aspect_ratio_override ?? $this->project->aspect_ratio;
    }

    public function durationInSeconds(): int
    {
        return $this->duration ?? $this->project->default_duration;
    }
}
