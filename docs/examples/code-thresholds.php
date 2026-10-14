<?php

declare(strict_types=1);

/*
 * Sample configuration for stejac/code-thresholds.
 *
 * Save it as `code-thresholds.php` in your project root (or pass any path with
 * --config=<file>). Every key is optional; what you omit keeps the default
 * shown here. Unknown keys and wrong types are rejected with exit code 2.
 *
 * All paths and globs are relative to the directory this file lives in.
 */

return [
    // Directories scanned by `--all`. Entries that do not exist are skipped.
    'source_dirs' => ['src'],

    // Files that are never checked, as fnmatch() globs. `*` also matches `/`.
    'exclude' => [
        // 'src/Generated/*',
        // 'src/Seed/BigFixtureData.php',
    ],

    // Maximum allowed values. Set a limit to null to switch the rule off.
    'limits' => [
        'methods_per_class' => 20,
        'parameters' => 7,
        'returns' => 3,
        'function_length' => 100, // lines in the function body
        'nesting_depth' => 4,     // nested if/for/foreach/while/switch/try/match blocks
    ],

    // Defect checks that are not about size.
    'rules' => [
        'unused_variable' => true,         // assigned once, never read
        'loop_counter_reassigned' => true, // a `for` counter reassigned in the body
    ],

    // Files exempt from the methods-per-class limit only. Entities track the
    // width of a table, and test classes hold one method per scenario.
    'methods_per_class_exclude' => ['*/Entity/*', 'tests/*'],

    // Constructors may take more parameters than the limit allows: injecting
    // eight collaborators is not the same defect as an eight-argument call.
    'exempt_constructors' => true,
];
