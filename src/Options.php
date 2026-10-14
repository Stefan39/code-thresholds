<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

use Stejac\CodeThresholds\Exception\InvalidArgumentsException;

/**
 * Parsed command line.
 *
 * @phpstan-type State array{all: bool, base: ?string, config: ?string, help: bool, version: bool, paths: list<string>}
 */
final readonly class Options
{
    /** @param list<string> $paths */
    public function __construct(
        public Mode $mode = Mode::Staged,
        public ?string $base = null,
        public array $paths = [],
        public ?string $configPath = null,
        public bool $help = false,
        public bool $version = false,
    ) {
    }

    /**
     * @param list<string> $args the arguments without the script name
     *
     * @throws InvalidArgumentsException
     */
    public static function fromArguments(array $args): self
    {
        $state = ['all' => false, 'base' => null, 'config' => null, 'help' => false, 'version' => false, 'paths' => []];

        foreach ($args as $arg) {
            $state = self::apply($state, $arg);
        }

        return self::build($state);
    }

    /**
     * @param State $state
     *
     * @return State
     */
    private static function apply(array $state, string $arg): array
    {
        match (true) {
            '--help' === $arg || '-h' === $arg => $state['help'] = true,
            '--version' === $arg || '-V' === $arg => $state['version'] = true,
            '--all' === $arg => $state['all'] = true,
            str_starts_with($arg, '--base=') => $state['base'] = self::value($arg, '--base='),
            str_starts_with($arg, '--config=') => $state['config'] = self::value($arg, '--config='),
            str_starts_with($arg, '-') => throw new InvalidArgumentsException(sprintf('Unknown option "%s". See --help.', $arg)),
            default => $state['paths'][] = $arg,
        };

        return $state;
    }

    private static function value(string $arg, string $prefix): string
    {
        $value = substr($arg, strlen($prefix));
        if ('' === $value) {
            throw new InvalidArgumentsException(sprintf('Option %s needs a value.', rtrim($prefix, '=')));
        }

        return $value;
    }

    /** @param State $state */
    private static function build(array $state): self
    {
        $selected = ($state['all'] ? 1 : 0) + (null !== $state['base'] ? 1 : 0) + ([] !== $state['paths'] ? 1 : 0);
        if ($selected > 1) {
            throw new InvalidArgumentsException('Use only one of --all, --base=<ref> or explicit paths.');
        }

        $mode = match (true) {
            $state['all'] => Mode::All,
            null !== $state['base'] => Mode::Base,
            [] !== $state['paths'] => Mode::Paths,
            default => Mode::Staged,
        };

        return new self($mode, $state['base'], $state['paths'], $state['config'], $state['help'], $state['version']);
    }
}
