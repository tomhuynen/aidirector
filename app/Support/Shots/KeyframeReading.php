<?php

declare(strict_types=1);

namespace App\Support\Shots;

/**
 * What the reader found in keyframe 1: for each of the cast where they stand,
 * whether that is where the description puts them, whether their pose is
 * natural, and whether they look like their picture. Boxes are on the grid of {@see KeyframeLayout}.
 *
 * @phpstan-import-type Box from KeyframeLayout
 *
 * @phpstan-type Read array{name: string, box: Box, facing: string, pose: string, placed_right: bool, looks_right: bool, pose_right: bool, problem: string}
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

            $cast[] = [
                'name' => $name,
                'box' => $box,
                'facing' => trim((string) ($read['facing'] ?? '')),
                'pose' => trim((string) ($read['pose'] ?? '')),
                'placed_right' => (bool) ($read['placed_right'] ?? true),
                'looks_right' => (bool) ($read['looks_right'] ?? true),
                'pose_right' => (bool) ($read['pose_right'] ?? true),
                'problem' => trim((string) ($read['problem'] ?? '')),
            ];
        }

        return new self($cast);
    }

    /**
     * Who stands in the wrong spot, in an unnatural pose or does not look like
     * their picture, one sentence each, for the director.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->cast as $read) {
            if ($read['placed_right'] && $read['looks_right'] && $read['pose_right']) {
                continue;
            }

            $problems[] = $read['problem'] !== '' ? $read['problem'] : match (true) {
                ! $read['placed_right'] => "{$read['name']} does not stand where the description puts them.",
                ! $read['pose_right'] => "{$read['name']} stands in an unnatural pose.",
                default => "{$read['name']} does not look like their picture.",
            };
        }

        return $problems;
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
