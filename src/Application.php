<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

use Stejac\CodeThresholds\Exception\CodeThresholdsException;

/**
 * The command line front end: parses arguments, resolves the files, runs the
 * checker and reports. Exit codes: 0 no violations, 1 violations found,
 * 2 usage or configuration error.
 */
final class Application
{
    public const string VERSION = '1.0.0';

    public const int EXIT_OK = 0;
    public const int EXIT_VIOLATIONS = 1;
    public const int EXIT_ERROR = 2;

    private const string USAGE = <<<'TXT'
        Usage:
          code-thresholds [options] [path...]

        Without paths, the PHP files staged in git are checked.

        Options:
          --base=<ref>     Check files changed against <ref> (for example origin/main)
          --all            Check every PHP file below the configured source_dirs
          --config=<file>  Use this config file instead of ./code-thresholds.php
          -h, --help       Show this help
          -V, --version    Show the version

        Paths may be files or directories. Exit codes: 0 no violations,
        1 violations, 2 usage or configuration error.

        TXT;

    private readonly string $workingDirectory;

    private readonly \Closure $stdout;

    private readonly \Closure $stderr;

    /**
     * @param (\Closure(string): void)|null $stdout receives normal output, defaults to STDOUT
     * @param (\Closure(string): void)|null $stderr receives errors and violations, defaults to STDERR
     */
    public function __construct(?string $workingDirectory = null, ?\Closure $stdout = null, ?\Closure $stderr = null)
    {
        $this->workingDirectory = $workingDirectory ?? (string) getcwd();
        $this->stdout = $stdout ?? static function (string $text): void {
            fwrite(\STDOUT, $text);
        };
        $this->stderr = $stderr ?? static function (string $text): void {
            fwrite(\STDERR, $text);
        };
    }

    /** @param list<string> $argv as received from the command line, script name first */
    public function run(array $argv): int
    {
        try {
            return $this->execute(array_slice($argv, 1));
        } catch (CodeThresholdsException $e) {
            ($this->stderr)('code-thresholds: '.$e->getMessage()."\n");

            return self::EXIT_ERROR;
        }
    }

    /** @param list<string> $args */
    private function execute(array $args): int
    {
        $options = Options::fromArguments($args);

        if ($options->help || $options->version) {
            ($this->stdout)($options->help ? self::USAGE : 'code-thresholds '.self::VERSION."\n");

            return self::EXIT_OK;
        }

        $config = (new ConfigLoader())->load($options->configPath, $this->workingDirectory);
        $files = (new FileResolver($config, $this->workingDirectory))->resolve($options);

        return $this->report(count($files), (new Checker($config))->check($files));
    }

    /** @param list<Violation> $violations */
    private function report(int $checked, array $violations): int
    {
        if ([] === $violations) {
            ($this->stdout)(sprintf("code-thresholds: %d file(s) checked, no violations.\n", $checked));

            return self::EXIT_OK;
        }

        $text = "code-thresholds: limits exceeded:\n\n";
        foreach ($violations as $v) {
            $text .= sprintf("  %s:%d\n    %s — %s\n", $v->file, $v->line, $v->rule, $v->detail);
        }
        ($this->stderr)($text.sprintf("\n%d violation(s).\n", count($violations)));

        return self::EXIT_VIOLATIONS;
    }
}
