<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Enums\ElementType;
use App\Enums\ProjectRuleStatus;
use App\Http\Resources\Public\ElementResource;
use App\Http\Resources\Public\ProjectResource;
use App\Http\Resources\Public\ShotListItemResource;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Support\Decisions\DecisionQueue;
use App\Support\Video\VideoFormats;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Locale;

class ViewController
{
    /**
     * The project overview: its style, cast and sets and shots, with the
     * way into the editor. A project still in setup goes back to its intake chat.
     */
    public function view(Project $project)
    {
        Gate::authorize(ProjectPolicy::VIEW, $project);

        if ($project->needsSetup()) {
            return redirect()->route('public.projects.setup', $project);
        }

        $project->load(['media', 'shots' => fn($shots) => $shots->with(['project', 'keyframes.media', 'parts.keyframes.media'])->withCount(['keyframes', 'parts'])]);

        return Inertia::render('projects/view', [
            'project' => fn() => ProjectResource::make($project),
            /** @var int How many decisions wait for the director in this project. */
            'decisionsCount' => fn() => app(DecisionQueue::class)->count($project),
            'shots' => fn() => ShotListItemResource::collection($project->shots),
            /** @var array{aspectRatios: array<int, array{value: string, name: string}>, resolutions: array<int, string>, sizes: array<string, array{width: int, height: int}>} */
            'videoFormats' => fn() => VideoFormats::catalogue(),
            /** @var array<int, array{value: string, label: string, plural: string}> */
            'elementTypes' => fn() => ElementType::catalogue(),
            /**
             * The languages a voice-over can be made in, by name.
             *
             * @var array<int, array{code: string, name: string}>
             */
            'voiceOverLanguages' => fn() => array_map(fn(string $code) => [
                'code' => $code,
                'name' => Locale::getDisplayName($code, 'en'),
            ], (array) Config::get('pipeline.voice_over.locales')),
            /**
             * Rules learned from recurring corrections: suggested ones to decide on, active ones in use.
             *
             * @var array<int, array{id: string, text: string, status: string, acceptUrl: string, dismissUrl: string}>
             */
            'rules' => fn() => $project->rules()
                ->whereIn('status', [ProjectRuleStatus::SUGGESTED, ProjectRuleStatus::ACTIVE])
                ->get()
                ->map(fn(ProjectRule $rule) => [
                    'id' => $rule->sqid,
                    'text' => $rule->text,
                    'status' => $rule->status->value,
                    'acceptUrl' => route('public.projects.rules.accept', [$project, $rule]),
                    'dismissUrl' => route('public.projects.rules.dismiss', [$project, $rule]),
                ])
                ->values()
                ->all(),
            'elements' => fn() => ElementResource::collection(
                $project->elements()->with('media')->get()->each->setRelation('project', $project)
            ),
        ]);
    }
}
