<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Support\Shots\KeyframeStaging;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KeyframeRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** What happens in the keyframe, in one short line. */
            'summary' => ['required', 'string', 'max:300'],
            /** The spot, with the things on it and their state. */
            'place' => ['nullable', 'string', 'max:1000'],
            /** Per person of the cast how they are staged. */
            'cast' => ['present', 'array', 'max:8'],
            'cast.*.name' => ['required', 'string', 'max:100'],
            'cast.*.stands' => ['nullable', 'string', Rule::in(KeyframeStaging::STANDS)],
            'cast.*.faces' => ['nullable', 'string', Rule::in(KeyframeStaging::FACES)],
            'cast.*.looks_at' => ['nullable', 'string', 'max:200'],
            'cast.*.holds' => ['nullable', 'string', 'max:200'],
            'cast.*.hand' => ['nullable', 'string', Rule::in(KeyframeStaging::HANDS)],
            'cast.*.doing' => ['nullable', 'string', 'max:300'],
            /** The one fact a viewer must be able to check at a glance; sent as the last sentence of the description. */
            'spatial' => ['nullable', 'string', 'max:500'],
            // Draw the keyframe again from its description instead of adjusting the current image to the change.
            'redraw' => ['sometimes', 'boolean'],
            // The keyframes drawn from this one are drawn again too, so they follow the change.
            'following' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The staging as the director edited it.
     */
    public function staging(): KeyframeStaging
    {
        return KeyframeStaging::fromArray($this->safe()->only(['summary', 'place', 'cast']));
    }
}
