<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

/**
 * Where the list of files to check comes from.
 */
enum Mode
{
    /** Files staged in git (the pre-commit case). */
    case Staged;

    /** Files changed against a base ref (the pull-request case). */
    case Base;

    /** Every PHP file below the configured source directories. */
    case All;

    /** Files and directories named on the command line. */
    case Paths;
}
