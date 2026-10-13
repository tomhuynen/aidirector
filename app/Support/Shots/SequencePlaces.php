<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Ai\Agents\CameraBriefWriter;
use App\Enums\ShotStatus;
use App\Jobs\GenerateSequencePlaceOption;
use App\Jobs\MakeSequenceMaster;
use App\Models\Shot;
use App\Notifications\Public\GenerationFinished;
use App\Support\Images\BackgroundDrift;
use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * The places a sequence of shots plays in, chosen at the start, in the chat
 * of its first shot, one place after the other: the director plans the
 * shots in order afterwards, and every shot in a place draws its own view
 * of that same chosen picture, so no shot waits for a later one.
 */
class SequencePlaces
{
    /** The step in the chat while a place of the sequence waits to be chosen. */
    public const STEP = 'sequence_place';

    /** On a chosen place: where its fixed things stand, written once it is chosen. */
    public const MAP = 'map';

    /** On the storyline of a shot in a place of its sequence: what it leaves behind there, read from its last keyframe, with that render's id. */
    public const LEFT_BEHIND = 'left_behind';

    /** On the storyline of a shot with its own view: what its camera sees of the place, with the place and view it was written for. */
    public const CAMERA_BRIEF = 'camera_brief';

    /** On the storyline of the first shot of a new sequence: its places wait for the director's yes to the overview before they are drawn. */
    public const PLACES_WAIT = 'sequence_places_wait';

    /** On a chosen picture of a place: it is the master, with the cast where they stand at the start. */
    public const MASTER = 'master';

    /** On the empty place made from a master: where the cast stands in it, in words, and the box around them, as shares of the picture. */
    public const BLOCKING = 'blocking';

    public const CAST_BOX = 'cast_box';

    /**
     * The places of the sequence the shot opens, in the order they first appear.
     *
     * @return list<array{key: string, name: string, shots: list<string>, ideas: list<string>, elements: list<string>}>
     */
    public function places(Shot $owner): array
    {
        return array_values(array_filter((array) ($owner->storyline['sequence_places'] ?? []), is_array(...)));
    }

    /**
     * The first shot of the sequence, where its places are chosen.
     */
    public function ownerOf(Shot $shot): ?Shot
    {
        if ($shot->group_key === null) {
            return null;
        }

        return Shot::query()->where('project_id', $shot->project_id)->where('group_key', $shot->group_key)->orderBy('position')->get()
            ->first(fn(Shot $member) => $this->places($member) !== []);
    }

    /**
     * The chosen picture of a place of the sequence.
     */
    public function chosen(Shot $owner, string $key): ?Media
    {
        return $owner->getMedia(Shot::SEQUENCE_PLACES)->first(fn(Media $media) => $media->getCustomProperty('place') === $key && $media->getCustomProperty(self::MASTER) !== true);
    }

    /**
     * The master of a place of the sequence: the chosen picture with the cast where they stand at the start, which
     * the empty place is made from and every shot there is cut from; null for a place chosen before masters.
     */
    public function master(Shot $owner, string $key): ?Media
    {
        return $owner->getMedia(Shot::SEQUENCE_PLACES)->first(fn(Media $media) => $media->getCustomProperty('place') === $key && $media->getCustomProperty(self::MASTER) === true);
    }

    /**
     * The master of the place this shot plays in.
     */
    public function masterFor(Shot $shot): ?Media
    {
        $key = $shot->sequencePlaceKey();
        $owner = $key === null ? null : $this->ownerOf($shot);

        return $owner === null ? null : $this->master($owner, $key);
    }

    /**
     * Whether the picture of a place is picked: its master, or the place itself for one chosen before masters.
     */
    private function picked(Shot $owner, string $key): bool
    {
        return $this->chosen($owner, $key) !== null || $this->master($owner, $key) !== null;
    }

    /**
     * The chosen picture of the place this shot plays in.
     */
    public function chosenFor(Shot $shot): ?Media
    {
        $key = $shot->sequencePlaceKey();
        $owner = $key === null ? null : $this->ownerOf($shot);

        return $owner === null ? null : $this->chosen($owner, $key);
    }

