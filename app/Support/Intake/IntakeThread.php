<?php

declare(strict_types=1);

namespace App\Support\Intake;

use App\Ai\Agents\ProjectIntake;
use App\Http\Resources\Public\StyleOptionResource;
use App\Models\Media;
use App\Models\Project;
use App\Models\StyleOption;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Rebuilds the intake chat as the director saw it, so a project that is
 * still in setup can be resumed where it was left: the stored turns, the
 * photos added along the way and the style rounds, in the order they
 * happened.
 */
class IntakeThread
{
    /**
     * Matches the photo notes the chat appends to a director's message.
     */
    private const PHOTO_NOTES = '/(?:^|\n\n)The director added \d+ photos?:\n.*$/s';

    /**
     * @return array{
     *  messages: list<array<string, mixed>>,
     *  ask: string|null,
     * }
     */
    public function for(Project $project, Request $request): array
    {
        $turns = ConversationMessage::query()
            ->where('conversation_id', $project->conversation_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'created_at']);

        // Photos are stored just before the turn that adds them, so each one
        // belongs to the first director turn stored after it.
        $photos = Media::query()
            ->where('model_type', $project->getMorphClass())
            ->where('model_id', $project->getKey())
            ->where('collection_name', Project::CONTENT_REFERENCES)
            ->orderBy('created_at')
            ->orderBy('order_column')
            ->get();
        $ask = null;
        $entries = collect();

        foreach ($turns as $turn) {
            /** @var CarbonInterface $at */
            $at = $turn->getAttribute('created_at');
            $content = (string) $turn->getAttribute('content');

            if ($turn->getAttribute('role') === 'user') {
                $claimed = $photos->filter(fn(Media $media) => $media->created_at <= $at);
                $photos = $photos->diff($claimed);
                $entries->push([$at, 0, $this->userMessage($content, $claimed)]);
            } else {
                $data = json_decode($content, true);
                $ask = is_array($data) ? ($data['ask'] ?? null) : null;
                $entries->push([$at, 0, $this->text('assistant', is_array($data) ? (string) ($data['reply'] ?? '') : $content)]);
            }

        }

        $project->styleOptions()->with(['media', 'parent'])->get()
            ->each(fn(StyleOption $option) => $option->setRelation('project', $project))
            ->groupBy('round')
            ->each(function (Collection $options, int $round) use ($entries, $project, $request) {
                /** @var StyleOption $first */
                $first = $options->first();

                $entries->push([$first->created_at, 1, [
                    'kind' => 'style-options',
                    'role' => 'assistant',
                    'content' => $first->parent === null
                        ? __('Four directions, each rendered with your own subjects.')
                        : __('More like “:name”.', ['name' => $first->parent->style()['name']]),
                    'round' => $round,
                    'optionsUrl' => route('public.projects.style.options', [$project, $round]),
                    'options' => StyleOptionResource::collection($options)->resolve($request),
                ]]);
            });

        $messages = $entries
            ->sort(fn(array $a, array $b) => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]])
            ->pluck(2)
            ->prepend($this->text('assistant', ProjectIntake::greeting()))
            ->values()
            ->all();

        return ['messages' => $messages, 'ask' => $ask];
    }

    /**
     * @param  Collection<int, Media>  $photos
     * @return array<string, mixed>
     */
    private function userMessage(string $content, Collection $photos): array
    {
        $text = trim((string) preg_replace(self::PHOTO_NOTES, '', $content));

        return [
            ...$this->text('user', $text),
            'attachments' => $photos->map(fn(Media $media) => [
                'id' => $media->sqid,
                'name' => $media->file_name,
                'previewUrl' => $media->signedUrl(Project::REFERENCE),
            ])->values()->all(),
        ];
    }

    /**
     * @return array{kind: 'text', role: string, content: string}
     */
    private function text(string $role, string $content): array
    {
        return ['kind' => 'text', 'role' => $role, 'content' => $content];
    }
}
