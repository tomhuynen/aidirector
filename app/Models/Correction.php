<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CorrectionKind;
use App\Enums\CorrectionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * One change the director made, or one mistake the keyframe check found,
 * labelled in the background as a correction or an instruction so recurring
 * corrections can become project rules.
 */
class Correction extends Model
{
    use UsesTenantConnection;

    protected $guarded = [];

    /**
     * @return array{
     *  source: 'App\Enums\CorrectionSource',
     *  kind: 'App\Enums\CorrectionKind',
     * }
     */
    protected function casts(): array
    {
        return [
            'source' => CorrectionSource::class,
            'kind' => CorrectionKind::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
