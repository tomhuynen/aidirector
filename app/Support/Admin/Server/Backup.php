<?php

declare(strict_types=1);

namespace App\Support\Admin\Server;

use App\Enums\Disk;
use Aws\Exception\CredentialsException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToListContents;

class Backup implements Arrayable
{
    public function download(string $name)
    {
        $hash = str_replace('.zip', '', $name);
        $backups = $this->listBackups();

        if (! $backups->has($hash)) {
            abort(404);
        }

        $backup = $backups->get($hash);
        $disk = $this->getBackupDisk();

        if (! $disk->has($backup->path)) {
            abort(404);
        }

        return $disk->temporaryUrl($backup->path, now()->addMinutes(15));
    }

    private function listBackups(): Collection
    {
        $cacheKey = 'system:database:backups';

        $ttl = [
            now()->addMinutes(15),
            now()->addMinutes(60),
        ];

        return Cache::flexible($cacheKey, $ttl, function () {
            try {
                $files = $this->getBackupDisk()
                    ->listContents(Config::get('backup.backup.name'))
                    ->filter(fn(StorageAttributes $attributes) => $attributes->isFile())
                    ->sortByPath();
            } catch (UnableToListContents) {
                // Backup disk not correctly configured
                return collect();
            } catch (CredentialsException|InvalidArgumentException) {
                // Backup disk not configured
                return collect();
            }

            return collect($files)
                ->sortByDesc(fn($file) => $file->lastModified())
                ->map(function ($file) {
                    /** @var FileAttributes $file */
                    $path = $file->path();
                    $hash = hash('xxh3', $path);
                    $extension = pathinfo($path, PATHINFO_EXTENSION);

                    return (object) [
                        'hash' => $hash,
                        'path' => $path,
                        'filesize' => $file->fileSize(),
                        'url' => route('admin.system.database.download', [$hash . '.' . $extension]),
                        'basename' => basename($path),
                        'createdAt' => Date::createFromTimestamp($file->lastModified()),
                    ];
                })
                ->filter()
                ->values()
                ->slice(0, 14)
                ->keyBy('hash');
        });
    }

    private function getBackupDisk(): FilesystemAdapter
    {
        return Disk::TENANT_BACKUP->storage();
    }

    public function toArray()
    {
        return [
            'hasEncryption' => ! empty(config('backup.backup.password')),
            /** @var array{hash:string,path:string,filesize:int,url:string,basename:string,createdAt:Carbon}[] */
            'backups' => $this->listBackups(),
        ];
    }
}
