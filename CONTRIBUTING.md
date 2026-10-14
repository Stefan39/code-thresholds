# Contributing

Thanks for taking the time! Issues and pull requests in English or German are welcome.

## Before you open an issue

- Search existing issues first.
- For bugs: version, PHP version, minimal reproduction (a small PHP file and the command you ran), expected vs. actual behaviour.
- Security problems: **do not** open an issue — see [SECURITY.md](SECURITY.md).

## Pull requests

1. Fork, then branch from `main`.
2. Add a test that fails without your change.
3. Run the checks locally:
   ```bash
   composer test         # PHPUnit
   composer phpstan      # PHPStan max level
   composer cs           # php-cs-fixer (dry run)
   composer thresholds   # the package checks its own src/ with itself
   ```
   The tests create temporary git repositories, so `git` must be on your `PATH`.
4. Keep PRs focused — one change per PR. Conventional Commits are appreciated (`fix: …`, `feat: …`).

## Code style

- `declare(strict_types=1);` in every PHP file.
- PSR-12 + Symfony rules via php-cs-fixer (`composer cs-fix` applies them).
- No generic `\Exception` / `\RuntimeException` — use a class from `Stejac\CodeThresholds\Exception`.
- No runtime dependencies besides `php` and `ext-tokenizer`.
- New measurements belong on the token stream (`token_get_all()`), never on regular expressions over source text.
- Fixtures that deliberately break the rules are written to a temporary directory inside the test, not checked in.

## Response times

Issues are triaged within a few working days. Maintainer: [@Stefan39](https://github.com/Stefan39).
