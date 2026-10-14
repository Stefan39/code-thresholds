<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

use Stejac\CodeThresholds\Exception\ConfigException;

/**
 * Validates the raw array of a config file and merges it onto the defaults.
 * Anything it does not understand is an error rather than silently ignored: a
 * misspelled key would otherwise leave a limit at its default without a trace.
 */
final class ConfigParser
{
    private const array KEYS = [
        'source_dirs',
        'exclude',
        'limits',
        'rules',
        'methods_per_class_exclude',
        'exempt_constructors',
    ];

    /**
     * @param array<mixed> $data
     *
     * @throws ConfigException
     */
    public function parse(array $data, string $projectRoot): Config
    {
        $unknown = array_diff(array_map('strval', array_keys($data)), self::KEYS);
        if ([] !== $unknown) {
            throw new ConfigException(sprintf('Unknown config key "%s". Allowed keys: %s.', reset($unknown), implode(', ', self::KEYS)));
        }

        $defaults = Config::defaults($projectRoot);

        return new Config(
            $this->stringList($data, 'source_dirs', $defaults->sourceDirs),
            $this->stringList($data, 'exclude', $defaults->exclude),
            $this->limits($data['limits'] ?? []),
            $this->rules($data['rules'] ?? []),
            $this->stringList($data, 'methods_per_class_exclude', $defaults->methodsPerClassExclude),
            $this->flag($data, 'exempt_constructors', $defaults->exemptConstructors),
            $projectRoot,
        );
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $default
     *
     * @return list<string>
     */
    private function stringList(array $data, string $key, array $default): array
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ConfigException(sprintf('Config key "%s" must be a list of strings.', $key));
        }

        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item) || '' === $item) {
                throw new ConfigException(sprintf('Config key "%s" must contain only non-empty strings.', $key));
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /** @param array<mixed> $data */
    private function flag(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? $default;
        if (!is_bool($value)) {
            throw new ConfigException(sprintf('Config key "%s" must be true or false.', $key));
        }

        return $value;
    }

    /** @return array<string, ?int> */
    private function limits(mixed $value): array
    {
        $limits = Config::DEFAULT_LIMITS;

        foreach ($this->map($value, 'limits', array_keys(Config::DEFAULT_LIMITS)) as $name => $limit) {
            if (null !== $limit && (!is_int($limit) || $limit < 1)) {
                throw new ConfigException(sprintf('Limit "%s" must be a positive integer or null.', $name));
            }
            $limits[$name] = $limit;
        }

        return $limits;
    }

    /** @return array<string, bool> */
    private function rules(mixed $value): array
    {
        $rules = Config::DEFAULT_RULES;

        foreach ($this->map($value, 'rules', array_keys(Config::DEFAULT_RULES)) as $name => $enabled) {
            if (!is_bool($enabled)) {
                throw new ConfigException(sprintf('Rule "%s" must be true or false.', $name));
            }
            $rules[$name] = $enabled;
        }

        return $rules;
    }

    /**
     * @param list<string> $allowed
     *
     * @return array<string, mixed>
     */
    private function map(mixed $value, string $section, array $allowed): array
    {
        if (!is_array($value)) {
            throw new ConfigException(sprintf('Config key "%s" must be an array.', $section));
        }

        $map = [];
        foreach ($value as $name => $item) {
            if (!in_array($name, $allowed, true)) {
                throw new ConfigException(sprintf('Unknown entry "%s" in "%s". Allowed: %s.', $name, $section, implode(', ', $allowed)));
            }
            $map[(string) $name] = $item;
        }

        return $map;
    }
}
