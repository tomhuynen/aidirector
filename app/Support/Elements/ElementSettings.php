<?php

declare(strict_types=1);

namespace App\Support\Elements;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Details that belong to one type of element, kept as one JSON column. For a
 * person: the voice they speak with as a presenter, and once they have
 * spoken, the exact voice per language, so they always sound the same.
 * Unknown or missing keys fall back to defaults.
 */
final readonly class ElementSettings implements Castable
{
    public const VOICES = ['male', 'female'];

    /**
     * @param  'male'|'female'|null  $voice
     * @param  array<string, string>  $voices  the voice id per language, such as "en" or "*" for every other language
     */
    public function __construct(
        public ?string $voice = null,
        public array $voices = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $voice = $data['voice'] ?? null;

        return new self(
            voice: in_array($voice, self::VOICES, true) ? $voice : null,
            voices: array_filter(array_map(fn(mixed $id) => is_string($id) ? $id : '', (array) ($data['voices'] ?? []))),
        );
    }

    /**
     * @return array{voice?: string, voices?: array<string, string>}
     */
    public function toArray(): array
    {
        return array_filter(['voice' => $this->voice, 'voices' => $this->voices]);
    }

    /**
     * Another voice clears the exact voices chosen for the old one.
     *
     * @param  'male'|'female'|null  $voice
     */
    public function withVoice(?string $voice): self
    {
        return new self(voice: $voice, voices: $voice === $this->voice ? $this->voices : []);
    }

    public function withVoiceFor(string $language, string $voiceId): self
    {
        return new self(voice: $this->voice, voices: [...$this->voices, $language => $voiceId]);
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
            public function get(Model $model, string $key, mixed $value, array $attributes): ElementSettings
            {
                return ElementSettings::fromArray(is_string($value) ? json_decode($value, true) : null);
            }

            /**
             * @param  array<string, mixed>  $attributes
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): string
            {
                $settings = $value instanceof ElementSettings ? $value : ElementSettings::fromArray(is_array($value) ? $value : null);

                return (string) json_encode($settings->toArray());
            }
        };
    }
}
