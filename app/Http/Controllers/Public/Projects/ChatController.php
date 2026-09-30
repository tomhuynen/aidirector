<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Ai\Agents\ProjectIntake;
use App\Http\Requests\Public\ProjectChatRequest;
use App\Models\Director;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * One turn of the project intake chat. The agent remembers the conversation
 * in the tenant database; once it has settled a title the project is created
 * and linked to that conversation.
 */
class ChatController
{
    public function store(ProjectChatRequest $request): JsonResponse
    {
        Gate::authorize(ProjectPolicy::CREATE, Project::class);

        /** @var Director $director */
        $director = $request->user('director');
        $conversationId = $request->validated('conversation');
        $model = Config::get('pipeline.models.text');

        $agent = new ProjectIntake();

        if ($conversationId !== null) {
            abort_unless($director->conversations()->whereKey($conversationId)->exists(), 403);

            $agent->continue($conversationId, as: $director);
        } else {
            $agent->forUser($director);
        }

        $started = hrtime(true);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt(
                $request->validated('message'),
                provider: 'openrouter',
                model: $model,
            );
        } catch (Throwable $exception) {
            $director->generations()->create([
                'director_id' => $director->id,
                'kind' => 'chat',
                'provider' => 'openrouter',
                'model' => $model,
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
                'error' => $exception->getMessage(),
            ]);

            report($exception);

            return response()->json([
                'message' => __('The director is unavailable right now. Please try again.'),
            ], 503);
        }

        $director->generations()->create([
            'director_id' => $director->id,
            'kind' => 'chat',
            'provider' => $response->meta->provider ?? 'openrouter',
            'model' => $response->meta->model ?? $model,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'usage' => $response->usage->toArray(),
        ]);

        /** @var string $conversationId */
        $conversationId = $response->conversationId;
        $data = $response->toArray();
        $project = $this->projectFor($director, $conversationId, $data['title'] ?? null);

        return response()->json([
            'conversation' => $conversationId,
            /** @var string */
            'reply' => (string) ($data['reply'] ?? ''),
            /** @var array{id: string, url: string}|null */
            'project' => $project === null ? null : [
                'id' => $project->sqid,
                'url' => route('public.projects.view', $project),
            ],
        ]);
    }

    /**
     * Create the project once the agent has settled a title. A conversation
     * creates at most one project; later turns return that same project.
     */
    private function projectFor(Director $director, string $conversationId, mixed $title): ?Project
    {
        $existing = Project::query()->where('conversation_id', $conversationId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $title = Str::limit(trim((string) $title), 120, '');

        if (mb_strlen($title) < 2) {
            return null;
        }

        return DB::connection((new Project())->getConnectionName())->transaction(function () use ($director, $conversationId, $title) {
            $project = Project::query()->create([
                'director_id' => $director->id,
                'conversation_id' => $conversationId,
                'title' => $title,
            ]);

            Conversation::query()->whereKey($conversationId)->update(['title' => $title]);

            return $project;
        });
    }
}
