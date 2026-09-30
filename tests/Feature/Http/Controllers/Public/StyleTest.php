<?php

declare(strict_types=1);

use App\Ai\Agents\StyleOptionsWriter;
use App\Enums\Disk;
use App\Jobs\GenerateStyleOption;
use App\Models\Director;
use App\Models\Generation;
use App\Models\Project;
use App\Models\StyleOption;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Ai\Prompts\AgentPrompt;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Queue::fake();
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

function proposedStyles(): array
{
    return [
        ['name' => 'Documentary photoreal', 'look' => 'Realistic detail', 'medium' => 'Photograph', 'mood' => 'Honest', 'palette' => 'Natural', 'lighting' => 'Daylight'],
        ['name' => 'Painterly', 'look' => 'Visible brush strokes', 'medium' => 'Gouache painting', 'mood' => 'Warm', 'palette' => 'Earth tones', 'lighting' => 'Golden hour'],
        ['name' => 'Soft 3D cartoon', 'look' => 'Rounded simplified shapes', 'medium' => '3D render', 'mood' => 'Friendly', 'palette' => 'Navy and warm grey', 'lighting' => 'Soft overcast'],
        ['name' => 'Flat vector', 'look' => 'Flat shapes, no outlines', 'medium' => 'Flat vector', 'mood' => 'Crisp', 'palette' => 'Bold primaries', 'lighting' => 'Flat'],
    ];
}

function addContentPhoto(Project $project, string $name, ?string $caption): void
{
    $adder = $project->addMedia(UploadedFile::fake()->image($name, 640, 480))->usingFileName($name);

    if ($caption !== null) {
        $adder->withCustomProperties([Project::CAPTION => $caption]);
    }

    $adder->toMediaCollection(Project::CONTENT_REFERENCES);
}

