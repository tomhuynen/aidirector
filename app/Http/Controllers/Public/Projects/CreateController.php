<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Ai\Agents\ProjectIntake;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Support\Intake\IntakeThread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * A new project starts as a conversation with the director agent instead of
 * a form, and a project still in setup resumes that conversation. Editing
 * an existing project still uses the form (UpdateController).
 */
class CreateController
{
    public function view()
    {
        Gate::authorize(ProjectPolicy::CREATE, Project::class);

        return Inertia::render('projects/create', [
            /** @var string */
            'greeting' => ProjectIntake::greeting(),
            'chatUrl' => route('public.projects.chat'),
            'uploadUrl' => route('public.uploads.store'),
            'resume' => null,
        ]);
    }

    /**
     * Continue the intake chat of a project that has no style yet.
     */
    public function resume(Request $request, Project $project, IntakeThread $thread)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        if (! $project->needsSetup()) {
            return redirect()->route('public.projects.view', $project);
        }

        $state = $thread->for($project, $request);

        return Inertia::render('projects/create', [
            /** @var string */
            'greeting' => ProjectIntake::greeting(),
            'chatUrl' => route('public.projects.chat'),
            'uploadUrl' => route('public.uploads.store'),
            /** @var array{conversation: string, ask: string|null, project: array{id: string, url: string, styleRoundsUrl: string}, messages: array<int, array<string, mixed>>}|null */
            'resume' => [
                'conversation' => (string) $project->conversation_id,
                'ask' => $state['ask'],
                'project' => [
                    'id' => $project->sqid,
                    'url' => route('public.projects.view', $project),
                    'styleRoundsUrl' => route('public.projects.style.round', $project),
                ],
                'messages' => $state['messages'],
            ],
        ]);
    }
}
