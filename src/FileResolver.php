<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

use Stejac\CodeThresholds\Exception\ConfigException;
use Stejac\CodeThresholds\Exception\GitException;

/**
 * Turns the selected mode into the list of PHP files to check: staged files,
 * files changed against a base ref, everything below the source directories,
 * or the paths given explicitly. Non-PHP files, files matching `exclude` and
 * paths that do not exist never make it into the list.
 */
final class FileResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly string $workingDirectory,
        private readonly Git $git = new Git(),
    ) {
    }

    /**
     * @return list<string> absolute paths, sorted
     *
     * @throws ConfigException
     * @throws GitException
     */
    public function resolve(Options $options): array
    {
        $candidates = match ($options->mode) {
            Mode::Staged => $this->inProject($this->git->stagedFiles($this->config->projectRoot)),
            Mode::Base => $this->inProject($this->git->changedFiles($this->config->projectRoot, (string) $options->base)),
            Mode::All => $this->sourceFiles(),
            Mode::Paths => $this->explicit($options->paths),
        };

        $files = array_filter($candidates, fn (string $file): bool => $this->accepts($file));
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function accepts(string $file): bool
    {
        return 'php' === pathinfo($file, \PATHINFO_EXTENSION)
            && is_file($file)
            && !$this->config->isExcluded($this->config->relativePath($file));
    }

    /**
     * @param list<string> $relative paths relative to the project root
     *
     * @return list<string>
     */
    private function inProject(array $relative): array
    {
        return array_map(fn (string $path): string => $this->config->projectRoot.'/'.$path, $relative);
    }

    /**
     * @return list<string>
     *
     * @throws ConfigException
     */
    private function sourceFiles(): array
    {
        $found = [];
        $existing = 0;

        foreach ($this->config->sourceDirs as $dir) {
            $path = $this->absolute($dir, $this->config->projectRoot);
            $existing += is_dir($path) ? 1 : 0;
            $found = [...$found, ...$this->scan($path)];
        }

        if (0 === $existing) {
            throw new ConfigException(sprintf('None of the configured source_dirs exists (%s), nothing to scan.', implode(', ', $this->config->sourceDirs)));
        }

        return $found;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function explicit(array $paths): array
    {
        $found = [];

        foreach ($paths as $path) {
            $absolute = $this->absolute($path, $this->workingDirectory);
            $found = [...$found, ...(is_dir($absolute) ? $this->scan($absolute) : [$absolute])];
        }

        return $found;
    }

    /** @return list<string> every file below $directory; empty when it is not a directory */
    private function scan(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    private function absolute(string $path, string $base): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($base, '/').'/'.$path;
    }
}
