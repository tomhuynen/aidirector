<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\CorrectionClassifier;
use App\Enums\CorrectionKind;
use App\Enums\CorrectionSource;
use App\Enums\ProjectRuleStatus;
use App\Models\Correction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Labels a correction in the background and, when the same kind of mistake
 * keeps coming back across shots, suggests a project rule for the director.
 * Never holds up the change it describes.
 */
#[DeleteWhenMissingModels]
class ClassifyCorrection implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly Correction $correction,
    ) {
        $this->onQueue(Config::get('pipeline.queue'));
    }

    public function handle(): void
    {
        $correction = $this->correction->load('project');
        $project = $correction->project;

        $categories = $project->corrections()->whereNotNull('category')->distinct()->pluck('category')->all();
        $classifier = new CorrectionClassifier($correction, array_values($categories));

        /** @var StructuredAgentResponse $response */
        $response = $classifier->prompt($classifier->promptFor(), provider: 'openrouter', model: (string) Config::get('pipeline.models.text'));
        $result = $response->toArray();

        $kind = $correction->source === CorrectionSource::CHECK || ($result['kind'] ?? '') === 'correction'
            ? CorrectionKind::CORRECTION
            : CorrectionKind::INSTRUCTION;

        $correction->forceFill([
            'kind' => $kind,
            'category' => Str::limit(Str::lower(trim((string) ($result['category'] ?? ''))), 60, ''),
            'rule' => $kind === CorrectionKind::CORRECTION ? trim((string) ($result['rule'] ?? '')) : null,
        ])->save();

        if ($kind === CorrectionKind::CORRECTION && filled($correction->category) && filled($correction->rule)) {
            $this->suggestRule($correction);
        }
    }

    /**
     * A category becomes a suggested rule once corrections in it come from enough different shots.
     */
    private function suggestRule(Correction $correction): void
    {
        $project = $correction->project;

        if ($project->rules()->where('category', $correction->category)->exists()) {
            return;
        }

        $shots = $project->corrections()
            ->where('kind', CorrectionKind::CORRECTION)
            ->where('category', $correction->category)
            ->whereNotNull('shot_id')
            ->distinct()
            ->count('shot_id');

        if ($shots < (int) Config::get('pipeline.rules.threshold')) {
            return;
        }

        $project->rules()->create([
            'category' => $correction->category,
            'text' => $correction->rule,
            'status' => ProjectRuleStatus::SUGGESTED,
        ]);
    }
}
