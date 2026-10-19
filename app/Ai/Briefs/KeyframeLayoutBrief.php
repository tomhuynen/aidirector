<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Models\Element;
use App\Models\Keyframe;
use App\Support\Shots\KeyframeLayout;
use Illuminate\Support\Str;

/**
 * The prompts for the layout model, which keeps an image and changes only
 * boxes in it. Each row has an id named after what it holds, such as
 * `security_guard`, and the instruction refers to it as `<security_guard>`,
 * so the model knows which box is meant. Image 0 is the image that is kept;
 * the pictures of the cast follow in the order given.
 *
 * The end frame is drawn on the start frame: the cast who stay are
 * anchored, those who move are moved out of that image, those who are new
 * are drawn from their picture, and those who left are taken out.
 *
 * @phpstan-import-type Box from KeyframeLayout
 */
final class KeyframeLayoutBrief
{
    /** The whole frame, on the 0 to 1000 grid. */
    private const array WHOLE = [0, 0, 1000, 1000];

    private const int EDGE = 5;

    /**
     * @param  array<string, int>  $pictures  per name of the cast, the number of its attached picture
     * @param  list<Element>  $props  objects the cast holds, each with its picture after the cast's
     * @param  string  $extra  added to this drawing only, such as a change the director asked for
     */
    public static function for(Keyframe $keyframe, KeyframeLayout $layout, KeyframeLayout $before, array $pictures, array $props = [], string $extra = ''): string
    {
        $lines = [
            "Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(),
            'What this keyframe shows: ' . $keyframe->boxedDescription(),
        ];

        foreach ($layout->cast as $member) {
            if (! $member['changed']) {
                continue;
            }

            $tag = '<' . self::id($member['name']) . '>';
            $old = $before->member($member['name']);
            $picture = isset($pictures[$member['name']]) ? " {$tag} looks exactly like <ref_image_{$pictures[$member['name']]}>." : '';

            $lines[] = match (true) {
                $old === null => "{$tag} comes into the frame in their box, {$member['facing']}; " . self::sentence($member['pose']) . $picture,
                self::sameSpot($old['box'], $member['box']) => "{$tag} stays where they stand, {$member['facing']}; " . self::sentence($member['pose']) . $picture,
                default => "{$tag} moves to their new box, {$member['facing']}; " . self::sentence($member['pose']) . " Where {$tag} stood before, the place shows as it is behind them, empty, with no shadow left on the floor." . $picture,
            };

            if (self::cutOff($member['box'])) {
                $lines[] = "{$tag} is cut off by the edge of the frame, as in <ref_image_0>: only the part of them inside the frame shows, at the same size. What is outside the frame there, such as their head, stays outside it.";
            }

            if ($member['box'][2] < 1000 - self::EDGE) {
                $lines[] = self::feet($tag, $member['box'], 'with the same kind of contact shadow under them as the others in <ref_image_0> have');
            }
        }

        array_push($lines, ...self::depth($layout));

        foreach ($layout->cast as $member) {
            if (! $member['changed']) {
                $lines[] = '<' . self::id($member['name']) . '> stays exactly as it is.';
            }
        }

        foreach (self::left($layout, $before) as $member) {
            $tag = '<' . self::id($member['name']) . '>';
            $lines[] = "{$tag} leaves the frame and is no longer in this keyframe. Where {$tag} stood, the place shows as it is behind them, empty, with no shadow.";
        }

        foreach ($props as $index => $prop) {
            $lines[] = "{$prop->name} looks exactly like <ref_image_" . (count($pictures) + 1 + $index) . '>.';
        }

        foreach ($layout->changes as $change) {
            $lines[] = "The {$change['thing']} is now {$change['state']}.";
        }

        if (trim($extra) !== '') {
            $lines[] = trim($extra);
        }

        $lines[] = self::noText($keyframe);
        $lines[] = self::encode(self::rows($layout, $before, $pictures));

        return implode("\n", $lines);
    }

