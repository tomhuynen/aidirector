<?php

declare(strict_types=1);

namespace App\Support\Images;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * The depth of an empty place, measured once by a depth model and kept on
 * the place: where people can be put, how big they are there, and what
 * stands in front of them.
 */
class PlaceDepth
{
    /** On a place: its depth grid. */
    public const string DEPTH = 'depth';

    /** The width of the grid kept; the height follows the image. Fine enough for sizes and what stands in front. */
    private const int WIDTH = 48;

    public function __construct(private readonly ReplicateClient $replicate) {}

    /**
     * The depth of a place, measured the first time it is asked for; null when it cannot be measured.
     */
    public function of(Media $place): ?DepthGrid
    {
        $stored = $place->getCustomProperty(self::DEPTH);

        if (is_array($stored)) {
            return DepthGrid::fromArray($stored);
        }

        if (! ReplicateClient::configured()) {
            return null;
        }

        try {
            $grid = $this->measure((string) Storage::disk($place->disk)->get($place->getPathRelativeToRoot()));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $place->setCustomProperty(self::DEPTH, $grid->toArray())->save();

        return $grid;
    }

    /**
     * Depth Anything answers with a JSON file holding the depth as float32 rows.
     */
    private function measure(string $image): DepthGrid
    {
        $output = $this->replicate->run((string) Config::get('pipeline.keyframes.composite.depth_model'), ['images' => [ReplicateClient::dataUrl($image)]]);
        $url = $output['data'][0] ?? null;

        if (! is_string($url)) {
            throw new RuntimeException('The depth model returned no depth.');
        }

        $depth = json_decode($this->replicate->download($url), true)['depth'] ?? null;

        if (! is_array($depth) || ! isset($depth['shape'], $depth['data'])) {
            throw new RuntimeException('The depth model returned no depth.');
        }

        [$height, $width] = array_map('intval', (array) $depth['shape']);
        $values = array_values((array) unpack('g*', (string) base64_decode((string) $depth['data'])));

        if ($width < 1 || $height < 1 || count($values) !== $width * $height) {
            throw new RuntimeException('The depth model returned a depth of the wrong size.');
        }

        $gridHeight = max(1, (int) round(self::WIDTH * $height / $width));
        $grid = [];

        for ($row = 0; $row < $gridHeight; $row++) {
            for ($column = 0; $column < self::WIDTH; $column++) {
                $grid[] = (float) $values[min($height - 1, (int) (($row + 0.5) * $height / $gridHeight)) * $width + min($width - 1, (int) (($column + 0.5) * $width / self::WIDTH))];
            }
        }

        return new DepthGrid(self::WIDTH, $gridHeight, $grid);
    }
}
