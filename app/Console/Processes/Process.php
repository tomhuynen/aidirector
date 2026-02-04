<?php

declare(strict_types=1);

namespace App\Console\Processes;

use App\Console\Processes\Exceptions\ProcessException;
use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process as FacadesProcess;
use Illuminate\Support\Str;

abstract class Process
{
    public private(set) string $basePath;

    protected bool $throwOnFailure = true;

    protected ?string $output = null;

    protected ?string $errorOutput = null;

    public function __construct(
        ?string $basePath = null,
    ) {
        $this->basePath = Str::finish($basePath ?? base_path(), '/');
    }

    public function run(array $command)
    {
        $this->resetOutput();

        $command = $this->newCommand($command, $this->basePath);

        $process = $command->run();

        $this->output = $this->normalizeOutput($process->output());
        $this->errorOutput = $this->normalizeOutput($process->errorOutput());

        if ($this->throwOnFailure && ! $process->successful()) {
            $this->throwException(
                "Process failed {$process->command()}: {$process->errorOutput()}",
            );
        }

        return $process;
    }

    public function normalizeOutput(?string $output = null): ?string
    {
        if (! $output) {
            return null;
        }

        return $output;
    }

    public function runQuietly(Closure $callback): self
    {
        $this->throwOnFailure(false);

        $callback($this);

        $this->throwOnFailure(true);

        return $this;
    }

    public function throwOnFailure(?bool $throwOnFailure = null): self
    {
        $this->throwOnFailure = is_null($throwOnFailure)
            ? true
            : $throwOnFailure;

        return $this;
    }

    public function hasErrorOutput(): bool
    {
        return ! is_null($this->errorOutput);
    }

    protected function newCommand($command, $path = null): PendingProcess
    {
        return FacadesProcess::path($path ?? $this->basePath)->command($command);
    }

    /**
     * Throw Exception
     *
     * @throws ProcessException
     */
    protected function throwException(string $message): void
    {
        throw new ProcessException($message);
    }

    private function resetOutput(): void
    {
        $this->output = null;
        $this->errorOutput = null;
    }
}
