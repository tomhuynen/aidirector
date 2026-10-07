<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Enums\ShotKind;
use App\Enums\ShotSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

class ShotPlanRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** What happens in the shot, in a few sentences. */
            'storyline' => ['nullable', 'string', 'max:2000'],
            'keyframes' => ['present', 'array', 'max:' . (int) Config::get('pipeline.keyframes.max_manual')],
            'keyframes.*.title' => ['required', 'string', 'max:120'],
            /** Exactly what the keyframe shows; it goes to the image model as written. */
            'keyframes.*.description' => ['required', 'string', 'max:2000'],
            /** The ids of the cast and sets in the keyframe; their pictures go to the image model. */
            'keyframes.*.elements' => ['sometimes', 'array'],
            'keyframes.*.elements.*' => ['string'],
            /** What must always or never happen in this shot; every drawing, adjustment and check follows them. */
            'rules' => ['sometimes', 'array', 'max:20'],
            'rules.*' => ['nullable', 'string', 'max:300'],
            /** A scene at one place, or a montage of separate stills joined with crossfades. */
            'kind' => ['sometimes', Rule::enum(ShotKind::class)],
            'framing' => ['required', 'array'],
            'framing.size' => ['required', Rule::enum(ShotSize::class)],
            /** Where in the place the shot plays, the same in every keyframe. */
            'framing.spot' => ['nullable', 'string', 'max:500'],
            'framing.light' => ['nullable', 'string', 'max:300'],
            'framing.seconds' => ['nullable', 'integer', 'min:2', 'max:30'],
        ];
    }
}
