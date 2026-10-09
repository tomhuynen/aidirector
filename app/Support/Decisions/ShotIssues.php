<?php

declare(strict_types=1);

namespace App\Support\Decisions;

use App\Ai\KeyframePainter;
use App\Jobs\GenerateKeyframeImage;
use App\Jobs\TweakKeyframeImage;
use App\Models\Keyframe;
use App\Models\ReviewerVerdict;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What the automatic checks found wrong with a shot before its video is
 * rendered, grouped by keyframe so the director can have each keyframe fixed
 * or its issues dismissed: the shot review's notes, what the keyframe checks
 * found, and a failed video. Issues that
 * name no keyframe form a group for the whole shot.
 *
 * A review note can name several keyframes. Fixing or dismissing it for one
 * keyframe is remembered under `resolved` in the review, so it stays listed
 * for the others.
 */
class ShotIssues
{
    /** The group for issues that name no keyframe. */
    public const SHOT = 'shot';

    public function __construct(private readonly KeyframePainter $painter) {}

    /**
     * @param  Collection<int, Keyframe>  $keyframes  the shot's keyframes, with their media
     * @return list<array{key: string, position: int|null, issues: list<string>, fixable: bool}>
     */
    public function groups(Shot $shot, Collection $keyframes): array
    {
        $issues = $this->issues($shot, $keyframes);
        $groups = [];

        foreach ($keyframes->sortBy('position') as $keyframe) {
            $texts = collect($issues)->filter(fn(array $issue) => in_array($keyframe->position, $issue['keyframes'], true))->pluck('text')->values()->all();

            if ($texts !== []) {
                $groups[] = ['key' => "keyframe-{$keyframe->position}", 'position' => $keyframe->position, 'issues' => $texts, 'fixable' => $keyframe->render() !== null];
            }
        }

        $general = collect($issues)->filter(fn(array $issue) => $issue['keyframes'] === [])->pluck('text')->values()->all();

        if ($general !== []) {
            $groups[] = ['key' => self::SHOT, 'position' => null, 'issues' => $general, 'fixable' => false];
        }

        return $groups;
    }

    /**
     * Redraw the keyframe, told everything the checks found wrong with it,
     * and take its issues off the list.
     */
    public function fix(Shot $shot, string $group): void
    {
        $keyframes = $shot->keyframes()->with('media')->get();
        $found = $this->find($shot, $keyframes, $group);
        $keyframe = $keyframes->firstWhere('position', $found['position']);

        if (! $found['fixable'] || $keyframe === null || $keyframe->rendering) {
            throw ValidationException::withMessages(['issue' => __('These issues cannot be fixed automatically.')]);
        }

        $problems = collect($this->issues($shot, $keyframes))
            ->filter(fn(array $issue) => in_array($keyframe->position, $issue['keyframes'], true))
            ->map(fn(array $issue) => '- ' . $issue['problem'])
            ->join("\n");

        $keyframe->forceFill(['rendering' => true, 'render_error' => null])->save();

        // A moved picture goes back onto its place as it is, keeping the people, poses and changes the director asked for.
        // Without a place it is drawn again from its plan.
        if ($keyframe->render()?->getCustomProperty(Keyframe::BACKGROUND_MOVED) === true) {
            $base = $this->painter->baseFor($keyframe, $keyframes->each->setRelation('shot', $shot));
            $others = collect($this->issues($shot, $keyframes))
                ->filter(fn(array $issue) => in_array($keyframe->position, $issue['keyframes'], true) && ! str_starts_with($issue['id'], 'moved-'))
                ->map(fn(array $issue) => $issue['problem'])
                ->join(' ');

            $base !== null && $this->painter->isPlace($base)
                ? TweakKeyframeImage::dispatch($keyframe, 'Nothing else: the people, their poses, where they look, their gestures and what they hold stay exactly as in the current version; only the background is the place\'s own again.' . ($others !== '' ? " Also solve this: {$others}" : ''), fromCheck: true)
                : GenerateKeyframeImage::dispatch($keyframe);
            $this->resolve($shot, $keyframes, $found, ReviewerVerdict::FIXED);

            return;
        }

        TweakKeyframeImage::dispatch($keyframe, "This is keyframe {$keyframe->position}. A check found these problems with it:\n{$problems}\nChange only what this keyframe needs to solve them.", fromCheck: true, rewrite: true);

        $this->resolve($shot, $keyframes, $found, ReviewerVerdict::FIXED);
    }

