<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Console\Processes\Exceptions\ProcessException;
use App\Console\Processes\Git;
use Illuminate\Support\Str;

class UpstreamMonitor
{
    protected Git $git;

    public function __construct(
        public private(set) string $upstreamRemote,
        public private(set) string $localBranch,
        ?Git $git = null,
    ) {
        $this->git = $git ?? new Git();
    }

    public function hasUpstreamChanges(): bool
    {
        $this->fetchUpstream();

        $localCommit = $this->getLocalCommit();
        $upstreamCommit = $this->getUpstreamCommit();

        return $localCommit !== $upstreamCommit;
    }

    public function getCommitsBehind(): int
    {
        $this->fetchUpstream();

        // Find the merge base (common ancestor)
        $mergeBase = $this->getMergeBase();

        // Count commits from merge base to upstream that aren't in local
        $result = $this->git->runGitCommand('rev-list', '--count', $mergeBase, '...', "$this->upstreamRemote");

        return (int) $this->normalizeOutput($result->output());
    }

    /**
     * Fetch the upstream repository.
     *
     * @throws ProcessException
     */
    private function fetchUpstream(): void
    {
        $this->git->runGitCommand('fetch', Str::before($this->upstreamRemote, '/'));
    }

    /**
     * Get the merge base (common ancestor) between local and upstream
     *
     * @throws ProcessException
     */
    private function getMergeBase(): string
    {
        $result = $this->git->runGitCommand('merge-base', 'HEAD', $this->upstreamRemote);

        return $this->normalizeOutput($result->output());
    }

    /**
     * Get current local commit hash
     *
     * @throws ProcessException
     */
    private function getLocalCommit(): string
    {
        $result = $this->git->runGitCommand('rev-parse', $this->localBranch);

        return $this->normalizeOutput($result->output());
    }

    /**
     * Get upstream commit hash
     *
     * @throws ProcessException
     */
    private function getUpstreamCommit(): string
    {
        $result = $this->git->runGitCommand('rev-parse', "{$this->upstreamRemote}/{$this->localBranch}");

        return $this->normalizeOutput($result->output());
    }

    private function normalizeOutput(string $output): string
    {
        return trim($output);
    }
}
