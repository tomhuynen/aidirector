<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Elements;

use App\Enums\ElementType;
use App\Http\Requests\Public\ElementRequest;
use App\Jobs\UpdateElementImage;
use App\Models\Element;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Models\Upload;
use App\Support\Elements\ElementSettings;
use App\Support\Media\ClaimUploads;
use Illuminate\Support\Facades\Gate;

class StoreController
{
    /**
     * Add a person, place or object to the cast and sets, draw its reference
     * image in the project's style, from the photo when one is given, and
     * open its page.
     */
    public function store(ElementRequest $request, Project $project, ClaimUploads $claimUploads)
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $element = $project->elements()->create([
            'type' => $request->validated('type'),
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'settings' => new ElementSettings(voice: $request->validated('type') === ElementType::PERSON->value ? $request->validated('voice') : null),
            'rendering' => true,
        ]);

        if (filled($photo = $request->validated('photo'))) {
            $claimUploads->toCollection($element, Upload::query()->whereSqid($photo)->get(), Element::PHOTO);
        }

        UpdateElementImage::dispatch($element, includes: $request->included()->modelKeys());

        return redirect()->route('public.projects.elements.view', [$project, $element]);
    }
}
