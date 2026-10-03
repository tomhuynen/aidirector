<?php

declare(strict_types=1);

use App\Ai\Agents\CorrectionClassifier;
use App\Ai\Agents\StorylineWriter;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframeReferences;
use App\Enums\CorrectionKind;
use App\Enums\CorrectionSource;
use App\Enums\Disk;
use App\Enums\ProjectRuleStatus;
use App\Enums\ShotStatus;
use App\Jobs\ClassifyCorrection;
use App\Models\Correction;
use App\Models\Director;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Shot;
use App\Support\Corrections\RecordCorrection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
    Config::set('pipeline.rules.enabled', true);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function readyShot(Project $project, int $position = 1): Shot
{
    $shot = Shot::factory()->for($project)->create([
        'position' => $position,
        'status' => ShotStatus::KEYFRAMES_READY,
        'storyline' => ['keyframes' => [
            ['title' => 'Arrive', 'description' => 'She walks up.', 'prompt' => 'She walks up.'],
            ['title' => 'Stop', 'description' => 'She stops at the line.', 'prompt' => 'She stops at the line.', 'must_show' => 'Both feet behind the line.'],
        ]],
    ]);

    Keyframe::factory()->for($shot)->create(['position' => 1, 'title' => 'Arrive', 'description' => 'She walks up.']);
    Keyframe::factory()->for($shot)->create(['position' => 2, 'title' => 'Stop', 'description' => 'She stops at the line.']);

    return $shot;
}

describe('recording', function () {
    it('keeps storyline feedback and has it labelled in the background', function () {
        Queue::fake();
        $shot = Shot::factory()->for($this->project)->create(['status' => ShotStatus::OPTIONS_READY, 'storyline_options' => [['title' => 'Gate', 'storyline' => 'At the gate.']]]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.storyline.suggest', [$this->project, $shot]), ['feedback' => 'Show the consequence, not just the rule']);

        $correction = Correction::query()->sole();

        expect($correction)->source->toBe(CorrectionSource::FEEDBACK)
            ->request->toBe('Show the consequence, not just the rule')
            ->shot_id->toBe($shot->id)
            ->kind->toBeNull();
        Queue::assertPushed(ClassifyCorrection::class, fn(ClassifyCorrection $job) => $job->correction->is($correction));
    });

    it('keeps a deleted keyframe and a rewritten description', function () {
        Queue::fake();
        $shot = readyShot($this->project);
        [$first, $second] = $shot->keyframes()->get();
        Keyframe::factory()->for($shot)->create(['position' => 3]);

        actingAs($this->director, 'director')
            ->post(route('public.shots.keyframes.update', [$this->project, $shot, $first]), ['description' => 'She walks up close to the crane.']);
        $shot->keyframes()->update(['rendering' => false]);
        actingAs($this->director, 'director')
            ->delete(route('public.shots.keyframes.destroy', [$this->project, $shot, $second]))
            ->assertSessionHasNoErrors();

        expect(Correction::query()->pluck('source')->all())->toBe([CorrectionSource::DESCRIPTION, CorrectionSource::DELETE])
            ->and(Correction::query()->first()->request)->toContain('to "She walks up close to the crane."');
    });

    it('records nothing when learning is switched off', function () {
        Config::set('pipeline.rules.enabled', false);

        RecordCorrection::record($this->project, CorrectionSource::ADJUSTMENT, 'Closer to the crane');

        expect(Correction::query()->count())->toBe(0);
    });
});

describe('learning', function () {
    it('suggests a rule once the same correction comes back in another shot', function () {
        CorrectionClassifier::fake(fn() => ['kind' => 'correction', 'category' => 'Distance to hazard', 'rule' => 'People stand within two steps of the hazard.']);

        RecordCorrection::record($this->project, CorrectionSource::ADJUSTMENT, 'She is too far from the crane', readyShot($this->project));

        expect(ProjectRule::query()->count())->toBe(0)
            ->and(Correction::query()->sole())->kind->toBe(CorrectionKind::CORRECTION)->category->toBe('distance to hazard');

        RecordCorrection::record($this->project, CorrectionSource::ADJUSTMENT, 'Put her next to the load', readyShot($this->project, 2));

        expect(ProjectRule::query()->sole())
            ->status->toBe(ProjectRuleStatus::SUGGESTED)
            ->text->toBe('People stand within two steps of the hazard.');
        CorrectionClassifier::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'The director asked to adjust a keyframe image.'));
    });

    it('never learns from instructions', function () {
        CorrectionClassifier::fake(fn() => ['kind' => 'instruction', 'category' => 'extra prop', 'rule' => 'Add clipboards.']);

        RecordCorrection::record($this->project, CorrectionSource::ADJUSTMENT, 'Give her a clipboard', readyShot($this->project));
        RecordCorrection::record($this->project, CorrectionSource::ADJUSTMENT, 'Give him a clipboard', readyShot($this->project, 2));

        expect(ProjectRule::query()->count())->toBe(0)
            ->and(Correction::query()->pluck('rule')->filter()->all())->toBe([]);
    });

    it('counts a check finding as a correction, whatever the label', function () {
        CorrectionClassifier::fake(fn() => ['kind' => 'instruction', 'category' => 'text on objects', 'rule' => 'Never put text on equipment.']);

        RecordCorrection::record($this->project, CorrectionSource::CHECK, 'Words are printed on the life jacket.', readyShot($this->project));

        expect(Correction::query()->sole()->kind)->toBe(CorrectionKind::CORRECTION);
    });

    it('does not suggest a category again once it was dismissed', function () {
        CorrectionClassifier::fake(fn() => ['kind' => 'correction', 'category' => 'distance to hazard', 'rule' => 'Closer.']);
        $this->project->rules()->create(['category' => 'distance to hazard', 'text' => 'Old', 'status' => ProjectRuleStatus::DISMISSED]);

        RecordCorrection::record($this->project, CorrectionSource::ADJUSTMENT, 'Closer', readyShot($this->project));
        RecordCorrection::record($this->project, CorrectionSource::ADJUSTMENT, 'Closer', readyShot($this->project, 2));

        expect(ProjectRule::query()->count())->toBe(1);
    });
});

