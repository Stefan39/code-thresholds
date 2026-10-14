<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

/**
 * Size measurements of a PHP file.
 *
 * Everything here answers "how big is this?" — deciding whether a number is too
 * big belongs to the checker.
 *
 * @phpstan-import-type Token from CodeTokenCursor
 * @phpstan-import-type Tokens from CodeTokenCursor
 *
 * @phpstan-type FunctionMetrics array{name: string, line: int, params: int, returns: int, lines: int, nesting: int, body: array{0: int, 1: int}}
 */
final class CodeMetrics
{
    /** @var list<int> */
    private const array TYPE_TOKENS = [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM];

    /** @var list<int> */
    private const array BLOCK_TOKENS = [\T_IF, \T_FOR, \T_FOREACH, \T_WHILE, \T_SWITCH, \T_TRY, \T_MATCH];

    /** @var list<string> */
    private const array OPENING_BRACKETS = ['(', '['];

    /** @var list<string> */
    private const array CLOSING_BRACKETS = [')', ']'];

    private CodeTokenCursor $cursor;

    public function __construct()
    {
        $this->cursor = new CodeTokenCursor();
    }

    /**
     * Method count per class. Closures declared inside methods are not methods
     * and must not inflate the count.
     *
     * @param Tokens $tokens
     *
     * @return list<array{name: string, line: int, methods: int}>
     */
    public function classes(array $tokens): array
    {
        $names = [];
        $lines = [];
        $counts = [];
        $open = -1;

        foreach ($tokens as $i => $token) {
            if (\is_string($token)) {
                continue;
            }

            if (\in_array($token[0], self::TYPE_TOKENS, true)) {
                $name = $this->cursor->nextName($tokens, $i);
                if (null !== $name) {
                    $names[] = $name;
                    $lines[] = $token[2];
                    $counts[] = 0;
                    $open = count($names) - 1;
                }
            } elseif ($this->isMethod($tokens, $i, $open)) {
                $counts[$open] = $counts[$open] + 1;
            }
        }

        $classes = [];
        foreach ($names as $i => $name) {
            $classes[] = ['name' => $name, 'line' => $lines[$i], 'methods' => $counts[$i]];
        }

        return $classes;
    }

    /** @param Tokens $tokens */
    private function isMethod(array $tokens, int $at, int $openClass): bool
    {
        $token = $tokens[$at];

        return !\is_string($token)
            && \T_FUNCTION === $token[0]
            && $openClass >= 0
            && null !== $this->cursor->nextName($tokens, $at);
    }

    /**
     * Metrics per named function.
     *
     * @param Tokens $tokens
     *
     * @return list<FunctionMetrics>
     */
    public function functions(array $tokens): array
    {
        $functions = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            $found = $this->functionAt($tokens, $i);
            $i = null === $found ? $i + 1 : $found['end'] + 1;

            if (null !== $found) {
                $functions[] = $found['metrics'];
            }
        }

