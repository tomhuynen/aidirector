<?php

declare(strict_types=1);

namespace App\Support\Media;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Paths on this machine for media that tools such as Imagick read from a
 * file. Media on a local disk is read where it is; media on a cloud disk is
 * copied to a temporary file, removed again once this object goes away.
 */
class LocalMediaFiles
{
    /**
     * @var array<string, string>
     */
    private array $copies = [];

    public function __destruct()
    {
        foreach ($this->copies as $copy) {
            @unlink($copy);
        }
    }

    public function path(Media $media, string $conversion = ''): string
    {
        if (self::isLocalDisk($media->disk)) {
            return $media->getPath($conversion);
        }

        $key = "{$media->getKey()}:{$conversion}";

        if (isset($this->copies[$key])) {
            return $this->copies[$key];
        }

        $relative = $media->getPathRelativeToRoot($conversion);
        $stream = Storage::disk($media->disk)->readStream($relative) ?? throw new RuntimeException("Media file {$relative} could not be read.");
        $copy = storage_path('app/tmp/media-' . Str::random(16) . '.' . pathinfo($relative, PATHINFO_EXTENSION));
        @mkdir(dirname($copy), 0755, true);

        try {
            file_put_contents($copy, $stream);
        } finally {
            fclose($stream);
        }

        return $this->copies[$key] = $copy;
    }

    /**
     * Whether the disk keeps its files on this machine, following scoped disks to the disk they wrap.
     */
    public static function isLocalDisk(string $disk): bool
    {
        $config = Config::get("filesystems.disks.{$disk}");

        while (is_array($config) && ($config['driver'] ?? null) === 'scoped') {
            $config = Config::get("filesystems.disks.{$config['disk']}");
        }

        return is_array($config) && ($config['driver'] ?? null) === 'local';
    }
}
