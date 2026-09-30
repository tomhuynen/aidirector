<?php

declare(strict_types=1);

use App\Enums\Disk;

return [
    /**
     * The disk temporary uploads are stored on. Every upload remembers the
     * disk it was written to, so this can change without breaking old rows.
     */
    'disk' => env('UPLOAD_DISK', Disk::TENANT->value),

    /**
     * The maximum size of a single upload, in bytes.
     */
    'max_file_size' => (int) env('UPLOAD_MAX_FILE_SIZE', 10 * 1024 * 1024),

    /**
     * The mime types the upload endpoint accepts.
     */
    'mime_types' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ],

    /**
     * Uploads are staging files: a form or chat turn claims them into their
     * final place. Anything not claimed within this window is removed by
     * app:cleanup.
     */
    'expires_after_hours' => 8,
];
