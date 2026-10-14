# code-thresholds

> A dependency-free size and complexity gate for PHP that checks only the files you changed: method count, parameters, returns, function length, nesting depth and two defect checks, in a pre-commit hook or a pull request.

[![CI](https://github.com/Stefan39/code-thresholds/actions/workflows/ci.yml/badge.svg)](https://github.com/Stefan39/code-thresholds/actions/workflows/ci.yml)
[![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](phpstan.neon)
[![Latest Stable Version](https://img.shields.io/packagist/v/stejac/code-thresholds.svg)](https://packagist.org/packages/stejac/code-thresholds)
[![codecov](https://codecov.io/gh/Stefan39/code-thresholds/branch/main/graph/badge.svg)](https://codecov.io/gh/Stefan39/code-thresholds)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

[Deutsche Version](README.de.md)

## Why this one?

Size and complexity limits are usually enforced late: a hosted analysis reports them after the push, and the fix turns into another round of review. This tool moves exactly the limits that can be counted from a single file to the moment before the commit.

| Tool | What it is good at | Where code-thresholds differs |
|---|---|---|
| [PHPStan](https://phpstan.org) | Types and bugs | It has no size or complexity limits; this tool does not look at types at all. Use both. |
| [PHPMD](https://phpmd.org) | Similar metrics (excessive method count, parameter list length, ...) | PHPMD brings its own dependencies and rule-set XML. This tool is one `composer require --dev` with `ext-tokenizer` as its only requirement, and one small PHP config file. |
| [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) | Code style, plus a few metrics sniffs | Style is not the goal here, and there is no ruleset to compose. |
| [SonarCloud](https://www.sonarsource.com/products/sonarcloud/) | Cognitive complexity, duplication, security hotspots, history | It runs on the server after the push. This tool answers locally, in the pre-commit hook or the pull request job, before a finding exists. |

What sets it apart is the combination: **zero dependencies**, **only changed files** (staged, or a diff against a base ref), so it stays fast enough for every commit, and **one config file** that is plain PHP.

### What it deliberately leaves out

A check that reports something a project does not really enforce is worse than no check: it blocks commits and gets switched off. Three checks are therefore absent on purpose.

- **Cognitive complexity.** A token-based reimplementation, compared with reference figures, came out too high for one method and too low for another. Understating complexity is the dangerous direction because it grants false confidence, so this stays with the reviewer or a full analysis tool.
- **Duplicated literals.** Counting them flags ordinary array keys such as `'id'` or `'value'`: noise, not signal.
- **Copy-paste detection.** It needs a tokeniser built for duplication; a local imitation either misses duplicates or cries wolf. Use a dedicated tool such as jscpd or PHP Copy/Paste Detector.

Everything is measured on `token_get_all()`, never with regular expressions. A regex over string literals matches the gap between two array keys as if it were a literal of its own.

## Quickstart

```bash
composer require --dev stejac/code-thresholds
```

```bash
vendor/bin/code-thresholds                 # PHP files staged in git
vendor/bin/code-thresholds --base=origin/main   # files changed against a ref
vendor/bin/code-thresholds --all           # everything below source_dirs
vendor/bin/code-thresholds src/Foo.php lib # explicit files or directories
```

Without a config file the defaults apply and `src/` is the source directory. Output on success goes to stdout, the violation list to stderr:

```text
code-thresholds: limits exceeded:

  src/Service/Importer.php:42
    parameters — import() is 9 (max 7)
  src/Service/Importer.php:57
    unused variable — $legacy in import()

2 violation(s).
```

Exit codes: `0` no violations, `1` violations found, `2` usage or configuration error.

Ready-made integrations:

- Pre-commit hook: [`docs/examples/pre-commit`](docs/examples/pre-commit)
- GitHub Actions on pull requests: [`docs/examples/github-action.yml`](docs/examples/github-action.yml)
- Fully commented config file: [`docs/examples/code-thresholds.php`](docs/examples/code-thresholds.php)

## Features

| Check | Config key | Default | Counts |
|---|---|---|---|
| Methods per class | `limits.methods_per_class` | 20 | Methods of one class, interface, trait or enum. Closures are not methods. |
| Parameters | `limits.parameters` | 7 | Parameters of a function or method. A trailing comma and commas inside default values or attributes are not counted. |
| Returns | `limits.returns` | 3 | `return` statements in a function. Returns inside a nested closure belong to the closure. |
| Function length | `limits.function_length` | 100 | Lines of the function body. |
| Nesting depth | `limits.nesting_depth` | 4 | Nested `if`, `for`, `foreach`, `while`, `switch`, `try` and `match` blocks. Ten sequential `if` blocks are depth one. |
| Unused variable | `rules.unused_variable` | on | A variable that appears exactly once in a function body, as an assignment. Deliberately conservative to avoid false alarms with destructuring, references and interpolation. |
| Loop counter reassigned | `rules.loop_counter_reassigned` | on | A `for` counter that is assigned inside the loop body. Use `while` instead. |

## Configuration

Create `code-thresholds.php` in the directory you run the tool from (or pass `--config=<file>`). It returns an array; every key is optional. Without a file the defaults below apply. Unknown keys and wrong types stop the run with exit code 2.

```php
<?php

return [
    'source_dirs' => ['src', 'lib'],
    'exclude' => ['src/Generated/*'],
    'limits' => ['parameters' => 5, 'function_length' => null], // null switches a rule off
    'rules' => ['loop_counter_reassigned' => false],
];
```

| Key | Type | Default | Meaning |
|---|---|---|---|
| `source_dirs` | `list<string>` | `['src']` | Directories scanned by `--all`. Missing directories are skipped; if none exists the run fails with exit code 2. |
| `exclude` | `list<string>` | `[]` | `fnmatch()` globs of files that are never checked. `*` also matches `/`. |
| `limits` | `array<string, int\|null>` | see Features | Keys `methods_per_class`, `parameters`, `returns`, `function_length`, `nesting_depth`. `null` disables a limit. |
| `rules` | `array<string, bool>` | all `true` | Keys `unused_variable`, `loop_counter_reassigned`. |
| `methods_per_class_exclude` | `list<string>` | `['*/Entity/*', 'tests/*']` | Globs of files exempt from the method count only. Entities track the width of a table, and a test class holds one method per scenario. |
| `exempt_constructors` | `bool` | `true` | Constructors are not checked against the parameter limit: injecting eight collaborators is not the same defect as an eight-argument call. |

The project root is the directory of the config file, or the working directory when there is none. Globs and `source_dirs` are relative to it. The match is anchored at the root on purpose: a checkout below a directory that happens to be called `tests` must not exempt all of `src/`.

Paths that do not exist, files that are not `*.php` and excluded files are ignored.

## Security

The tool reads the PHP files it is pointed at and never executes them. The config file is the exception: it is a PHP file that is included, so treat it like `composer.json` scripts and only run the tool in projects you trust. Git is started without a shell and the `--base` ref is validated against a conservative pattern, so a ref cannot inject options or commands. To report a vulnerability, see [SECURITY.md](SECURITY.md).

## Requirements

PHP 8.3 or newer with `ext-tokenizer`. `git` for staged and `--base` mode. Developed and tested on Linux and macOS.

## Contributing

Issues and pull requests are welcome, see [CONTRIBUTING.md](CONTRIBUTING.md). The checks you need locally:

```bash
composer test         # PHPUnit
composer phpstan      # PHPStan, max level
composer cs           # php-cs-fixer (dry run)
composer thresholds   # the package checks its own src/ with itself
```

## About

Built and maintained by [Stefan Jacomeit](https://www.jacomeit.de).

## License

MIT, see [LICENSE](LICENSE).
