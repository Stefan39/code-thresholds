<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds\Exception;

/**
 * Base class of every error the tool reports to the user instead of crashing.
 * The CLI maps all of them to exit code 2.
 */
abstract class CodeThresholdsException extends \RuntimeException
{
}
