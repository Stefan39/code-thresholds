<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds\Tests\Support;

/**
 * A throw-away git repository for tests, with a local identity so no global
 * git configuration is needed.
 */
final class GitRepository
{
    public function __construct(public readonly string $path)
    {
        $this->git('init', '-q', '-b', 'main');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'Test');
        $this->git('config', 'commit.gpgsign', 'false');
    }

    public function git(string ...$arguments): void
    {
        $command = 'git -C '.escapeshellarg($this->path).' '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1';
        exec($command, $output, $status);

        if (0 !== $status) {
            throw new \LogicException(sprintf("%s failed:\n%s", $command, implode("\n", $output)));
        }
    }

    public function commitAll(string $message = 'commit'): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', $message);
    }
}
