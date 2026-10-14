<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Stejac\CodeThresholds\Application;
use Stejac\CodeThresholds\Tests\Support\GitRepository;
use Stejac\CodeThresholds\Tests\Support\TemporaryDirectoryTestCase;

#[CoversClass(Application::class)]
final class ApplicationTest extends TemporaryDirectoryTestCase
{
    private const string CLEAN = "<?php\n\nclass Clean\n{\n    public function a(): int\n    {\n        return 1;\n    }\n}\n";
    private const string DIRTY = "<?php\n\nclass Dirty\n{\n    public function a(): int\n    {\n        \$unused = 1;\n\n        return 1;\n    }\n}\n";

    public function testExitsWithZeroWhenNothingIsWrong(): void
    {
        $this->write('src/Clean.php', self::CLEAN);

        [$status, $out, $err] = $this->runCli(['src']);

        self::assertSame(0, $status);
        self::assertSame("code-thresholds: 1 file(s) checked, no violations.\n", $out);
        self::assertSame('', $err);
    }

    public function testExitsWithOneAndListsViolationsOnStderr(): void
    {
        $this->write('src/Dirty.php', self::DIRTY);

        [$status, $out, $err] = $this->runCli(['src/Dirty.php']);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertSame(
            "code-thresholds: limits exceeded:\n\n  src/Dirty.php:7\n    unused variable — \$unused in a()\n\n1 violation(s).\n",
            $err,
        );
    }

    public function testExitsWithTwoOnUnknownOptions(): void
    {
        [$status, , $err] = $this->runCli(['--nope']);

        self::assertSame(2, $status);
        self::assertSame("code-thresholds: Unknown option \"--nope\". See --help.\n", $err);
    }

    public function testExitsWithTwoOnInvalidConfig(): void
    {
        $this->write('code-thresholds.php', "<?php\n\nreturn ['limit' => []];\n");

        [$status, , $err] = $this->runCli([]);

        self::assertSame(2, $status);
        self::assertStringContainsString('Unknown config key "limit"', $err);
    }

    public function testExitsWithTwoWhenAllFindsNoSourceDirectory(): void
    {
        [$status, , $err] = $this->runCli(['--all']);

        self::assertSame(2, $status);
        self::assertStringContainsString('nothing to scan', $err);
    }

    public function testHelpListsTheOptions(): void
    {
        [$status, $out] = $this->runCli(['--help']);

        self::assertSame(0, $status);
        foreach (['--base=<ref>', '--all', '--config=<file>', '--help', '--version'] as $option) {
            self::assertStringContainsString($option, $out);
        }
    }

    public function testVersion(): void
    {
        [$status, $out] = $this->runCli(['--version']);

        self::assertSame(0, $status);
        self::assertSame('code-thresholds '.Application::VERSION."\n", $out);
    }

    public function testAllChecksTheSourceDirectoriesOfTheConfig(): void
    {
        $this->write('lib/Dirty.php', self::DIRTY);
        $this->write('src/Clean.php', self::CLEAN);
        $this->write('code-thresholds.php', "<?php\n\nreturn ['source_dirs' => ['lib']];\n");

        [$status, , $err] = $this->runCli(['--all']);

        self::assertSame(1, $status);
        self::assertStringContainsString('lib/Dirty.php', $err);
    }

    public function testExplicitConfigFileDefinesLimitsAndRoot(): void
    {
        $this->write('src/Clean.php', self::CLEAN);
        $this->write('ci/strict.php', "<?php\n\nreturn ['limits' => ['methods_per_class' => 1], 'rules' => ['unused_variable' => false]];\n");
        $this->write('src/Dirty.php', self::DIRTY);
        $this->write('src/Two.php', "<?php\n\nclass Two\n{\n    public function a(): void\n    {\n    }\n\n    public function b(): void\n    {\n    }\n}\n");

        [$status, , $err] = $this->runCli(['--config=ci/strict.php', $this->dir.'/src']);

        self::assertSame(1, $status);
        self::assertStringContainsString('methods per class — Two is 2 (max 1)', $err);
        self::assertStringNotContainsString('unused variable', $err);
        self::assertStringContainsString('1 violation(s).', $err);
        self::assertStringContainsString('/src/Two.php', $err);
    }

    public function testWithoutArgumentsTheStagedFilesAreChecked(): void
    {
        $repo = new GitRepository($this->dir);
        $this->write('Dirty.php', self::DIRTY);
        $this->write('Unstaged.php', self::DIRTY);
        $repo->git('add', 'Dirty.php');

        [$status, , $err] = $this->runCli([]);

        self::assertSame(1, $status);
        self::assertStringContainsString('Dirty.php:7', $err);
        self::assertStringNotContainsString('Unstaged.php', $err);
    }

    public function testBaseChecksTheFilesChangedAgainstTheRef(): void
    {
        $repo = new GitRepository($this->dir);
        $this->write('Clean.php', self::CLEAN);
        $repo->commitAll();
        $this->write('Dirty.php', self::DIRTY);
        $repo->commitAll();

        [$status, , $err] = $this->runCli(['--base=HEAD~1']);

        self::assertSame(1, $status);
        self::assertStringContainsString('Dirty.php:7', $err);
    }

    public function testAnInvalidBaseRefExitsWithTwo(): void
    {
        [$status, , $err] = $this->runCli(['--base=--output=x']);

        self::assertSame(2, $status);
        self::assertStringContainsString('is not a valid git ref', $err);
    }

    public function testNothingStagedIsNotAViolation(): void
    {
        new GitRepository($this->dir);

        [$status, $out] = $this->runCli([]);

        self::assertSame(0, $status);
        self::assertSame("code-thresholds: 0 file(s) checked, no violations.\n", $out);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runCli(array $args): array
    {
        $out = '';
        $err = '';
        $application = new Application(
            $this->dir,
            static function (string $text) use (&$out): void {
                $out .= $text;
            },
            static function (string $text) use (&$err): void {
                $err .= $text;
            },
        );

        return [$application->run(['code-thresholds', ...$args]), $out, $err];
    }
}
