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
 *
 * A round can be prepared in the background before the chat reaches its
 * category; it is only part of the thread once it is presented.
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
     *  presented_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'type' => ElementType::class,
            'status' => ElementRoundStatus::class,
            'presented_at' => 'datetime',
        ];
    }

    /**
     * Whether the round was prepared in the background and not shown in the chat yet.
     */
    public function isPrepared(): bool
    {
        return $this->presented_at === null && $this->status !== ElementRoundStatus::SKIPPED;
    }

    /**
     * Whether the round is shown in the chat and still waiting for the director to pick.
     */
    public function isOpen(): bool
    {
        return $this->presented_at !== null && in_array($this->status, [ElementRoundStatus::SUGGESTING, ElementRoundStatus::READY], true);
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
