<?php

declare(strict_types=1);

namespace App\Support\Decisions;

use App\Enums\ProjectRuleStatus;
use App\Enums\ShotStatus;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Everything in a project that waits for the director, oldest first: a
 * planned shot to draw, a storyline to choose, keyframe 1 to pick, keyframes to review and render,
 * something that failed, or a learned rule to confirm. Worked out from the
 * state of the shots, so nothing is stored.
 */
class DecisionQueue
{
    public function __construct(private readonly ShotIssues $issues) {}

    public const PLAN = 'plan';

    public const FIRST_KEYFRAME = 'first-keyframe';

    public const RENDER = 'render';

    public const ATTENTION = 'attention';

    public const RULE = 'rule';

    /**
     * @return Collection<int, non-empty-array<string, mixed>>
     */
    public function for(Project $project): Collection
    {
        $shots = $project->shots()->with(['project', 'keyframes.media', 'keyframes.elements', 'media'])->get();

        $decisions = $shots->map(fn(Shot $shot) => $this->forShot($project, $shot))->filter();

        $rules = $project->rules()->where('status', ProjectRuleStatus::SUGGESTED)->get()->map(fn(ProjectRule $rule) => [
            'id' => "rule-{$rule->sqid}",
            'type' => self::RULE,
            'waitingSince' => $rule->created_at?->toIso8601String(),
            'rule' => [
                'text' => $rule->text,
                'acceptUrl' => route('public.projects.rules.accept', [$project, $rule]),
                'dismissUrl' => route('public.projects.rules.dismiss', [$project, $rule]),
            ],
        ]);

        return $decisions->concat($rules)->sortBy('waitingSince')->values();
    }

    public function count(Project $project): int
    {
        return $this->for($project)->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function forShot(Project $project, Shot $shot): ?array
    {
        $shot->setRelation('project', $project);
        $keyframes = $shot->keyframes->each->setRelation('shot', $shot);
        $failed = $keyframes->first(fn(Keyframe $keyframe) => filled($keyframe->render_error) && ! $keyframe->rendering);

        $type = match (true) {
            $shot->status === ShotStatus::DRAFT && filled($shot->storyline_error) => self::ATTENTION,
            $failed !== null => self::ATTENTION,
            $shot->status === ShotStatus::STORYLINE_READY && $keyframes->isEmpty() && $shot->storylineKeyframes() !== [] => self::PLAN,
            $shot->status === ShotStatus::FIRST_KEYFRAME_READY && ! (bool) $keyframes->first()?->rendering => self::FIRST_KEYFRAME,
            // Asked only once the keyframes are drawn and reviewed together, so the issues are known.
            $shot->status === ShotStatus::KEYFRAMES_READY
                && $shot->video() === null
                && $keyframes->isNotEmpty()
                && ! $keyframes->contains('rendering', true)
                && ! $shot->reviewing => self::RENDER,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $decision = [
            'id' => "{$type}-{$shot->sqid}",
            'type' => $type,
            'waitingSince' => $shot->updated_at?->toIso8601String(),
            'shot' => [
                'id' => $shot->sqid,
                'code' => sprintf('SH%03d', $shot->position * 10),
                'title' => $shot->title,
                'takeaway' => $shot->takeaway,
                'storyline' => $shot->chosenStoryline()['storyline'] ?? null,
                'voiceOver' => $shot->voice_over,
                'url' => route('public.shots.view', [$project, $shot]),
            ],
        ];

        return match ($type) {
            self::PLAN => [
                ...$decision,
                'plan' => array_map(fn(array $keyframe) => ['title' => $keyframe['title'], 'description' => $keyframe['description']], $shot->storylineKeyframes()),
                'drawUrl' => route('public.shots.keyframes.generate', [$project, $shot]),
            ],
            self::FIRST_KEYFRAME => $shot->getMedia(Shot::PLATE_OPTIONS)->isNotEmpty() && ! $shot->hasChosenPlate() ? [
                ...$decision,
                'options' => $shot->getMedia(Shot::PLATE_OPTIONS)->map(fn($media) => ['id' => (int) $media->id, 'imageUrl' => URL::temporarySignedRoute('public.media.view', now()->startOfHour()->addHours(3), ['media' => $media])])->values()->all(),
                'field' => 'plate',
                'chooseUrl' => route('public.shots.plate.choose', [$project, $shot]),
                'moreUrl' => route('public.shots.keyframes.first.more', [$project, $shot]),
            ] : [
                ...$decision,
                'options' => $this->firstOptions($project, $shot, $keyframes->first()),
                'field' => 'render',
                'chooseUrl' => route('public.shots.keyframes.first.choose', [$project, $shot]),
                'moreUrl' => route('public.shots.keyframes.first.more', [$project, $shot]),
            ],
            self::RENDER => [
                ...$decision,
                'keyframes' => $keyframes->map(fn(Keyframe $keyframe) => [
                    'title' => $keyframe->title,
                    'imageUrl' => $keyframe->render() === null ? null : $this->renderUrl($project, $shot, $keyframe, $keyframe->render()->id),
                ])->values()->all(),
                'issueGroups' => array_map(fn(array $group) => [
                    'key' => $group['key'],
                    'position' => $group['position'],
                    'issues' => $group['issues'],
                    'fixUrl' => $group['fixable'] ? route('public.shots.issues.fix', [$project, $shot, $group['key']]) : null,
                    'dismissUrl' => route('public.shots.issues.dismiss', [$project, $shot, $group['key']]),
                ], $this->issues->groups($shot, $keyframes)),
                'fixAllUrl' => $this->issues->hasFixable($shot, $keyframes) ? route('public.shots.issues.fix-all', [$project, $shot]) : null,
                'renderUrl' => route('public.shots.video.generate', [$project, $shot]),
            ],
            default => [
                ...$decision,
                'message' => $this->failureMessage($failed, (string) ($failed === null ? $shot->storyline_error : $failed->render_error)),
                'retryUrl' => route('public.shots.retry', [$project, $shot]),
            ],
        };
    }

    /**
     * Why something failed, in words the director can act on. Running out of
     * credits says so instead of showing the provider's raw reply.
     */
    private function failureMessage(?Keyframe $failed, string $error): string
    {
        if (str_contains($error, 'status code 402') || str_contains($error, 'Insufficient credits')) {
            $error = __('The AI provider is out of credits. Top up OpenRouter or raise the key limit, then try again.');
        }

        return $failed === null ? $error : __('Keyframe :n could not be drawn: :error', ['n' => $failed->position, 'error' => $error]);
    }

    /**
     * @return list<array{id: int, imageUrl: string}>
     */
    private function firstOptions(Project $project, Shot $shot, ?Keyframe $first): array
    {
        if ($first === null) {
            return [];
        }

        return $first->renders()->map(fn($render) => [
            'id' => (int) $render->id,
            'imageUrl' => $this->renderUrl($project, $shot, $first, (int) $render->id),
        ])->values()->all();
    }

    private function renderUrl(Project $project, Shot $shot, Keyframe $keyframe, int $render, ?string $conversion = null): string
    {
        return route('public.shots.keyframes.image', array_filter([
            'project' => $project,
            'shot' => $shot,
            'keyframe' => $keyframe,
            'conversion' => $conversion,
            'render' => $render,
        ]));
    }
}
