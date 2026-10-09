<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * What the director decided about one finding of a reviewer: fixed, or left
 * as it is. Counted per reviewer, it shows how often each one is right.
 */
class ReviewerVerdict extends Model
{
    use UsesTenantConnection;

    /** The keyframe check, right after a keyframe is drawn. */
    public const CHECK = 'check';

    /** The review of all keyframes of a shot together. */
    public const REVIEW = 'review';

    /** The measurement of a background that moved. */
    public const DRIFT = 'drift';

    public const FIXED = 'fixed';

    public const DISMISSED = 'dismissed';

    /** Decided by answering in the chat. */
    public const VIA_CHAT = 'chat';

    /** Decided in the list of decisions. */
    public const VIA_DECISIONS = 'decisions';

    protected $guarded = [];

    /**
     * @return BelongsTo<Shot, $this>
     */
    public function shot(): BelongsTo
    {
        return $this->belongsTo(Shot::class);
    }
}