    /**
     * Redraw every keyframe that has issues, each once with all of its issues.
     * Issues that name no keyframe stay for the director.
     */
    public function fixAll(Shot $shot): void
    {
        $groups = collect($this->groups($shot, $shot->keyframes()->with('media')->get()))
            ->filter(fn(array $group) => $group['fixable'] && $group['position'] !== null);

        if ($groups->isEmpty()) {
            throw ValidationException::withMessages(['issue' => __('There is nothing to fix automatically.')]);
        }

        $groups->each(fn(array $group) => $this->fix($shot->fresh() ?? $shot, $group['key']));
    }

    /**
     * Take every issue off the list without changing anything else.
     */
    public function dismissAll(Shot $shot): void
    {
        $groups = $this->groups($shot, $shot->keyframes()->with('media')->get());

        if ($groups === []) {
            throw ValidationException::withMessages(['issue' => __('Those issues are no longer there.')]);
        }

        foreach ($groups as $group) {
            $this->dismiss($shot->fresh() ?? $shot, $group['key']);
        }
    }

    /**
     * Every finding that is not fixed or dismissed yet: the review's notes,
     * the keyframe checks' findings and a background that moved.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return list<array{id: string, text: string, problem: string, keyframes: list<int>}>
     */
    public function open(Shot $shot, Collection $keyframes): array
    {
        return array_values(array_filter($this->issues($shot, $keyframes), fn(array $issue) => $issue['id'] !== 'video'));
    }

    /**
     * Has the keyframes of findings the director agreed with in the chat
     * redrawn, each once with all of its findings, and takes those findings
     * off the list. A keyframe that is still being drawn keeps its findings.
     * Returns the keyframes that are redrawn and those that are busy.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @param  list<string>  $ids
     * @return array{fixed: list<int>, busy: list<int>}
     */
    public function fixInChat(Shot $shot, Collection $keyframes, array $ids): array
    {
        $issues = collect($this->open($shot, $keyframes))->filter(fn(array $issue) => in_array($issue['id'], $ids, true));
        $fixed = [];
        $busy = [];

        foreach ($issues->flatMap(fn(array $issue) => $issue['keyframes'])->unique()->sort() as $position) {
            $keyframe = $keyframes->firstWhere('position', $position);

            if ($keyframe === null || $keyframe->rendering || $keyframe->render() === null) {
                $busy[] = $position;

                continue;
            }

            $problems = $issues->filter(fn(array $issue) => in_array($position, $issue['keyframes'], true))->map(fn(array $issue) => '- ' . $issue['problem'])->join("\n");
            $keyframe->forceFill(['rendering' => true, 'render_error' => null])->save();
            TweakKeyframeImage::dispatch($keyframe, "This is keyframe {$position}. A check found these problems with it:\n{$problems}\nChange only what this keyframe needs to solve them.", fromCheck: true, rewrite: true);
            $fixed[] = $position;
        }

        // Findings of a busy keyframe stay open; the rest are done.
        $done = $issues->reject(fn(array $issue) => array_intersect($issue['keyframes'], $busy) !== [])->pluck('id')->all();
        $this->resolveIds($shot, $keyframes, $done, ReviewerVerdict::FIXED, ReviewerVerdict::VIA_CHAT);

        return ['fixed' => $fixed, 'busy' => $busy];
    }

    /**
     * Takes findings off the list by their id, with what the director decided about them.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @param  list<string>  $ids
     */
    public function resolveIds(Shot $shot, Collection $keyframes, array $ids, string $verdict, string $via): void
    {
        foreach (collect($this->open($shot, $keyframes))->filter(fn(array $issue) => in_array($issue['id'], $ids, true)) as $issue) {
            // One verdict per finding, also when it is about several keyframes.
            foreach ($issue['keyframes'] === [] ? [null] : $issue['keyframes'] as $index => $position) {
                $this->resolve($shot->fresh() ?? $shot, $keyframes, ['key' => '', 'position' => $position, 'issues' => [], 'fixable' => false], $verdict, $via, $issue['id'], record: $index === 0);
            }
        }
    }

