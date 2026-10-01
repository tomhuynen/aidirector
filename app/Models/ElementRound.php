<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ElementRoundStatus;
use App\Enums\ElementType;
use App\Events\ElementRoundDeleting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * One round of cast and sets suggestions in the intake chat: a handful of people,
 * places or objects written from a brief the director confirmed, each
 * rendered in the project style for the director to pick from. A skipped
 * category is a round without suggestions.
 */
class ElementRound extends Model
{
    /** @use HasFactory<\Database\Factories\ElementRoundFactory> */
    use HasFactory;
    use HasSqids;
    use UsesTenantConnection;

    protected $guarded = [];

    /**
     * Suggestions are removed by a listener, never by the database.
     */
    protected $dispatchesEvents = [
        'deleting' => ElementRoundDeleting::class,
    ];

    /**
     * @return array{
     *  type: 'App\Enums\ElementType',
     *  status: 'App\Enums\ElementRoundStatus',
     * }
     */
    protected function casts(): array
    {
        return [
            'type' => ElementType::class,
            'status' => ElementRoundStatus::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ElementSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(ElementSuggestion::class)->orderBy('position');
    }

    /** @return MorphMany<Generation, $this> */
    public function generations(): MorphMany
    {
        return $this->morphMany(Generation::class, 'generatable');
    }
}