        return $functions;
    }

    /**
     * @param Tokens $tokens
     *
     * @return array{metrics: FunctionMetrics, end: int}|null
     */
    private function functionAt(array $tokens, int $at): ?array
    {
        $token = $tokens[$at];
        if (\is_string($token) || \T_FUNCTION !== $token[0]) {
            return null;
        }

        $name = $this->cursor->nextName($tokens, $at);

        return null === $name ? null : $this->describe($tokens, $at, $name, $token[2]);
    }

    /**
     * @param Tokens $tokens
     *
     * @return array{metrics: FunctionMetrics, end: int}|null
     */
    private function describe(array $tokens, int $at, string $name, int $line): ?array
    {
        $open = $this->cursor->skipTo($tokens, $at, '(');
        $close = $this->cursor->matching($tokens, $open, '(', ')');
        $body = $this->cursor->bodyStart($tokens, $close);

        if (null === $body) {
            return null;
        }

        $end = $this->cursor->matching($tokens, $body, '{', '}');
        $head = [
            'name' => $name,
            'line' => $line,
            'params' => $this->countParams($tokens, $open, $close),
            'body' => [$body, $end],
        ];

        return ['metrics' => $head + $this->measure($tokens, $body, $end), 'end' => $end];
    }

    /**
     * Measured in three single-purpose passes rather than one loop with four
     * counters: the combined version reached a cognitive complexity of 24.
     * Walking the tokens three times costs nothing on the handful of files a
     * commit touches.
     *
     * @param Tokens $tokens
     *
     * @return array{returns: int, lines: int, nesting: int}
     */
    private function measure(array $tokens, int $from, int $to): array
    {
        return [
            'returns' => $this->countReturns($tokens, $from, $to),
            'lines' => $this->lineSpan($tokens, $from, $to),
            'nesting' => $this->maxNesting($tokens, $from, $to),
        ];
    }

    /**
     * Returns inside a nested closure belong to that closure, not to the method
     * around it — otherwise every callback passed to a cache or an array
     * function would read as one return too many.
     *
     * @param Tokens $tokens
     */
    private function countReturns(array $tokens, int $from, int $to): int
    {
        $returns = 0;
        $i = $from;

        while ($i <= $to) {
            $token = $tokens[$i];

            if (!\is_string($token) && \in_array($token[0], [\T_FUNCTION, \T_FN], true)) {
                $i = $this->cursor->skipNested($tokens, $i, $to) + 1;
                continue;
            }

            $returns += (!\is_string($token) && \T_RETURN === $token[0]) ? 1 : 0;
            ++$i;
        }

        return $returns;
    }

    /** @param Tokens $tokens */
    private function lineSpan(array $tokens, int $from, int $to): int
    {
        $first = 0;
        $last = 0;

        for ($i = $from; $i <= $to; ++$i) {
            $token = $tokens[$i];
            if (\is_string($token)) {
                continue;
            }

            $first = 0 === $first ? $token[2] : $first;
            $last = $token[2];
        }

        return $last - $first + 1;
    }

    /**
     * A control keyword marks the NEXT brace as a nesting level. Without that
     * pairing, ten sequential if-blocks read as depth ten instead of one.
     *
     * @param Tokens $tokens
     */
    private function maxNesting(array $tokens, int $from, int $to): int
    {
        $nesting = 0;
        $max = 0;
        $braces = [];
        $pending = false;

        for ($i = $from; $i <= $to; ++$i) {
            $token = $tokens[$i];

            if (!\is_string($token)) {
                $pending = $pending || \in_array($token[0], self::BLOCK_TOKENS, true);
                continue;
            }

            if ('{' === $token) {
                $braces[] = $pending;
                $nesting += $pending ? 1 : 0;
                $max = max($max, $nesting);
                $pending = false;
            } elseif ('}' === $token) {
                $nesting -= (true === array_pop($braces)) ? 1 : 0;
            } elseif (';' === $token) {
                $pending = false;
            }
        }

        return $max;
    }

    /**
     * Top-level commas separate parameters, but a trailing comma — a common
     * style for multi-line signatures — is not one of them:
     * `($a, $b,)` has two parameters, not three. A comma only counts once
     * something other than whitespace/comments follows it before the closing
     * paren.
     *
     * @param Tokens $tokens
     */
    private function countParams(array $tokens, int $open, int $close): int
    {
        $params = 0;
        $depth = 0;
        $seen = false;
        $lastComma = null;

        for ($i = $open + 1; $i < $close; ++$i) {
            $token = $tokens[$i];
            $depth += $this->depthDelta($token);

            if (',' === $token && 0 === $depth) {
                ++$params;
                $lastComma = $i;
            }

            $seen = $seen || $this->isSignificant($token);
        }

        if (null !== $lastComma && $this->isTrailingComma($tokens, $lastComma, $close)) {
            --$params;
        }

        return $seen ? $params + 1 : 0;
    }

    /**
     * How one token moves the bracket depth, so countParams() only counts commas
     * that actually separate parameters.
     *
     * `#[` opens a parameter attribute as a single T_ATTRIBUTE token rather than a
     * '[' string, while its closing `]` IS a plain string. Counting the attribute
     * as an opening bracket keeps the pair balanced; without it that `]` drives the
     * depth permanently negative and no top-level comma after it is ever recognised
     * again — silently hiding real parameter-count violations.
     *
     * @param Token $token
     */
    private function depthDelta(array|string $token): int
    {
        if (!\is_string($token)) {
            return \T_ATTRIBUTE === $token[0] ? 1 : 0;
        }

        return match (true) {
            \in_array($token, self::OPENING_BRACKETS, true) => 1,
            \in_array($token, self::CLOSING_BRACKETS, true) => -1,
            default => 0,
        };
    }

    /**
     * Whether a token makes the parameter list non-empty. Whitespace alone does
     * not: `(   )` has no parameters, `( $a )` has one.
     *
     * @param Token $token
     */
    private function isSignificant(array|string $token): bool
    {
        return \is_string($token) || \T_WHITESPACE !== $token[0];
    }

    /**
     * True if nothing but whitespace/comments sits between a comma and the
     * closing paren — the trailing-comma case that countParams() must not
     * count as a parameter separator.
     *
     * @param Tokens $tokens
     */
    private function isTrailingComma(array $tokens, int $comma, int $close): bool
    {
        for ($i = $comma + 1; $i < $close; ++$i) {
            $token = $tokens[$i];
            if (\is_string($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return false;
            }
        }

        return true;
    }
}
