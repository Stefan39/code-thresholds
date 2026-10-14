<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

/**
 * One exceeded limit or detected smell at a given place in a file.
 */
final readonly class Violation
{
    public function __construct(
        public string $file,
        public int $line,
        public string $rule,
        public string $detail,
    ) {
    }
}
