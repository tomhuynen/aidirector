<?php

declare(strict_types=1);

namespace App\Support\Projects;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * The project's settings, kept as one JSON column. Unknown or missing keys
 * fall back to defaults, so settings can grow without migrations.
 */
final readonly class ProjectSettings implements Castable
{
    /**
     * @param  list<string>  $voiceOverLocales  locale codes such as "nl-NL"
     */
    public function __construct(
        public bool $voiceOver = false,
        public array $voiceOverLocales = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $voiceOver = (array) ($data['voice_over'] ?? []);

        return new self(
            voiceOver: (bool) ($voiceOver['enabled'] ?? false),
            voiceOverLocales: self::supported((array) ($voiceOver['locales'] ?? [])),
        );
    }

    /**
     * @return array{voice_over: array{enabled: bool, locales: list<string>}}
     */
    public function toArray(): array
    {
        return [
            'voice_over' => [
                'enabled' => $this->voiceOver,
                'locales' => $this->voiceOverLocales,
            ],
        ];
    }

    /**
     * The languages the voice-over is made in; none while the voice-over is off.
     *
     * @return list<string>
     */
    public function enabledLocales(): array
    {
        return $this->voiceOver ? $this->voiceOverLocales : [];
    }

    /**
     * @param  list<string>  $locales
     */
    public function withVoiceOver(bool $enabled, array $locales): self
    {
        return new self(voiceOver: $enabled, voiceOverLocales: self::supported($locales));
    }

    /**
     * Only languages the voice-over can be made in, in the catalogue's order.
     *
     * @param  array<mixed>  $locales
     * @return list<string>
     */
    private static function supported(array $locales): array
    {
        return array_values(array_intersect((array) Config::get('pipeline.voice_over.locales'), $locales));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return CastsAttributes<self, self|array<string, mixed>>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes {
            /**
             * @param  array<string, mixed>  $attributes
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): ProjectSettings
            {
                return ProjectSettings::fromArray(is_string($value) ? json_decode($value, true) : null);
            }

            /**
             * @param  array<string, mixed>  $attributes
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): string
            {
                $settings = $value instanceof ProjectSettings ? $value : ProjectSettings::fromArray(is_array($value) ? $value : null);

                return (string) json_encode($settings->toArray());
            }
        };
    }
}
