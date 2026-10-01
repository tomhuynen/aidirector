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
        'image' => env('AI_IMAGE_MODEL', 'google/gemini-3.1-flash-image-preview'),
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

    'keyframes' => [
        'min' => 3,
        'max' => 6,
        // Variations of keyframe 1 the director chooses from before the rest renders.
        'first_options' => (int) env('AI_FIRST_KEYFRAME_OPTIONS', 3),
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
        'resolution' => env('AI_VIDEO_RESOLUTION', '720p'),
        'resolutions' => ['480p', '720p', '1080p', '4K'],
        'min_duration' => 4,
        'max_duration' => 15,
        'poll_seconds' => 20,
        'max_wait_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Photo search
    |--------------------------------------------------------------------------
    |
    | Google image results through Serper, used to suggest contextual photos
    | in the intake chat. Each query searches the client's website first and
    | tops up from the whole web. Results smaller than min_edge are dropped.
    |
    */

    'photo_search' => [
        'api_key' => env('SERPER_API_KEY'),
        'endpoint' => env('SERPER_IMAGES_URL', 'https://google.serper.dev/images'),
        'per_query' => 8,
        'max_queries' => 3,
        'min_edge' => 600,
        'max_download_bytes' => 15 * 1024 * 1024,
        // Serper charges one credit per request; $1 per 1,000 at the smallest pack.
        'cost_per_request' => 0.001,
    ],
];
