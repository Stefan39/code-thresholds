<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

use Stejac\CodeThresholds\Exception\ConfigException;

/**
 * Finds and reads the config file.
 *
 * Without --config the file is `code-thresholds.php` in the working directory;
 * when it does not exist, the defaults apply. The project root is the
 * directory of the config file, else the working directory.
 */
final class ConfigLoader
{
    public const string FILE_NAME = 'code-thresholds.php';

    /** @throws ConfigException */
    public function load(?string $explicitPath, string $workingDirectory): Config
    {
        $path = $this->locate($explicitPath, $workingDirectory);

        if (null === $path) {
            return Config::defaults($this->canonical($workingDirectory));
        }

        return Config::fromArray($this->read($path), dirname($path));
    }

    /** @throws ConfigException */
    private function locate(?string $explicitPath, string $workingDirectory): ?string
    {
        $candidate = null === $explicitPath
            ? rtrim($workingDirectory, '/').'/'.self::FILE_NAME
            : $this->absolute($explicitPath, $workingDirectory);

        if (is_file($candidate)) {
            return $this->canonical($candidate);
        }

        if (null !== $explicitPath) {
            throw new ConfigException(sprintf('Config file not found: %s', $explicitPath));
        }

        return null;
    }

    /**
     * @return array<mixed>
     *
     * @throws ConfigException
     */
    private function read(string $path): array
    {
        try {
            $data = (static fn (string $file): mixed => require $file)($path);
        } catch (\ParseError $e) {
            throw new ConfigException(sprintf('Config file %s has a syntax error: %s', $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($data)) {
            throw new ConfigException(sprintf('Config file %s must return an array.', $path));
        }

        return $data;
    }

    private function absolute(string $path, string $workingDirectory): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($workingDirectory, '/').'/'.$path;
    }

    private function canonical(string $path): string
    {
        $real = realpath($path);

        return false !== $real ? $real : $path;
    }
}
