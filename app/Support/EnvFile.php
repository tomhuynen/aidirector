<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class EnvFile
{
    public private(set) string $path;

    public private(set) string $examplePath;

    public function __construct(?string $path = null, ?string $examplePath = null)
    {
        $this->path = $path ?? app()->environmentFilePath();
        $this->examplePath = $examplePath ?? base_path('.env.example');
    }

    public function exists(): bool
    {
        return File::exists($this->path);
    }

    /**
     * Create the env file from the example file, overriding the given keys.
     *
     * @param  array<string, string>  $values
     */
    public function createFromExample(array $values = []): void
    {
        if (! File::exists($this->examplePath)) {
            throw new RuntimeException("Example env file not found at {$this->examplePath}.");
        }

        $contents = File::get($this->examplePath);

        foreach ($values as $key => $value) {
            $contents = $this->replaceKey($contents, $key, $value);
        }

        File::put($this->path, $contents);
    }

    private function replaceKey(string $contents, string $key, string $value): string
    {
        $line = $key . '=' . $this->formatValue($value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, $line, $contents, 1);
        }

        return Str::finish($contents, PHP_EOL) . $line . PHP_EOL;
    }

    private function formatValue(string $value): string
    {
        if (preg_match('/[\s#"\'$]/', $value) !== 1) {
            return $value;
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }
}
