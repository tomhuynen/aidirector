<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Ai\Agents\StagingBoxer;
use Illuminate\Support\Facades\Config;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Stages the first keyframe of a shot animated from it: plain grey
 * placeholders put into the empty place by FLUX Fill, where a vision model
 * put their boxes, so what stands in front of what comes from the place and
 * its depth, not from the image model's guess. The edit model then makes the
 * placeholders the cast, keeping where they stand.
 */
class StartFrameStaging
{
    public function __construct(
        private readonly Cutout $cutout,
        private readonly FluxFill $fill,
    ) {}

    /**
     * The place with a grey placeholder for each cast member, or null when it cannot be staged; a staging is never needed to draw.
     *
     * @param  list<array{name: string, type: string}>  $cast
     * @return array{image: string, boxes: list<array{name: string, left: float, top: float, right: float, bottom: float, facing: string, before: list<string>}>}|null
     */
    public function stage(string $place, string $description, string $spatial, array $cast, ?DepthGrid $depth = null): ?array
    {
        if ($cast === [] || ! ReplicateClient::configured()) {
            return null;
        }

        try {
            $answer = $this->boxes($place, $description, $spatial, $cast);
            $fixed = $this->fixedParts($place, $answer['fixed_parts']);
            $problem = $depth === null ? '' : $this->depthProblem($answer['boxes'], $fixed, $depth);

            // Once more when a box stands on the wrong side of a fixed part, such as past the barrier it must wait at.
            if ($problem !== '') {
                $answer = $this->boxes($place, $description, $spatial, $cast, $problem);
                $fixed = $this->fixedParts($place, $answer['fixed_parts']);
            }

            if ($answer['boxes'] === []) {
                return null;
            }

            $image = $place;
            $types = collect($cast)->mapWithKeys(fn(array $member) => [mb_strtolower($member['name']) => $member['type']]);

            // One placeholder per pass, each in its own mask: together, Fill puts them in each other's box.
            foreach ($answer['boxes'] as $box) {
                $image = $this->fill->fill($image, $this->maskFor($image, $box, $fixed), $this->placeholder($box, (string) ($types[mb_strtolower($box['name'])] ?? 'object')));
            }

            return ['image' => $image, 'boxes' => $answer['boxes']];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Who of the cast the drawn image does not show where they were staged: found by the cut-out model, a person as
     * "person" and a thing by its name, and wrong when what is found has its middle outside the box widened by half
     * its size, or is more than four times as large. A cast member that is not found at all counts as fine, since
     * finding can fail.
     *
     * @param  list<array{name: string, left: float, top: float, right: float, bottom: float}>  $boxes
     * @param  list<array{name: string, type: string}>  $cast
     * @return list<string>
     */
    public function misplaced(string $image, array $boxes, array $cast): array
    {
        $types = collect($cast)->mapWithKeys(fn(array $member) => [mb_strtolower($member['name']) => $member['type']]);
        $people = collect($boxes)->filter(fn(array $box) => ($types[mb_strtolower($box['name'])] ?? '') === 'person')->count();
        $wrong = [];

        foreach ($boxes as $box) {
            $person = ($types[mb_strtolower($box['name'])] ?? '') === 'person';

            // With several people "person" finds all of them, so only a single person is checked.
            if ($person && $people > 1) {
                continue;
            }

            try {
                $found = $this->foundBox(Masks::grey($this->cutout->thing($image, $person ? Cutout::PERSON : $box['name']), 100, 100));
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if ($found === null) {
                continue;
            }

            [$width, $height] = [$box['right'] - $box['left'], $box['bottom'] - $box['top']];
            [$x, $y] = [($found['left'] + $found['right']) / 2, ($found['top'] + $found['bottom']) / 2];
            $outside = $x < $box['left'] - $width / 2 || $x > $box['right'] + $width / 2 || $y < $box['top'] - $height / 2 || $y > $box['bottom'] + $height / 2;
            $tooLarge = ($found['right'] - $found['left']) * ($found['bottom'] - $found['top']) > 4 * $width * $height;

            if ($outside || $tooLarge) {
                $wrong[] = $box['name'];
            }
        }

        return $wrong;
    }

    /**
     * The box around the white of a mask, in shares of its size; null when it is empty.
     *
     * @return array{left: float, top: float, right: float, bottom: float}|null
     */
    private function foundBox(Imagick $mask): ?array
    {
        [$width, $height] = [$mask->getImageWidth(), $mask->getImageHeight()];
        $pixels = $mask->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_CHAR);
        [$left, $top, $right, $bottom] = [$width, $height, -1, -1];

        foreach ($pixels as $index => $value) {
            if ($value > 127) {
                [$x, $y] = [$index % $width, intdiv($index, $width)];
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x), max($bottom, $y)];
            }
        }

        return $right < 0 ? null : ['left' => $left / $width, 'top' => $top / $height, 'right' => ($right + 1) / $width, 'bottom' => ($bottom + 1) / $height];
    }

