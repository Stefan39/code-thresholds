<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stejac\CodeThresholds\Config;
use Stejac\CodeThresholds\ConfigParser;
use Stejac\CodeThresholds\Exception\ConfigException;

#[CoversClass(Config::class)]
#[CoversClass(ConfigParser::class)]
final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::defaults('/project');

        self::assertSame(['src'], $config->sourceDirs);
        self::assertSame([], $config->exclude);
        self::assertSame(['*/Entity/*', 'tests/*'], $config->methodsPerClassExclude);
        self::assertTrue($config->exemptConstructors);
        self::assertSame('/project', $config->projectRoot);
        self::assertSame(20, $config->limit('methods_per_class'));
        self::assertSame(7, $config->limit('parameters'));
        self::assertSame(3, $config->limit('returns'));
        self::assertSame(100, $config->limit('function_length'));
        self::assertSame(4, $config->limit('nesting_depth'));
        self::assertTrue($config->isRuleEnabled('unused_variable'));
        self::assertTrue($config->isRuleEnabled('loop_counter_reassigned'));
    }

    public function testAnEmptyArrayGivesTheDefaults(): void
    {
        self::assertEquals(Config::defaults('/project'), Config::fromArray([], '/project'));
    }

    public function testOverridesAreMergedOntoTheDefaults(): void
    {
        $config = Config::fromArray([
            'source_dirs' => ['app', 'lib'],
            'exclude' => ['app/Generated/*'],
            'limits' => ['parameters' => 5],
            'rules' => ['unused_variable' => false],
            'methods_per_class_exclude' => ['app/Dto/*'],
            'exempt_constructors' => false,
        ], '/project');

        self::assertSame(['app', 'lib'], $config->sourceDirs);
        self::assertSame(['app/Generated/*'], $config->exclude);
        self::assertSame(5, $config->limit('parameters'));
        self::assertSame(20, $config->limit('methods_per_class'));
        self::assertFalse($config->isRuleEnabled('unused_variable'));
        self::assertTrue($config->isRuleEnabled('loop_counter_reassigned'));
        self::assertSame(['app/Dto/*'], $config->methodsPerClassExclude);
        self::assertFalse($config->exemptConstructors);
    }

    public function testNullDisablesALimit(): void
    {
        $config = Config::fromArray(['limits' => ['returns' => null]], '/project');

        self::assertNull($config->limit('returns'));
        self::assertSame(7, $config->limit('parameters'));
    }

    public function testExcludeAndMethodExemptionMatchRelativePaths(): void
    {
        $config = Config::fromArray(['exclude' => ['src/Generated/*']], '/project');

        self::assertTrue($config->isExcluded('src/Generated/A.php'));
        self::assertTrue(Config::fromArray(['exclude' => ['./lib/*']], '/project')->isExcluded('lib/A.php'));
        self::assertFalse($config->isExcluded('src/Other/A.php'));
        self::assertTrue($config->isExemptFromMethodCount('src/Entity/User.php'));
        self::assertTrue($config->isExemptFromMethodCount('tests/Unit/FooTest.php'));
        self::assertFalse($config->isExemptFromMethodCount('src/Service/Foo.php'));
        self::assertFalse($config->isExemptFromMethodCount('vendor/x/tests/Foo.php'));
    }

    public function testRelativePathIsAnchoredAtTheProjectRoot(): void
    {
        $root = (string) realpath(__DIR__.'/..');
        $config = Config::defaults($root);

        self::assertSame('src/Config.php', $config->relativePath($root.'/src/Config.php'));
        self::assertSame('does/not/exist.php', $config->relativePath($root.'/does/not/exist.php'));
        self::assertSame('/elsewhere/File.php', $config->relativePath('/elsewhere/File.php'));
    }

    /** @param array<mixed> $data */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigIsRejected(array $data, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        Config::fromArray($data, '/project');
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'unknown key' => [['source_dir' => ['src']], 'Unknown config key "source_dir"'];
        yield 'source_dirs not a list' => [['source_dirs' => 'src'], '"source_dirs" must be a list of strings'];
        yield 'source_dirs keyed' => [['source_dirs' => ['a' => 'src']], '"source_dirs" must be a list of strings'];
        yield 'source_dirs with non-string' => [['source_dirs' => ['src', 5]], '"source_dirs" must contain only non-empty strings'];
        yield 'exclude with empty string' => [['exclude' => ['']], '"exclude" must contain only non-empty strings'];
        yield 'limits not an array' => [['limits' => 5], 'Config key "limits" must be an array'];
        yield 'unknown limit' => [['limits' => ['depth' => 3]], 'Unknown entry "depth" in "limits"'];
        yield 'limit as string' => [['limits' => ['returns' => '3']], 'Limit "returns" must be a positive integer or null'];
        yield 'limit zero' => [['limits' => ['returns' => 0]], 'Limit "returns" must be a positive integer or null'];
        yield 'rules not an array' => [['rules' => true], 'Config key "rules" must be an array'];
        yield 'unknown rule' => [['rules' => ['cognitive' => true]], 'Unknown entry "cognitive" in "rules"'];
        yield 'rule not a bool' => [['rules' => ['unused_variable' => 1]], 'Rule "unused_variable" must be true or false'];
        yield 'exempt_constructors not a bool' => [['exempt_constructors' => 'yes'], '"exempt_constructors" must be true or false'];
    }
}
