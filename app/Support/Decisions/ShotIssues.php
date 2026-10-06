<?php

declare(strict_types=1);

namespace App\Support\Decisions;

use App\Ai\KeyframePainter;
use App\Jobs\GenerateKeyframeImage;
use App\Jobs\TweakKeyframeImage;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What the automatic checks found wrong with a shot before its video is
 * rendered, grouped by keyframe so the director can have each keyframe fixed
 * or its issues dismissed: the shot review's notes, the keyframe checks that
 * could not see what a keyframe must show, and a failed video. Issues that
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

            $base?->collection_name === Shot::PLATE
                ? TweakKeyframeImage::dispatch($keyframe, 'Nothing else: the people, their poses, where they look, their gestures and what they hold stay exactly as in the current version; only the background is the place\'s own again.' . ($others !== '' ? " Also solve this: {$others}" : ''), fromCheck: true)
                : GenerateKeyframeImage::dispatch($keyframe);
            $this->resolve($shot, $keyframes, $found);

            return;
        }

        TweakKeyframeImage::dispatch($keyframe, "This is keyframe {$keyframe->position}. A check found these problems with it:\n{$problems}\nChange only what this keyframe needs to solve them.", fromCheck: true, rewrite: true);

        $this->resolve($shot, $keyframes, $found);
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
     * The review's notes and the place check's findings that are not fixed or dismissed yet, in their order.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return list<string>
     */
    public function openNotes(Shot $shot, Collection $keyframes): array
    {
        $reviewIds = collect($this->reviewNotes($shot, $keyframes))->map(fn(array $note) => $this->reviewId($note['text']))->all();

        return collect($this->issues($shot, $keyframes))
            ->filter(fn(array $issue) => in_array($issue['id'], $reviewIds, true) || str_starts_with($issue['id'], 'place-') || str_starts_with($issue['id'], 'found-'))
            ->map(fn(array $issue) => str_starts_with($issue['id'], 'place-') || str_starts_with($issue['id'], 'found-') ? __('Keyframe :n: :text', ['n' => $issue['keyframes'][0] ?? '', 'text' => $issue['text']]) : $issue['text'])
            ->values()
            ->all();
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

        $this->resolve($shot, $keyframes, $this->find($shot, $keyframes, $group));
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

            $place = array_values(array_filter((array) $keyframe->render()?->getCustomProperty(Keyframe::PLACE_ISSUES, [])));

            if ($place !== []) {
                $issues[] = [
                    'id' => "place-{$keyframe->position}",
                    'text' => __('The place differs from keyframe 1: :what', ['what' => implode(' ', $place)]),
                    'problem' => 'The place must look exactly like in keyframe 1, and it does not yet: ' . implode(' ', $place),
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

            $warning = $keyframe->render()?->getCustomProperty(Keyframe::CHECK_WARNING);

            if (filled($warning)) {
                $issues[] = [
                    'id' => "check-{$keyframe->position}",
                    'text' => __('The check could not see that :what', ['what' => lcfirst((string) $warning)]),
                    'problem' => "It must clearly show this, and it does not yet: {$warning}",
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
     */
    private function resolve(Shot $shot, Collection $keyframes, array $group): void
    {
        $position = $group['position'];
        $resolved = [];

        foreach ($this->issues($shot, $keyframes) as $issue) {
            $belongs = $position === null ? $issue['keyframes'] === [] : in_array($position, $issue['keyframes'], true);

            if (! $belongs) {
                continue;
            }

            if ($issue['id'] === 'video') {
                $shot->video_error = null;
            } elseif (str_starts_with($issue['id'], 'check-')) {
                $keyframes->firstWhere('position', $position)?->render()?->forgetCustomProperty(Keyframe::CHECK_WARNING)->save();
            } elseif (str_starts_with($issue['id'], 'found-')) {
                $keyframes->firstWhere('position', $position)?->render()?->forgetCustomProperty(Keyframe::CHECK_ISSUES)->save();
            } elseif (str_starts_with($issue['id'], 'moved-')) {
                $keyframes->firstWhere('position', $position)?->render()?->forgetCustomProperty(Keyframe::BACKGROUND_MOVED)->save();
            } elseif (str_starts_with($issue['id'], 'place-')) {
                $keyframes->firstWhere('position', $position)?->render()?->forgetCustomProperty(Keyframe::PLACE_ISSUES)->save();
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
