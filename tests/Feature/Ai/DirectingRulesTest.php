<?php

declare(strict_types=1);

use App\Ai\Briefs\BodyRules;
use App\Ai\Briefs\DirectingRules;
use App\Ai\Briefs\RenderCapabilities;
use App\Ai\Briefs\VideoLimitsBrief;
use App\Enums\ShotSize;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * @return list<string>
 */
function tempoWords(): array
{
    $words = Str::of(BodyRules::NO_TEMPO)->between('such as ', '. ')->replace(' or ', ', ')->explode(', ');

    return [...$words->map(fn(string $word) => trim($word))->filter()->values()->all(), 'pause', 'stillness'];
}

it('directs without tempo words or pauses', function () {
    $brief = Str::lower(DirectingRules::brief());

    expect(tempoWords())->toContain('slowly', 'for a beat', 'pause');

    foreach (tempoWords() as $word) {
        expect(preg_match('/\b' . preg_quote($word, '/') . '\b/', $brief))->toBe(0, "The directing rules contain \"{$word}\".");
    }
});

it('keeps the start and end frame mechanics out of the directing rules', function () {
    expect(DirectingRules::brief())
        ->toContain('the decisive change')
        ->toContain('Medium shot at eye level')
        ->not->toContain('end frame')
        ->not->toContain('match on action')
        ->not->toContain('mid-action')
        ->not->toContain('Kling')
        ->not->toContain('Luma')
        ->not->toContain('image model')
        ->not->toContain('video model');
});

it('marks the bag example as scene-specific and contrasts it with a warning sign', function () {
    expect(DirectingRules::brief())
        ->toContain('Approved example, the bag in the corridor:')
        ->toContain('"Exit right" is not a default.')
        ->toContain('Approved example, the warning sign:')
        ->toContain('the pictogram cannot be read clearly in a shot of her')
        ->toContain('No insert is needed when the sign can be read clearly in the shot of her');
});

it('describes the sequence render when a sequence video model is set', function () {
    Config::set('pipeline.models.sequence_video', 'kwaivgi/kling-v3-omni-video');

    expect(RenderCapabilities::brief())
        ->toBe(RenderCapabilities::sequence())
        ->toContain('No end frame is sent.')
        ->toContain('one line per shot with its framing and its action')
        ->not->toContain(VideoLimitsBrief::brief())
        ->not->toContain('mid-action');
});

it('describes the keyframe pairs when no sequence video model is set', function () {
    Config::set('pipeline.models.sequence_video', '');

    expect(RenderCapabilities::brief())
        ->toBe(RenderCapabilities::keyframePairs())
        ->toContain(VideoLimitsBrief::brief())
        ->toContain('as a match on action')
        ->toContain('is mid-action: a hand halfway to the handle');
});

it('offers a face close-up and anchors the main wide view to the picture of the place', function () {
    expect(DirectingRules::brief())
        ->toContain('- close-up with subject face: ' . ShotSize::faceFraming())
        ->toContain("the main wide view of a place stands where that picture's camera stands");
});
