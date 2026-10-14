<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Stejac\CodeThresholds\ConfigLoader;
use Stejac\CodeThresholds\Exception\ConfigException;
use Stejac\CodeThresholds\Tests\Support\TemporaryDirectoryTestCase;

#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TemporaryDirectoryTestCase
{
    public function testWithoutAConfigFileTheDefaultsApplyAndTheRootIsTheWorkingDirectory(): void
    {
        $config = (new ConfigLoader())->load(null, $this->dir);

        self::assertSame(['src'], $config->sourceDirs);
        self::assertSame($this->dir, $config->projectRoot);
    }

    public function testPicksUpCodeThresholdsPhpInTheWorkingDirectory(): void
    {
        $this->write('code-thresholds.php', "<?php\n\nreturn ['limits' => ['returns' => 2]];\n");

        $config = (new ConfigLoader())->load(null, $this->dir);

        self::assertSame(2, $config->limit('returns'));
        self::assertSame($this->dir, $config->projectRoot);
    }

    public function testAnExplicitRelativePathIsResolvedAgainstTheWorkingDirectoryAndSetsTheRoot(): void
    {
        $this->write('config/custom.php', "<?php\n\nreturn ['source_dirs' => ['lib']];\n");

        $config = (new ConfigLoader())->load('config/custom.php', $this->dir);

        self::assertSame(['lib'], $config->sourceDirs);
        self::assertSame($this->dir.'/config', $config->projectRoot);
    }

    public function testAnExplicitAbsolutePathWorks(): void
    {
        $file = $this->write('abs.php', "<?php\n\nreturn [];\n");

        self::assertSame($this->dir, (new ConfigLoader())->load($file, '/')->projectRoot);
    }

    public function testAMissingExplicitFileIsAnError(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Config file not found: nope.php');

        (new ConfigLoader())->load('nope.php', $this->dir);
    }

    public function testAFileThatDoesNotReturnAnArrayIsAnError(): void
    {
        $this->write('code-thresholds.php', "<?php\n\nreturn 5;\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must return an array');

        (new ConfigLoader())->load(null, $this->dir);
    }

    public function testASyntaxErrorIsReportedAsAConfigError(): void
    {
        $this->write('code-thresholds.php', "<?php\n\nreturn [;\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('syntax error');

        (new ConfigLoader())->load(null, $this->dir);
    }

    public function testInvalidContentIsReportedAsAConfigError(): void
    {
        $this->write('code-thresholds.php', "<?php\n\nreturn ['nope' => 1];\n");

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load(null, $this->dir);
    }
}
