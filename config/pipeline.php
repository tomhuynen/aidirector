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
        'video' => env('AI_VIDEO_MODEL', 'alibaba/wan-3.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Director behaviour
    |--------------------------------------------------------------------------
    */

    'options_count' => 3,

    /*
     * Reasoning effort per agent, keyed by its class name in snake case: none,
     * minimal, low, medium or high, or null to leave it to the model. Measure a
     * change with `php artisan ai:bench`. Low keeps the storyline suggestions
     * as good as the default and finishes in about 7 seconds instead of 10 to
     * 30 (benchmarked 2026-10-02).
     */
    'reasoning_effort' => [
        'storyline_options_writer' => env('AI_STORYLINE_OPTIONS_REASONING', 'low'),
        'storyline_writer' => null,
        'style_options_writer' => null,
        'element_suggester' => null,
        'photo_analyst' => null,
        'project_intake' => null,
        'tweak_interpreter' => null,
        'video_prompt_writer' => null,
        'bench_judge' => 'low',
    ],

    /*
     * Blind judges that score the outputs of `php artisan ai:bench`. Two
     * judges from different labs keep either from favouring its own models.
     */
    'bench' => [
        'judges' => ['anthropic/claude-opus-5.5', 'openai/gpt-5.6-sol'],
    ],

    /*
     * Tiles per round of the style exploration.
     */
    'style_options_count' => 4,

    /*
     * Suggestions per cast and sets category in the intake chat.
     */
    'element_suggestions_count' => 8,

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

    /*
     * Joins the clips of merged shots. Both must be installed on the servers
     * that run the queue.
     */
    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    ],

    'video' => [
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        // The default for projects without their own; the lowest the model offers, for quick tests.
        'resolution' => env('AI_VIDEO_RESOLUTION', '480p'),
        // What Wan 3.0 renders; a model without 4K refuses it.
        'resolutions' => ['480p', '720p', '1080p'],
        'min_duration' => 4,
        'max_duration' => 15,
        'poll_seconds' => 20,
        'max_wait_minutes' => 30,
    ],

];
