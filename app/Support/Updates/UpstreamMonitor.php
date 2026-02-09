<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Console\Processes\Exceptions\ProcessException;
use App\Console\Processes\Git;

class UpstreamMonitor
{
    protected Git $git;

    private string $upstreamRef;

    public function __construct(
        public private(set) string $upstreamRemote,
        public private(set) string $localBranch,
        ?Git $git = null,
    ) {
        $this->git = $git ?? new Git();
        $this->upstreamRef = "{$this->upstreamRemote}/{$this->localBranch}";
    }

    public function hasUpstreamChanges(): bool
    {
        return $this->getCommitsBehind() > 0;
    }

    public function getCommitsBehind(): int
    {
        $this->fetchUpstream();

        $result = $this->git->runGitCommand('rev-list', '--count', 'HEAD..' . $this->upstreamRef);

        return (int) $this->normalizeOutput($result->output());
    }

    /**
     * Fetch the upstream repository.
     *
     * @throws ProcessException
     */
    private function fetchUpstream(): void
    {
        $this->git->runGitCommand('fetch', $this->upstreamRemote);
    }

    private function normalizeOutput(string $output): string
    {
        return trim($output);
    }
}