    /**
     * Whether any keyframe has issues that Fix all can redraw.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     */
    public function hasFixable(Shot $shot, Collection $keyframes): bool
    {
        return collect($this->groups($shot, $keyframes))->contains(fn(array $group) => $group['fixable'] && $group['position'] !== null);
    }

    /**
     * Take the group's issues off the list without changing anything else.
     */
    public function dismiss(Shot $shot, string $group): void
    {
        $keyframes = $shot->keyframes()->with('media')->get();

        $this->resolve($shot, $keyframes, $this->find($shot, $keyframes, $group), ReviewerVerdict::DISMISSED);
    }

    /**
     * @param  Collection<int, Keyframe>  $keyframes
     * @return list<array{id: string, text: string, problem: string, keyframes: list<int>}>
     */
    private function issues(Shot $shot, Collection $keyframes): array
    {
        $issues = [];
        $resolved = (array) ($shot->keyframe_review['resolved'] ?? []);

        if (($shot->keyframe_review['clear'] ?? true) === false) {
            foreach ($this->reviewNotes($shot, $keyframes) as ['text' => $note, 'keyframes' => $named]) {
                $id = $this->reviewId($note);
                $done = (array) ($resolved[$id] ?? []);

                $open = $named === []
                    ? (in_array(0, $done, true) ? null : [])
                    : array_values(array_diff($named, $done));

                if ($open !== null && ($named === [] || $open !== [])) {
                    $issues[] = ['id' => $id, 'text' => $note, 'problem' => $note, 'keyframes' => $open];
                }
            }
        }

        foreach ($keyframes as $keyframe) {
            if ($keyframe->render()?->getCustomProperty(Keyframe::BACKGROUND_MOVED) === true) {
                $issues[] = [
                    'id' => "moved-{$keyframe->position}",
                    'text' => __('The background moved compared with the place, also after drawing it again.'),
                    'problem' => 'The background must stay exactly where it is in the place it is drawn on.',
                    'keyframes' => [$keyframe->position],
                ];
            }

            $found = array_values(array_filter((array) $keyframe->render()?->getCustomProperty(Keyframe::CHECK_ISSUES, [])));

            if ($found !== []) {
                $issues[] = [
                    'id' => "found-{$keyframe->position}",
                    'text' => __('The check found: :what', ['what' => implode(' ', $found)]),
                    'problem' => implode(' ', $found),
                    'keyframes' => [$keyframe->position],
                ];
            }
        }

        if (filled($shot->video_error)) {
            $issues[] = ['id' => 'video', 'text' => (string) $shot->video_error, 'problem' => (string) $shot->video_error, 'keyframes' => []];
        }

        return $issues;
    }

