<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Shots\Keyframes;

use App\Models\Keyframe;
use App\Models\Policies\Public\ShotPolicy;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Renders live on the private tenant disk, so they are streamed through the
 * app after checking the director may see the shot.
 */
class ImageController
{
    /**
     * Streams the chosen render, or the render named by the `render` query parameter.
     */
    public function view(Request $request, Project $project, Shot $shot, Keyframe $keyframe, ?string $conversion = null)
    {
        Gate::authorize(ShotPolicy::VIEW, $shot);

        $render = $request->filled('render')
            ? $keyframe->renders()->firstWhere('id', $request->integer('render'))
            : $keyframe->render();

        $render ?? abort(404);

        $path = $render->getPathRelativeToRoot(
            $conversion !== null && $render->hasGeneratedConversion($conversion) ? $conversion : ''
        );

        return Storage::disk($render->disk)->response($path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
