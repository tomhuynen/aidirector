<?php

declare(strict_types=1);

namespace App\Support\Style;

use App\Models\Media;
use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Support\Facades\DB;

/**
 * Makes an option the project's style anchor: its sheet becomes the style
 * reference and its descriptor fills the project's style fields.
 */
class PinStyleOption
{
    public function pin(StyleOption $option): Project
    {
        $project = $option->project;
        $style = $option->style();

        return DB::connection($project->getConnectionName())->transaction(function () use ($project, $option, $style): Project {
            $project->styleOptions()->whereNotNull('pinned_at')->update(['pinned_at' => null]);
            $option->forceFill(['pinned_at' => now()])->save();

            $project->clearMediaCollection(Project::STYLE_REFERENCES);

            $render = $option->render();

            if ($render !== null) {
                /** @var Media $anchor */
                $anchor = $project
                    ->addMediaFromDisk($render->getPathRelativeToRoot(), $render->disk)
                    ->preservingOriginal()
                    ->usingName($style['name'])
                    ->usingFileName($render->file_name)
                    ->withCustomProperties([Project::CAPTION => $style['name'] . '. ' . $style['look']])
                    ->toMediaCollection(Project::STYLE_REFERENCES);
            }

            $project->forceFill([
                'style' => [
                    ...($project->style ?? []),
                    'look' => $style['look'] . ' ' . $style['lighting'],
                    'medium' => $style['medium'],
                    'mood' => $style['mood'],
                    'palette' => $style['palette'],
                ],
            ])->save();

            return $project;
        });
    }
}
