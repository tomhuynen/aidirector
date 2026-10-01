<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Ai\Agents\PhotoCaptioner;
use App\Ai\Agents\ProjectIntake;
use App\Enums\AspectRatio;
use App\Enums\ElementType;
use App\Enums\ProjectPurpose;
use App\Http\Requests\Public\ProjectChatRequest;
use App\Jobs\AnalyzePhoto;
use App\Models\Director;
use App\Models\ElementRound;
use App\Models\Media;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\Upload;
use App\Support\Elements\ElementRoundState;
use App\Support\Elements\StartElementRound;
use App\Support\Media\ClaimUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * One turn of the project intake chat. The agent remembers the conversation
 * in the tenant database; once it has settled the description, purpose and
 * title the project is created and linked to that conversation. Photos added
 * to a turn are claimed into the project's content references, captioned,
 * and handed to the agent as text.
 */
class ChatController
{
    public function __construct(
        private readonly ClaimUploads $claimUploads,
        private readonly StartElementRound $startElementRound,
        private readonly ElementRoundState $elementRoundState,
    ) {}

    public function store(ProjectChatRequest $request): JsonResponse
    {
        Gate::authorize(ProjectPolicy::CREATE, Project::class);

        /** @var Director $director */
        $director = $request->user('director');
        $conversationId = $request->validated('conversation');
        $uploads = $this->uploads($request->uploadIds());
        $project = $conversationId === null ? null : Project::query()->where('conversation_id', $conversationId)->first();

        if ($uploads->isNotEmpty() && $project === null) {
            throw ValidationException::withMessages([
                'uploads' => __('Let’s settle the project title first, then add photos.'),
            ]);
        }

        if ($conversationId !== null) {
            abort_unless($director->conversations()->whereKey($conversationId)->exists(), 403);
        }

        $photos = $uploads->isEmpty() ? collect() : $this->addPhotos($director, $project, $uploads);

        // The agent sees the project's photos, style and cast and sets status on every turn.
        $agent = new ProjectIntake($project);

        if ($conversationId !== null) {
            $agent->continue($conversationId, as: $director);
        } else {
            $agent->forUser($director);
        }
        $prompt = $this->prompt((string) $request->validated('message'), $photos);
        $model = Config::get('pipeline.models.text');
        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt($prompt, provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            $this->logGeneration($director, 'chat', $model, $started, error: $exception->getMessage());

            report($exception);

            return response()->json([
                'message' => __('The director is unavailable right now. Please try again.'),
            ], 503);
        }

        $this->logGeneration($director, 'chat', $response->meta->model ?? $model, $started, usage: $response->usage->toArray());

        /** @var string $conversationId */
        $conversationId = $response->conversationId;
        $data = $response->toArray();
        $project = $this->projectFor($director, $conversationId, $data);
        $round = $project === null ? null : $this->elementAction($project, $data);
        $done = $project !== null && (bool) ($data['done'] ?? false) && $this->completeSetup($project);

        return response()->json([
            'conversation' => $conversationId,
            /** @var string */
            'reply' => (string) ($data['reply'] ?? ''),
            /** @var string|null */
            'ask' => $data['ask'] ?? null,
            /** @var bool */
            'done' => $done,
            /** @var array{id: string, type: 'person'|'place'|'object', label: string, status: 'suggesting'|'ready'|'picked'|'skipped'|'failed', error: string|null, pollUrl: string, pickUrl: string, options: array<int, array{id: string, name: string, description: string, status: 'pending'|'ready'|'failed', picked: bool, fromPhoto: bool, thumbnailUrl: string|null, imageUrl: string|null}>}|null */
            'elementRound' => $round === null ? null : $this->elementRoundState->for($round),
            /** @var array{id: string, url: string, styleRoundsUrl: string}|null */
            'project' => $project === null ? null : [
                'id' => $project->sqid,
                'url' => route('public.projects.view', $project),
                'styleRoundsUrl' => route('public.projects.style.round', $project),
            ],
            /** @var array<int, array{id: string, caption: string|null}> */
            'photos' => $photos->map(fn(Media $media) => [
                'id' => $media->sqid,
                'caption' => $media->getCustomProperty(Project::CAPTION),
            ])->values()->all(),
        ]);
    }

    /**
     * The staging uploads named in the request, in the order they were given.
     *
     * @param  list<string>  $ids
     * @return Collection<int, Upload>
     */
    private function uploads(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Upload::query()->whereSqidIn('id', $ids)->get()
            ->sortBy(fn(Upload $upload) => array_search($upload->sqid, $ids, true))
            ->values();
    }

