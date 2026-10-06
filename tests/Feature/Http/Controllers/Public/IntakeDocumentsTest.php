<?php

declare(strict_types=1);

use App\Ai\Agents\ProjectIntake;
use App\Models\Director;
use App\Models\Upload;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake(Config::get('uploads.disk'));
    $this->director = Director::factory()->create();

    ProjectIntake::fake([
        ['reply' => 'Thanks, I read it.', 'description' => null, 'purpose' => null, 'title' => null, 'ask' => null, 'done' => false],
    ]);
});

function stagedDocument(string $name, string $mime, string $contents): Upload
{
    $upload = Upload::factory()->create(['name' => $name, 'mime_type' => $mime]);
    Storage::disk(Config::get('uploads.disk'))->put($upload->path, $contents);

    return $upload;
}

it('takes a long pasted brief in one message', function () {
    actingAs($this->director, 'director')
        ->postJson(route('public.projects.chat'), ['conversation' => null, 'message' => str_repeat('Safety intelligence. ', 750)])
        ->assertSuccessful();

    ProjectIntake::assertPrompted(fn($prompt) => mb_strlen($prompt->prompt) > 15000);
});

it('reads a shared text document into the conversation before the project exists', function () {
    $document = stagedDocument('ontwerp.md', 'text/markdown', "# Functioneel ontwerp\n\nSignaal naar training.");

    actingAs($this->director, 'director')
        ->postJson(route('public.projects.chat'), ['conversation' => null, 'message' => 'Here is the design', 'uploads' => [$document->sqid]])
        ->assertSuccessful();

    ProjectIntake::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'Here is the design')
        && str_contains($prompt->prompt, "The director shared the document \"ontwerp.md\":\n<<<\n# Functioneel ontwerp\n\nSignaal naar training.\n>>>"));

    expect(Upload::query()->find($document->id))->toBeNull();
});

it('reads the text of a shared PDF', function () {
    Process::fake(['*' => Process::result('Signal detection and training updates.')]);
    $document = stagedDocument('design.pdf', 'application/pdf', '%PDF-1.4 fake');

    actingAs($this->director, 'director')
        ->postJson(route('public.projects.chat'), ['conversation' => null, 'message' => '', 'uploads' => [$document->sqid]])
        ->assertSuccessful();

    ProjectIntake::assertPrompted(fn($prompt) => str_contains($prompt->prompt, "\"design.pdf\":\n<<<\nSignal detection and training updates.\n>>>"));
    Process::assertRan(fn($process) => in_array('-layout', $process->command, true));
});

it('reads the text of a shared RTF document, as TextEdit writes them', function () {
    $rtf = "{\\rtf1\\ansi\\ansicpg1252\\cocoartf2822\n{\\fonttbl\\f0\\fswiss\\fcharset0 Helvetica;}\n{\\colortbl;\\red255\\green255\\blue255;}\n{\\*\\expandedcolortbl;;}\n\\pard\\tx560\\pardirnatural\\partightenfactor0\n\n\\f0\\fs24 \\cf0 # Functioneel ontwerp\\\n\\\nSignaal \\uc0\\u8594  Training\\\nCaf\\'e9, co\\'f6rdinatie.}";
    $document = stagedDocument('ontwerp.rtf', 'text/rtf', $rtf);

    actingAs($this->director, 'director')
        ->postJson(route('public.projects.chat'), ['conversation' => null, 'message' => '', 'uploads' => [$document->sqid]])
        ->assertSuccessful();

    ProjectIntake::assertPrompted(fn($prompt) => str_contains($prompt->prompt, "\"ontwerp.rtf\":\n<<<\n# Functioneel ontwerp\n\nSignaal → Training\nCafé, coördinatie.\n>>>"));
});

it('says so when a document cannot be read', function () {
    Process::fake(['*' => Process::result(errorOutput: 'broken', exitCode: 1)]);
    $document = stagedDocument('broken.pdf', 'application/pdf', '%PDF-1.4 broken');

    actingAs($this->director, 'director')
        ->postJson(route('public.projects.chat'), ['conversation' => null, 'message' => 'Design attached', 'uploads' => [$document->sqid]])
        ->assertSuccessful();

    ProjectIntake::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'tried to share the document "broken.pdf", but it could not be read'));
});

it('still needs the project before photos can be added', function () {
    $photo = Upload::factory()->create();

    actingAs($this->director, 'director')
        ->postJson(route('public.projects.chat'), ['conversation' => null, 'message' => 'A photo', 'uploads' => [$photo->sqid]])
        ->assertJsonValidationErrors('uploads');
});

it('asks for a functional design document as its second question', function () {
    expect((string) (new ProjectIntake())->instructions())
        ->toContain('Your second question, right after the user first describes the project, is whether they have a document with the functional design')
        ->toContain('between <<< and >>>');
});