    /**
     * The place this shot plays in, with its chosen picture and its map once
     * there are, and the part of it this shot shows.
     *
     * @return array{name: string, image: Media|null, map: string|null, view: string|null, brief: string|null, left: array{shot: string, text: string, image: Media|null}|null}|null
     */
    public function of(Shot $shot): ?array
    {
        $key = $shot->sequencePlaceKey();
        $owner = $key === null ? null : $this->ownerOf($shot);
        $place = $owner === null ? null : collect($this->places($owner))->firstWhere('key', $key);

        if ($place === null) {
            return null;
        }

        $image = $this->chosen($owner, $key);
        $map = trim((string) $image?->getCustomProperty(self::MAP));
        $left = $this->leftBefore($shot);
        $brief = $shot->storyline[self::CAMERA_BRIEF] ?? null;
        $brief = $image !== null && is_array($brief) && ($brief['for'] ?? null) === self::briefKey($image, $shot->sequenceView(), $left['text'] ?? null) ? (string) $brief['text'] : null;

        return ['name' => $place['name'], 'image' => $image, 'map' => $map === '' ? null : $map, 'view' => $shot->sequenceView(), 'brief' => $brief, 'left' => $left];
    }

    /**
     * Writes once what the camera of a shot with its own view sees of its
     * place, before that view is drawn: without it the image model copies
     * the camera of the chosen picture. Kept until the place or view change.
     */
    public function writeCameraBrief(Shot $shot): void
    {
        $place = $this->of($shot);

        if ($place === null || $place['image'] === null || $place['view'] === null || $place['brief'] !== null) {
            return;
        }

        $writer = new CameraBriefWriter($place['name'], $place['view'], $place['map'], $place['left']['text'] ?? null);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $writer->prompt($writer->promptFor(), attachments: [ImageFile::fromStorage($place['image']->getPathRelativeToRoot(), $place['image']->disk)], provider: 'openrouter', model: (string) Config::get('pipeline.models.text'));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $text = trim((string) ($response->toArray()['brief'] ?? ''));
        $key = self::briefKey($place['image'], $place['view'], $place['left']['text'] ?? null);

        if ($text !== '') {
            $shot->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), self::CAMERA_BRIEF => ['for' => $key, 'text' => $text]]);
        }
    }

    /**
     * Whether an image of the place a shot with its own view drew is still the
     * camera of the chosen picture: nearly the same view after a small shift
     * or zoom, while the shot asked for another camera position.
     */
    public function sameCamera(Shot $shot, Media $drawn): bool
    {
        $place = $this->of($shot);

        if ($place === null || $place['image'] === null || $place['view'] === null) {
            return false;
        }

        return app(BackgroundDrift::class)->measure($place['image'], $drawn, 1.01)['aligned'] >= (float) Config::get('pipeline.keyframes.sequence_view_same_camera');
    }

    private static function briefKey(Media $image, ?string $view, ?string $left = null): string
    {
        return $image->id . ':' . hash('xxh3', $view . '|' . $left);
    }

    /**
     * What the shot waits for while its place is not chosen yet, such as "the meeting-room corridor of the sequence".
     */
    public function waitsFor(Shot $shot): ?string
    {
        $place = $this->of($shot);

        if ($place === null) {
            return null;
        }

        if ($place['image'] === null) {
            return __(':place of the sequence', ['place' => $place['name']]);
        }

        // What the shot before in this place leaves behind, such as a landed helicopter, comes from its last keyframe.
        $before = $this->before($shot);

        return match (true) {
            $before === null => null,
            ! in_array($before->status, [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_PENDING, ShotStatus::VIDEO_READY], true) || $before->keyframes()->where('rendering', true)->exists() => __('the keyframes of :shot', ['shot' => $before->code()]),
            ! $this->leftBehindRead($before) => __('what :shot leaves behind', ['shot' => $before->code()]),
            default => null,
        };
    }

    /**
     * The shot before this one in the same place of its sequence, whose end it starts from.
     */
    public function before(Shot $shot): ?Shot
    {
        $key = $shot->sequencePlaceKey();

        if ($key === null || $shot->group_key === null) {
            return null;
        }

        return Shot::query()->where('project_id', $shot->project_id)->where('group_key', $shot->group_key)->where('position', '<', $shot->position)->orderByDesc('position')->get()
            ->first(fn(Shot $earlier) => $earlier->sequencePlaceKey() === $key);
    }

    /**
     * The later shots in the same place of the sequence that start from this one.
     *
     * @return Collection<int, Shot>
     */
    public function dependents(Shot $shot): Collection
    {
        if ($shot->sequencePlaceKey() === null || $shot->group_key === null) {
            return collect();
        }

        return Shot::query()->where('project_id', $shot->project_id)->where('group_key', $shot->group_key)->where('position', '>', $shot->position)->orderBy('position')->get()
            ->filter(fn(Shot $later) => $this->before($later)?->is($shot) ?? false)->values();
    }

    /**
     * What the shot before in this place left behind there, with its last keyframe; null when nothing.
     *
     * @return array{shot: string, text: string, image: Media|null}|null
     */
    private function leftBefore(Shot $shot): ?array
    {
        $before = $this->before($shot);
        $text = trim((string) ($before?->storyline[self::LEFT_BEHIND]['text'] ?? ''));

        if ($before === null || $text === '') {
            return null;
        }

        return ['shot' => $before->code(), 'text' => $text, 'image' => $before->keyframes()->with('media')->orderByDesc('position')->first()?->render()];
    }

    /**
     * Whether what the shot leaves behind was read from its current last keyframe.
     */
    private function leftBehindRead(Shot $shot): bool
    {
        $last = $shot->keyframes()->with('media')->orderByDesc('position')->first()?->render();

        return $last !== null && ($shot->storyline[self::LEFT_BEHIND]['for'] ?? null) === $last->id;
    }

    /**
     * The place that waits to be chosen next, in order.
     *
     * @return array{key: string, name: string, shots: list<string>, ideas: list<string>, elements: list<string>}|null
     */
    public function current(Shot $owner): ?array
    {
        return collect($this->places($owner))->first(fn(array $place) => ! $this->picked($owner, $place['key']));
    }

    /**
     * The options of a place.
     *
     * @return Collection<int, Media>
     */
    public function options(Shot $owner, string $key): Collection
    {
        return $owner->getMedia(Shot::SEQUENCE_PLACE_OPTIONS)->filter(fn(Media $media) => $media->getCustomProperty('place') === $key)->values();
    }

    /**
     * Whether the options of a place are being drawn.
     */
    public function drawing(Shot $owner): bool
    {
        return $this->drawingPlace($owner) !== null;
    }

    /**
     * The place whose options are being drawn, with how many are still to come, for the chat to show while it waits.
     *
     * @return array{name: string, shots: list<string>, count: int}|null
     */
    public function drawingPlace(Shot $owner): ?array
    {
        $key = $owner->storyline['sequence_places_drawing'] ?? null;
        $place = is_string($key) ? collect($this->places($owner))->firstWhere('key', $key) : null;

        if ($place === null) {
            return null;
        }

        // The ones of this round that are not there yet: the ones already drawn show as options.
        $ready = $this->options($owner, $place['key'])->count() - (int) ($owner->storyline['sequence_places_drawn'] ?? 0);

        return ['name' => $place['name'], 'shots' => $place['shots'], 'count' => max(0, max(1, (int) Config::get('pipeline.keyframes.plate_options')) - $ready)];
    }

    /**
     * What waits for the director's choice in this shot's chat: the options of
     * the next place of its sequence, once any are drawn, also while more are
     * drawn; null otherwise.
     *
     * @return array{step: string, options: Collection<int, Media>, place: array{key: string, name: string, shots: list<string>, ideas: list<string>, elements: list<string>}}|null
     */
    public function waiting(Shot $shot): ?array
    {
        $place = $this->current($shot);

        if ($place === null) {
            return null;
        }

        // While more are drawn, the ones there stay to choose from.
        $options = $this->options($shot, $place['key']);

        return $options->isEmpty() ? null : ['step' => self::STEP, 'options' => $options, 'place' => $place];
    }

    /**
     * Draws the options of the next place to choose, when it has none yet.
     */
    public function draw(Shot $owner): void
    {
        $place = $this->current($owner);

        if ($place !== null && $this->options($owner, $place['key'])->isEmpty()) {
            $this->drawRound($owner, $place);
        }
    }

    /**
     * Draws more options of the place that waits to be chosen.
     */
    public function more(Shot $owner): void
    {
        $place = $this->current($owner);

        if ($place === null || $this->drawing($owner)) {
            throw ValidationException::withMessages(['place' => __('Wait until the places are drawn.')]);
        }

        $this->drawRound($owner, $place);
    }

    /**
     * Chooses the picture of a place; the next place is drawn, or, when all are
     * chosen, the shots that waited for them are drawn. Returns what comes next,
     * for the chat: said here, or added to the reply when chosen in the chat;
     * and the chosen place, shown in the conversation where it was chosen.
     *
     * @return array{text: string, place: array{name: string, media: int}, all_chosen: bool}
     */
    public function choose(Shot $owner, Media $option, bool $say = true): array
    {
        $key = (string) $option->getCustomProperty('place');
        $place = collect($this->places($owner))->firstWhere('key', $key);

        if ($place === null || $this->picked($owner, $key)) {
            throw ValidationException::withMessages(['place' => __('That place is chosen already.')]);
        }

        // The chosen option is the master, with the cast where they start; the empty place every shot is cut from is made from it.
        $chosen = $option->copy($owner, Shot::SEQUENCE_PLACES);
        $chosen->setCustomProperty('place', $key)->setCustomProperty(self::MASTER, true)->save();
        MakeSequenceMaster::dispatch($owner, $key);
        $owner->load('media');

        $next = $this->current($owner);

        if ($next !== null) {
            $this->draw($owner);
            $text = __(':place is chosen. Now :next, for :shots; I am drawing it.', ['place' => ucfirst($place['name']), 'next' => $next['name'], 'shots' => implode(', ', $next['shots'])]);
        } else {
            Shot::drawWaitingShots($owner->project_id);
            $text = __(':place is chosen.', ['place' => ucfirst($place['name'])]);
        }

        if ($say) {
            $owner->say($text);
        }

        return ['text' => $text, 'place' => ['name' => $place['name'], 'media' => $chosen->id], 'all_chosen' => $next === null];
    }

    /**
     * Once a round of options is drawn or failed: the chat says the place is ready to choose.
     */
    public static function finish(int $ownerId, string $key): void
    {
        $owner = Shot::query()->find($ownerId);

        if ($owner === null) {
            return;
        }

        $owner->updateStoredJson('storyline', fn(?array $storyline) => array_diff_key($storyline ?? [], ['sequence_places_drawing' => true, 'sequence_places_drawn' => true]));
        $places = app(self::class);
        $place = collect($places->places($owner))->firstWhere('key', $key);
        $drawn = $places->options($owner->load('media'), $key)->isNotEmpty();
        $url = route('public.shots.view', [$owner->project, $owner]);

        // Chosen meanwhile from the ones already there: the extra ones are not offered.
        if ($place !== null && $places->picked($owner, $key)) {
            return;
        }

        if (! $drawn || $place === null) {
            $owner->say(__('The places could not be drawn. Ask me to try again.'));
            GenerationFinished::failed(__('The places for “:shot” could not be drawn', ['shot' => $owner->title]), $url)->sendTo($owner->project);

            return;
        }

        $owner->say(__('The options for :place are ready, for :shots. Click the one you like or tell me which one, or ask me for more.', ['place' => $place['name'], 'shots' => implode(', ', $place['shots'])]));
        GenerationFinished::ready(__('Places for the sequence of “:shot” are ready to choose from', ['shot' => $owner->title]), $url)->sendTo($owner->project);
    }

    /**
     * @param  array{key: string, name: string, shots: list<string>, ideas: list<string>, elements: list<string>}  $place
     */
    private function drawRound(Shot $owner, array $place): void
    {
        $drawn = $this->options($owner, $place['key'])->count();
        // With how many there were, so the chat shows only the ones still being drawn.
        $owner->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'sequence_places_drawing' => $place['key'], 'sequence_places_drawn' => $drawn]);
        $ownerId = $owner->id;
        $key = $place['key'];

        Bus::batch(collect(range(0, max(1, (int) Config::get('pipeline.keyframes.plate_options')) - 1))->map(fn(int $index) => new GenerateSequencePlaceOption($owner, $key, $drawn + $index))->all())
            ->name("Sequence places for shot {$owner->id}")
            ->onQueue(Config::get('pipeline.queue'))
            ->allowFailures()
            ->finally(static fn(Batch $batch) => self::finish($ownerId, $key))
            ->dispatch();
    }
}
