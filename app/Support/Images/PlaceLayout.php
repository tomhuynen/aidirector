<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Ai\Agents\PlaceMapper;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * The layout of a place, made once and kept on it: where its fixed things
 * stand from its camera, which way they face, and the routes through it,
 * such as a lane or a door. A plan is checked against it before it is drawn.
 */
class PlaceLayout
{
    /** On a place: its layout. */
    public const string KEY = 'layout';

    /**
     * @param  list<string>  $things  the names of the cast and sets that may stand here
     * @return array{map: string, things: list<array{name: string, side: string, distance: string, faces: string}>, routes: list<array{name: string, runs: string}>}|null
     */
    public function of(Media $place, string $name, array $things = []): ?array
    {
        $stored = $place->getCustomProperty(self::KEY);

        if (is_array($stored)) {
            return $stored;
        }

        $mapper = new PlaceMapper($name, $things);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $mapper->prompt($mapper->promptFor(), attachments: [ImageFile::fromStorage($place->getPathRelativeToRoot(), $place->disk)], provider: 'openrouter', model: (string) Config::get('pipeline.models.text'));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $answer = $response->toArray();
        $layout = [
            'map' => trim((string) ($answer['map'] ?? '')),
            'things' => array_values(array_filter((array) ($answer['things'] ?? []), is_array(...))),
            'routes' => array_values(array_filter((array) ($answer['routes'] ?? []), is_array(...))),
        ];
        $place->setCustomProperty(self::KEY, $layout)->save();

        return $layout;
    }

    /**
     * The layout in plain lines, for a prompt.
     *
     * @param  array{map: string, things: list<array{name: string, side: string, distance: string, faces: string}>, routes: list<array{name: string, runs: string}>}  $layout
     */
    public static function lines(array $layout): string
    {
        $things = collect($layout['things'])->map(fn(array $thing) => "- {$thing['name']}: {$thing['side']} of the frame, {$thing['distance']}" . (filled($thing['faces']) ? ", {$thing['faces']}" : ''))->join("\n");
        $routes = collect($layout['routes'])->map(fn(array $route) => "- {$route['name']}: {$route['runs']}")->join("\n");

        return trim(implode("\n", array_filter([
            $layout['map'],
            $things !== '' ? "Fixed things:\n{$things}" : null,
            $routes !== '' ? "Routes through the place:\n{$routes}" : null,
        ])));
    }
}
