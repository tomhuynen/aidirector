<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Disk;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Testing\MimeType;
use Illuminate\Support\Facades\Config;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Upload>
 */
class UploadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'disk' => Config::get('uploads.disk'),
            'path' => 'uploads/' . fake()->uuid() . '/' . fake()->uuid() . '.png',
            'name' => fake()->word() . '.png',
            'mime_type' => 'image/png',
            'size' => fake()->numberBetween(100, 100_000),
        ];
    }

    /**
     * Name the upload after a filename, deriving the mime type from its extension.
     */
    public function named(string $name): self
    {
        return $this->state([
            'name' => $name,
            'mime_type' => MimeType::from($name),
        ]);
    }

    public function disk(Disk $disk): self
    {
        return $this->state(['disk' => $disk]);
    }

    public function size(int $bytes): self
    {
        return $this->state(['size' => $bytes]);
    }

    public function expired(): self
    {
        $hours = (int) Config::get('uploads.expires_after_hours');

        return $this->state(['created_at' => now()->subHours($hours + 1)]);
    }
}
