<?php

declare(strict_types=1);

namespace App\Support\Shots;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * How a keyframe is staged, in the fields the director edits: one short
 * line on what happens, the spot with the things on it, and per person of
 * the cast where they stand, which way they face, what they look at, what
 * they hold in which hand and what they do. The description the models get
 * is built from these fields in code, so it always says what they say.
 *
 * @phpstan-type Member array{name: string, stands: string, faces: string, looks_at: string, holds: string, hand: string, doing: string}
 */
final class KeyframeStaging
{
    /** Where someone stands in the frame. */
    public const STANDS = ['left', 'centre', 'right'];

    /** Which way someone's body faces, from the camera's point of view: straight, side-on, or turned three-quarters toward or away from the camera. */
    public const FACES = ['camera', 'camera-left', 'camera-right', 'left', 'right', 'away-left', 'away-right', 'away'];

    /** Which hand holds what someone holds. */
    public const HANDS = ['left', 'right', 'both'];

    /**
     * @param  list<Member>  $cast
     */
    public function __construct(
        public readonly string $summary = '',
        public readonly string $place = '',
        public readonly array $cast = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        $cast = [];

        foreach ((array) ($stored['cast'] ?? []) as $member) {
            $member = self::member(is_array($member) ? $member : []);

            if ($member !== null) {
                $cast[] = $member;
            }
        }

        return new self(trim((string) ($stored['summary'] ?? '')), trim((string) ($stored['place'] ?? '')), $cast);
    }

    /**
     * One person of the cast with every field, each a known value or empty; null without a name.
     *
     * @param  array<string, mixed>  $member
     * @return Member|null
     */
    public static function member(array $member): ?array
    {
        $name = trim((string) ($member['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $pick = fn(string $key, array $values) => in_array($value = trim((string) ($member[$key] ?? '')), $values, true) ? $value : '';
        $text = fn(string $key) => rtrim(trim((string) ($member[$key] ?? '')), '.');

        return [
            'name' => $name,
            'stands' => $pick('stands', self::STANDS),
            'faces' => $pick('faces', self::FACES),
            'looks_at' => $text('looks_at'),
            'holds' => $text('holds'),
            'hand' => $text('holds') === '' ? '' : $pick('hand', self::HANDS),
            'doing' => $text('doing'),
        ];
    }

    /**
     * @return array{summary: string, place: string, cast: list<Member>}
     */
    public function toArray(): array
    {
        return ['summary' => $this->summary, 'place' => $this->place, 'cast' => $this->cast];
    }

    /**
     * The staging and the description built from it, for a planned keyframe or a keyframe row.
     *
     * @return array{staging: array{summary: string, place: string, cast: list<Member>}, description: string}
     */
    public function fields(): array
    {
        return ['staging' => $this->toArray(), 'description' => $this->describe()];
    }

    /**
     * The same staging with one of the cast changed, or added when they were not in it.
     *
     * @param  array<string, mixed>  $member
     */
    public function withMember(array $member): self
    {
        $member = self::member($member);

        if ($member === null) {
            return $this;
        }

        $cast = $this->cast;
        $index = array_search(mb_strtolower($member['name']), array_map(fn(array $other) => mb_strtolower($other['name']), $cast), true);
        $index === false ? $cast[] = $member : $cast[$index] = $member;

        return new self($this->summary, $this->place, $cast);
    }

    /**
     * What the keyframe shows as the models get it: the line, the spot and one sentence per person of the cast.
     */
    public function describe(): string
    {
        $sentences = [$this->summary, $this->place, ...array_map(self::sentence(...), $this->cast)];

        return implode(' ', array_map(fn(string $sentence) => preg_match('/[.!?]$/', $sentence) === 1 ? $sentence : "{$sentence}.", array_filter(array_map('trim', $sentences))));
    }

    /**
     * The fields as plain lines, for a prompt that writes them again.
     */
    public function outline(): string
    {
        $lines = ["Summary: {$this->summary}", 'Place: ' . ($this->place ?: '(none)')];

        foreach ($this->cast as $member) {
            $lines[] = "- {$member['name']}: " . collect(['stands' => $member['stands'], 'faces' => $member['faces'], 'looks at' => $member['looks_at'], 'holds' => $member['holds'], 'hand' => $member['hand'], 'doing' => $member['doing']])
                ->filter(fn(string $value) => $value !== '')
                ->map(fn(string $value, string $field) => "{$field} {$value}")
                ->join('; ');
        }

        return implode("\n", $lines);
    }

    /**
     * The fields of a staging, for an agent's structured answer.
     *
     * @param  list<string>  $names  the people of the cast and sets it may stage; any name when empty
     * @return array<string, Type>
     */
    public static function schema(JsonSchema $schema, array $names = []): array
    {
        return [
            'summary' => $schema->string()->required(),
            'place' => $schema->string()->required(),
            'cast' => $schema->array()->items($schema->object(self::memberSchema($schema, $names)))->required(),
        ];
    }

    /**
     * The fields of one person of the cast, for an agent's structured answer; an empty value leaves a field out.
     *
     * @param  list<string>  $names  the people of the cast and sets it may stage; any name when empty
     * @return array<string, Type>
     */
    public static function memberSchema(JsonSchema $schema, array $names = []): array
    {
        $name = $schema->string();

        return [
            'name' => ($names === [] ? $name : $name->enum($names))->required(),
            'stands' => $schema->string()->enum([...self::STANDS, ''])->required(),
            'faces' => $schema->string()->enum([...self::FACES, ''])->required(),
            'looks_at' => $schema->string()->required(),
            'holds' => $schema->string()->required(),
            'hand' => $schema->string()->enum([...self::HANDS, ''])->required(),
            'doing' => $schema->string()->required(),
        ];
    }

    /**
     * One person of the cast in one sentence, such as "The Damen host stands
     * on the right, is side-on facing left, looks at the Helmeted visitor
     * and holds the permit in the right hand."
     *
     * @param  Member  $member
     */
    private static function sentence(array $member): string
    {
        $hand = match ($member['hand']) {
            'both' => ' in both hands',
            'left', 'right' => " in the {$member['hand']} hand",
            default => '',
        };

        $phrases = array_values(array_filter([
            match ($member['stands']) {
                'left' => 'stands on the left',
                'centre' => 'stands in the centre',
                'right' => 'stands on the right',
                default => '',
            },
            match ($member['faces']) {
                'camera' => 'faces the camera',
                'camera-left', 'camera-right' => 'is turned three-quarters toward the camera, facing the ' . substr($member['faces'], 7) . ' of the frame',
                'left', 'right' => "is side-on facing {$member['faces']}",
                'away-left', 'away-right' => 'is turned three-quarters away from the camera, facing into the frame to the ' . substr($member['faces'], 5),
                'away' => 'has the back to the camera',
                default => '',
            },
            $member['looks_at'] !== '' ? "looks at {$member['looks_at']}" : '',
            $member['holds'] !== '' ? "holds {$member['holds']}{$hand}" : '',
            $member['doing'],
        ]));

        $subject = 'The ' . $member['name'];

        if ($phrases === []) {
            return "{$subject} is in the frame";
        }

        $last = array_pop($phrases);

        return $subject . ' ' . ($phrases === [] ? $last : implode(', ', $phrases) . " and {$last}");
    }
}
