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
 * A later keyframe is drawn on the plate: the place of the shot without
 * people, from the same camera, so the background is the same in every
 * keyframe. The cast and what earlier keyframes changed are moved out of the
 * keyframe before, who is new is drawn from their picture. Without a plate
 * it is drawn on the keyframe before: the cast who stay are anchored, those
 * who move are moved out of it, and those who left are taken out.
 *
 * @phpstan-import-type Box from KeyframeLayout
 */
final class KeyframeLayoutBrief
{
    /** The whole frame, on the 0 to 1000 grid. */
    private const array WHOLE = [0, 0, 1000, 1000];

    /**
     * @param  array<string, int>  $pictures  per name of the cast, the number of its attached picture
     * @param  list<Element>  $props  objects the cast holds, each with its picture after the cast's
     * @param  string  $extra  added to this drawing only, such as a change the director asked for
     */
    public static function for(Keyframe $keyframe, KeyframeLayout $layout, KeyframeLayout $before, array $pictures, array $props = [], string $extra = ''): string
    {
        $lines = [
            "Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(),
            'What this keyframe shows: ' . $keyframe->fullDescription(),
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
                $old['box'] === $member['box'] => "{$tag} stays where they stand, {$member['facing']}; " . self::sentence($member['pose']) . $picture,
                default => "{$tag} moves to their new box, {$member['facing']}; " . self::sentence($member['pose']) . " Where {$tag} stood before, the place shows as it is behind them, empty, with no shadow left on the floor." . $picture,
            };
            $lines[] = self::feet($tag, $member['box'], 'with the same kind of contact shadow under them as the others in <ref_image_0> have');
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

        foreach ($layout->cast as $member) {
            $id = self::id($member['name']);

            if (! $member['changed']) {
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];

                continue;
            }

            $old = $before->member($member['name']);
            $picture = $pictures[$member['name']] ?? null;
            $desc = "{$member['name']}, {$member['facing']}, " . rtrim($member['pose'], '.');

            $rows[] = match (true) {
                $old !== null => ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::pad($old['box']), 'tgt_bbox' => self::pad($member['box']), 'desc' => $desc, 'kind' => 'move'],
                $picture !== null => ['id' => $id, 'from' => "ref_image_{$picture}", 'src_bbox' => self::WHOLE, 'tgt_bbox' => $member['box'], 'desc' => $desc, 'kind' => 'move'],
                default => ['id' => $id, 'from' => null, 'src_bbox' => null, 'tgt_bbox' => $member['box'], 'desc' => $desc, 'kind' => 'new'],
            };
        }

        // Who left is taken out and the model fills in what was behind them.
        foreach (self::left($layout, $before) as $member) {
            $rows[] = ['id' => self::id($member['name']), 'from' => 'ref_image_0', 'src_bbox' => self::pad($member['box']), 'tgt_bbox' => null, 'desc' => "{$member['name']}, removed", 'kind' => 'move'];
        }

        foreach ($layout->changes as $change) {
            $rows[] = ['id' => self::id($change['thing']), 'from' => null, 'src_bbox' => null, 'tgt_bbox' => $change['box'], 'desc' => "the {$change['thing']}, now {$change['state']}", 'kind' => 'new'];
        }

        return $rows;
    }

    /**
     * The prompt for a later keyframe drawn on the plate: the place without
     * people is the image that is kept, so the background is the same in
     * every keyframe. The people and the things that changed earlier in the
     * shot are moved out of the keyframe before; who is new comes from their
     * picture. An object of the plate that moved or is held is taken out of it.
     *
     * @param  array<string, Box>  $onPlate  per name, where an object of the cast stands in the plate
     * @param  list<array{thing: string, state: string, box: Box}>  $carried  things an earlier keyframe changed, as they are now
     * @param  array<string, int>  $pictures  per name of the cast, the number of its attached picture
     * @param  list<Element>  $props  objects the cast holds, each with its picture after the cast's
     */
    public static function onPlate(Keyframe $keyframe, KeyframeLayout $layout, KeyframeLayout $before, array $onPlate, array $carried, array $pictures, array $props = [], string $extra = ''): string
    {
        $lines = [
            "Keyframe {$keyframe->position} of an animated e-learning film. <ref_image_0> is the place of this shot without people, from the fixed camera: keep it exactly as it is, including every sign, the camera, the framing, the light and the style. <ref_image_1> is the keyframe before this one: the people and the things that changed come from it, as the rows below say. Change only what the rows below change.",
            'What this keyframe shows: ' . $keyframe->fullDescription(),
        ];

        foreach ($layout->cast as $member) {
            $tag = '<' . self::id($member['name']) . '>';
            $old = $before->member($member['name']);
            $plated = self::platedAt($onPlate, $member['name']);

            if (! $member['changed'] && $plated === $member['box']) {
                $lines[] = "{$tag} stays exactly as it is.";

                continue;
            }

            if ($plated !== null) {
                $lines[] = "Where {$tag} stands in <ref_image_0>, the place shows as it is behind it, empty, with no shadow.";
            }

            if (! $member['changed'] && $old !== null) {
                $lines[] = "{$tag} stays where they stand, exactly as in <ref_image_1>.";

                continue;
            }

            $picture = isset($pictures[$member['name']]) ? " {$tag} looks exactly like <ref_image_{$pictures[$member['name']]}>." : '';
            $lines[] = match (true) {
                $old === null => "{$tag} comes into the frame in their box, {$member['facing']}; " . self::sentence($member['pose']) . $picture,
                $old['box'] === $member['box'] => "{$tag} stays where they stand, {$member['facing']}; " . self::sentence($member['pose']) . $picture,
                default => "{$tag} moves to their new box, {$member['facing']}; " . self::sentence($member['pose']) . $picture,
            };
            $lines[] = self::feet($tag, $member['box'], 'with a contact shadow under them that matches the light of <ref_image_0>');
        }

        array_push($lines, ...self::depth($layout));

        foreach (self::offPlate($layout, $onPlate) as $name => $box) {
            $lines[] = '<' . self::id($name) . '> is no longer there. Where it stands in <ref_image_0>, the place shows as it is behind it, empty, with no shadow.';
        }

        foreach ($props as $index => $prop) {
            $lines[] = "{$prop->name} looks exactly like <ref_image_" . (count($pictures) + 2 + $index) . '>.';
        }

        foreach ($carried as $change) {
            $lines[] = "The {$change['thing']} stays {$change['state']}, exactly as in <ref_image_1>.";
        }

        foreach ($layout->changes as $change) {
            $lines[] = "The {$change['thing']} is now {$change['state']}.";
        }

        if (trim($extra) !== '') {
            $lines[] = trim($extra);
        }

        $lines[] = self::noText($keyframe);
        $lines[] = self::encode(self::plateRows($layout, $before, $onPlate, $carried, $pictures));

        return implode("\n", $lines);
    }

    /**
     * The rows on the plate: the plate as the anchor, objects of the plate
     * that moved or left taken out of it, the cast moved out of the keyframe
     * before or drawn from their picture, what an earlier keyframe changed
     * copied from the keyframe before, and what changes now drawn new.
     *
     * @param  array<string, Box>  $onPlate
     * @param  list<array{thing: string, state: string, box: Box}>  $carried
     * @param  array<string, int>  $pictures
     * @return list<array{id: string, from: string|null, src_bbox: Box|null, tgt_bbox: Box|null, desc: string, kind: string}>
     */
    public static function plateRows(KeyframeLayout $layout, KeyframeLayout $before, array $onPlate, array $carried, array $pictures): array
    {
        $rows = [self::frame('the place without people: everything that does not change')];

        foreach ($onPlate as $name => $box) {
            $member = $layout->member($name);

            if ($member === null || $member['changed'] || $member['box'] !== $box) {
                $rows[] = ['id' => self::id($name) . '_plate', 'from' => 'ref_image_0', 'src_bbox' => self::pad($box), 'tgt_bbox' => null, 'desc' => "{$name}, taken out of the place", 'kind' => 'move'];
            }
        }

        foreach ($carried as $change) {
            $rows[] = ['id' => self::id($change['thing']), 'from' => 'ref_image_1', 'src_bbox' => $change['box'], 'tgt_bbox' => $change['box'], 'desc' => "the {$change['thing']}, still {$change['state']}", 'kind' => 'move'];
        }

        // From back to front, so who is nearer is drawn over who is further away.
        $cast = $layout->cast;
        usort($cast, fn(array $a, array $b) => $a['box'][2] <=> $b['box'][2]);

        foreach ($cast as $member) {
            $id = self::id($member['name']);
            $old = $before->member($member['name']);
            $picture = $pictures[$member['name']] ?? null;

            if (! $member['changed'] && self::platedAt($onPlate, $member['name']) === $member['box']) {
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];

                continue;
            }

            if (! $member['changed'] && $old !== null) {
                $rows[] = ['id' => $id, 'from' => 'ref_image_1', 'src_bbox' => self::pad($old['box']), 'tgt_bbox' => self::pad($member['box']), 'desc' => "{$member['name']}, unchanged", 'kind' => 'move'];

                continue;
            }

            $desc = "{$member['name']}, {$member['facing']}, " . rtrim($member['pose'], '.');

            $rows[] = match (true) {
                $old !== null => ['id' => $id, 'from' => 'ref_image_1', 'src_bbox' => self::pad($old['box']), 'tgt_bbox' => self::pad($member['box']), 'desc' => $desc, 'kind' => 'move'],
                $picture !== null => ['id' => $id, 'from' => "ref_image_{$picture}", 'src_bbox' => self::WHOLE, 'tgt_bbox' => $member['box'], 'desc' => $desc, 'kind' => 'move'],
                default => ['id' => $id, 'from' => null, 'src_bbox' => null, 'tgt_bbox' => $member['box'], 'desc' => $desc, 'kind' => 'new'],
            };
        }

        foreach ($layout->changes as $change) {
            $rows[] = ['id' => self::id($change['thing']), 'from' => null, 'src_bbox' => null, 'tgt_bbox' => $change['box'], 'desc' => "the {$change['thing']}, now {$change['state']}", 'kind' => 'new'];
        }

        return $rows;
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
     * @param  array<string, Box>  $onPlate
     * @return Box|null
     */
    private static function platedAt(array $onPlate, string $name): ?array
    {
        foreach ($onPlate as $plated => $box) {
            if (mb_strtolower($plated) === mb_strtolower($name)) {
                return $box;
            }
        }

        return null;
    }

    /**
     * The objects of the plate that are no longer in the keyframe, such as one someone picked up.
     *
     * @param  array<string, Box>  $onPlate
     * @return array<string, Box>
     */
    private static function offPlate(KeyframeLayout $layout, array $onPlate): array
    {
        return array_filter($onPlate, fn(array $box, string $name) => $layout->member($name) === null, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The prompt for the plate: keyframe 1 with every person taken out, so
     * the place shows as it is behind them, from the same camera. The
     * place's picture, when there is one, says what is there.
     *
     * @param  list<string>  $people  the names of the people in keyframe 1
     */
    public static function plate(Keyframe $first, KeyframeLayout $layout, array $people): string
    {
        $people = array_map('mb_strtolower', $people);
        $lines = ['The first keyframe of an animated e-learning film. ' . self::keep(), 'Take every person out of the image, so it shows the place on its own, with nobody in it.'];
        $rows = [self::frame('the keyframe, unchanged wherever no person stands')];

        foreach ($layout->cast as $member) {
            $id = self::id($member['name']);

            if (! in_array(mb_strtolower($member['name']), $people, true)) {
                $lines[] = "<{$id}> stays exactly as it is.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];

                continue;
            }

            $lines[] = "<{$id}> is taken out. Where <{$id}> stood, the place shows as it is behind them, empty, with no shadow.";
            $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::pad($member['box']), 'tgt_bbox' => null, 'desc' => "{$member['name']}, removed", 'kind' => 'move'];
        }

        $lines[] = self::noText($first);
        $lines[] = self::encode($rows);

        return implode("\n", $lines);
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
    public static function moved(Keyframe $keyframe, KeyframeLayout $layout, string $name, ?array $box, ?int $picture = null, bool $cutOff = false, ?int $plate = null): string
    {
        $lines = ["Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(), 'What this keyframe shows: ' . $keyframe->fullDescription()];
        $rows = [self::frame('the keyframe as it is drawn, unchanged where no row changes it')];
        $moving = $layout->member($name);

        // Where they stood, the plate first; they are drawn over it where they stand now.
        if ($plate !== null && $moving !== null) {
            $lines[] = self::behindLine($moving['name'], $plate);
            $rows[] = self::behindRow($moving, $plate);
        }

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

                if ($plate === null) {
                    $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::pad($member['box']), 'tgt_bbox' => null, 'desc' => "{$member['name']}, removed", 'kind' => 'move'];
                }

                continue;
            }

            $lines[] = "<{$id}> moves to their new box, in the same pose and facing the same way, at the size they have there. Their feet stand on the floor, and their shadow moves along with them. Where <{$id}> stood, the place shows as it is behind them, empty, with no shadow left on the floor."
                . ($picture !== null ? " <{$id}> looks exactly like <ref_image_{$picture}>, the whole of them." : '')
                . ($cutOff ? " <{$id}> stands at the edge of the frame: only the part of them inside the box shows, the rest is cut off by the edge." : '');
            $rows[] = $picture !== null
                ? ['id' => $id, 'from' => "ref_image_{$picture}", 'src_bbox' => self::WHOLE, 'tgt_bbox' => $box, 'desc' => $desc, 'kind' => 'move']
                : ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::pad($member['box']), 'tgt_bbox' => self::pad($box), 'desc' => $desc, 'kind' => 'move'];
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
        $lines = ["Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(), 'What this keyframe shows: ' . $keyframe->fullDescription()];
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
        $lines = ["Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(), 'What this keyframe shows: ' . $keyframe->fullDescription()];
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
     * Who was in the keyframe before and is not in this one: they left.
     *
     * @return list<array{name: string, box: Box, facing: string, pose: string, changed: bool}>
     */
    private static function left(KeyframeLayout $layout, KeyframeLayout $before): array
    {
        return array_values(array_filter($before->cast, fn(array $member) => $layout->member($member['name']) === null));
    }

    private static function behindLine(string $name, int $plate): string
    {
        return '<' . self::id($name) . "_behind> is copied from <ref_image_{$plate}>, the same place from this camera without people: where {$name} stood, the place shows as it is there, empty, with no shadow.";
    }

    /**
     * @param  array{name: string, box: Box, facing: string, pose: string, changed: bool}  $member
     * @return array{id: string, from: string, src_bbox: Box, tgt_bbox: Box, desc: string, kind: string}
     */
    private static function behindRow(array $member, int $plate): array
    {
        return ['id' => self::id($member['name']) . '_behind', 'from' => "ref_image_{$plate}", 'src_bbox' => self::pad($member['box']), 'tgt_bbox' => self::pad($member['box']), 'desc' => "the empty place where {$member['name']} stood", 'kind' => 'move'];
    }

    private static function keep(): string
    {
        return 'In <ref_image_0>, keep everything exactly as it is, including every sign, the camera, the framing, the light and the style. Change only what the rows below change.';
    }

    private static function noText(Keyframe $keyframe): string
    {
        return TextRules::forEdits($keyframe->shot->project) . ' Signs and markings already in <ref_image_0> stay where they are.';
    }

    /**
     * A box with room around someone drawn again out of the image, so the
     * edge where the new part blends in falls on plain floor and wall, not
     * along their legs and feet, and their shadow is inside it: taken from
     * where they stood, it moves along with them. Wider at the sides and
     * below, the same on both spots, so they keep their size.
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
            min(1000, (int) round($bottom + $height * 0.12)),
            min(1000, (int) round($right + $width * 0.15)),
        ];
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
        return json_encode(['rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
