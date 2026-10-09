<?php

declare(strict_types=1);

namespace App\Support\Decisions;

use App\Ai\Agents\FindingsReporter;
use App\Models\Keyframe;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Tells the director in the chat what the keyframe checks and the shot
 * review found, once a round of drawing is reviewed: numbered, each with
 * its keyframes, and asks which to fix. Only what was not reported before;
 * the director's answer in the chat fixes them or leaves them.
 */
class FindingsReport
{
    public function __construct(private readonly ShotIssues $issues) {}

    public function report(Shot $shot): void
    {
        $keyframes = $shot->keyframes()->with('media')->get();
        $reported = collect($shot->plan_chat ?? [])->flatMap(fn(array $turn) => collect($turn['findings'] ?? [])->flatMap(fn(array $finding) => $finding['keys'] ?? []))->all();
        $new = array_values(array_filter($this->issues->open($shot, $keyframes), fn(array $issue) => ! in_array(self::key($issue), $reported, true)));

        if ($new === []) {
            return;
        }

        [$intro, $items] = $this->phrase($shot, $new);

        $findings = collect($items)->values()->map(function (array $item, int $index) use ($new) {
            $sources = collect($item['sources'])->map(fn(int $source) => $new[$source - 1] ?? null)->filter()->values();

            return [
                'n' => $index + 1,
                'keyframes' => $sources->flatMap(fn(array $issue) => $issue['keyframes'])->unique()->sort()->values()->all(),
                'note' => $item['note'],
                'issues' => $sources->pluck('id')->unique()->values()->all(),
                'keys' => $sources->map(fn(array $issue) => self::key($issue))->values()->all(),
                'verdict' => null,
            ];
        })->filter(fn(array $finding) => $finding['issues'] !== [])->values();

        // Findings the reporter left out are still reported, as they were found.
        $covered = $findings->flatMap(fn(array $finding) => $finding['keys'])->all();
        foreach ($new as $issue) {
            if (! in_array(self::key($issue), $covered, true)) {
                $findings->push(['n' => $findings->count() + 1, 'keyframes' => $issue['keyframes'], 'note' => self::plain($issue), 'issues' => [$issue['id']], 'keys' => [self::key($issue)], 'verdict' => null]);
            }
        }

        $lines = $findings->map(fn(array $finding) => "{$finding['n']}. " . self::where($finding['keyframes']) . $finding['note'])->join("\n");
        $question = $findings->count() === 1 ? __('Shall I fix it, or is it fine?') : __('Shall I fix them? Say which ones, or that they are fine.');

        $shot->updateStoredJson('plan_chat', fn(?array $chat) => [
            ...array_values($chat ?? []),
            ['role' => 'assistant', 'text' => "{$intro}\n{$lines}\n{$question}", 'findings' => $findings->all()],
        ]);
    }

    /**
     * The findings that wait for the director's answer, from the latest report:
     * not answered yet and still open.
     *
     * @param  Collection<int, Keyframe>  $keyframes
     * @return array{turn: int, findings: list<array{n: int, keyframes: list<int>, note: string, issues: list<string>}>}|null
     */
    public function waiting(Shot $shot, Collection $keyframes): ?array
    {
        $chat = array_values($shot->plan_chat ?? []);
        $turn = collect($chat)->keys()->reverse()->first(fn(int $index) => ! empty($chat[$index]['findings']));

        if ($turn === null) {
            return null;
        }

        $open = collect($this->issues->open($shot, $keyframes))->pluck('id')->all();
        $findings = collect($chat[$turn]['findings'])
            ->filter(fn(array $finding) => ($finding['verdict'] ?? null) === null && array_intersect($finding['issues'], $open) !== [])
            ->values()
            ->all();

        return $findings === [] ? null : ['turn' => $turn, 'findings' => $findings];
    }

    /**
     * "Keyframe 2: " or "Keyframes 2 and 3: ", nothing for the whole shot.
     *
     * @param  list<int>  $keyframes
     */
    public static function where(array $keyframes): string
    {
        return match (count($keyframes)) {
            0 => '',
            1 => __('Keyframe :n: ', ['n' => $keyframes[0]]),
            default => __('Keyframes :list: ', ['list' => collect($keyframes)->join(', ', ' and ')]),
        };
    }

    /**
     * @param  list<array{id: string, text: string, problem: string, keyframes: list<int>}>  $new
     * @return array{0: string, 1: list<array{sources: list<int>, note: string}>}
     */
    private function phrase(Shot $shot, array $new): array
    {
        $reporter = new FindingsReporter(array_map(fn(array $issue) => ['keyframes' => $issue['keyframes'], 'text' => self::plain($issue)], $new));
        $model = (string) Config::get('pipeline.models.text');

        try {
            /** @var StructuredAgentResponse $response */
            $response = $reporter->prompt($reporter->promptFor(), provider: 'openrouter', model: $model);
            $result = $response->toArray();
            $shot->generations()->create(['director_id' => $shot->project->director_id, 'kind' => 'text', 'provider' => $response->meta->provider ?? 'openrouter', 'model' => $response->meta->model ?? $model, 'prompt' => $reporter->promptFor(), 'usage' => $response->usage->toArray()]);

            $items = collect((array) ($result['items'] ?? []))
                ->filter(fn(mixed $item) => is_array($item) && trim((string) ($item['note'] ?? '')) !== '')
                ->map(fn(array $item) => ['sources' => array_values(array_map('intval', (array) ($item['sources'] ?? []))), 'note' => trim((string) $item['note'])])
                ->values()
                ->all();

            if ($items !== []) {
                return [trim((string) ($result['intro'] ?? '')) ?: __('I looked at the new keyframes and noticed this.'), $items];
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        // Without the reporter, as the checks wrote them.
        return [__('I looked at the new keyframes and noticed this.'), array_map(fn(int $index) => ['sources' => [$index + 1], 'note' => self::plain($new[$index])], array_keys($new))];
    }

    /**
     * A finding as plain sentences, without the "The check found:" the list of decisions puts before a check's findings.
     *
     * @param  array{id: string, text: string, problem: string}  $issue
     */
    private static function plain(array $issue): string
    {
        return str_starts_with($issue['id'], 'found-') ? $issue['problem'] : $issue['text'];
    }

    /**
     * What a finding is known by, so the same finding is reported once and a new one on a redrawn keyframe again.
     *
     * @param  array{id: string, text: string}  $issue
     */
    private static function key(array $issue): string
    {
        return hash('xxh3', "{$issue['id']}|{$issue['text']}");
    }
}
