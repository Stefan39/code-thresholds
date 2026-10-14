# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-14

### Added
- `bin/code-thresholds` command with four ways to select files: staged files (default), `--base=<ref>` for files changed against a ref, `--all` for everything below the configured source directories, and explicit file or directory paths.
- Size limits measured on the token stream: methods per class, parameters, returns, function length and nesting depth.
- Two defect checks: variables that are assigned and never read, and `for` loop counters reassigned inside the loop body.
- Optional `code-thresholds.php` config file (or `--config=<file>`) with source directories, exclude globs, limits (`null` switches a rule off), rules, a methods-per-class exemption list and the constructor exemption. Unknown keys and wrong types are rejected.
- Exit codes: 0 no violations, 1 violations, 2 usage or configuration error.
- Examples for a GitHub Actions workflow, a git pre-commit hook and a fully commented config file under `docs/examples/`.
- README in English and German.

[Unreleased]: https://github.com/Stefan39/code-thresholds/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Stefan39/code-thresholds/releases/tag/v1.0.0
