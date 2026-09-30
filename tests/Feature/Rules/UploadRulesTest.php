<?php

declare(strict_types=1);

use App\Models\Upload;
use App\Rules\UploadExists;
use App\Rules\UploadIsImage;
use App\Rules\UploadMaxSize;
use Illuminate\Support\Facades\Validator;

$passes = fn(mixed $value, mixed $rule): bool => Validator::make(['upload' => $value], ['upload' => [$rule]])->passes();

describe('UploadExists', function () use ($passes) {
    it('accepts an existing upload and empty values', function () use ($passes) {
        $upload = Upload::factory()->create();

        expect($passes($upload->sqid, new UploadExists()))->toBeTrue()
            ->and($passes(null, new UploadExists()))->toBeTrue()
            ->and($passes('', new UploadExists()))->toBeTrue();
    });

    it('rejects unknown and malformed values', function () use ($passes) {
        expect($passes('nope', new UploadExists()))->toBeFalse()
            ->and($passes(['x'], new UploadExists()))->toBeFalse();
    });
});

describe('UploadIsImage', function () use ($passes) {
    it('accepts images, empty values and unknown uploads', function () use ($passes) {
        $image = Upload::factory()->named('photo.jpg')->create();

        expect($passes($image->sqid, new UploadIsImage()))->toBeTrue()
            ->and($passes(null, new UploadIsImage()))->toBeTrue()
            ->and($passes('nope', new UploadIsImage()))->toBeTrue();
    });

    it('rejects other file types', function () use ($passes) {
        $pdf = Upload::factory()->named('brief.pdf')->create();

        expect($passes($pdf->sqid, new UploadIsImage()))->toBeFalse();
    });
});

describe('UploadMaxSize', function () use ($passes) {
    it('accepts uploads within the limit', function () use ($passes) {
        $upload = Upload::factory()->size(1024)->create();

        expect($passes($upload->sqid, new UploadMaxSize(1024)))->toBeTrue()
            ->and($passes(null, new UploadMaxSize(1)))->toBeTrue();
    });

    it('rejects uploads over the limit', function () use ($passes) {
        $upload = Upload::factory()->size(2048)->create();

        expect($passes($upload->sqid, new UploadMaxSize(1024)))->toBeFalse();
    });
});