describe('rounds', function () {
    it('starts the first round from the content photos and queues a render per style', function () {
        StyleOptionsWriter::fake([['styles' => proposedStyles()]]);
        addContentPhoto($this->project, 'tug.jpg', 'A grey harbour tug with a red stripe.');
        addContentPhoto($this->project, 'yard.jpg', null);

        $response = actingAs($this->director, 'director')
            ->postJson(route('public.projects.style.round', $this->project))
            ->assertSuccessful()
            ->assertJsonCount(4)
            ->assertJsonPath('0.name', 'Documentary photoreal')
            ->assertJsonPath('0.status', 'pending')
            ->assertJsonPath('0.round', 1)
            ->assertJsonPath('0.thumbnailUrl', null)
            ->assertJsonPath('3.position', 4);

        $options = $this->project->styleOptions()->get();

        expect($options)->toHaveCount(4)
            ->and($options[2]->style()['name'])->toBe('Soft 3D cartoon')
            ->and($options[2]->prompt)->toContain('two panels side by side')
            ->and($options[2]->prompt)->toContain('1. A grey harbour tug with a red stripe.')
            ->and($options[2]->prompt)->toContain('Style: Soft 3D cartoon')
            ->and($options[2]->parent_id)->toBeNull()
            ->and($response->json('2.links.pin'))->toBe(route('public.projects.style.pin', [$this->project, $options[2]]))
            ->and(Generation::query()->where('kind', 'text')->count())->toBe(1);

        Queue::assertPushed(GenerateStyleOption::class, 4);

        StyleOptionsWriter::assertPrompted(fn(AgentPrompt $prompt) => str_contains($prompt->prompt, 'photoreal to flat cartoon'));
    });

    it('branches a later round from a parent option', function () {
        StyleOptionsWriter::fake([['styles' => proposedStyles()]]);
        $parent = StyleOption::factory()->for($this->project)->ready()->create(['round' => 2]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.style.round', $this->project), ['parent' => $parent->sqid])
            ->assertSuccessful()
            ->assertJsonPath('0.round', 3);

        expect($this->project->styleOptions()->where('round', 3)->pluck('parent_id')->unique()->all())->toBe([$parent->id]);

        StyleOptionsWriter::assertPrompted(fn(AgentPrompt $prompt) => str_contains($prompt->prompt, 'Name: Soft 3D cartoon'));
    });

    it('rejects a parent from another project', function () {
        StyleOptionsWriter::fake();
        $foreign = StyleOption::factory()->create();

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.style.round', $this->project), ['parent' => $foreign->sqid])
            ->assertNotFound();

        Queue::assertNothingPushed();
    });

    it('lists the options of a round with their renders', function () {
        $ready = StyleOption::factory()->for($this->project)->ready()->create(['round' => 1, 'position' => 1]);
        $ready->addMedia(UploadedFile::fake()->image('sheet.png', 1600, 900))->toMediaCollection(StyleOption::RENDER);
        StyleOption::factory()->for($this->project)->failed('Nope')->create(['round' => 1, 'position' => 2]);
        StyleOption::factory()->for($this->project)->create(['round' => 2]);

        $response = actingAs($this->director, 'director')
            ->getJson(route('public.projects.style.options', [$this->project, 1]))
            ->assertSuccessful()
            ->assertJsonCount(2)
            ->assertJsonPath('0.status', 'ready')
            ->assertJsonPath('1.status', 'failed')
            ->assertJsonPath('1.error', 'Nope');

        $thumbnail = $response->json('0.thumbnailUrl');

        expect($thumbnail)->toContain(route('public.media.view', [$ready->render(), StyleOption::THUMBNAIL]))
            ->and($response->json('0.imageUrl'))->toContain(route('public.media.view', [$ready->render()]));

        actingAs($this->director, 'director')
            ->get($thumbnail)
            ->assertSuccessful()
            ->assertHeader('content-type', 'image/jpeg');
    });

    it('forbids another director', function () {
        StyleOptionsWriter::fake();
        $other = Director::factory()->create();

        actingAs($other, 'director')
            ->postJson(route('public.projects.style.round', $this->project))
            ->assertForbidden();

        actingAs($other, 'director')
            ->getJson(route('public.projects.style.options', [$this->project, 1]))
            ->assertForbidden();
    });
});

describe('pin', function () {
    it('makes the option the style anchor and fills the project style', function () {
        $option = StyleOption::factory()->for($this->project)->ready()->create();
        $option->addMedia(UploadedFile::fake()->image('sheet.png', 1600, 900))->usingFileName('sheet.png')->toMediaCollection(StyleOption::RENDER);
        $earlier = StyleOption::factory()->for($this->project)->ready()->create(['pinned_at' => now()]);

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.style.pin', [$this->project, $option]))
            ->assertSuccessful()
            ->assertJsonPath('option.pinned', true)
            ->assertJsonPath('project.style.medium', '3D render');

        $project = $this->project->fresh();
        $anchor = $project->getFirstMedia(Project::STYLE_REFERENCES);

        expect($option->fresh()->isPinned())->toBeTrue()
            ->and($earlier->fresh()->isPinned())->toBeFalse()
            ->and($anchor)->not->toBeNull()
            ->and($anchor->file_name)->toBe('sheet.png')
            ->and($anchor->getCustomProperty(Project::CAPTION))->toStartWith('Soft 3D cartoon.')
            ->and($option->fresh()->render())->not->toBeNull()
            ->and($project->style['look'])->toBe('Rounded, simplified shapes with soft shading Soft overcast daylight')
            ->and($project->style['palette'])->toBe('Navy, warm grey, red accent')
            ->and($project->style['mood'])->toBe('Friendly and calm');

        Storage::disk(Disk::TENANT->value)->assertExists($anchor->getPathRelativeToRoot(Project::REFERENCE));
    });

    it('refuses to pin an option that is not rendered', function () {
        $option = StyleOption::factory()->for($this->project)->create();

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.style.pin', [$this->project, $option]))
            ->assertUnprocessable();

        expect($option->fresh()->isPinned())->toBeFalse();
    });

    it('scopes the option to the project', function () {
        $foreign = StyleOption::factory()->ready()->create();

        actingAs($this->director, 'director')
            ->postJson(route('public.projects.style.pin', [$this->project, $foreign]))
            ->assertNotFound();
    });
});

describe('media', function () {
    it('rejects unsigned media links', function () {
        $option = StyleOption::factory()->for($this->project)->ready()->create();
        $option->addMedia(UploadedFile::fake()->image('sheet.png'))->toMediaCollection(StyleOption::RENDER);

        actingAs($this->director, 'director')
            ->get(route('public.media.view', [$option->render()]))
            ->assertForbidden();
    });

    it('returns not found for a conversion that does not exist', function () {
        $option = StyleOption::factory()->for($this->project)->ready()->create();
        $option->addMedia(UploadedFile::fake()->image('sheet.png'))->toMediaCollection(StyleOption::RENDER);

        actingAs($this->director, 'director')
            ->get(URL::temporarySignedRoute('public.media.view', now()->addMinute(), ['media' => $option->render(), 'conversion' => 'huge']))
            ->assertNotFound();
    });
});
