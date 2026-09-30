<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Disk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file uploaded ahead of the form or chat turn that will claim it. Uploads
 * are staging records: the owner moves the file to its final place and
 * deletes the upload, and unclaimed uploads expire.
 */
class Upload extends Model
{
    /** @use HasFactory<\Database\Factories\UploadFactory> */
    use HasFactory;
    use HasSqids;
    use UsesTenantConnection;

    protected $guarded = [];

    /**
     * @return array{
     *  size: 'integer',
     *  disk: 'App\Enums\Disk',
     * }
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'disk' => Disk::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Upload $upload) {
            if ($upload->path !== null) {
                $upload->storage()->delete($upload->path);
            }
        });
    }

    /**
     * Store an uploaded file and record it. The disk defaults to the
     * configured upload disk.
     */
    public static function fromFile(UploadedFile $file, ?Disk $disk = null): self
    {
        $disk ??= Disk::from((string) Config::get('uploads.disk'));

        $upload = static::query()->create([
            'disk' => $disk,
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        $path = $file->store("uploads/{$upload->id}", $disk->value);

        $upload->update(['path' => $path]);

        return $upload->fresh();
    }

    /**
     * Uploads that were never claimed and can be removed.
     *
     * @return Builder<static>
     */
    public static function expired(): Builder
    {
        $hours = (int) Config::get('uploads.expires_after_hours');

        return static::query()->where('created_at', '<=', Date::now()->subHours($hours));
    }

    public function storage(): FilesystemAdapter
    {
        return $this->disk->storage();
    }

    public function response(): StreamedResponse
    {
        return $this->storage()->response($this->path, $this->name);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function extension(): ?string
    {
        return pathinfo($this->name, PATHINFO_EXTENSION) ?: null;
    }
}
