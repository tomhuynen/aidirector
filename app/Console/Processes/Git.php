<?php

declare(strict_types=1);

namespace App\Console\Processes;

use Illuminate\Contracts\Process\ProcessResult;

class Git extends Process
{
    public function root()
    {
        return $this->runGitCommand('rev-parse', '--show-toplevel');
    }

    public function isRepo(): bool
    {
        $this->runQuietly(function (self $git) {
            $git->root();
        });

        return ! $this->hasErrorOutput();
    }

    public function runGitCommand(...$parts): ProcessResult
    {
        return $this->run($this->prepareProcessArguments($parts));
    }

    private function prepareProcessArguments(array $command): array
    {
        return collect(['git'])
            ->merge($command)
            ->flatten()
            ->reject(fn($part) => is_null($part))
            ->all();
    }
}