describe('rules', function () {
    it('lets the director accept or dismiss a suggested rule on the project page', function () {
        $rule = $this->project->rules()->create(['category' => 'distance to hazard', 'text' => 'People stand within two steps of the hazard.', 'status' => ProjectRuleStatus::SUGGESTED]);

        actingAs($this->director, 'director')
            ->get(route('public.projects.view', $this->project))
            ->assertInertia(fn($page) => $page->where('rules.0.text', 'People stand within two steps of the hazard.')->where('rules.0.status', 'suggested'));

        actingAs($this->director, 'director')->post(route('public.projects.rules.accept', [$this->project, $rule]));
        expect($rule->fresh()->status)->toBe(ProjectRuleStatus::ACTIVE);

        actingAs($this->director, 'director')->post(route('public.projects.rules.dismiss', [$this->project, $rule]));
        expect($rule->fresh()->status)->toBe(ProjectRuleStatus::DISMISSED);
    });

    it('forbids deciding on another director\'s rules', function () {
        $other = Project::factory()->create();
        $rule = $other->rules()->create(['category' => 'x', 'text' => 'X', 'status' => ProjectRuleStatus::SUGGESTED]);

        actingAs($this->director, 'director')
            ->post(route('public.projects.rules.accept', [$other, $rule]))
            ->assertForbidden();
    });

    it('feeds active rules into the planner and the image prompts, not suggested ones', function () {
        $this->project->rules()->create(['category' => 'distance to hazard', 'text' => 'People stand within two steps of the hazard.', 'status' => ProjectRuleStatus::ACTIVE]);
        $this->project->rules()->create(['category' => 'mood', 'text' => 'Always sunny.', 'status' => ProjectRuleStatus::SUGGESTED]);
        $shot = readyShot($this->project)->load('project');

        expect((string) (new StorylineWriter($shot))->instructions())
            ->toContain("Rules the director confirmed for this project, always follow them:\n- People stand within two steps of the hazard.")
            ->not->toContain('Always sunny.')
            ->and(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[0], new KeyframeReferences()))
            ->toContain("Project rules, always follow them:\n- People stand within two steps of the hazard.");
    });
});
