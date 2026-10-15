<?php

declare(strict_types=1);

namespace App\Support\Shots;

/**
 * Where everything a keyframe changes stands in the frame: each of the cast
 * with a box, which way they face and their pose, and things of the place
 * whose state changes. Boxes are [top, left, bottom, right] on a 0 to 1000
 * grid of the frame; the bottom of a box is where the feet or wheels touch
 * the floor. The next keyframe keeps what does not change from it.
 *
 * @phpstan-type Box array{0: int, 1: int, 2: int, 3: int}
 * @phpstan-type Member array{name: string, box: Box, facing: string, pose: string, changed: bool}
 * @phpstan-type Change array{thing: string, state: string, box: Box}
 */
final class KeyframeLayout
{
    /**
     * @param  list<Member>  $cast
     * @param  list<Change>  $changes
     */
    public function __construct(
        public readonly array $cast = [],
        public readonly array $changes = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        $cast = [];

        foreach ((array) ($stored['cast'] ?? []) as $member) {
            $box = self::box($member['box'] ?? null);
            $name = trim((string) ($member['name'] ?? ''));

            if ($box !== null && $name !== '') {
                $cast[] = [
                    'name' => $name,
                    'box' => $box,
                    'facing' => trim((string) ($member['facing'] ?? '')),
                    'pose' => trim((string) ($member['pose'] ?? '')),
                    'changed' => (bool) ($member['changed'] ?? true),
                ];
            }
        }

        $changes = [];

        foreach ((array) ($stored['changes'] ?? []) as $change) {
            $box = self::box($change['box'] ?? null);
            $thing = trim((string) ($change['thing'] ?? ''));

            if ($box !== null && $thing !== '') {
                $changes[] = ['thing' => $thing, 'state' => trim((string) ($change['state'] ?? '')), 'box' => $box];
            }
        }

        return new self($cast, $changes);
    }

    /**
     * @return array{cast: list<Member>, changes: list<Change>}
     */
    public function toArray(): array
    {
        return ['cast' => $this->cast, 'changes' => $this->changes];
    }

    /**
     * Where one of the cast stands, or null when they are not in the frame.
     *
     * @return Member|null
     */
    public function member(string $name): ?array
    {
        foreach ($this->cast as $member) {
            if (mb_strtolower($member['name']) === mb_strtolower($name)) {
                return $member;
            }
        }

        return null;
    }

    /**
     * The same layout with one of the cast in another box.
     *
     * @param  Box  $box
     */
    public function withBox(string $name, array $box): self
    {
        return new self(
            array_map(fn(array $member) => mb_strtolower($member['name']) === mb_strtolower($name) ? [...$member, 'box' => $box] : $member, $this->cast),
            $this->changes,
        );
    }

    /**
     * The same layout without one of the cast, who left the frame.
     */
    public function without(string $name): self
    {
        return new self(
            array_values(array_filter($this->cast, fn(array $member) => mb_strtolower($member['name']) !== mb_strtolower($name))),
            $this->changes,
        );
    }

    /**
     * Whether a box reaches the edge of the frame, so what is in it may be cut off by it.
     *
     * @param  Box  $box
     */
    public static function cutOff(array $box): bool
    {
        return $box[0] <= 0 || $box[1] <= 0 || $box[2] >= 1000 || $box[3] >= 1000;
    }

    /**
     * A box as four whole numbers on the 0 to 1000 grid, with some height and width; null when it is not one.
     *
     * @return Box|null
     */
    public static function box(mixed $box): ?array
    {
        if (is_array($box) && isset($box['top'], $box['left'], $box['bottom'], $box['right'])) {
            $box = [$box['top'], $box['left'], $box['bottom'], $box['right']];
        }

        if (! is_array($box) || count($box) !== 4) {
            return null;
        }

        [$top, $left, $bottom, $right] = array_map(fn(mixed $value) => (int) round(max(0, min(1000, (float) $value))), array_values($box));

        return $bottom - $top >= 10 && $right - $left >= 5 ? [$top, $left, $bottom, $right] : null;
    }
}
