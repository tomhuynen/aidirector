<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Enums\ShotStatus;
use App\Http\Resources\Concerns\AuthorizesResource;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateVoiceOver;
use App\Models\Element;
use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Shot;
use App\Support\Decisions\ShotIssues;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Locale;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** @mixin Shot */
class ShotResource extends JsonResource
{
    use AuthorizesResource;
    /** @var Collection<int, Keyframe>|null */
    private ?Collection $checkedKeyframes = null;

    /** @var list<array{key: string, position: int|null, issues: list<string>, fixable: bool}>|null */
    private ?array $checkedGroups = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->sqid,
            /** @var int */
            'position' => $this->position,
            'title' => $this->title,
            'takeaway' => $this->takeaway,
            /** @var string|null */
            'notes' => $this->notes,
            /**
             * The ids of the cast and sets the storylines must use.
             *
             * @var array<int, string>
             */
            'preferredElements' => $this->preferredElementSqids(),
            'status' => $this->status,
            'statusLabel' => $this->status->description(),
            /** @var string|null */
            'purposeOverride' => $this->purpose_override,
            /** @var string|null */
            'aspectRatioOverride' => $this->aspect_ratio_override,
            /** @var int|null */
            'duration' => $this->duration,
            /**
             * The length the shot renders at: the director's, the planner's or the project default.
             *
             * @var int
             */
            'seconds' => $this->durationInSeconds(),
            /** @var array<int, array{title: string, storyline: string}>|null */
            'storylineOptions' => $this->storyline_options,
            /** @var array{title: string, storyline: string}|null */
            'chosenStoryline' => $this->chosen_storyline,
            /** @var array{keyframes: array<int, array{title: string, description: string, prompt?: string}>}|null */
            'storyline' => $this->storyline,
            /** @var string|null */
            'storylineError' => $this->storyline_error,
            /**
             * The review of all keyframes together, with only the notes not yet fixed or dismissed.
             *
             * @var array{clear: bool, notes: list<string>}|null
             */
            'keyframeReview' => $this->openReview(),
            /**
             * What the checks found, grouped per keyframe (position) or for the whole shot (position null), with what can be done about it.
             *
             * @var array<int, array{key: string, position: int|null, issues: array<int, string>, fixUrl: string|null, dismissUrl: string}>
             */
            'issueGroups' => $this->issueGroups(),
            /** Whether the keyframes are being reviewed together right now. */
            'reviewing' => (bool) $this->reviewing,
            /**
             * The empty places to choose the one every keyframe is drawn on.
             *
             * @var array<int, array{id: int, imageUrl: string}>
             */
            'plateOptions' => $this->resource->exists
                ? $this->getMedia(Shot::PLATE_OPTIONS)->map(fn(Media $media) => ['id' => $media->id, 'imageUrl' => (string) $this->mediaUrl($media)])->values()->all()
                : [],
            /**
             * What must always or never happen in this shot, set by the director.
             *
             * @var array<int, string>
             */
            'rules' => $this->resource->shotRules(),
            /** Whether a place is chosen and keyframe 1 is drawn on it. */
            'plateChosen' => $this->resource->exists && $this->resource->hasChosenPlate(),
            /** New drawings start from empty places to choose from. */
            'startsWithPlate' => (bool) config('pipeline.keyframes.start_with_plate'),
            /** @var int How many places one round draws. */
            'plateOptionCount' => max(1, (int) config('pipeline.keyframes.plate_options')),
            /**
             * Whether Fix all can redraw keyframes the checks found something wrong with.
             *
             * @var bool
             */
            'fixableIssues' => in_array($this->status, [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_READY], true)
                && collect($this->checkedGroups())->contains(fn(array $group) => $group['fixable'] && $group['position'] !== null),
            /** @var string|null */
            'voiceOver' => $this->voice_over,
            /**
             * How long the voice-over takes at a calm pace, in seconds.
             *
             * @var float|null
             */
            /**
             * The spoken track per voice-over language of the project; empty while the voice-over is off.
             *
             * @var array<int, array{locale: string, name: string, status: string, audioUrl: string|null, outdated: bool, error: string|null}>
             */
            'voiceOverTracks' => $this->voiceOverTracks(),
            'voiceOverSeconds' => $this->voice_over === null ? null : round(GenerateVoiceOver::words($this->voice_over) / GenerateVoiceOver::WORDS_PER_SECOND, 1),
            /** @var int */
            'firstKeyframeOptions' => GenerateKeyframes::optionCount(),
            /** @var int */
            'maxKeyframes' => (int) config('pipeline.keyframes.max_manual'),
            'videoResolution' => $this->videoResolution(),
            /** @var array<int, string> */
            'videoResolutions' => config('pipeline.video.resolutions'),
            /** @var string|null */
            'videoPrompt' => $this->video_prompt,
            /** @var string|null */
            'videoError' => $this->video_error,
            /** @var string|null */
            'videoUrl' => $this->mediaUrl($this->video()),
            /** @var string|null */
            'videoDownloadUrl' => $this->downloadUrl($this->video()),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
            'links' => $this->when($this->resource->exists, fn() => [
                'view' => route('public.shots.view', [$this->project, $this->resource]),
                'update' => route('public.shots.update', [$this->project, $this->resource]),
                'destroy' => route('public.shots.destroy', [$this->project, $this->resource]),
                'storylineSuggest' => route('public.shots.storyline.suggest', [$this->project, $this->resource]),
                'storylineChoose' => route('public.shots.storyline.choose', [$this->project, $this->resource]),
                'storylineGenerate' => route('public.shots.storyline.generate', [$this->project, $this->resource]),
                'storylineReopen' => route('public.shots.storyline.reopen', [$this->project, $this->resource]),
                'keyframesGenerate' => route('public.shots.keyframes.generate', [$this->project, $this->resource]),
                'keyframesStore' => route('public.shots.keyframes.store', [$this->project, $this->resource]),
                'keyframesReorder' => route('public.shots.keyframes.reorder', [$this->project, $this->resource]),
                'firstKeyframeChoose' => route('public.shots.keyframes.first.choose', [$this->project, $this->resource]),
                'firstKeyframeMore' => route('public.shots.keyframes.first.more', [$this->project, $this->resource]),
                'firstKeyframeAdjust' => route('public.shots.keyframes.first.adjust', [$this->project, $this->resource]),
                'videoGenerate' => route('public.shots.video.generate', [$this->project, $this->resource]),
                'plan' => route('public.shots.plan', [$this->project, $this->resource]),
                'plateChoose' => route('public.shots.plate.choose', [$this->project, $this->resource]),
                'plateReset' => route('public.shots.plate.reset', [$this->project, $this->resource]),
                'planChanges' => route('public.shots.plan.changes', [$this->project, $this->resource]),
                'planWrite' => route('public.shots.plan.write', [$this->project, $this->resource]),
                'issuesFixAll' => route('public.shots.issues.fix-all', [$this->project, $this->resource]),
                'issuesDismissAll' => route('public.shots.issues.dismiss-all', [$this->project, $this->resource]),
                'voiceOver' => route('public.shots.voice-over', [$this->project, $this->resource]),
                'voiceOverAudio' => route('public.shots.voice-over.audio', [$this->project, $this->resource]),
            ]),
            /** @var array<string, bool> */
            'can' => $this->when(! is_null($request->user()), fn() => $this->authorizations($request, ShotPolicy::abilities(ShotPolicy::CREATE)), []),
        ];
    }

    /**
     * A signed link to a private media file. The expiry is rounded to the hour
     * so the link stays the same while the page polls and the browser can cache it.
     */
    /**
     * A signed link that saves the clip under a readable name, such as "SH070 Quay Stop Line.mp4".
     */
    private function downloadUrl(?Media $media): ?string
    {
        if ($media === null) {
            return null;
        }

        $name = sprintf('SH%03d %s.%s', $this->position * 10, Str::of($this->title)->replaceMatches('/[^\pL\pN ._-]+/u', '')->trim()->limit(80, ''), pathinfo($media->file_name, PATHINFO_EXTENSION) ?: 'mp4');

        return URL::temporarySignedRoute('public.media.view', now()->startOfHour()->addHours(3), ['media' => $media, 'download' => $name]);
    }

    /**
     * @return list<array{locale: string, name: string, status: string, audioUrl: string|null, outdated: bool, error: string|null}>
     */
    private function voiceOverTracks(): array
    {
        if (! $this->resource->exists) {
            return [];
        }

        $media = $this->getMedia(Shot::VOICE_OVERS)->keyBy(fn(Media $item) => $item->getCustomProperty('locale'));
        $tracks = $this->voice_over_tracks ?? [];

        return array_map(function (string $locale) use ($media, $tracks) {
            $audio = $media->get($locale);
            $status = $tracks[$locale]['status'] ?? ($audio === null ? 'missing' : 'ready');

            return [
                'locale' => $locale,
                'name' => Locale::getDisplayName($locale, 'en'),
                'status' => $status,
                'audioUrl' => $status === 'pending' ? null : $this->mediaUrl($audio),
                'outdated' => $audio !== null && $audio->getCustomProperty('script') !== $this->voice_over,
                'error' => $status === 'failed' ? ($tracks[$locale]['error'] ?? null) : null,
            ];
        }, $this->project->settings->enabledLocales());
    }

    /**
     * @return array{clear: bool, notes: list<string>}|null
     */
    private function openReview(): ?array
    {
        if ($this->keyframe_review === null || ! $this->resource->exists) {
            return null;
        }

        $notes = app(ShotIssues::class)->openNotes($this->resource, $this->checkedKeyframes());

        return ['clear' => $notes === [], 'notes' => $notes];
    }

    /**
     * @return list<array{key: string, position: int|null, issues: list<string>, fixUrl: string|null, dismissUrl: string}>
     */
    private function issueGroups(): array
    {
        return array_map(fn(array $group) => [
            'key' => $group['key'],
            'position' => $group['position'],
            'issues' => $group['issues'],
            'fixUrl' => $group['fixable'] ? route('public.shots.issues.fix', [$this->project, $this->resource, $group['key']]) : null,
            'dismissUrl' => route('public.shots.issues.dismiss', [$this->project, $this->resource, $group['key']]),
        ], $this->checkedGroups());
    }

    /**
     * The shot's keyframes with their images, loaded once for everything the checks found.
     *
     * @return Collection<int, Keyframe>
     */
    private function checkedKeyframes(): Collection
    {
        return $this->checkedKeyframes ??= $this->resource->exists ? $this->keyframes()->with('media')->get() : new Collection();
    }

    /**
     * What the checks found per keyframe, worked out once per response.
     *
     * @return list<array{key: string, position: int|null, issues: list<string>, fixable: bool}>
     */
    private function checkedGroups(): array
    {
        return $this->checkedGroups ??= $this->resource->exists ? app(ShotIssues::class)->groups($this->resource, $this->checkedKeyframes()) : [];
    }

    private function mediaUrl(?Media $media): ?string
    {
        if ($media === null) {
            return null;
        }

        return URL::temporarySignedRoute('public.media.view', now()->startOfHour()->addHours(3), ['media' => $media]);
    }

    /**
     * @return array<int, string>
     */
    private function preferredElementSqids(): array
    {
        if (blank($this->preferred_elements) || ! $this->resource->exists) {
            return [];
        }

        return $this->preferredElements()->map(fn(Element $element) => $element->sqid)->values()->all();
    }
}
