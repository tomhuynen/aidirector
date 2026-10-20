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
        // Creates style sheets, covers and element pictures: stays closest to the pinned style.
        'image' => env('AI_IMAGE_MODEL', 'google/gemini-3.1-flash-image-preview'),
        // Creates keyframes; falls back to the image model.
        'keyframe' => env('AI_KEYFRAME_MODEL', env('AI_IMAGE_MODEL', 'google/gemini-3.1-flash-image-preview')),
        // Turns a photo of a real person into a drawing first; the image model often refuses such photos.
        'photo_drawing' => env('AI_PHOTO_DRAWING_MODEL', 'bytedance-seed/seedream-5-0-flash'),
        // Changes an element picture (adds an object, adjusts details) and leaves the rest alone.
        'image_edit' => env('AI_IMAGE_EDIT_MODEL', 'openai/gpt-5.4-image-2'),
        // Adjusts an existing keyframe and leaves the rest alone; kept everything else identical in a test of 2026-10-04.
        'keyframe_edit' => env('AI_KEYFRAME_EDIT_MODEL', 'openai/gpt-image-2.5-sunburst'),
        // Renders the clips on Luma: a scene or close-up from its start frame to its end frame, a still of a montage from that still.
        'video' => env('AI_VIDEO_MODEL', 'ray-3.2'),
        // Renders a whole sequence as one clip with its own cuts and timing, on Replicate: one drawn keyframe per shot, the first as the start frame and the others as references. Empty: every shot renders its own clip on Luma.
        'sequence_video' => env('AI_SEQUENCE_VIDEO_MODEL'),
        // Speaks the voice-over; the voice per language is set under voice_over.voices.
        'voice' => env('AI_VOICE_MODEL', 'microsoft/mai-voice-2.1'),
        // Animates the project cover into a seamless loop; needs first and last frame control.
        'cover_loop' => env('AI_COVER_LOOP_MODEL', 'bytedance/seedance-2.0'),
        // Makes a person in one still speak a text to the camera, lip-synced, with a voice of its own.
        'presenter' => env('AI_PRESENTER_MODEL', 'heygen/avatar-iv'),
        // Checks each drawn keyframe and decides on a redraw: few false alarms (benchmark of 2026-10-04).
        // Draws a keyframe on the image before it, keeping that image and drawing only the boxes of the cast who change: FLUX 3 on Replicate.
        'keyframe_layout' => env('AI_KEYFRAME_LAYOUT_MODEL', 'black-forest-labs/flux-3-image'),
        // Blocks a keyframe on the image before it: where each of the cast stands, faces and how they pose.
        'keyframe_stager' => env('AI_KEYFRAME_STAGER_MODEL', 'google/gemini-3.8-flash'),
        'keyframe_check' => env('AI_KEYFRAME_CHECK_MODEL', 'google/gemini-3.8-flash'),
        // Directs a story into a sequence of shots: as good as GPT-5.5 in about a third of the time at low effort (benchmark of 2026-10-19).
        'scene_director' => env('AI_SCENE_DIRECTOR_MODEL', 'anthropic/claude-sonnet-5.5'),
        // Reads the layout of a place from its picture. Tried 2026-10-19 on four places against gemini-3.8-flash: both got the doors right, Haiku was faster and named more of the openings.
        'place_layout' => env('AI_PLACE_LAYOUT_MODEL', 'anthropic/claude-haiku-5.5'),
        // Reviews a directed sequence against its story, the place layouts and the rendering mode before the director sees it.
        'sequence_reviewer' => env('AI_SEQUENCE_REVIEWER_MODEL', 'anthropic/claude-sonnet-5.5'),
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
        'keyframe_checker' => env('AI_KEYFRAME_CHECK_REASONING', 'low'),
        'keyframe_stager' => 'low',
        'keyframe_fix_locator' => 'low',
        'shot_reviewer' => env('AI_SHOT_REVIEW_REASONING', 'medium'),
        'correction_classifier' => env('AI_CORRECTION_REASONING', 'low'),
        'voice_over_writer' => env('AI_VOICE_OVER_REASONING', 'low'),
        'voice_over_translator' => env('AI_VOICE_OVER_REASONING', 'low'),
        'bench_judge' => 'low',
        'voice_judge' => 'low',
        'storyline_follower' => 'low',
        'shot_timer' => 'low',
        'plan_director' => 'medium',
        'scene_director' => 'low',
        'place_layout_reader' => null,
        'sequence_reviewer' => 'low',
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

    /*
     * Each drawn keyframe is checked against its plan and references; a clear
     * mistake gets one redraw with a fix before the director sees it.
     */
    'keyframe_check' => env('AI_KEYFRAME_CHECK', true),

    /*
     * Changes and check findings are labelled in the background; a kind of
     * correction that comes back in this many different shots is suggested
     * as a project rule.
     */
    'rules' => [
        'enabled' => env('AI_LEARN_RULES', true),
        'threshold' => 2,
    ],

    'keyframes' => [
        // The most stills a montage has; a scene or close-up has a start frame and an end frame.
        'max' => 6,
        // Versions of the start frame, each drawn whole in the shot's place, the director chooses from before the end frame is drawn.
        'first_options' => (int) env('AI_FIRST_KEYFRAME_OPTIONS', 3),
        // Reference images of the cast and sets attached to one keyframe render.
        'max_element_references' => 3,
        // Measures the depth of a place, so a person or a thing is drawn at the size it has where it stands: Depth Anything 3 metric on Replicate.
        'depth_model' => env('AI_DEPTH_MODEL', 'd2ef7653aa75f87e3b502738a3f57ca2b09ba92f6f286075c8929a69937a013f'),
        // Cuts a person or a thing out of a keyframe by its name, so only the people and things that change are taken from a drawing: Lang-SAM on Replicate.
        // How the layout model draws: whether it draws keyframes 2 and later on the keyframe before (otherwise the keyframe
        // model draws them whole and the layout model only edits), and whether it may search the web and images first.
        // It always draws at the shot's aspect ratio.
        'layout' => [
            'draws_keyframes' => (bool) env('AI_LAYOUT_DRAWS_KEYFRAMES', true),
            'grounding' => (bool) env('AI_LAYOUT_GROUNDING', true),
        ],
        // Measured in code after each edit: a render whose background moved compared with the image it was drawn on is drawn again.
        'drift' => [
            'enabled' => (bool) env('AI_DRIFT_CHECK', true),
            // With this share of the background in place nothing is searched; below it, the render counts as moved only when a shift or zoom lines it up clearly better.
            'min_stillness' => (float) env('AI_DRIFT_MIN_STILLNESS', 0.85),
            'attempts' => (int) env('AI_DRIFT_ATTEMPTS', 3),
        ],
    ],

    'queue' => 'ai',

    /*
    |--------------------------------------------------------------------------
    | Video
    |--------------------------------------------------------------------------
    |
    | Luma Ray renders a scene or close-up from its start frame, pinned on the
    | first frame, to its end frame, pinned where the action ends and held to
    | the last frame; a still of a montage is pinned on the first frame alone.
    | Durations are clamped to what it accepts; the poll jobs follow the renders
    | until they are done or the wait runs out.
    |
    */

    /*
    | Image models that OpenRouter only serves through its images endpoint,
    | not through chat completions.
    */
    'images_api_models' => [
        'openai/gpt-image-2.5-sunburst',
        'openai/gpt-image-2.5-flare',
        'bytedance-seed/seedream-5-0-flash',
        'openai/gpt-image-2',
        'krea/k3-edit',
        'black-forest-labs/flux-3-image',
        'black-forest-labs/flux.2-klein-4b',
    ],

    /*
     * Joins the clips of merged shots. Both must be installed on the servers
     * that run the queue.
     */
    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    ],

    /*
     * An insert's clip is made from its one still in code: how much the camera
     * pushes in over the clip, as a share of the frame; 0 holds the still.
     */
    'insert' => [
        'push_in' => 0.06,
    ],

    /*
     * The languages a voice-over can be made in: the voices of Microsoft
     * MAI Voice 2.1 on OpenRouter, the widest multilingual set there.
     */
    'voice_over' => [
        // Whether a shot's voice-over is written once its plan is made and spoken while its video renders; off, the director asks for it.
        'automatic' => (bool) env('AI_VOICE_OVER_AUTOMATIC', false),
        'locales' => [
            'nl-NL', 'en-GB', 'en-US', 'de-DE', 'fr-FR', 'es-ES', 'it-IT', 'pt-PT', 'pt-BR', 'pl-PL',
            'da-DK', 'sv-SE', 'nb-NO', 'fi-FI', 'cs-CZ', 'hu-HU', 'ro-RO', 'ru-RU', 'tr-TR', 'es-MX',
            'en-AU', 'en-IN', 'hi-IN', 'id-ID', 'th-TH', 'vi-VN', 'ko-KR', 'zh-CN',
        ],
        // One calm narration voice per language, from the voice model's own catalogue.
        'voices' => [
            'nl-NL' => 'nl-NL-Harper:MAI-Voice-2.1', 'en-GB' => 'en-GB-Emily:MAI-Voice-2.1', 'en-US' => 'en-US-Olivia:MAI-Voice-2.1',
            'de-DE' => 'de-DE-Mia:MAI-Voice-2.1', 'fr-FR' => 'fr-FR-Soleil:MAI-Voice-2.1', 'es-ES' => 'es-ES-Marta:MAI-Voice-2.1',
            'it-IT' => 'it-IT-Rosa:MAI-Voice-2.1', 'pt-PT' => 'pt-PT-Harper:MAI-Voice-2.1', 'pt-BR' => 'pt-BR-Luana:MAI-Voice-2.1',
            'pl-PL' => 'pl-PL-Harper:MAI-Voice-2.1', 'da-DK' => 'da-DK-Harper:MAI-Voice-2.1', 'sv-SE' => 'sv-SE-Harper:MAI-Voice-2.1',
            'nb-NO' => 'nb-NO-Harper:MAI-Voice-2.1', 'fi-FI' => 'fi-FI-Harper:MAI-Voice-2.1', 'cs-CZ' => 'cs-CZ-Harper:MAI-Voice-2.1',
            'hu-HU' => 'hu-HU-Lilla:MAI-Voice-2.1', 'ro-RO' => 'ro-RO-Elena:MAI-Voice-2.1', 'ru-RU' => 'ru-RU-Masha:MAI-Voice-2.1',
            'tr-TR' => 'tr-TR-Elif:MAI-Voice-2.1', 'es-MX' => 'es-MX-Valeria:MAI-Voice-2.1', 'en-AU' => 'en-AU-Isla:MAI-Voice-2.1',
            'en-IN' => 'en-IN-Priya:MAI-Voice-2.1', 'hi-IN' => 'hi-IN-Kavya:MAI-Voice-2.1', 'id-ID' => 'id-ID-Harper:MAI-Voice-2.1',
            'th-TH' => 'th-TH-Harper:MAI-Voice-2.1', 'vi-VN' => 'vi-VN-Harper:MAI-Voice-2.1', 'ko-KR' => 'ko-KR-Haena:MAI-Voice-2.1',
            'zh-CN' => 'zh-CN-Mei:MAI-Voice-2.1',
        ],
        // A spoken track may be sped up this much at most to fit the clip; beyond that it is rewritten shorter.
        'max_speed' => 1.25,
    ],

    /*
     * Presenter shots: the HeyGen voice per language and gender. A language
     * without its own voice uses the multilingual one.
     */
    'presenter' => [
        'resolution' => '720p',
        'voices' => [
            'male' => ['en' => '6be73833ef9a4eb0aeee399b8fe9d62b', 'es' => '707365599f8545d5b6ce7a32a20e9c93', 'fr' => '29b464727cc249f3bdf42f82562409f8', 'de' => '0971fe1493314d1f9a43602cf4a3b210', 'ja' => '662e1397965c484e8f65fa58c77effde', '*' => '3097f9a8fd3b4340b6bbe913177b378f'],
            'female' => ['en' => '16a09e4706f74997ba4ed05ea11470f6', 'es' => '246cdbf530954380a62109f3107fce0d', 'fr' => '4b1de1582d2c477485ad2e0c2717f0ff', 'de' => '5d25200b1d4442d6b7265a9ff5fad582', 'ja' => '926a3d25100b4687a80fc76ec8f2bf38', '*' => '80441555167a467e967ab9487d844a30'],
        ],
    ],

    'video' => [
        // The OpenRouter video API, which renders the presenter's lip-synced clips.
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        // The default for projects without their own; the lowest, for quick tests. Ray has no 480p and renders 540p for it.
        'resolution' => env('AI_VIDEO_RESOLUTION', '480p'),
        'resolutions' => ['480p', '720p', '1080p'],
        // The shortest shot; a short action is a short shot. Ray renders 5 or 10 seconds and the clip is cut to the shot.
        'min_duration' => 2,
        // Ray renders at most 10 seconds.
        'max_duration' => (int) env('AI_VIDEO_MAX_DURATION', 10),
        // A render takes about a minute: the first check waits, later ones follow quicker.
        'first_poll_seconds' => 45,
        'poll_seconds' => 20,
        'max_wait_minutes' => 30,
        // What a Luma clip costs in USD, by resolution and length; the API returns no cost. From lumalabs.ai/api on 2026-10-16.
        'luma_prices' => [
            '540p' => [5 => 0.15, 10 => 0.45],
            '720p' => [5 => 0.30, 10 => 0.90],
            '1080p' => [5 => 1.20, 10 => 3.60],
        ],
    ],

];