    /**
     * Claim the uploads as content references and caption them so the intake
     * agent can reason about them as text. A failing captioner does not fail
     * the turn: the photos are kept, without captions.
     *
     * @param  Collection<int, Upload>  $uploads
     * @return Collection<int, Media>
     */
    private function addPhotos(Director $director, Project $project, Collection $uploads): Collection
    {
        $photos = $this->claimUploads->toCollection($project, $uploads, Project::CONTENT_REFERENCES);

        // What each photo shows is worked out in the background for the cast and sets stage.
        $photos->each(fn(Media $photo) => AnalyzePhoto::dispatch($photo));
        $captioner = new PhotoCaptioner($photos, (string) $project->description);
        $model = Config::get('pipeline.models.text');
        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $captioner->prompt($captioner->promptText(), attachments: $captioner->attachments(), provider: 'openrouter', model: $model);
        } catch (Throwable $exception) {
            $this->logGeneration($director, 'caption', $model, $started, error: $exception->getMessage());

            report($exception);

            return $photos;
        }

        $this->logGeneration($director, 'caption', $response->meta->model ?? $model, $started, usage: $response->usage->toArray());

        $captions = collect($response->toArray()['photos'] ?? [])->pluck('caption');

        return $photos->each(function (Media $media, int $index) use ($captions) {
            $caption = trim((string) ($captions[$index] ?? ''));

            if ($caption !== '') {
                $media->setCustomProperty(Project::CAPTION, $caption)->save();
            }
        });
    }

    /**
     * What the agent reads: the director's words plus a numbered list of
     * the photos added this turn.
     *
     * @param  Collection<int, Media>  $photos
     */
    private function prompt(string $message, Collection $photos): string
    {
        $message = trim($message);

        if ($photos->isEmpty()) {
            return $message;
        }

        $list = $photos
            ->map(fn(Media $media, int $index) => ($index + 1) . '. ' . ($media->getCustomProperty(Project::CAPTION) ?? __('(no description available)')))
            ->join("\n");

        $notes = trans_choice('The director added :count photo:|The director added :count photos:', $photos->count(), ['count' => $photos->count()]) . "\n" . $list;

        return $message === '' ? $notes : $message . "\n\n" . $notes;
    }

    /**
     * @param  array<string, mixed>|null  $usage
     */
    private function logGeneration(Director $director, string $kind, string $model, int $started, ?array $usage = null, ?string $error = null): void
    {
        $director->generations()->create([
            'director_id' => $director->id,
            'kind' => $kind,
            'provider' => 'openrouter',
            'model' => $model,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $usage,
            'error' => $error,
        ]);
    }

    /**
     * Starts or skips the cast and sets category the agent asked for. Only
     * once a style is pinned: suggestions are drawn in that style.
     *
     * @param  array<string, mixed>  $data
     */
    private function elementAction(Project $project, array $data): ?ElementRound
    {
        if ($project->styleReference() === null) {
            return null;
        }

        $skip = ElementType::tryFrom((string) ($data['skip'] ?? ''));

        if ($skip !== null) {
            $this->startElementRound->skip($project, $skip);
        }

        $request = $data['element_round'] ?? null;
        $type = is_array($request) ? ElementType::tryFrom((string) ($request['type'] ?? '')) : null;
        $brief = is_array($request) ? trim((string) ($request['brief'] ?? '')) : '';

        if ($type === null || $brief === '' || in_array($type, $project->settledElementTypes(), true)) {
            return null;
        }

        return $this->startElementRound->start($project, $type, $brief)->setRelation('project', $project);
    }

    /**
     * Finishes the intake when the agent says it is done and the project
     * really is: style pinned and every cast and sets category settled.
     */
    private function completeSetup(Project $project): bool
    {
        if ($project->setup_completed_at !== null) {
            return true;
        }

        if (! $project->canCompleteSetup()) {
            return false;
        }

        $project->forceFill(['setup_completed_at' => now()])->save();

        return true;
    }

    /**
     * Create the project once the agent has settled all three fields. A
     * conversation creates at most one project; later turns return that
     * same project.
     *
     * @param  array<string, mixed>  $data
     */
    private function projectFor(Director $director, string $conversationId, array $data): ?Project
    {
        $existing = Project::query()->where('conversation_id', $conversationId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $title = Str::limit(trim((string) ($data['title'] ?? '')), 120, '');
        $description = Str::limit(trim((string) ($data['description'] ?? '')), 2000, '');
        $purpose = ProjectPurpose::coerce($data['purpose'] ?? null);

        if (mb_strlen($title) < 2 || $description === '' || $purpose === null) {
            return null;
        }

        return DB::connection((new Project())->getConnectionName())->transaction(function () use ($director, $conversationId, $title, $description, $purpose) {
            $project = Project::query()->create([
                'director_id' => $director->id,
                'conversation_id' => $conversationId,
                'title' => $title,
                'description' => $description,
                'purpose' => $purpose,
                'aspect_ratio' => $purpose === ProjectPurpose::SOCIAL_SHORT ? AspectRatio::PORTRAIT : AspectRatio::LANDSCAPE,
            ]);

            Conversation::query()->whereKey($conversationId)->update(['title' => $title]);

            return $project;
        });
    }
}
