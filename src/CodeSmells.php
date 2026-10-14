<?php

declare(strict_types=1);

namespace Stejac\CodeThresholds;

/**
 * Rules about a defect rather than a size: a variable assigned and never read,
 * a loop counter moved behind the loop header's back.
 *
 * @phpstan-import-type Tokens from CodeTokenCursor
 */
final class CodeSmells
{
    private CodeTokenCursor $cursor;

    public function __construct()
    {
        $this->cursor = new CodeTokenCursor();
    }

    /**
     * Variables assigned once and never read (the unused-variable rule).
     *
     * Deliberately conservative: only a variable appearing exactly once in the
     * body, immediately followed by "=", counts. Anything looser trips over
     * destructuring, references and string interpolation.
     *
     * @param Tokens $tokens
     *
     * @return list<array{name: string, line: int}>
     */
    public function unusedVariables(array $tokens, int $from, int $to): array
    {
        $seen = [];

        for ($i = $from; $i <= $to; ++$i) {
            $token = $tokens[$i];
            if (\is_string($token) || \T_VARIABLE !== $token[0] || '$this' === $token[1]) {
                continue;
            }

            $seen[$token[1]][] = [
                'line' => $token[2],
                'assigned' => '=' === $this->cursor->nextSymbol($tokens, $i),
            ];
        }

        return $this->onlyWriteOnce($seen);
    }

    /**
     * @param array<string, list<array{line: int, assigned: bool}>> $seen
     *
     * @return list<array{name: string, line: int}>
     */
    private function onlyWriteOnce(array $seen): array
    {
        $unused = [];

        foreach ($seen as $name => $uses) {
            if (1 === count($uses) && true === $uses[0]['assigned']) {
                $unused[] = ['name' => $name, 'line' => $uses[0]['line']];
            }
        }

        return $unused;
    }

    /**
     * `for` loops whose counter is reassigned inside the body. Rewriting
     * such a loop as `while` is the usual fix — the counter then moves by
     * intent rather than behind the loop header's back.
     *
     * @param Tokens $tokens
     *
     * @return list<array{name: string, line: int}>
     */
    public function reassignedLoopCounters(array $tokens, int $from, int $to): array
    {
        $found = [];

        for ($i = $from; $i <= $to; ++$i) {
            $token = $tokens[$i];
            if (\is_string($token) || \T_FOR !== $token[0]) {
                continue;
            }

            $counter = $this->offendingCounter($tokens, $i);
            if (null !== $counter) {
                $found[] = ['name' => $counter, 'line' => $token[2]];
            }
        }

        return $found;
    }

    /**
     * The counter of the `for` loop at $at, if the body reassigns it.
     *
     * @param Tokens $tokens
     */
    private function offendingCounter(array $tokens, int $at): ?string
    {
        $head = $this->cursor->matching($tokens, $this->cursor->skipTo($tokens, $at, '('), '(', ')');
        $body = $this->cursor->bodyStart($tokens, $head);
        $counter = $this->counterOf($tokens, $at, $head);

        if (null === $body || null === $counter) {
            return null;
        }

        $end = $this->cursor->matching($tokens, $body, '{', '}');

        return $this->reassigns($tokens, $body, $end, $counter) ? $counter : null;
    }

    /**
     * The variable a for-header increments, taken from its last clause.
     *
     * @param Tokens $tokens
     */
    private function counterOf(array $tokens, int $from, int $head): ?string
    {
        $last = null;

        for ($i = $from; $i < $head; ++$i) {
            $token = $tokens[$i];
            if (!\is_string($token) && \T_VARIABLE === $token[0]) {
                $last = $token[1];
            }
        }

        return $last;
    }

    /** @param Tokens $tokens */
    private function reassigns(array $tokens, int $from, int $to, string $counter): bool
    {
        $found = false;

        for ($i = $from; $i <= $to && !$found; ++$i) {
            $token = $tokens[$i];
            $found = !\is_string($token)
                && \T_VARIABLE === $token[0]
                && $counter === $token[1]
                && '=' === $this->cursor->nextSymbol($tokens, $i);
        }

        return $found;
    }
}