    /**
     * The rows: the image as the anchor, the cast who stay anchored where they
     * stand, the cast who move moved out of the image, the cast who are new
     * from their picture, and the things of the place in their new state.
     *
     * @param  array<string, int>  $pictures
     * @return list<array{id: string, from: string|null, src_bbox: Box|null, tgt_bbox: Box|null, desc: string, kind: string}>
     */
    public static function rows(KeyframeLayout $layout, KeyframeLayout $before, array $pictures): array
    {
        $rows = [self::frame('the image before this keyframe: the place and everything in it that does not change')];
        $kept = array_values(array_map(fn(array $member) => $member['box'], array_filter($layout->cast, fn(array $member) => ! $member['changed'])));

        // What stays comes first, so a change drawn over a part of it, such as a hand at a handle, wins.
        foreach ($layout->cast as $member) {
            if (! $member['changed']) {
                $rows[] = ['id' => self::id($member['name']), 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];
            }
        }

        foreach ($layout->cast as $member) {
            if (! $member['changed']) {
                continue;
            }

            $id = self::id($member['name']);
            $old = $before->member($member['name']);
            $picture = $pictures[$member['name']] ?? null;
            $desc = "{$member['name']}, {$member['facing']}, " . rtrim($member['pose'], '.');

            $rows[] = match (true) {
                $old !== null => ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::clear(self::pad($old['box']), $old['box'], $kept), 'tgt_bbox' => self::clear(self::pad($member['box']), $member['box'], $kept), 'desc' => $desc, 'kind' => 'move'],
                $picture !== null => ['id' => $id, 'from' => "ref_image_{$picture}", 'src_bbox' => self::WHOLE, 'tgt_bbox' => $member['box'], 'desc' => $desc, 'kind' => 'move'],
                default => ['id' => $id, 'from' => null, 'src_bbox' => null, 'tgt_bbox' => $member['box'], 'desc' => $desc, 'kind' => 'new'],
            };
        }

        // Who left is taken out and the model fills in what was behind them.
        foreach (self::left($layout, $before) as $member) {
            $rows[] = ['id' => self::id($member['name']), 'from' => 'ref_image_0', 'src_bbox' => self::clear(self::pad($member['box']), $member['box'], $kept), 'tgt_bbox' => null, 'desc' => "{$member['name']}, removed", 'kind' => 'move'];
        }

        foreach ($layout->changes as $change) {
            $rows[] = ['id' => self::id($change['thing']), 'from' => null, 'src_bbox' => null, 'tgt_bbox' => $change['box'], 'desc' => "the {$change['thing']}, now {$change['state']}", 'kind' => 'new'];
        }

