<?php

declare(strict_types=1);

use App\Ai\Agents\KeyframeChecker;
use App\Ai\Agents\StorylineWriter;
use App\Ai\Briefs\KeyframeImageBrief;
use App\Ai\KeyframePainter;
use App\Enums\Disk;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GeneratePlateOption;
use App\Models\Director;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function closeUpShot(Project $project): Shot
{
    return Shot::factory()->for($project)->create([
        'kind' => ShotKind::CLOSE_UP,
        'status' => ShotStatus::FIRST_KEYFRAME_PENDING,
        'chosen_storyline' => ['title' => 'Badge On', 'storyline' => 'She clips the badge high on her chest.'],
        'storyline' => ['framing' => ['spot' => 'The chest of her hi-vis vest.', 'seconds' => 4], 'keyframes' => [
            ['title' => 'Badge In Hand', 'description' => 'The vest pocket, the badge held in her right hand.', 'spatial' => 'The badge is a hand away from the pocket.'],
            ['title' => 'Badge Clipped', 'description' => 'The badge clipped on the vest pocket.', 'spatial' => 'The badge is high and centred on the chest.'],
        ]],
    ]);
}

it('plans a close-up by its own rules', function () {
    $shot = Shot::factory()->for($this->project)->make(['kind' => ShotKind::CLOSE_UP]);
    $instructions = (string) (new StorylineWriter($shot))->instructions();

    expect($instructions)
        ->toContain('This shot is a close-up: give close-up as the kind.')
        ->toContain('only the hands, forearms and sleeves are in the frame, never a face')
        ->not->toContain('Keyframe 1 sets the camera for the whole shot');
});

it('starts from close views of the empty surface, like a scene on its place', function () {
    Config::set('pipeline.keyframes.start_with_plate', true);
    Image::fake(function () {
        $image = imagecreatetruecolor(90, 160);
        ob_start();
        imagepng($image);

        return base64_encode((string) ob_get_clean());
    });
    Bus::fake([GeneratePlateOption::class]);
    $shot = closeUpShot($this->project);

    (new GenerateKeyframes($shot))->handle();

    Bus::assertBatched(fn($batch) => $batch->jobs->every(fn($job) => $job instanceof GeneratePlateOption));

    (new GeneratePlateOption($shot, 0))->handle(app(KeyframePainter::class));

    Image::assertGenerated(fn(ImagePrompt $prompt) => $prompt->contains('Frame it close on the surface where the hands will act')
        && $prompt->contains('Close-up at eye level'));
});

it('adds only the hands when a keyframe is drawn on the surface, and checks it as a close-up', function () {
    $shot = closeUpShot($this->project);
    $shot->load('project');
    expect(KeyframeImageBrief::for($shot, $shot->storylineKeyframes()[1], new App\Ai\KeyframeReferences(
        first: Laravel\Ai\Files\Image::fromStorage('x.png', Disk::TENANT->value),
        castNames: ['Helmeted visitor'],
        firstShowsCast: false,
        firstIsPlate: true,
    )))
        ->toContain('Add the hands and forearms of Helmeted visitor into it')
        ->toContain('The badge clipped on the vest pocket. The badge is high and centred on the chest.');

    $keyframe = App\Models\Keyframe::factory()->for($shot)->create(['position' => 2]);

    expect((string) (new KeyframeChecker($keyframe, ['the keyframe to check']))->instructions())->toContain('This keyframe is a close-up');
});
