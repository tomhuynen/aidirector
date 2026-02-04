<?php

declare(strict_types=1);

namespace App\Enums\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait EnumHelpers
{
    /**
     * Get the description for an enum value.
     */
    public function description(): string
    {
        return self::getFriendlyName($this->name);
    }

    /**
     * Checks if this instance is equal to the given enum instance or value.
     */
    public function is(mixed $enumValue): bool
    {
        if ($enumValue instanceof self) {
            return $this->value === $enumValue->value;
        }

        return $this->value === $enumValue;
    }

    /**
     * Checks if this instance is not equal to the given enum instance or value.
     */
    public function isNot(mixed $enumValue): bool
    {
        return ! $this->is($enumValue);
    }

    /**
     * Checks if a matching enum instance or value is in the given values.
     *
     * @param  iterable<mixed>  $values
     */
    public function in(iterable $values): bool
    {
        foreach ($values as $value) {
            if ($this->is($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a matching enum instance or value is not in the given values.
     *
     * @param  iterable<mixed>  $values
     */
    public function notIn(iterable $values): bool
    {
        foreach ($values as $value) {
            if ($this->is($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return a JSON-serializable representation of the enum.
     */
    public function jsonSerialize(): mixed
    {
        return $this->value;
    }

    /**
     * Check that the enum contains a specific value.
     *
     * @param  mixed  $value
     */
    public static function hasValue($value): bool
    {
        if ($value instanceof self) {
            $value = $value->value;
        }

        $values = array_map(fn($v) => $v->value, self::cases());

        return in_array($value, $values);
    }

    /**
     * Attempt to instantiate a new Enum using the given value or instance.
     */
    public static function coerce(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return $value;
        }

        if (self::hasValue($value)) {
            return self::from($value);
        }

        return null;
    }

    /**
     * Get the enum as an array formatted for a select.
     *
     * @return array<array-key, string>
     */
    public static function asSelectArray(): array
    {
        $selectArray = [];

        foreach (self::cases() as $case) {
            $selectArray[$case->value] = $case->description();
        }

        return $selectArray;
    }

    public static function collect(): Collection
    {
        return collect(self::cases());
    }

    /**
     * Return a plain representation of the enum.
     *
     * This method is not meant to be called directly, but rather be called
     * by Laravel through a recursive serialization with @see \Illuminate\Contracts\Support\Arrayable.
     * Thus, it returns a value meant to be included in a plain array.
     */
    public function toArray(): mixed
    {
        return [
            'value' => $this->value,
            'label' => $this->description(),
        ];
    }

    /**
     * Transform the name into a friendly, formatted version.
     */
    protected static function getFriendlyName(string $name): string
    {
        if (ctype_upper((string) preg_replace('/[^a-zA-Z]/', '', $name))) {
            $name = strtolower($name);
        }

        return ucfirst(str_replace('_', ' ', Str::snake($name)));
    }
}
