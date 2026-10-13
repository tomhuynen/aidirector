<?php

declare(strict_types=1);

use App\Ai\Agents\StagingBoxer;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Support\Images\DepthGrid;
use App\Support\Images\StartFrameStaging;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * A png of the given size, filled with one colour, with an optional white rectangle.
 *
 * @param  array{int, int, int, int}|null  $white  left, top, right, bottom
 */
function stagingPng(int $width, int $height, array $fill = [90, 110, 160], ?array $white = null): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$fill));

    if ($white !== null) {
        imagefilledrectangle($image, $white[0], $white[1], $white[2], $white[3], (int) imagecolorallocate($image, 255, 255, 255));
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * The grey value at a point of a png sent as a data URL.
 */
function greyAt(string $dataUrl, int $x, int $y): int
{
    $image = new Imagick();
    $image->readImageBlob((string) base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1)));

    return (int) round($image->getImagePixelColor($x, $y)->getColorValue(Imagick::COLOR_RED) * 255);
}

beforeEach(function () {
    Config::set('services.replicate.token', 'test-token');
    // The barrier arm, found by name: a band across the middle of the place.
    Http::fake([
        'api.replicate.com/v1/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/arm.png']),
        'api.replicate.com/v1/models/black-forest-labs/flux-fill-pro/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/filled.png']),
        'replicate.delivery/arm.png' => Http::response(stagingPng(144, 256, [0, 0, 0], [0, 112, 143, 140])),
        // Fill answers at its own size; the staging is brought back to the size of the place.
        'replicate.delivery/filled.png' => Http::response(stagingPng(72, 128, [180, 180, 180])),
    ]);
    $this->cast = [['name' => 'Visitor car', 'type' => 'object'], ['name' => 'Security guard', 'type' => 'person']];
    $this->boxes = ['boxes' => [
        ['name' => 'Visitor car', 'left' => 0.4, 'top' => 0.4, 'right' => 0.9, 'bottom' => 0.9, 'facing' => 'away from the camera', 'before' => ['the striped barrier arm']],
        ['name' => 'Security guard', 'left' => 0.05, 'top' => 0.3, 'right' => 0.3, 'bottom' => 0.7, 'facing' => 'to the right of the frame', 'before' => []],
        ['name' => 'Someone not in the cast', 'left' => 0.1, 'top' => 0.1, 'right' => 0.2, 'bottom' => 0.2, 'facing' => '', 'before' => []],
    ], 'fixed_parts' => ['the striped barrier arm']];
});

it('puts a grey placeholder for each cast member into the place, one pass each, never over a fixed part', function () {
    StagingBoxer::fake([$this->boxes]);

    $staged = app(StartFrameStaging::class)->stage(stagingPng(144, 256), 'The car waits before the closed barrier.', 'The car stands on the camera side of the arm.', $this->cast);

    $size = new Imagick();
    $size->pingImageBlob($staged['image']);

    expect($staged)->not->toBeNull()
        ->and(array_column($staged['boxes'], 'name'))->toBe(['Visitor car', 'Security guard'])
        ->and([$size->getImageWidth(), $size->getImageHeight()])->toBe([144, 256]);

    $fills = Http::recorded(fn(HttpRequest $request) => str_contains($request->url(), 'flux-fill-pro'))->map(fn(array $pair) => $pair[0])->values();

    expect($fills)->toHaveCount(2)
        ->and($fills[0]['input']['prompt'])->toContain('placeholder shaped like a Visitor car')->toContain('facing away from the camera')
        ->and($fills[1]['input']['prompt'])->toContain('mannequin')
        // Inside the car's box the mask is white, but not over the barrier arm that runs through it.
        ->and(greyAt($fills[0]['input']['mask'], 100, 150))->toBe(255)
        ->and(greyAt($fills[0]['input']['mask'], 100, 125))->toBe(0)
        ->and(greyAt($fills[0]['input']['mask'], 20, 20))->toBe(0);
    StagingBoxer::assertPrompted(fn($prompt) => str_contains($prompt->prompt, '- Visitor car (object)') && count($prompt->attachments) === 1);
});

it('asks for the boxes again when the depth of the place puts someone behind what they must stand before', function () {
    StagingBoxer::fake([$this->boxes, $this->boxes]);
    // Far below the arm and near the bottom: the car's feet are deeper than the arm, so it would stand past it.
    $values = array_merge(array_fill(0, 16 * 12, 5.0), array_fill(0, 16 * 4, 30.0));
    $depth = new DepthGrid(16, 16, $values);

    app(StartFrameStaging::class)->stage(stagingPng(144, 256), 'The car waits before the closed barrier.', '', $this->cast, $depth);

    StagingBoxer::assertPromptedTimes(2);
    StagingBoxer::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Visitor car stands further from the camera than the striped barrier arm'));
});

it('does not stage without Replicate or without a cast to place', function () {
    StagingBoxer::fake();

    expect(app(StartFrameStaging::class)->stage(stagingPng(144, 256), 'An empty corridor.', '', []))->toBeNull();

    Config::set('services.replicate.token', null);

    expect(app(StartFrameStaging::class)->stage(stagingPng(144, 256), 'The car waits.', '', $this->cast))->toBeNull();
    StagingBoxer::assertNeverPrompted();
});

it('has the edit model replace only the placeholders, each with the picture of who it stands for', function () {
    expect(KeyframeImageBrief::fromStaging('The car waits before the closed barrier.', ['Visitor car', 'Security guard']))
        ->toContain('Edit image 1. It is the place of this shot with plain grey placeholders in it. Replace only the placeholders, exactly where they are')
        ->toContain('image 2 is Visitor car; image 3 is Security guard')
        ->toContain('What this keyframe shows: The car waits before the closed barrier.');
});

it('says who the drawn image shows away from their placeholder or far larger than it', function () {
    // The person is found in the top left corner, far from the box at the bottom right.
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        'api.replicate.com/v1/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/found.png']),
        'replicate.delivery/found.png' => Http::response(stagingPng(144, 256, [0, 0, 0], [0, 0, 30, 60])),
    ]);
    $boxes = [['name' => 'Security guard', 'left' => 0.6, 'top' => 0.6, 'right' => 0.8, 'bottom' => 0.9]];

    expect(app(StartFrameStaging::class)->misplaced(stagingPng(144, 256), $boxes, [['name' => 'Security guard', 'type' => 'person']]))->toBe(['Security guard']);

    // Found inside the box: fine.
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake([
        'api.replicate.com/v1/predictions' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/found.png']),
        'replicate.delivery/found.png' => Http::response(stagingPng(144, 256, [0, 0, 0], [90, 160, 110, 220])),
    ]);

    expect(app(StartFrameStaging::class)->misplaced(stagingPng(144, 256), $boxes, [['name' => 'Security guard', 'type' => 'person']]))->toBe([]);
});