    /**
     * @param  list<array{name: string, type: string}>  $cast
     * @return array{boxes: list<array{name: string, left: float, top: float, right: float, bottom: float, facing: string, before: list<string>}>, fixed_parts: list<string>}
     */
    private function boxes(string $place, string $description, string $spatial, array $cast, string $problem = ''): array
    {
        $boxer = new StagingBoxer($description, $spatial, $cast, $problem);

        /** @var StructuredAgentResponse $response */
        $response = $boxer->prompt($boxer->promptFor(), attachments: [ImageFile::fromBase64(base64_encode($place), 'image/png')], provider: 'openrouter', model: (string) Config::get('pipeline.models.keyframe_check'));
        $answer = $response->toArray();
        $names = collect($cast)->map(fn(array $member) => mb_strtolower($member['name']));
        $clamp = fn(mixed $value) => max(0.0, min(1.0, (float) $value));

        $boxes = collect((array) ($answer['boxes'] ?? []))
            ->filter(fn(mixed $box) => is_array($box) && $names->contains(mb_strtolower(trim((string) ($box['name'] ?? '')))))
            ->map(fn(array $box) => [
                'name' => trim((string) $box['name']),
                'left' => $clamp($box['left'] ?? 0), 'top' => $clamp($box['top'] ?? 0), 'right' => $clamp($box['right'] ?? 0), 'bottom' => $clamp($box['bottom'] ?? 0),
                'facing' => trim((string) ($box['facing'] ?? '')),
                'before' => array_values(array_filter(array_map(fn(mixed $part) => trim((string) $part), (array) ($box['before'] ?? [])))),
            ])
            ->filter(fn(array $box) => $box['right'] - $box['left'] > 0.02 && $box['bottom'] - $box['top'] > 0.02)
            ->unique(fn(array $box) => mb_strtolower($box['name']))
            ->values()
            ->all();

        return ['boxes' => $boxes, 'fixed_parts' => array_values(array_unique(array_filter(array_map(fn(mixed $part) => trim((string) $part), (array) ($answer['fixed_parts'] ?? [])))))];
    }

    /**
     * Each fixed part found in the place by its name, as a grey mask at the size of the place.
     *
     * @param  list<string>  $names
     * @return array<string, Imagick>
     */
    private function fixedParts(string $place, array $names): array
    {
        [$width, $height] = $this->sizeOf($place);
        $parts = [];

        foreach ($names as $name) {
            try {
                $parts[mb_strtolower($name)] = Masks::grey($this->cutout->thing($place, $name), $width, $height);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $parts;
    }

    /**
     * What is wrong in the boxes against the depth of the place: a box that must stand before a fixed part, such as
     * a car before the barrier, whose feet are further from the camera than that part. Empty when all fit.
     *
     * @param  list<array{name: string, left: float, top: float, right: float, bottom: float, facing: string, before: list<string>}>  $boxes
     * @param  array<string, Imagick>  $fixed
     */
    private function depthProblem(array $boxes, array $fixed, DepthGrid $depth): string
    {
        $problems = [];

        foreach ($boxes as $box) {
            $feet = $depth->at(($box['left'] + $box['right']) / 2, min(0.999, $box['bottom']));

            foreach ($box['before'] as $part) {
                $mask = $fixed[mb_strtolower($part)] ?? null;
                $partDepth = $mask === null ? null : $this->depthOf($mask, $depth);

                if ($partDepth !== null && $feet > $partDepth) {
                    $problems[] = "{$box['name']} stands further from the camera than {$part}, so it would be behind it; its feet must be nearer the camera, lower in the picture.";
                }
            }
        }

        return implode(' ', $problems);
    }

    /**
     * The middle depth of what a mask covers.
     */
    private function depthOf(Imagick $mask, DepthGrid $depth): ?float
    {
        $small = clone $mask;
        $small->resizeImage($depth->width, $depth->height, Imagick::FILTER_TRIANGLE, 1);
        $inside = $small->exportImagePixels(0, 0, $depth->width, $depth->height, 'I', Imagick::PIXEL_CHAR);
        $values = [];

        foreach ($inside as $index => $value) {
            if ($value > 127) {
                $values[] = $depth->values[$index];
            }
        }

        if ($values === []) {
            return null;
        }

        sort($values);

        return $values[intdiv(count($values), 2)];
    }

    /**
     * The box, never over a fixed part of the place.
     *
     * @param  array{left: float, top: float, right: float, bottom: float}  $box
     * @param  array<string, Imagick>  $fixed
     */
    private function maskFor(string $image, array $box, array $fixed): string
    {
        [$width, $height] = $this->sizeOf($image);
        $mask = Masks::black($width, $height);
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->rectangle((int) round($box['left'] * $width), (int) round($box['top'] * $height), (int) round($box['right'] * $width), (int) round($box['bottom'] * $height));
        $mask->drawImage($draw);

        foreach ($fixed as $part) {
            $free = clone $part;
            $free->negateImage(false);
            $mask->compositeImage($free, Imagick::COMPOSITE_MULTIPLY, 0, 0);
        }

        return Masks::png($mask);
    }

    /**
     * @param  array{name: string, facing: string}  $box
     */
    private function placeholder(array $box, string $type): string
    {
        $facing = $box['facing'] !== '' ? ", facing {$box['facing']}" : '';

        return $type === 'person'
            ? "A plain matte light grey mannequin, like an untextured 3D blocking model of a standing person, the whole figure from head to feet{$facing}, with a soft shadow on the ground. No face, no clothes, no colours."
            : "A plain matte light grey placeholder shaped like a {$box['name']}, like an untextured 3D blocking model{$facing}, with a soft shadow on the ground. No details, no colours, no people.";
    }

    /**
     * @return array{int, int}
     */
    private function sizeOf(string $image): array
    {
        $size = new Imagick();
        $size->pingImageBlob($image);

        return [$size->getImageWidth(), $size->getImageHeight()];
    }
}