    /**
     * @param  Collection<int, Keyframe>  $keyframes
     * @param  array{key: string, position: int|null, issues: list<string>, fixable: bool}  $group
     * @param  string|null  $only  only this finding, instead of all of the group's
     * @param  bool  $record  whether to keep the director's verdict; once per finding
     */
    private function resolve(Shot $shot, Collection $keyframes, array $group, string $verdict, string $via = ReviewerVerdict::VIA_DECISIONS, ?string $only = null, bool $record = true): void
    {
        $position = $group['position'];
        $resolved = [];

        foreach ($this->issues($shot, $keyframes) as $issue) {
            $belongs = $position === null ? $issue['keyframes'] === [] : in_array($position, $issue['keyframes'], true);

            if (! $belongs || ($only !== null && $issue['id'] !== $only)) {
                continue;
            }

            // What the director decided, per reviewer, so it can be measured how often each one is right.
            if ($record && $issue['id'] !== 'video') {
                ReviewerVerdict::query()->create([
                    'shot_id' => $shot->id,
                    'keyframe' => $position,
                    'reviewer' => match (true) {
                        str_starts_with($issue['id'], 'found-') => ReviewerVerdict::CHECK,
                        str_starts_with($issue['id'], 'moved-') => ReviewerVerdict::DRIFT,
                        default => ReviewerVerdict::REVIEW,
                    },
                    'note' => $issue['text'],
                    'verdict' => $verdict,
                    'via' => $via,
                ]);
            }

            if ($issue['id'] === 'video') {
                $shot->video_error = null;
            } elseif (str_starts_with($issue['id'], 'found-')) {
                $keyframes->firstWhere('position', $position)?->render()?->forgetCustomProperty(Keyframe::CHECK_ISSUES)->save();
            } elseif (str_starts_with($issue['id'], 'moved-')) {
                $keyframes->firstWhere('position', $position)?->render()?->forgetCustomProperty(Keyframe::BACKGROUND_MOVED)->save();
            } else {
                $resolved[$issue['id']] = $position ?? 0;
            }
        }

        $shot->save();

        // Added to what is stored now, so a review that finishes meanwhile keeps these and these keep its notes.
        if ($resolved !== []) {
            $shot->updateStoredJson('keyframe_review', function (?array $review) use ($resolved) {
                if ($review === null) {
                    return null;
                }

                $stored = (array) ($review['resolved'] ?? []);

                foreach ($resolved as $id => $position) {
                    $stored[$id] = [...(array) ($stored[$id] ?? []), $position];
                }

                return [...$review, 'resolved' => $stored];
            });
        }
    }

    /**
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{key: string, position: int|null, issues: list<string>, fixable: bool}
     */
    private function find(Shot $shot, Collection $keyframes, string $group): array
    {
        return collect($this->groups($shot, $keyframes))->firstWhere('key', $group)
            ?? throw ValidationException::withMessages(['issue' => __('Those issues are no longer there.')]);
    }

    /**
     * The review's notes with the keyframes each one is about. The reviewer
     * names them; reviews from before that only name them in the sentence.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return list<array{text: string, keyframes: list<int>}>
     */
    private function reviewNotes(Shot $shot, Collection $keyframes): array
    {
        return collect((array) ($shot->keyframe_review['notes'] ?? []))
            ->map(fn(mixed $note) => is_array($note)
                ? ['text' => (string) ($note['text'] ?? ''), 'keyframes' => $this->toChange(array_map('intval', (array) ($note['keyframes'] ?? [])), $keyframes)]
                : ['text' => (string) $note, 'keyframes' => $this->positionsIn((string) $note, $keyframes)])
            ->filter(fn(array $note) => $note['text'] !== '')
            ->values()
            ->all();
    }

    private function reviewId(string $note): string
    {
        return substr(hash('sha256', $note), 0, 10);
    }

    /**
     * The keyframes a review note names, such as "keyframe 3", "keyframes 3 and 4"
     * or "keyframes 1 through 4".
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return list<int>
     */
    private function positionsIn(string $note, Collection $keyframes): array
    {
        $positions = [];

        preg_match_all('/keyframes?\s+((?:\d+|\s|,|and|through|to|-|–)+)/i', $note, $mentions);

        foreach ($mentions[1] as $mention) {
            preg_match_all('/(\d+)\s*(?:through|to|-|–)\s*(\d+)|(\d+)/i', $mention, $parts, PREG_SET_ORDER);

            foreach ($parts as $part) {
                if (($part[3] ?? '') !== '') {
                    $positions[] = (int) $part[3];
                } else {
                    array_push($positions, ...range((int) $part[1], (int) $part[2]));
                }
            }
        }

        return $this->toChange($positions, $keyframes);
    }

    /**
     * The existing keyframes among those a note names that have to change.
     * Keyframe 1 is what the others are drawn to match: when a note names it
     * with others, the others change.
     *
     * @param  list<int>  $positions
     * @param  Collection<int, Keyframe>  $keyframes
     * @return list<int>
     */
    private function toChange(array $positions, Collection $keyframes): array
    {
        $existing = $keyframes->pluck('position')->all();
        $named = array_values(array_unique(array_filter($positions, fn(int $position) => in_array($position, $existing, true))));

        return count($named) > 1 ? array_values(array_diff($named, [1])) : $named;
    }
}
