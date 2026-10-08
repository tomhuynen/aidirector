<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Ai\Agents\ProjectIntake;
use App\Enums\AspectRatio;
use App\Enums\CoverStatus;
use App\Enums\ElementType;
use App\Enums\ProjectPurpose;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Http\Requests\Public\ProjectChatRequest;
use App\Jobs\AnalyzePhoto;
use App\Jobs\GenerateProjectCover;
use App\Jobs\GenerateStoryline;
use App\Models\Director;
use App\Models\ElementRound;
use App\Models\Media;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\Upload;
use App\Support\Elements\ElementRoundState;
use App\Support\Elements\StartElementRound;
use App\Support\Intake\DocumentText;
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
    /** The most shots the intake creates in one go. */
    public const MAX_GENERATED_SHOTS = 30;

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

        // Documents are read into the conversation right away; photos need the project to exist.
        [$documents, $uploads] = $uploads->partition(fn(Upload $upload) => $upload->isDocument())->all();
        $documentNote = $this->readDocuments($documents);

        if ($uploads->isNotEmpty() && $project === null) {
            throw ValidationException::withMessages([
                'uploads' => __('Let’s settle the project title first, then add photos.'),
            ]);
        }

        if ($conversationId !== null) {
            abort_unless($director->conversations()->whereKey($conversationId)->exists(), 403);
        }

        $photos = $uploads->isEmpty() ? collect() : $this->addPhotos($project, $uploads);

        // The agent sees the project's photos, style and cast and sets status on every turn.
        $agent = new ProjectIntake($project);

        if ($conversationId !== null) {
            $agent->continue($conversationId, as: $director);
        } else {
            $agent->forUser($director);
        }
        $prompt = trim($this->prompt((string) $request->validated('message'), $photos) . "\n\n" . $documentNote);
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

        if ($done) {
            $this->createShots($project, (array) ($data['shots'] ?? []));
        }

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
            /** @var array{id: string, url: string, styleRoundsUrl: string, coverUrl: string, coverStatus: 'painting'|'ready'|'failed'|null}|null */
            'project' => $project === null ? null : [
                'id' => $project->sqid,
                'url' => route('public.projects.view', $project),
                'styleRoundsUrl' => route('public.projects.style.round', $project),
                'coverUrl' => route('public.projects.cover.view', $project),
                'coverStatus' => $project->cover_status?->value,
            ],
            /** @var array<int, array{id: string, caption: string|null}> */
            'photos' => $photos->map(fn(Media $media) => [
                'id' => $media->sqid,
                'caption' => $media->getCustomProperty(Project::CAPTION),
            ])->values()->all(),
        ]);
    }

    /**
     * The shots the director asked the intake to generate: each gets its
     * takeaway and context as its brief, and its storylines are suggested
     * straight away, as if the director had filled in the brief.
     *
     * @param  array<mixed>  $shots
     */
    private function createShots(Project $project, array $shots): void
    {
        $position = (int) ($project->allShots()->max('position') ?? 0);

        foreach (array_slice($shots, 0, self::MAX_GENERATED_SHOTS) as $brief) {
            $takeaway = trim((string) (is_array($brief) ? ($brief['takeaway'] ?? '') : ''));

            if ($takeaway === '') {
                continue;
            }

            $shot = $project->allShots()->create([
                'position' => ++$position,
                'title' => Str::limit($takeaway, 80),
                'takeaway' => $takeaway,
                'kind' => ShotKind::tryFrom((string) ($brief['kind'] ?? '')) ?? ShotKind::SCENE,
                'notes' => trim((string) ($brief['context'] ?? '')) ?: null,
                'status' => ShotStatus::STORYLINE_PENDING,
            ]);

            // Planned straight away by the kind chosen for it, drawn once the director starts it from the decisions.
            GenerateStoryline::dispatch($shot, keepKind: true);
        }
    }

    /**
     * The text of the shared documents, for the assistant to read. A document
     * that cannot be read is named, so the assistant can ask for it again.
     *
     * @param  Collection<int, Upload>  $documents
     */
    private function readDocuments(Collection $documents): string
    {
        return $documents->map(function (Upload $document): string {
            try {
                $text = app(DocumentText::class)->read($document);
            } catch (Throwable $exception) {
                report($exception);

                return "The director tried to share the document \"{$document->name}\", but it could not be read.";
            } finally {
                $document->delete();
            }

            return DocumentText::SHARED . " \"{$document->name}\":\n<<<\n{$text}\n>>>";
        })->join("\n\n");
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
     * Claim the uploads as content references and queue their analysis. The
     * turn does not wait for it: each photo gets its caption and the people,
     * places and objects in it in the background, in parallel.
     *
     * @param  Collection<int, Upload>  $uploads
     * @return Collection<int, Media>
     */
    private function addPhotos(Project $project, Collection $uploads): Collection
    {
        $photos = $this->claimUploads->toCollection($project, $uploads, Project::CONTENT_REFERENCES);

        $photos->each(fn(Media $photo) => AnalyzePhoto::dispatch($photo));

        return $photos;
    }

    /**
     * What the agent reads: the director's words plus a note on how many
     * photos were added. Their contents reach the agent through the project
     * knowledge once the background analysis is done.
     *
     * @param  Collection<int, Media>  $photos
     */
    private function prompt(string $message, Collection $photos): string
    {
        $message = trim($message);

        if ($photos->isEmpty()) {
            return $message;
        }

        $note = trans_choice('The director added :count photo. It is being analysed in the background.|The director added :count photos. They are being analysed in the background.', $photos->count(), ['count' => $photos->count()]);

        return $message === '' ? $note : $message . "\n\n" . $note;
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
     * Prepares, starts or skips the cast and sets categories the agent asked
     * for. Only once a style is pinned: suggestions are drawn in that style.
     *
     * Prepared categories render in the background until the chat reaches
     * them. A round for a settled category is an extra one the director
     * asked for at the end; a category with a round still waiting for a
     * pick does not get another.
     *
     * @param  array<string, mixed>  $data
     */
    private function elementAction(Project $project, array $data): ?ElementRound
    {
        if ($project->styleReference() === null) {
            return null;
        }

        if ((bool) ($data['skip_elements'] ?? false)) {
            foreach (ElementType::cases() as $type) {
                $this->startElementRound->skip($project, $type);
            }

            return null;
        }

        $skip = ElementType::tryFrom((string) ($data['skip'] ?? ''));

        if ($skip !== null) {
            $this->startElementRound->skip($project, $skip);
        }

        foreach ((array) ($data['prepare'] ?? []) as $prepared) {
            $type = is_array($prepared) ? ElementType::tryFrom((string) ($prepared['type'] ?? '')) : null;
            $brief = is_array($prepared) ? trim((string) ($prepared['brief'] ?? '')) : '';

            if ($type !== null && $brief !== '') {
                $this->startElementRound->prepare($project, $type, $brief);
            }
        }

        $request = $data['element_round'] ?? null;
        $type = is_array($request) ? ElementType::tryFrom((string) ($request['type'] ?? '')) : null;
        $brief = is_array($request) ? trim((string) ($request['brief'] ?? '')) : '';

        if ($type === null || $project->elementRounds()->where('type', $type)->get()->contains(fn(ElementRound $round) => $round->isOpen())) {
            return null;
        }

        $round = (bool) ($request['use_prepared'] ?? false) ? $this->startElementRound->present($project, $type) : null;

        if ($round === null && $brief !== '') {
            $round = $this->startElementRound->start($project, $type, $brief);
        }

        return $round?->setRelation('project', $project);
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

        // Rounds prepared in the background but never reached are of no use anymore.
        $project->elementRounds()->get()
            ->filter(fn(ElementRound $round) => $round->isPrepared())
            ->each(fn(ElementRound $round) => $round->delete());

        // With cast and sets picked, a group picture heads the project page.
        if ($project->elements()->exists()) {
            $project->forceFill(['cover_status' => CoverStatus::PAINTING])->save();
            GenerateProjectCover::dispatch($project);
        }

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
                'aspect_ratio' => AspectRatio::PORTRAIT,
            ]);

            Conversation::query()->whereKey($conversationId)->update(['title' => $title]);

            return $project;
        });
    }
}
