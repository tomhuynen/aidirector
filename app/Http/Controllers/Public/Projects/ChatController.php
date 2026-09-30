<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Ai\Agents\PhotoCaptioner;
use App\Ai\Agents\ProjectIntake;
use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Http\Requests\Public\ProjectChatRequest;
use App\Models\Director;
use App\Models\Media;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\Upload;
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

        $agent = new ProjectIntake();

        if ($conversationId !== null) {
            abort_unless($director->conversations()->whereKey($conversationId)->exists(), 403);

            $agent->continue($conversationId, as: $director);
        } else {
            $agent->forUser($director);
        }

        $photos = $uploads->isEmpty() ? collect() : $this->addPhotos($director, $project, $uploads);
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

        return response()->json([
            'conversation' => $conversationId,
            /** @var string */
            'reply' => (string) ($data['reply'] ?? ''),
            /** @var string|null */
            'ask' => $data['ask'] ?? null,
            /** @var bool */
            'done' => (bool) ($data['done'] ?? false),
            /** @var array{id: string, url: string}|null */
            'project' => $project === null ? null : [
                'id' => $project->sqid,
                'url' => route('public.projects.view', $project),
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
