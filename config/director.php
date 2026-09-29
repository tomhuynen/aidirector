<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | OpenRouter model slugs used by the AI director pipeline. Text drives the
    | director and keyframe planner, image generates assets and keyframes,
    | video turns the keyframe collage into a clip.
    |
    */

    'models' => [
        'text' => env('AI_DIRECTOR_MODEL', 'openai/gpt-5.5'),
        'image' => env('AI_IMAGE_MODEL', 'google/gemini-3.1-flash-image-preview'),
        'video' => env('AI_VIDEO_MODEL', 'bytedance/seedance-2.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Director behaviour
    |--------------------------------------------------------------------------
    */

    'options_count' => 3,

    'keyframes' => [
        'min' => 3,
        'max' => 6,
    ],

    'queue' => 'ai',

];
