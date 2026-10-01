<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Projects\Photos;

use App\Http\Requests\Public\PhotoPickRequest;
use App\Http\Resources\UploadResource;
use App\Models\PhotoSuggestion;
use App\Models\Policies\Public\ProjectPolicy;
use App\Models\Project;
use App\Support\PhotoSearch\DownloadSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Downloads the photos the director ticked in a search gallery and stages
 * them as uploads. The chat then sends them in a turn, exactly like photos
 * added with the + button.
 */
class PickController
{
    public function __construct(
        private readonly DownloadSuggestion $downloadSuggestion,
    ) {}

    public function store(PhotoPickRequest $request, Project $project): JsonResponse
    {
        Gate::authorize(ProjectPolicy::UPDATE, $project);

        $suggestions = $project->photoSuggestions()->whereSqidIn('id', $request->suggestionIds())->get();
        $uploads = collect();
        $failed = [];

        foreach ($suggestions as $suggestion) {
            /** @var PhotoSuggestion $suggestion */
            try {
                $uploads->push($this->downloadSuggestion->download($suggestion));
                $suggestion->forceFill(['picked_at' => now()])->save();
            } catch (RuntimeException $exception) {
                report($exception);
                $failed[] = $suggestion->sqid;
            }
        }

        return response()->json([
            /** @var array<int, array{id: string, name: string, url: string}> */
            'uploads' => UploadResource::collection($uploads)->resolve($request),
            /** @var array<int, string> */
            'failed' => $failed,
        ]);
    }
}
