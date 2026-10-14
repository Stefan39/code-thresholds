<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

/**
 * Checks size and complexity limits that are countable exactly from a single
 * file.
 *
 * Three checks are deliberately absent, because a check that reports what a
 * project does not actually enforce is worse than no check — it blocks commits
 * and gets switched off:
 *
 *   - Copy-paste detection needs a tokeniser built for duplication; a local
 *     imitation either misses duplicates or cries wolf. Use a dedicated tool.
 *   - Duplicated literals: counting them flags ordinary array keys such as
 *     'value' or 'id' — noise, not signal.
 *   - Cognitive complexity: a token-based reimplementation checked against
 *     reference figures came out too high for one method and too low for
 *     another. Understating complexity is the dangerous direction, because it
 *     grants false confidence, so that check stays with the reviewer.
 *
 * Analysis runs on token_get_all(), never on regular expressions. A regex count
 * of string literals matches the gap *between* two array keys as if it were a
 * literal of its own — an early prototype reported a phantom `' => 0, '` that
 * way.
 *
 * @phpstan-import-type Tokens from CodeTokenCursor
 * @phpstan-import-type FunctionMetrics from CodeMetrics
 */
final class Checker
{
    private const array RULE_LABELS = [
        Config::LIMIT_METHODS => 'methods per class',
        Config::LIMIT_PARAMETERS => 'parameters',
        Config::LIMIT_RETURNS => 'returns',
        Config::LIMIT_LENGTH => 'function length',
        Config::LIMIT_NESTING => 'nesting depth',
        Config::RULE_UNUSED => 'unused variable',
        Config::RULE_COUNTER => 'loop counter reassigned',
    ];

    private CodeMetrics $metrics;

    private CodeSmells $smells;

    public function __construct(private readonly Config $config)
    {
        $this->metrics = new CodeMetrics();
        $this->smells = new CodeSmells();
    }

    /**
     * @param list<string> $files
     *
     * @return list<Violation>
     */
    public function check(array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            $violations = [...$violations, ...$this->inspect($file)];
        }

        return $violations;
    }

    /** @return list<Violation> */
    public function inspect(string $file): array
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        if (false === $contents) {
            return [];
        }

        $name = $this->config->relativePath($file);
        $tokens = token_get_all($contents);
        $found = $this->classViolations($name, $tokens);

        foreach ($this->metrics->functions($tokens) as $fn) {
            $found = [...$found, ...$this->functionViolations($name, $fn, $tokens)];
        }

        return $found;
    }

    /**
     * @param Tokens $tokens
     *
     * @return list<Violation>
     */
    private function classViolations(string $file, array $tokens): array
    {
        if ($this->config->isExemptFromMethodCount($file)) {
            return [];
        }

        $found = [];
        foreach ($this->metrics->classes($tokens) as $class) {
            $found[] = $this->overLimit($file, $class['line'], Config::LIMIT_METHODS, $class['name'], $class['methods']);
        }

        return $this->present($found);
    }

    /**
     * @param FunctionMetrics $fn
     * @param Tokens          $tokens
     *
     * @return list<Violation>
     */
    private function functionViolations(string $file, array $fn, array $tokens): array
    {
        $subject = $fn['name'].'()';
        $line = $fn['line'];
        $skipParameters = $this->config->exemptConstructors && '__construct' === $fn['name'];

        $found = $this->present([
            $skipParameters ? null : $this->overLimit($file, $line, Config::LIMIT_PARAMETERS, $subject, $fn['params']),
            $this->overLimit($file, $line, Config::LIMIT_RETURNS, $subject, $fn['returns']),
            $this->overLimit($file, $line, Config::LIMIT_LENGTH, $subject, $fn['lines']),
            $this->overLimit($file, $line, Config::LIMIT_NESTING, $subject, $fn['nesting']),
        ]);

        return [...$found, ...$this->smellViolations($file, $subject, $fn['body'], $tokens)];
    }

    /**
     * @param array{0: int, 1: int} $body
     * @param Tokens                $tokens
     *
     * @return list<Violation>
     */
    private function smellViolations(string $file, string $subject, array $body, array $tokens): array
    {
        [$from, $to] = $body;
        $found = [];

        if ($this->config->isRuleEnabled(Config::RULE_UNUSED)) {
            foreach ($this->smells->unusedVariables($tokens, $from, $to) as $unused) {
                $found[] = new Violation($file, $unused['line'], self::RULE_LABELS[Config::RULE_UNUSED], $unused['name'].' in '.$subject);
            }
        }

        if ($this->config->isRuleEnabled(Config::RULE_COUNTER)) {
            foreach ($this->smells->reassignedLoopCounters($tokens, $from, $to) as $counter) {
                $found[] = new Violation($file, $counter['line'], self::RULE_LABELS[Config::RULE_COUNTER], $counter['name'].' in '.$subject.' — use while');
            }
        }

        return $found;
    }

    private function overLimit(string $file, int $line, string $limitKey, string $subject, int $value): ?Violation
    {
        $limit = $this->config->limit($limitKey);

        return null !== $limit && $value > $limit
            ? new Violation($file, $line, self::RULE_LABELS[$limitKey], sprintf('%s is %d (max %d)', $subject, $value, $limit))
            : null;
    }

    /**
     * @param list<Violation|null> $candidates
     *
     * @return list<Violation>
     */
    private function present(array $candidates): array
    {
        return array_values(array_filter($candidates));
    }
}
