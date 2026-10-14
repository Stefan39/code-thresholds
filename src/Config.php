<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

use Stejac\CodeThresholds\Exception\ConfigException;

/**
 * Immutable settings of one run. Build it from the array a config file returns
 * with {@see Config::fromArray()}, or take {@see Config::defaults()}.
 */
final readonly class Config
{
    public const string LIMIT_METHODS = 'methods_per_class';
    public const string LIMIT_PARAMETERS = 'parameters';
    public const string LIMIT_RETURNS = 'returns';
    public const string LIMIT_LENGTH = 'function_length';
    public const string LIMIT_NESTING = 'nesting_depth';

    public const string RULE_UNUSED = 'unused_variable';
    public const string RULE_COUNTER = 'loop_counter_reassigned';

    /** @var array<string, int> */
    public const array DEFAULT_LIMITS = [
        self::LIMIT_METHODS => 20,
        self::LIMIT_PARAMETERS => 7,
        self::LIMIT_RETURNS => 3,
        self::LIMIT_LENGTH => 100,
        self::LIMIT_NESTING => 4,
    ];

    /** @var array<string, bool> */
    public const array DEFAULT_RULES = [
        self::RULE_UNUSED => true,
        self::RULE_COUNTER => true,
    ];

    /**
     * Entities track the width of a table, not the complexity of a class, and
     * a test class holds one method per scenario: neither is the shape the
     * methods-per-class limit is about.
     *
     * @var list<string>
     */
    public const array DEFAULT_METHODS_EXCLUDE = ['*/Entity/*', 'tests/*'];

    /**
     * @param list<string>        $sourceDirs             directories scanned by --all
     * @param list<string>        $exclude                globs of files that are never checked
     * @param array<string, ?int> $limits                 null disables a limit
     * @param array<string, bool> $rules
     * @param list<string>        $methodsPerClassExclude globs of files exempt from the method count
     * @param string              $projectRoot            all globs and relative paths refer to this directory
     */
    public function __construct(
        public array $sourceDirs,
        public array $exclude,
        public array $limits,
        public array $rules,
        public array $methodsPerClassExclude,
        public bool $exemptConstructors,
        public string $projectRoot,
    ) {
    }

    public static function defaults(string $projectRoot): self
    {
        return new self(['src'], [], self::DEFAULT_LIMITS, self::DEFAULT_RULES, self::DEFAULT_METHODS_EXCLUDE, true, $projectRoot);
    }

    /**
     * @param array<mixed> $data the array returned by a config file
     *
     * @throws ConfigException on unknown keys or values of the wrong type
     */
    public static function fromArray(array $data, string $projectRoot): self
    {
        return (new ConfigParser())->parse($data, $projectRoot);
    }

    /** The configured maximum, or null when the limit is switched off. */
    public function limit(string $key): ?int
    {
        return $this->limits[$key] ?? null;
    }

    public function isRuleEnabled(string $key): bool
    {
        return $this->rules[$key] ?? false;
    }

    public function isExcluded(string $relativePath): bool
    {
        return self::matchesAny($this->exclude, $relativePath);
    }

    public function isExemptFromMethodCount(string $relativePath): bool
    {
        return self::matchesAny($this->methodsPerClassExclude, $relativePath);
    }

    /**
     * The path relative to the project root, which is what every glob is
     * matched against. Anchoring at the root matters: a checkout that happens
     * to live below a directory called "tests" must not make all of src/ look
     * like test code. A file outside the root keeps its absolute path.
     */
    public function relativePath(string $file): string
    {
        $real = realpath($file);
        $path = false !== $real ? $real : $file;
        $root = realpath($this->projectRoot);
        $prefix = rtrim(false !== $root ? $root : $this->projectRoot, '/').'/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /** @param list<string> $patterns */
    private static function matchesAny(array $patterns, string $relativePath): bool
    {
        $matched = false;

        foreach ($patterns as $pattern) {
            $matched = $matched || fnmatch($pattern, $relativePath) || fnmatch($pattern, './'.$relativePath);
        }

        return $matched;
    }
}
