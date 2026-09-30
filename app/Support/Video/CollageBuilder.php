<?php

declare(strict_types=1);

namespace App\Support\Video;

use Illuminate\Support\Facades\Config;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use InvalidArgumentException;

/**
 * Lays the keyframe renders out as one numbered storyboard: equal panels in a
 * single row up to five, a grid above that, each with a round number badge in
 * the top-left corner. Composed on our side so nothing is invented.
 */
class CollageBuilder
{
    public const MAX_ROW = 5;

    private const BACKGROUND = '#ffffff';

    private const BADGE = '#111827';

    /**
     * @param  list<string>  $images  The raw bytes of each keyframe render, in order.
     * @return string The collage as JPEG bytes.
     */
    public function build(array $images): string
    {
        if ($images === []) {
            throw new InvalidArgumentException('A collage needs at least one keyframe.');
        }

        $panelHeight = (int) Config::get('pipeline.video.collage_panel_height', 720);
        $panels = array_map(fn(string $bytes) => $this->panel($bytes, $panelHeight), $images);

        $panelWidth = max(array_map(fn(Imagick $panel) => $panel->getImageWidth(), $panels));
        $columns = count($panels) <= self::MAX_ROW ? count($panels) : (int) ceil(sqrt(count($panels)));
        $rows = (int) ceil(count($panels) / $columns);
        $gutter = (int) round($panelHeight * 0.03);

        $collage = new Imagick();
        $collage->newImage(
            $columns * $panelWidth + ($columns + 1) * $gutter,
            $rows * $panelHeight + ($rows + 1) * $gutter,
            new ImagickPixel(self::BACKGROUND),
        );

        foreach ($panels as $index => $panel) {
            $x = $gutter + ($index % $columns) * ($panelWidth + $gutter) + intdiv($panelWidth - $panel->getImageWidth(), 2);
            $y = $gutter + intdiv($index, $columns) * ($panelHeight + $gutter);

            $collage->compositeImage($panel, Imagick::COMPOSITE_OVER, $x, $y);
            $this->badge($collage, $index + 1, $x, $y, $panelHeight);
            $panel->clear();
        }

        $collage->setImageFormat('jpeg');
        $collage->setImageCompressionQuality(88);

        $bytes = $collage->getImageBlob();
        $collage->clear();

        return $bytes;
    }

    private function panel(string $bytes, int $height): Imagick
    {
        $panel = new Imagick();
        $panel->readImageBlob($bytes);
        $panel->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $panel->thumbnailImage(0, $height);

        return $panel;
    }

    /**
     * A filled circle with the keyframe number, sized relative to the panel.
     */
    private function badge(Imagick $collage, int $number, int $x, int $y, int $panelHeight): void
    {
        $radius = (int) round($panelHeight * 0.055);
        $margin = (int) round($panelHeight * 0.03);
        $centerX = $x + $margin + $radius;
        $centerY = $y + $margin + $radius;

        $circle = new ImagickDraw();
        $circle->setFillColor(new ImagickPixel(self::BADGE));
        $circle->setStrokeColor(new ImagickPixel('#ffffff'));
        $circle->setStrokeWidth(max(2, $radius / 10));
        $circle->circle($centerX, $centerY, $centerX + $radius, $centerY);
        $collage->drawImage($circle);

        $label = new ImagickDraw();
        $label->setFont(resource_path('fonts/SourceSans3-Bold.ttf'));
        $label->setFontSize($radius * 1.3);
        $label->setFillColor(new ImagickPixel('#ffffff'));
        $label->setTextAlignment(Imagick::ALIGN_CENTER);

        $metrics = $collage->queryFontMetrics($label, (string) $number);
        $baseline = $centerY + ($metrics['ascender'] + $metrics['descender']) / 2;

        $collage->annotateImage($label, $centerX, $baseline, 0, (string) $number);
    }
}
