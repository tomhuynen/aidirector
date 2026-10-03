<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects;

use App\Enums\ProjectRuleStatus;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\ProjectRule;
use Illuminate\Support\Facades\Gate;

/**
 * The director decides on rules learned from recurring corrections: a
 * suggested rule is accepted or dismissed, an active one can be removed.
 */
class RuleController
{
    public function accept(Project $project, ProjectRule $rule)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $rule->update(['status' => ProjectRuleStatus::ACTIVE]);

        return back();
    }

    /**
     * Dismissed rules stay, so the same category is not suggested again.
     */
    public function dismiss(Project $project, ProjectRule $rule)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $rule->update(['status' => ProjectRuleStatus::DISMISSED]);

        return back();
    }
}
