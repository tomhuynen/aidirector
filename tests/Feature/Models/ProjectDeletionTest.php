<?php

declare(strict_types=1);

use App\Ai\Agents\ProjectIntake;
use App\Enums\Disk;
use App\Models\Director;
use App\Models\Generation;
use App\Models\Keyframe;
use App\Models\Media;
use App\Models\PhotoSuggestion;
use App\Models\Project;
use App\Models\Shot;
use App\Models\StyleOption;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Disk::TENANT->value);

    $this->director = Director::factory()->create();
    $this->project = Project::factory()->ownedBy($this->director)->create();
});

/**
 * A project with something of every kind attached, files included.
 */
function furnishedProject(Project $project, Director $director): void
{
    $project->addMedia(UploadedFile::fake()->image('photo.jpg'))->toMediaCollection(Project::CONTENT_REFERENCES);
    $project->addMedia(UploadedFile::fake()->image('anchor.png'))->toMediaCollection(Project::STYLE_REFERENCES);

    $shot = Shot::factory()->for($project)->create();
    $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 32);
    $shot->addMediaFromString($mp4)->usingFileName('clip.mp4')->toMediaCollection(Shot::VIDEO);
    $keyframe = Keyframe::factory()->for($shot)->create();
    $keyframe->addMedia(UploadedFile::fake()->image('render.png'))->toMediaCollection(Keyframe::RENDERS);

    $parent = StyleOption::factory()->for($project)->ready()->create(['round' => 1]);
    $parent->addMedia(UploadedFile::fake()->image('sheet.png'))->toMediaCollection(StyleOption::RENDER);
    $child = StyleOption::factory()->childOf($parent)->ready()->create();
    $child->addMedia(UploadedFile::fake()->image('sheet-2.png'))->toMediaCollection(StyleOption::RENDER);

    PhotoSuggestion::factory()->for($project)->create();
    $parent->generations()->create(['director_id' => $director->id, 'kind' => 'image', 'provider' => 'openrouter', 'model' => 'x']);

    ProjectIntake::fake([['reply' => 'Hi', 'ask' => null, 'done' => false]]);
    $conversationId = (new ProjectIntake())->forUser($director)->prompt('Damen', provider: 'openrouter', model: 'test')->conversationId;
    $project->update(['conversation_id' => $conversationId]);
}

it('removes every relation and every file when a project is deleted', function () {
    furnishedProject($this->project, $this->director);
    $other = Project::factory()->ownedBy($this->director)->create();
    $other->addMedia(UploadedFile::fake()->image('keep.jpg'))->toMediaCollection(Project::CONTENT_REFERENCES);

    expect(Storage::disk(Disk::TENANT->value)->allFiles())->not->toBeEmpty();

    actingAs($this->director, 'director')
        ->delete(route('public.projects.destroy', $this->project))
        ->assertRedirect(route('public.projects.index'));

    expect(Project::query()->whereKey($this->project->id)->exists())->toBeFalse()
        ->and(Shot::query()->count())->toBe(0)
        ->and(Keyframe::query()->count())->toBe(0)
        ->and(StyleOption::query()->count())->toBe(0)
        ->and(PhotoSuggestion::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0)
        ->and(ConversationMessage::query()->count())->toBe(0)
        ->and(Media::query()->pluck('model_id')->all())->toBe([$other->id])
        ->and(Generation::query()->count())->toBe(1);

    $files = Storage::disk(Disk::TENANT->value)->allFiles();

    expect($files)->not->toBeEmpty()
        ->and(collect($files)->every(fn(string $file) => str_contains($file, 'keep')))->toBeTrue();
});

it('removes a shot\'s keyframes and their renders when the shot is deleted', function () {
    $shot = Shot::factory()->for($this->project)->create();
    $keyframe = Keyframe::factory()->for($shot)->create();
    $keyframe->addMedia(UploadedFile::fake()->image('render.png'))->toMediaCollection(Keyframe::RENDERS);
    $path = $keyframe->getFirstMedia(Keyframe::RENDERS)->getPathRelativeToRoot();

    $shot->delete();

    expect(Keyframe::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);

    Storage::disk(Disk::TENANT->value)->assertMissing($path);
});

it('keeps branched style options when their parent is deleted', function () {
    $parent = StyleOption::factory()->for($this->project)->create();
    $child = StyleOption::factory()->childOf($parent)->create();

    $parent->delete();

    expect($child->fresh()->parent_id)->toBeNull();
});
