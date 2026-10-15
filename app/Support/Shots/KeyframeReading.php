<?php

declare(strict_types=1);

namespace App\Support\Shots;

/**
 * What the reader found in keyframe 1: for each of the cast where they stand,
 * whether that is where the description puts them, and whether they look
 * like their picture. Boxes are on the grid of {@see KeyframeLayout}.
 *
 * @phpstan-import-type Box from KeyframeLayout
 *
 * @phpstan-type Read array{name: string, box: Box, facing: string, pose: string, placed_right: bool, target: Box, looks_right: bool, problem: string}
 */
final class KeyframeReading
{
    /**
     * @param  list<Read>  $cast
     */
    public function __construct(public readonly array $cast = []) {}

    /**
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $names  the cast that may be read; others are left out
     */
    public static function fromArray(array $answer, array $names): self
    {
        $allowed = array_map('mb_strtolower', $names);
        $cast = [];

        foreach ((array) ($answer['cast'] ?? []) as $read) {
            $name = trim((string) ($read['name'] ?? ''));
            $box = KeyframeLayout::box($read['box'] ?? null);

            if ($box === null || ! in_array(mb_strtolower($name), $allowed, true)) {
                continue;
            }

            $placedRight = (bool) ($read['placed_right'] ?? true);
            $cast[] = [
                'name' => $name,
                'box' => $box,
                'facing' => trim((string) ($read['facing'] ?? '')),
                'pose' => trim((string) ($read['pose'] ?? '')),
                'placed_right' => $placedRight,
                'target' => $placedRight ? $box : (KeyframeLayout::box($read['target'] ?? null) ?? $box),
                'looks_right' => (bool) ($read['looks_right'] ?? true),
                'problem' => trim((string) ($read['problem'] ?? '')),
            ];
        }

        return new self($cast);
    }

    /**
     * Whether anyone stands in the wrong spot or does not look like their picture.
     */
    public function needsFixing(): bool
    {
        foreach ($this->cast as $read) {
            if (! $read['placed_right'] || ! $read['looks_right']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where everyone stands, as the layout the keyframe after it starts from.
     */
    public function layout(): KeyframeLayout
    {
        return new KeyframeLayout(array_map(fn(array $read) => [
            'name' => $read['name'],
            'box' => $read['box'],
            'facing' => $read['facing'],
            'pose' => $read['pose'],
            'changed' => true,
        ], $this->cast));
    }
}