        return $rows;
    }

    /**
     * Whether a box changed only as much as two readings of the same pose differ.
     *
     * @param  Box  $old
     * @param  Box  $new
     */
    private static function sameSpot(array $old, array $new): bool
    {
        $overlap = max(0, min($old[2], $new[2]) - max($old[0], $new[0])) * max(0, min($old[3], $new[3]) - max($old[1], $new[1]));
        $union = ($old[2] - $old[0]) * ($old[3] - $old[1]) + ($new[2] - $new[0]) * ($new[3] - $new[1]) - $overlap;

        return $union > 0 && $overlap / $union >= 0.9;
    }

    /**
     * Whether a box touches the top or a side of the frame, so part of who is in it is outside the frame.
     *
     * @param  Box  $box
     */
    private static function cutOff(array $box): bool
    {
        return $box[0] <= self::EDGE || $box[1] <= self::EDGE || $box[3] >= 1000 - self::EDGE;
    }

    /**
     * Where the feet touch the floor: the bottom of their own box, which the
     * row's box reaches past to hold the shadow.
     *
     * @param  Box  $box
     */
    private static function feet(string $tag, array $box, string $shadow): string
    {
        return "The feet of {$tag} touch the floor at {$box[2]} of the frame height, on the 0 to 1000 grid, {$shadow}; their box reaches lower only to hold that shadow.";
    }

    /**
     * Who hides whom where boxes overlap: who stands lower in the frame is nearer to the camera.
     *
     * @return list<string>
     */
    private static function depth(KeyframeLayout $layout): array
    {
        $lines = [];

        foreach ($layout->cast as $index => $a) {
            foreach (array_slice($layout->cast, $index + 1) as $b) {
                if ($a['box'][0] >= $b['box'][2] || $b['box'][0] >= $a['box'][2] || $a['box'][1] >= $b['box'][3] || $b['box'][1] >= $a['box'][3] || $a['box'][2] === $b['box'][2]) {
                    continue;
                }

                [$near, $far] = $a['box'][2] > $b['box'][2] ? [$a, $b] : [$b, $a];
                $lines[] = '<' . self::id($near['name']) . '> is in front of <' . self::id($far['name']) . '>, nearer to the camera: <' . self::id($far['name']) . '> stays in its spot and is partly hidden behind <' . self::id($near['name']) . '>.';
            }
        }

        return $lines;
    }

    /**
     * The prompt for moving one of the cast in a keyframe to the box the
     * director dragged them to, with everyone else staying. Without a box
     * they leave the frame. With a picture they are drawn from it, for
     * someone who was cut off by the edge of the frame; otherwise they are
     * moved out of the keyframe itself. When cut off, only the part inside
     * the frame shows.
     *
     * @param  Box|null  $box
     */
    public static function moved(Keyframe $keyframe, KeyframeLayout $layout, string $name, ?array $box, ?int $picture = null, bool $cutOff = false): string
    {
        $lines = ["Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(), 'What this keyframe shows: ' . $keyframe->boxedDescription()];
        $rows = [self::frame('the keyframe as it is drawn, unchanged where no row changes it')];

        $kept = array_values(array_map(fn(array $member) => $member['box'], array_filter($layout->cast, fn(array $member) => mb_strtolower($member['name']) !== mb_strtolower($name))));

        foreach ($layout->cast as $member) {
            $id = self::id($member['name']);
            $desc = "{$member['name']}, {$member['facing']}, " . rtrim($member['pose'], '.');

            if (mb_strtolower($member['name']) !== mb_strtolower($name)) {
                $lines[] = "<{$id}> stays exactly as it is.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];

                continue;
            }

            if ($box === null) {
                $lines[] = "<{$id}> leaves the frame and is no longer in this keyframe. Where <{$id}> stood, the place shows as it is behind them, empty, with no shadow.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::clear(self::pad($member['box']), $member['box'], $kept), 'tgt_bbox' => null, 'desc' => "{$member['name']}, removed", 'kind' => 'move'];

                continue;
            }

            $lines[] = "<{$id}> moves to their new box, in the same pose and facing the same way, at the size they have there. Their feet stand on the floor, and their shadow moves along with them. Where <{$id}> stood, the place shows as it is behind them, empty, with no shadow left on the floor."
                . ($picture !== null ? " <{$id}> looks exactly like <ref_image_{$picture}>, the whole of them." : '')
                . ($cutOff ? " <{$id}> stands at the edge of the frame: only the part of them inside the box shows, the rest is cut off by the edge." : '');
            $rows[] = $picture !== null
                ? ['id' => $id, 'from' => "ref_image_{$picture}", 'src_bbox' => self::WHOLE, 'tgt_bbox' => $box, 'desc' => $desc, 'kind' => 'move']
                : ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::clear(self::pad($member['box']), $member['box'], $kept), 'tgt_bbox' => self::clear(self::pad($box), $box, $kept), 'desc' => $desc, 'kind' => 'move'];
        }

        $lines[] = self::noText($keyframe);
        $lines[] = self::encode($rows);

        return implode("\n", $lines);
    }

    /**
     * The prompt for changing some of the cast where they stand, as the
     * director asked in the chat or a check found, with everyone else
     * staying. Someone with a picture is drawn again from it in their box, so
     * the old pose in the image does not hold them back.
     *
     * @param  array<string, string>  $changes  per name of the cast, what changes about them
     * @param  array<string, int>  $pictures  per name of the cast, the number of its attached picture
     */
    public static function changed(Keyframe $keyframe, KeyframeLayout $layout, array $changes, array $pictures = []): string
    {
        $lines = ["Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(), 'What this keyframe shows: ' . $keyframe->boxedDescription()];
        $rows = [self::frame('the keyframe as it is drawn, unchanged where no row changes it')];
        $changes = array_change_key_case($changes, CASE_LOWER);
        $pictures = array_change_key_case($pictures, CASE_LOWER);

        foreach ($layout->cast as $member) {
            $id = self::id($member['name']);
            $change = $changes[mb_strtolower($member['name'])] ?? null;
            $picture = $pictures[mb_strtolower($member['name'])] ?? null;

            if ($change === null) {
                $lines[] = "<{$id}> stays exactly as it is.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];

                continue;
            }

            $desc = "{$member['name']}, " . rtrim(trim($change), '.');

            if ($picture === null) {
                $lines[] = "<{$id}> stays where they stand, in their box, and changes only this: " . self::sentence($change) . " Everything else about <{$id}> stays as it is.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::pad($member['box']), 'tgt_bbox' => self::pad($member['box']), 'desc' => $desc, 'kind' => 'move'];

                continue;
            }

            $lines[] = "<{$id}> stays where they stand and is drawn again in their box, the whole of them, looking exactly like <ref_image_{$picture}>, in this pose: " . self::sentence($change) . ' What this keyframe shows says the rest of their pose.';
            $lines[] = "The feet of <{$id}> stand on the floor at the bottom of the box, at the size of the box, with the same kind of contact shadow under them as the others in <ref_image_0> have.";
            $rows[] = ['id' => $id, 'from' => "ref_image_{$picture}", 'src_bbox' => self::WHOLE, 'tgt_bbox' => $member['box'], 'desc' => $desc, 'kind' => 'move'];
        }

        $lines[] = self::noText($keyframe);
        $lines[] = self::encode($rows);

        return implode("\n", $lines);
    }

    /**
     * The prompt that fixes what a check found in a keyframe: each problem is
     * changed in place, inside the box found for it, with the picture of the
     * one of the cast it is about as the reference when they look wrong; the
     * cast it is not about and everything outside the boxes stay.
     *
     * @param  list<array{problem: string, box: Box, member: string|null, looks: bool, shows: string}>  $fixes
     * @param  array<string, int>  $pictures  per name of the cast, the number of its attached picture
     */
    public static function repaired(Keyframe $keyframe, KeyframeLayout $layout, array $fixes, array $pictures): string
    {
        $lines = ["Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(), 'What this keyframe shows: ' . $keyframe->boxedDescription()];
        $rows = [self::frame('the keyframe as it is drawn, unchanged where no row changes it')];
        $fixed = array_filter(array_map(fn(array $fix) => $fix['member'] === null ? null : mb_strtolower($fix['member']), $fixes));

        foreach ($layout->cast as $member) {
            if (! in_array(mb_strtolower($member['name']), $fixed, true)) {
                $id = self::id($member['name']);
                $lines[] = "<{$id}> stays exactly as it is.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];
            }
        }

        foreach ($fixes as $index => $fix) {
            $id = $fix['member'] !== null ? self::id($fix['member']) . '_fix' : 'fix_' . ($index + 1);
            $picture = $fix['looks'] ? ($pictures[(string) $fix['member']] ?? null) : null;
            $lines[] = "Inside <{$id}>, fix this: " . self::sentence($fix['problem']) . ' Afterwards it shows ' . lcfirst(self::sentence($fix['shows']))
                . match (true) {
                    $picture !== null => " Copy how {$fix['member']} looks there exactly from <ref_image_{$picture}>, the picture of {$fix['member']}, keeping their pose and where they stand.",
                    $fix['member'] !== null => " {$fix['member']} looks as they do now; only their pose, what they hold or wear, or where they are changes.",
                    default => '',
                }
            . " Everything else inside <{$id}> stays as it is.";
            $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $fix['box'], 'tgt_bbox' => $fix['box'], 'desc' => rtrim($fix['shows'], '.'), 'kind' => 'move'];
        }

        // A fix may bring back a logo from the picture of whoever it is about.
        $lines[] = TextRules::forImages($keyframe->shot->project) . ' Signs and markings already in <ref_image_0> stay where they are.';
        $lines[] = self::encode($rows);

        return implode("\n", $lines);
    }

    /**
     * The id of a row, named after what it holds, such as `security_guard`.
     */
    public static function id(string $name): string
    {
        return Str::slug($name, '_');
    }

    /**
     * Who was in the start frame and is not in this one: they left.
     *
     * @return list<array{name: string, box: Box, facing: string, pose: string, changed: bool}>
     */
    private static function left(KeyframeLayout $layout, KeyframeLayout $before): array
    {
        return array_values(array_filter($before->cast, fn(array $member) => $layout->member($member['name']) === null));
    }

    private static function keep(): string
    {
        return 'In <ref_image_0>, keep everything exactly as it is, including every sign, the camera, the framing, the light and the style. The framing is the same crop at the same distance: never zoom in or out, and never show more or less of the place or the people than <ref_image_0> does. Change only what the rows below change.';
    }

    private static function noText(Keyframe $keyframe): string
    {
        return TextRules::forEdits($keyframe->shot->project) . ' Signs and markings already in <ref_image_0> stay where they are.';
    }

    /**
     * A box with room around someone drawn again out of the image, so the
     * edge where the new part blends in falls on plain floor and wall, not
     * along their legs and feet, and their shadow is inside it: taken from
     * where they stood, it moves along with them. Wider at the sides and a
     * little below, the same on both spots, so they keep their size; the
     * feet stay near the bottom of the box.
     *
     * @param  Box  $box
     * @return Box
     */
    private static function pad(array $box): array
    {
        [$top, $left, $bottom, $right] = $box;
        $width = $right - $left;
        $height = $bottom - $top;

        return [
            max(0, (int) round($top - $height * 0.03)),
            max(0, (int) round($left - $width * 0.15)),
            min(1000, (int) round($bottom + $height * 0.04)),
            min(1000, (int) round($right + $width * 0.15)),
        ];
    }

    /**
     * A padded box kept clear of the boxes that stay as they are, so no part
     * of the image is kept and drawn again at once. It never shrinks into the
     * box it pads: where that box itself overlaps one that stays, it is left.
     *
     * @param  Box  $padded
     * @param  Box  $own
     * @param  list<Box>  $kept
     * @return Box
     */
    private static function clear(array $padded, array $own, array $kept): array
    {
        [$top, $left, $bottom, $right] = $padded;

        foreach ($kept as [$keptTop, $keptLeft, $keptBottom, $keptRight]) {
            if ($top >= $keptBottom || $keptTop >= $bottom || $left >= $keptRight || $keptLeft >= $right) {
                continue;
            }

            if ($own[1] >= $keptRight) {
                $left = $keptRight;
            } elseif ($own[3] <= $keptLeft) {
                $right = $keptLeft;
            } elseif ($own[0] >= $keptBottom) {
                $top = $keptBottom;
            } elseif ($own[2] <= $keptTop) {
                $bottom = $keptTop;
            }
        }

        return [$top, $left, $bottom, $right];
    }

    private static function sentence(string $text): string
    {
        return rtrim(trim($text), '.') . '.';
    }

    /**
     * @return array{id: string, from: string, src_bbox: Box, tgt_bbox: Box, desc: string, kind: string}
     */
    private static function frame(string $desc): array
    {
        return ['id' => 'frame', 'from' => 'ref_image_0', 'src_bbox' => self::WHOLE, 'tgt_bbox' => self::WHOLE, 'desc' => $desc, 'kind' => 'anchor'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function encode(array $rows): string
    {
        // The layout model reads a bare array of rows; whether a row keeps, moves, adds or removes follows from its boxes.
        return json_encode(array_map(fn(array $row) => array_diff_key($row, ['kind' => true]), $rows), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
