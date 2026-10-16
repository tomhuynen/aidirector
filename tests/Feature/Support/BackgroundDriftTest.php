<?php

declare(strict_types=1);

use App\Enums\Disk;
use App\Models\Keyframe;
use App\Support\Images\BackgroundDrift;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);
});

/**
 * A place of blocks in a fixed pattern; `$from` moves where the pattern starts, as a camera that jumped would.
 */
function placeImage(Keyframe $keyframe, int $from = 0, int $person = 0): Media
{
    $image = imagecreatetruecolor(288, 512);
    mt_srand(7);

    for ($y = 0; $y < 512; $y += 16) {
        for ($x = 0; $x < 288; $x += 16) {
            $shade = mt_rand(0, 255);
            imagefilledrectangle($image, $x + $from, $y + $from, $x + $from + 15, $y + $from + 15, imagecolorallocate($image, $shade, $shade, $shade));
        }
    }

    // A person in the middle third, who may change between keyframes.
    if ($person > 0) {
        imagefilledrectangle($image, 96, 170, 192, 340, imagecolorallocate($image, $person, 40, 40));
    }

    ob_start();
    imagepng($image);

    return $keyframe->addMediaFromString((string) ob_get_clean())->usingFileName('render.png')->toMediaCollection(Keyframe::RENDERS);
}

it('keeps a render where only the person changed', function () {
    $keyframe = Keyframe::factory()->create();

    $measured = app(BackgroundDrift::class)->measure(placeImage($keyframe), placeImage($keyframe, person: 220));

    expect($measured['stillness'])->toBeGreaterThan(0.5)
        ->and($measured['moved'])->toBeFalse();
});

it('counts a render as moved when the camera jumped further than a shift or zoom brings back', function () {
    $keyframe = Keyframe::factory()->create();

    $measured = app(BackgroundDrift::class)->measure(placeImage($keyframe), placeImage($keyframe, from: 120));

    expect($measured['stillness'])->toBeLessThan(0.5)
        ->and($measured['moved'])->toBeTrue();
});
