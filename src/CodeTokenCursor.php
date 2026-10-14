<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

/**
 * Navigation over a PHP token stream: finding names, matching brackets and
 * stepping over nested closures.
 *
 * Split out of CodeMetrics when the methods-per-class limit kept pointing at
 * this seam — the limit was right, as it usually is.
 *
 * @phpstan-type Token array{0: int, 1: string, 2: int}|string
 * @phpstan-type Tokens list<Token>
 */
final class CodeTokenCursor
{
    /**
     * The identifier following a keyword, or null for anonymous constructs such
     * as closures and anonymous classes.
     *
     * @param Tokens $tokens
     */
    public function nextName(array $tokens, int $from): ?string
    {
        $count = count($tokens);
        $name = null;

        for ($i = $from + 1; $i < $count && null === $name; ++$i) {
            $token = $tokens[$i];

            if (\is_string($token)) {
                break;
            }

            if (\T_STRING === $token[0]) {
                $name = $token[1];
            } elseif (!\in_array($token[0], [\T_WHITESPACE, \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                break;
            }
        }

        return $name;
    }

    /** @param Tokens $tokens */
    public function skipTo(array $tokens, int $from, string $needle): int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; ++$i) {
            if ($needle === $tokens[$i]) {
                return $i;
            }
        }

        return $count - 1;
    }

    /** @param Tokens $tokens */
    public function matching(array $tokens, int $open, string $opener, string $closer): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; ++$i) {
            $depth += $this->weigh($tokens[$i], $opener, $closer);
            if (0 === $depth && $i > $open) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * Interpolation such as "{$spend}" opens a brace the tokenizer reports as
     * T_CURLY_OPEN while the closing one arrives as a plain "}". Counting only
     * plain braces therefore closes a function body too early, and every metric
     * measured on that body comes out wrong.
     *
     * @param Token $token
     */
    private function weigh(array|string $token, string $opener, string $closer): int
    {
        if (\is_string($token)) {
            return ($opener === $token ? 1 : 0) - ($closer === $token ? 1 : 0);
        }

        $interpolation = \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true);

        return '{' === $opener && $interpolation ? 1 : 0;
    }

    /**
     * Index of the opening brace after a signature, or null for abstract and
     * interface methods, which have none.
     *
     * @param Tokens $tokens
     */
    public function bodyStart(array $tokens, int $afterParams): ?int
    {
        $count = count($tokens);
        $found = null;

        for ($i = $afterParams; $i < $count && null === $found; ++$i) {
            if ('{' === $tokens[$i]) {
                $found = $i;
            } elseif (';' === $tokens[$i]) {
                break;
            }
        }

        return $found;
    }

    /**
     * Jumps past a nested closure or arrow function.
     *
     * @param Tokens $tokens
     */
    public function skipNested(array $tokens, int $at, int $limit): int
    {
        $brace = $this->bodyStart($tokens, $at);

        return null === $brace || $brace > $limit ? $at : $this->matching($tokens, $brace, '{', '}');
    }

    /**
     * The next meaningful character after a token, skipping whitespace. Yields
     * "=" only for a plain assignment, never for "==", "=>" or "+=", because
     * those arrive as their own token types.
     *
     * @param Tokens $tokens
     */
    public function nextSymbol(array $tokens, int $from): ?string
    {
        $count = count($tokens);
        $symbol = null;

        for ($i = $from + 1; $i < $count && null === $symbol; ++$i) {
            $token = $tokens[$i];

            if (\is_string($token)) {
                $symbol = $token;
            } elseif (\T_WHITESPACE !== $token[0]) {
                break;
            }
        }

        return $symbol;
    }
}
