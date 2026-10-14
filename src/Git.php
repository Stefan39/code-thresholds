<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

use Stejac\CodeThresholds\Exception\GitException;

/**
 * Runs git without a shell: the command is an argument list handed to
 * proc_open(), so a ref or path can never be interpreted as shell syntax.
 */
final class Git
{
    /** Characters that may appear in a ref name handed to `git diff`. */
    private const string REF_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.\/@~^{}-]*$/';

    /**
     * PHP files staged for commit (added, copied, modified), relative to $directory.
     *
     * @return list<string>
     *
     * @throws GitException
     */
    public function stagedFiles(string $directory): array
    {
        return $this->run($directory, ['diff', '--cached', '--name-only', '-z', '--diff-filter=ACM', '--relative', '--', '*.php']);
    }

    /**
     * PHP files changed between $ref and HEAD (merge base), relative to $directory.
     *
     * @return list<string>
     *
     * @throws GitException
     */
    public function changedFiles(string $directory, string $ref): array
    {
        if (1 !== preg_match(self::REF_PATTERN, $ref)) {
            throw new GitException(sprintf('"%s" is not a valid git ref.', $ref));
        }

        return $this->run($directory, ['diff', '--name-only', '-z', '--diff-filter=ACMR', '--relative', $ref.'...HEAD', '--', '*.php']);
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string> the NUL-separated entries git printed
     *
     * @throws GitException
     */
    private function run(string $directory, array $arguments): array
    {
        $process = @proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
        if (!is_resource($process)) {
            throw new GitException('Could not start git. Is it installed?');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (0 !== proc_close($process)) {
            throw new GitException(sprintf('git %s failed: %s', $arguments[0], '' !== $stderr ? $stderr : 'unknown error'));
        }

        return array_values(array_filter(explode("\0", $stdout), static fn (string $line): bool => '' !== $line));
    }
}
