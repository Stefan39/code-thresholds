<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Gives every test its own scratch directory. Fixtures are written at runtime
 * rather than checked in: a file that deliberately breaks the rules would
 * otherwise be analysed by PHPStan and PHP-CS-Fixer.
 */
abstract class TemporaryDirectoryTestCase extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()).'/code-thresholds-'.uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    /** Writes $contents to $name below the scratch directory and returns the absolute path. */
    protected function write(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            $path = $dir.'/'.$entry;
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
