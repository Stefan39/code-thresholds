<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds\Tests;

use Stejac\CodeThresholds\Tests\Support\TemporaryDirectoryTestCase;

/**
 * Runs the real executable in a separate process, the way a user or CI does.
 */
final class BinTest extends TemporaryDirectoryTestCase
{
    public function testReportsViolationsWithExitCodeOne(): void
    {
        $this->write('src/Dirty.php', "<?php\n\nfunction f(): int\n{\n    \$unused = 1;\n\n    return 1;\n}\n");

        [$status, , $stderr] = $this->execute(['--all']);

        self::assertSame(1, $status);
        self::assertStringContainsString("src/Dirty.php:5\n    unused variable — \$unused in f()", $stderr);
    }

    public function testPassesACleanProjectWithExitCodeZero(): void
    {
        $this->write('src/Clean.php', "<?php\n\nfunction f(): int\n{\n    return 1;\n}\n");

        [$status, $stdout] = $this->execute(['--all']);

        self::assertSame(0, $status);
        self::assertSame("code-thresholds: 1 file(s) checked, no violations.\n", $stdout);
    }

    public function testUsageErrorsExitWithCodeTwo(): void
    {
        [$status, , $stderr] = $this->execute(['--bogus']);

        self::assertSame(2, $status);
        self::assertStringContainsString('Unknown option "--bogus"', $stderr);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function execute(array $args): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/code-thresholds', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
