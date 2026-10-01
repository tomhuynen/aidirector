<?php

declare(strict_types=1);

namespace App\Support\Elements;

use App\Models\ElementRound;
use App\Models\ElementSuggestion;

/**
 * A cast and sets round as the chat shows it: its status and the
 * suggestions with their renders so far. Used for the chat turn that starts
 * it, for polling while it fills, and when a project in setup is resumed.
 */
class ElementRoundState
{
    /**
     * @return array{
     *  id: string,
     *  type: string,
     *  label: string,
     *  status: string,
     *  error: string|null,
     *  pollUrl: string,
     *  pickUrl: string,
     *  options: list<array{id: string, name: string, description: string, status: string, picked: bool, fromPhoto: bool, thumbnailUrl: string|null, imageUrl: string|null}>,
     * }
     */
    public function for(ElementRound $round): array
    {
        $project = $round->project;

        return [
            'id' => (string) $round->sqid,
            'type' => $round->type->value,
            'label' => $round->type->plural(),
            'status' => $round->status->value,
            'error' => $round->error,
            'pollUrl' => route('public.projects.elements.rounds.view', [$project, $round]),
            'pickUrl' => route('public.projects.elements.rounds.pick', [$project, $round]),
            'options' => $round->suggestions()->with('media')->get()
                ->map(function (ElementSuggestion $suggestion) {
                    $render = $suggestion->render();

                    return [
                        'id' => (string) $suggestion->sqid,
                        'name' => (string) $suggestion->name,
                        'description' => (string) $suggestion->description,
                        'status' => $suggestion->status->value,
                        'picked' => $suggestion->isPicked(),
                        'fromPhoto' => $suggestion->source_media_id !== null,
                        'thumbnailUrl' => $render?->signedUrl(ElementSuggestion::THUMBNAIL),
                        'imageUrl' => $render?->signedUrl(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}
