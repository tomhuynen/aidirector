<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\StorylineFollower;
use App\Models\Keyframe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Brings the shot's storyline in line with a keyframe whose description the
 * director changed, so the review does not judge the keyframe by the old story.
 */
#[DeleteWhenMissingModels]
class FollowStoryline implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly Keyframe $keyframe,
        public readonly string $before,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        self::follow($this->keyframe, $this->before);
    }

    /**
     * Rewrites the storyline as little as the new description asks. A failure
     * leaves the storyline as it was; it never holds up the keyframe.
     */
    public static function follow(Keyframe $keyframe, string $before): void
    {
        $shot = $keyframe->shot()->with('project')->firstOrFail();
        $storyline = trim((string) ($shot->chosen_storyline['storyline'] ?? ''));
        $after = $keyframe->fullDescription();

        if ($storyline === '' || trim($before) === trim($after)) {
            return;
        }

        $follower = new StorylineFollower($storyline, $keyframe->position, $before, $after);
        $model = (string) Config::get('pipeline.models.text');

        try {
            /** @var StructuredAgentResponse $response */
            $response = $follower->prompt($follower->promptFor(), provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $shot->generations()->create([
            'director_id' => $shot->project->director_id,
            'kind' => 'text',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'prompt' => $follower->promptFor(),
            'usage' => $response->usage->toArray(),
        ]);

        $followed = trim((string) ($response->toArray()['storyline'] ?? ''));

        if ($followed !== '' && $followed !== $storyline) {
            $shot->updateStoredJson('chosen_storyline', fn(?array $chosen) => [...($chosen ?? []), 'storyline' => $followed]);
        }
    }
}
