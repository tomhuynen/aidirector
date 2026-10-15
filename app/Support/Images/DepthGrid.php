<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * How far each part of an image is from the camera, on a small grid: only
 * ratios between depths are used, such as how much smaller a person gets
 * further back, so the unit does not matter.
 */
final class DepthGrid
{
    /**
     * @param  list<float>  $values  row by row, from the top left
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly array $values,
    ) {}

    /**
     * The depth at a point given as shares of the image width and height.
     */
    public function at(float $x, float $y): float
    {
        $column = (int) min($this->width - 1, max(0, floor($x * $this->width)));
        $row = (int) min($this->height - 1, max(0, floor($y * $this->height)));

        return $this->values[$row * $this->width + $column];
    }

    /**
     * Kept small on the place: the depths as 16-bit steps of the deepest one.
     *
     * @return array{width: int, height: int, max: float, values: string}
     */
    public function toArray(): array
    {
        $max = max(0.001, ...$this->values);

        return [
            'width' => $this->width,
            'height' => $this->height,
            'max' => $max,
            'values' => base64_encode(pack('v*', ...array_map(fn(float $value) => (int) round(max(0, $value) / $max * 65535), $this->values))),
        ];
    }

    /**
     * @param  array{width: int, height: int, max: float, values: string}  $stored
     */
    public static function fromArray(array $stored): self
    {
        $steps = array_values((array) unpack('v*', (string) base64_decode($stored['values'])));

        return new self((int) $stored['width'], (int) $stored['height'], array_map(fn(int $step) => $step / 65535 * (float) $stored['max'], $steps));
    }
}
