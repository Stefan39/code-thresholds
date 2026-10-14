<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stejac\CodeThresholds\Exception\InvalidArgumentsException;
use Stejac\CodeThresholds\Mode;
use Stejac\CodeThresholds\Options;

#[CoversClass(Options::class)]
final class OptionsTest extends TestCase
{
    public function testNoArgumentsMeanStagedFiles(): void
    {
        $options = Options::fromArguments([]);

        self::assertSame(Mode::Staged, $options->mode);
        self::assertSame([], $options->paths);
        self::assertNull($options->configPath);
    }

    public function testAll(): void
    {
        self::assertSame(Mode::All, Options::fromArguments(['--all'])->mode);
    }

    public function testBase(): void
    {
        $options = Options::fromArguments(['--base=origin/main']);

        self::assertSame(Mode::Base, $options->mode);
        self::assertSame('origin/main', $options->base);
    }

    public function testPaths(): void
    {
        $options = Options::fromArguments(['src/A.php', 'lib']);

        self::assertSame(Mode::Paths, $options->mode);
        self::assertSame(['src/A.php', 'lib'], $options->paths);
    }

    public function testConfigHelpAndVersion(): void
    {
        $options = Options::fromArguments(['--config=ci.php', '-h', '-V', '--all']);

        self::assertSame('ci.php', $options->configPath);
        self::assertTrue($options->help);
        self::assertTrue($options->version);
        self::assertTrue(Options::fromArguments(['--help'])->help);
        self::assertTrue(Options::fromArguments(['--version'])->version);
    }

    /** @param list<string> $args */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsAreRejected(array $args, string $message): void
    {
        $this->expectException(InvalidArgumentsException::class);
        $this->expectExceptionMessage($message);

        Options::fromArguments($args);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidArguments(): iterable
    {
        yield 'unknown option' => [['--frobnicate'], 'Unknown option "--frobnicate"'];
        yield 'base without equals sign' => [['--base', 'main'], 'Unknown option "--base"'];
        yield 'empty base' => [['--base='], 'Option --base needs a value'];
        yield 'empty config' => [['--config='], 'Option --config needs a value'];
        yield 'all with base' => [['--all', '--base=main'], 'Use only one of'];
        yield 'all with paths' => [['--all', 'src'], 'Use only one of'];
        yield 'base with paths' => [['--base=main', 'src'], 'Use only one of'];
    }
}
