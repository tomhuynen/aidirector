<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | OpenRouter model slugs used by the generation pipeline. Text drives the
    | director and keyframe planner, image generates assets and keyframes,
    | video turns the keyframes into a clip.
    |
    */

    'models' => [
        'text' => env('AI_DIRECTOR_MODEL', 'openai/gpt-5.5'),
        // Creates keyframes and style sheets: stays closest to the pinned style.
        'image' => env('AI_IMAGE_MODEL', 'google/gemini-3.1-flash-image-preview'),
        // Adjusts an existing keyframe (pose, gaze, details) and leaves the rest alone.
        'image_edit' => env('AI_IMAGE_EDIT_MODEL', 'openai/gpt-5.4-image-2'),
        'video' => env('AI_VIDEO_MODEL', 'bytedance/seedance-2.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Director behaviour
    |--------------------------------------------------------------------------
    */

    'options_count' => 3,

    /*
     * Tiles per round of the style exploration.
     */
    'style_options_count' => 4,

    /*
    | Image size for keyframes, options and cast and sets: low, medium or high
    | (1K, 2K or 4K on OpenRouter's Gemini image models). Low keeps tests quick.
    */
    'image_quality' => env('AI_IMAGE_QUALITY', 'low'),

    'keyframes' => [
        'min' => 3,
        'max' => 6,
        // Variations of keyframe 1 the director chooses from before the rest renders.
        'first_options' => (int) env('AI_FIRST_KEYFRAME_OPTIONS', 3),
        // Reference images of the cast and sets attached to one keyframe render.
        'max_element_references' => 3,
    ],

    'queue' => 'ai',

    /*
    |--------------------------------------------------------------------------
    | Video
    |--------------------------------------------------------------------------
    |
    | The video model animates the keyframes, sent as reference images. Durations are
    | clamped to what the model accepts; the job polls OpenRouter until the
    | clip is done or the wait runs out.
    |
    */

    'video' => [
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        // The default for projects without their own; the lowest the model offers, for quick tests.
        'resolution' => env('AI_VIDEO_RESOLUTION', '480p'),
        'resolutions' => ['480p', '720p', '1080p', '4K'],
        'min_duration' => 4,
        'max_duration' => 15,
        'poll_seconds' => 20,
        'max_wait_minutes' => 30,
    ],

];
