<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Stejac\CodeThresholds\Checker;
use Stejac\CodeThresholds\CodeMetrics;
use Stejac\CodeThresholds\CodeSmells;
use Stejac\CodeThresholds\CodeTokenCursor;
use Stejac\CodeThresholds\Config;
use Stejac\CodeThresholds\Tests\Support\TemporaryDirectoryTestCase;

/**
 * Runs the checker end to end against generated files.
 */
#[CoversClass(Checker::class)]
#[CoversClass(CodeMetrics::class)]
#[CoversClass(CodeSmells::class)]
#[CoversClass(CodeTokenCursor::class)]
final class CheckerTest extends TemporaryDirectoryTestCase
{
    private const string OPEN = "<?php\n\nclass Sample\n{\n";
    private const string CLOSE = "}\n";

    public function testAcceptsCodeWithinEveryLimit(): void
    {
        $file = $this->write('clean.php', self::OPEN."    public function a(int \$x): int\n    {\n        return \$x;\n    }\n".self::CLOSE);

        [$status, $output] = $this->check($file);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('no violations', $output);
    }

    public function testReportsTooManyReturns(): void
    {
        $body = "    public function pick(int \$x): string\n    {\n"
            ."        if (1 === \$x) { return 'a'; }\n"
            ."        if (2 === \$x) { return 'b'; }\n"
            ."        if (3 === \$x) { return 'c'; }\n"
            ."        return 'd';\n    }\n";

        [$status, $output] = $this->check($this->write('returns.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(1, $status);
        self::assertStringContainsString('returns — pick() is 4 (max 3)', $output);
    }

    public function testReportsTooManyMethods(): void
    {
        [$status, $output] = $this->check($this->write('methods.php', self::OPEN.self::methods(21).self::CLOSE));

        self::assertSame(1, $status);
        self::assertStringContainsString('methods per class — Sample is 21 (max 20)', $output);
    }

    public function testReportsTooManyParameters(): void
    {
        $params = implode(', ', array_map(static fn (int $i): string => "int \$p{$i}", range(1, 8)));

        [$status, $output] = $this->check($this->write('params.php', self::OPEN."    public function wide({$params}): void\n    {\n    }\n".self::CLOSE));

        self::assertSame(1, $status);
        self::assertStringContainsString('parameters — wide() is 8 (max 7)', $output);
    }

    /**
     * Multi-line signatures are commonly written with a trailing comma.
     * countParams() used to read that comma as an eighth separator and turn
     * seven parameters into a phantom violation the real limit never
     * exceeded.
     */
    public function testTrailingCommaInAMultilineSignatureIsNotCountedAsAnExtraParameter(): void
    {
        $params = implode(",\n        ", array_map(static fn (int $i): string => "int \$p{$i}", range(1, 7)));
        $body = "    public function wide(\n        {$params},\n    ): void\n    {\n    }\n";

        [$status, $output] = $this->check($this->write('trailing.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    /**
     * The trailing-comma fix must not swallow a genuine eighth parameter —
     * undercounting would hide a real parameter-count violation instead of a
     * fake one.
     */
    public function testTrailingCommaDoesNotHideARealParameterCountViolation(): void
    {
        $params = implode(",\n        ", array_map(static fn (int $i): string => "int \$p{$i}", range(1, 8)));
        $body = "    public function wide(\n        {$params},\n    ): void\n    {\n    }\n";

        [$status, $output] = $this->check($this->write('trailing-over.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(1, $status);
        self::assertStringContainsString('parameters — wide() is 8 (max 7)', $output);
    }

    /**
     * Combines both param-counting rules at once: the trailing comma after the
     * last parameter must not count, and the commas inside that parameter's
     * own default array must not count either. Seven real parameters either
     * way.
     */
    public function testTrailingCommaAndNestedDefaultArrayCommasBothStayOutOfTheCount(): void
    {
        $params = implode(",\n        ", array_map(static fn (int $i): string => "int \$p{$i}", range(1, 6)));
        $body = "    public function wide(\n        {$params},\n        array \$p7 = ['x', 'y'],\n    ): void\n    {\n    }\n";

        [$status, $output] = $this->check($this->write('trailing-nested.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    /**
     * `#[` opens a parameter attribute as a single T_ATTRIBUTE token rather than
     * a '[' string, but its closing `]` IS a plain string. Before the fix that
     * drove $depth permanently negative, so no top-level comma after the
     * attribute was ever counted again — eight real parameters silently read as
     * far fewer, hiding a genuine violation instead of reporting a fake one.
     */
    public function testParameterAttributeDoesNotBreakTheDepthCount(): void
    {
        $body = "    public function wide(\n"
            ."        int \$a,\n"
            ."        #[Autowire(service: 'foo')]\n"
            ."        int \$b,\n"
            ."        int \$c, int \$d, int \$e, int \$f, int \$g, int \$h,\n"
            ."    ): void\n    {\n    }\n";

        [$status, $output] = $this->check($this->write('attribute-over.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(1, $status);
        self::assertStringContainsString('parameters — wide() is 8 (max 7)', $output);
    }

    public function testParameterAttributeOnASignatureWithinTheLimitReportsNothing(): void
    {
        $body = "    public function wide(\n"
            ."        int \$a,\n"
            ."        #[Autowire(service: 'foo')]\n"
            ."        int \$b,\n"
            ."        int \$c, int \$d, int \$e, int \$f, int \$g,\n"
            ."    ): void\n    {\n    }\n";

        [$status, $output] = $this->check($this->write('attribute-within.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    /**
     * The attribute's own argument list can carry commas of its own
     * (`a: 1, b: 2`) — those must stay inside the attribute's bracket depth and
     * never register as a parameter separator.
     */
    public function testCommaInsideAttributeArgumentsDoesNotShiftTheCount(): void
    {
        $body = "    public function wide(\n"
            ."        int \$a,\n"
            ."        #[Foo(a: 1, b: 2)]\n"
            ."        int \$b,\n"
            ."        int \$c, int \$d, int \$e, int \$f, int \$g,\n"
            ."    ): void\n    {\n    }\n";

        [$status, $output] = $this->check($this->write('attribute-args-comma.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    public function testMultipleParameterAttributesOnDifferentParametersAreCountedCorrectly(): void
    {
        $body = "    public function wide(\n"
            ."        #[Foo]\n"
            ."        int \$a,\n"
            ."        #[Bar(service: 'x')]\n"
            ."        int \$b,\n"
            ."        int \$c, int \$d, int \$e, int \$f, int \$g, int \$h,\n"
            ."    ): void\n    {\n    }\n";

        [$status, $output] = $this->check($this->write('attribute-multiple.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(1, $status);
        self::assertStringContainsString('parameters — wide() is 8 (max 7)', $output);
    }

    /**
     * Regression guard for two interactions verified once by hand during review
     * but never pinned down by a test: a parameter attribute combined with the
     * the trailing-comma style, and PHP's grouped attribute syntax
     * (`#[A, B]`), where the comma belongs to the attribute list, not to the
     * parameter signature. Either combination silently breaking would not be
     * caught by the single-concern tests above.
     */
    public function testParameterAttributeAndTrailingCommaTogetherAreCountedCorrectly(): void
    {
        $body = "    public function wide(\n"
            ."        int \$a,\n"
            ."        #[Autowire(service: 'foo')]\n"
            ."        int \$b,\n"
            ."        int \$c,\n"
            ."        int \$d,\n"
            ."        int \$e,\n"
            ."        int \$f,\n"
            ."        int \$g,\n"
            ."    ): void\n    {\n    }\n";

        [$status, $output] = $this->check($this->write('attribute-trailing.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    public function testGroupedAttributeSyntaxCommaIsNotCountedAsAParameterSeparator(): void
    {
        $body = "    public function wide(\n"
            ."        int \$a,\n"
            ."        #[A, B]\n"
            ."        int \$b,\n"
            ."        int \$c, int \$d, int \$e, int \$f, int \$g,\n"
            ."    ): void\n    {\n    }\n";

        [$status, $output] = $this->check($this->write('attribute-grouped.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    public function testExemptsConstructorsFromTheParameterLimit(): void
    {
        $params = implode(', ', array_map(static fn (int $i): string => "private int \$p{$i}", range(1, 9)));

        [$status, $output] = $this->check($this->write('ctor.php', self::OPEN."    public function __construct({$params})\n    {\n    }\n".self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    /**
     * The prototype counted ten sequential guard clauses as depth ten, because
     * it never paired a control keyword with its closing brace.
     */
    public function testSequentialBlocksAreNotNesting(): void
    {
        $body = "    public function collect(array \$in): array\n    {\n        \$out = [];\n";
        for ($i = 0; $i < 10; ++$i) {
            $body .= "        if (isset(\$in[{$i}])) {\n            \$out[] = {$i};\n        }\n";
        }
        $body .= "        return \$out;\n    }\n";

        [$status, $output] = $this->check($this->write('sequential.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    public function testReportsGenuinelyDeepNesting(): void
    {
        $body = "    public function deep(array \$a): void\n    {\n"
            ."        foreach (\$a as \$b) {\n            if (\$b) {\n                while (\$b) {\n"
            ."                    foreach (\$a as \$c) {\n                        if (\$c) {\n"
            ."                            \$b = false;\n"
            ."                        }\n                    }\n                }\n            }\n        }\n    }\n";

        [$status, $output] = $this->check($this->write('deep.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(1, $status);
        self::assertStringContainsString('nesting depth', $output);
    }

    /**
     * This checker counts a closure's returns against the closure, not against
     * the method holding it — a callback handed to a cache or an array
     * function would otherwise read as an extra return.
     */
    public function testReturnsInsideClosuresBelongToTheClosure(): void
    {
        $body = "    public function cached(): string\n    {\n"
            ."        \$value = \$this->cache(function (): string {\n            return 'inner';\n        });\n"
            ."        if ('' === \$value) {\n            return 'empty';\n        }\n"
            ."        return \$value;\n    }\n";

        [$status, $output] = $this->check($this->write('closure.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    public function testEntitiesAreExemptFromTheMethodCount(): void
    {
        $file = $this->write('src/Entity/Wide.php', self::OPEN.self::methods(30).self::CLOSE);

        [$status, $output] = $this->check($file);

        self::assertSame(0, $status, $output);
    }

    /**
     * Test code is exempt from the methods-per-class limit — one method per
     * test scenario is the intended shape. The same 21-method class must
     * still be flagged under a main-code path (see testReportsTooManyMethods)
     * and must not be flagged under a test path.
     *
     * The globs are relative to the project root: "tests/Wide.php" is a test
     * path, an absolute path that merely contains "tests" is not.
     */
    public function testMethodCountIsNotEnforcedUnderATestPath(): void
    {
        $file = $this->write('tests/Wide.php', self::OPEN.self::methods(21).self::CLOSE);

        [$status, $output] = $this->check($file, $this->dir);

        self::assertSame(0, $status, $output);
    }

    /**
     * The counterpart: a directory called "tests" ABOVE the project root
     * belongs to the machine, not to the project. Matching it anywhere in the
     * path would switch the methods-per-class limit off for all of src/ for
     * anyone whose checkout lives under ~/tests/ — main code stays main code
     * there.
     */
    public function testATestsDirectoryAboveTheProjectRootDoesNotExemptMainCode(): void
    {
        $file = $this->write('tests/repo/src/Wide.php', self::OPEN.self::methods(21).self::CLOSE);

        [$status, $output] = $this->check($file, $this->dir.'/tests/repo');

        self::assertSame(1, $status);
        self::assertStringContainsString('methods per class', $output);
    }

    public function testReportsUnusedVariable(): void
    {
        $body = "    public function go(): int\n    {\n        \$unused = 5;\n\n        return 1;\n    }\n";

        [$status, $output] = $this->check($this->write('unused.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(1, $status);
        self::assertStringContainsString('unused variable — $unused', $output);
    }

    /**
     * The tokenizer reports the opening brace of "{\$x}" as T_CURLY_OPEN but the
     * closing one as a plain "}". Counting only plain braces ended the function
     * body early, so every interpolated variable looked unused.
     */
    public function testVariablesUsedInInterpolationAreNotUnused(): void
    {
        $body = "    public function go(): string\n    {\n        \$name = 'x';\n\n"
            ."        return \"hello {\$name}\";\n    }\n";

        [$status, $output] = $this->check($this->write('interpolated.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    public function testReportsLoopCounterReassignedInBody(): void
    {
        $body = "    public function scan(array \$a): void\n    {\n"
            ."        for (\$i = 0; \$i < 10; ++\$i) {\n            \$i = \$i + 2;\n        }\n    }\n";

        [$status, $output] = $this->check($this->write('counter.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(1, $status);
        self::assertStringContainsString('loop counter reassigned', $output);
    }

    public function testWhileLoopsMayMoveTheirCounter(): void
    {
        $body = "    public function scan(array \$a): void\n    {\n        \$i = 0;\n"
            ."        while (\$i < 10) {\n            \$i = \$i + 2;\n        }\n    }\n";

        [$status, $output] = $this->check($this->write('while.php', self::OPEN.$body.self::CLOSE));

        self::assertSame(0, $status, $output);
    }

    /** A class body of $count empty methods — the methods-per-class fixture, needed at three sizes. */
    private static function methods(int $count): string
    {
        $methods = '';
        for ($i = 0; $i < $count; ++$i) {
            $methods .= "    public function m{$i}(): void\n    {\n    }\n\n";
        }

        return $methods;
    }

    public function testViolationCarriesFileLineRuleAndDetail(): void
    {
        $file = $this->write('src/Sample.php', self::OPEN."    public function pick(): int\n    {\n        \$a = 1;\n\n        return 2;\n    }\n".self::CLOSE);

        $violations = (new Checker(Config::defaults($this->dir)))->inspect($file);

        self::assertCount(1, $violations);
        self::assertSame('src/Sample.php', $violations[0]->file);
        self::assertSame(7, $violations[0]->line);
        self::assertSame('unused variable', $violations[0]->rule);
        self::assertSame('$a in pick()', $violations[0]->detail);
    }

    public function testCheckCollectsViolationsOfEveryFile(): void
    {
        $one = $this->write('one.php', self::OPEN.self::methods(21).self::CLOSE);
        $two = $this->write('two.php', self::OPEN.self::methods(22).self::CLOSE);

        $violations = (new Checker(Config::defaults($this->dir)))->check([$one, $two]);

        self::assertCount(2, $violations);
        self::assertSame(['one.php', 'two.php'], array_map(static fn ($v): string => $v->file, $violations));
    }

    public function testAMissingFileYieldsNoViolations(): void
    {
        self::assertSame([], (new Checker(Config::defaults($this->dir)))->inspect($this->dir.'/missing.php'));
    }

    public function testALimitSetToNullIsNotChecked(): void
    {
        $config = Config::fromArray(['limits' => ['methods_per_class' => null]], $this->dir);

        [$status, $output] = $this->check($this->write('methods.php', self::OPEN.self::methods(40).self::CLOSE), null, $config);

        self::assertSame(0, $status, $output);
    }

    public function testACustomLimitReplacesTheDefault(): void
    {
        $config = Config::fromArray(['limits' => ['returns' => 1]], $this->dir);
        $body = "    public function pick(int \$x): int\n    {\n        if (\$x) { return 1; }\n        return 2;\n    }\n";

        [$status, $output] = $this->check($this->write('returns.php', self::OPEN.$body.self::CLOSE), null, $config);

        self::assertSame(1, $status);
        self::assertStringContainsString('returns — pick() is 2 (max 1)', $output);
    }

    public function testDisabledRulesAreNotReported(): void
    {
        $config = Config::fromArray(['rules' => ['unused_variable' => false, 'loop_counter_reassigned' => false]], $this->dir);
        $body = "    public function go(): void\n    {\n        \$unused = 5;\n        for (\$i = 0; \$i < 3; ++\$i) {\n            \$i = \$i + 1;\n        }\n    }\n";

        [$status, $output] = $this->check($this->write('rules.php', self::OPEN.$body.self::CLOSE), null, $config);

        self::assertSame(0, $status, $output);
    }

    public function testConstructorsAreCheckedWhenTheExemptionIsOff(): void
    {
        $config = Config::fromArray(['exempt_constructors' => false], $this->dir);
        $params = implode(', ', array_map(static fn (int $i): string => "private int \$p{$i}", range(1, 9)));

        [$status, $output] = $this->check($this->write('ctor.php', self::OPEN."    public function __construct({$params})\n    {\n    }\n".self::CLOSE), null, $config);

        self::assertSame(1, $status);
        self::assertStringContainsString('parameters — __construct() is 9 (max 7)', $output);
    }

    public function testAnExceptGlobOverridesTheMethodCountExemption(): void
    {
        $config = Config::fromArray(['methods_per_class_exclude' => []], $this->dir);
        $file = $this->write('src/Entity/Wide.php', self::OPEN.self::methods(21).self::CLOSE);

        [$status] = $this->check($file, null, $config);

        self::assertSame(1, $status);
    }

    public function testAbstractAndInterfaceMethodsWithoutBodyAreSkipped(): void
    {
        $file = $this->write('iface.php', "<?php\n\ninterface Contract\n{\n    public function a(int \$x): int;\n}\n");

        [$status, $output] = $this->check($file);

        self::assertSame(0, $status, $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function check(string $file, ?string $root = null, ?Config $config = null): array
    {
        $checker = new Checker($config ?? Config::defaults($root ?? $this->dir));
        $lines = array_map(static fn ($v): string => $v->rule.' — '.$v->detail, $checker->inspect($file));

        return [[] === $lines ? 0 : 1, [] === $lines ? 'no violations' : implode("\n", $lines)];
    }
}
