<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use InvalidArgumentException;

/**
 * Evaluates the small arithmetic expressions used by `value_calculation`
 * field-mapping configs, without `eval()`.
 *
 * The grammar is closed and hand-parsed: decimal numbers (including exponent
 * notation), the four binary operators, unary sign, parentheses, and a single
 * whitelisted function, `round()`. Anything else is a parse failure, which the
 * caller treats as "leave the value alone".
 *
 * This replaces a regex sanitizer that stripped every character outside
 * `[0-9+\-*\/()\s.]` before handing the remainder to `eval()`. That sanitizer
 * was the actual defect, not the `eval()`:
 *
 * - It stripped `round(`, silently turning the codebase's only real calculation
 *   — Formidable `max_limit`'s `round([value] * 4.79)` — into `([value] * 4.79)`
 *   and writing a float into an integer character limit.
 * - It stripped the `e` from a value like `1e5`, which `is_numeric()` accepts,
 *   turning `100000` into `15`.
 * - Its `catch (Exception)` could not catch `ParseError` or
 *   `DivisionByZeroError`, both of which extend `Error`, so `'[value] mod 3'`
 *   or `'[value] / 0'` aborted the import outright.
 */
class SafeMathEvaluator
{
    /**
     * Longest expression accepted.
     *
     * `value_calculation` expressions are short hard-coded configs, so this only
     * has to be generous enough for those while bounding parser work — and,
     * because every nesting level costs at least one character, it also bounds
     * recursion depth.
     */
    // A constant initializer is never reported as an executed line, so Pest runs no
    // test against a mutant here. Changing the value by hand fails "accepts an
    // expression of exactly the maximum length" or "refuses an expression one
    // character too long" in SafeMathEvaluatorTest.
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    public const MAX_EXPRESSION_LENGTH = 255;

    /**
     * The functions the grammar permits, mapped to their allowed argument counts.
     *
     * @var array<string, list<int>>
     */
    // A constant initializer is never reported as an executed line. Changing either
    // count or removing an entry by hand fails a round() row of SafeMathEvaluatorTest,
    // except lowering 1 to 0: a call always parses at least one argument, so a count
    // of zero is never reached.
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger, RemoveArrayItem
    private const array ALLOWED_FUNCTIONS = ['round' => [1, 2]];

    /**
     * The token stream for the expression currently being parsed.
     *
     * @var list<array{type: string, value: string}>
     */
    private array $tokens = [];

    /**
     * Index of the next token to consume.
     */
    // evaluate() sets the position before every parse, so the initial value is
    // never read
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    private int $position = 0;

    /**
     * Evaluate an arithmetic expression.
     *
     * @param  string  $expression  The expression to evaluate
     * @return ?float The result, or null if the expression is not valid arithmetic
     */
    public static function evaluate(
        string $expression
    ): ?float {
        // Reject anything longer than the grammar is meant to serve; an empty
        // expression fails to parse below
        if (\strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            return null;
        }

        $evaluator = new self;

        try {
            // Tokenize, then parse and evaluate in one recursive-descent pass
            $evaluator->tokens = $evaluator->tokenize($expression);
            $evaluator->position = 0;

            $result = $evaluator->parseExpression();

            // Trailing tokens mean the expression did not parse as a whole
            if ($evaluator->position !== \count($evaluator->tokens)) {
                return null;
            }
        }

        // Any grammar violation, division by zero, or overflow means the
        // expression cannot be evaluated; the caller keeps the original value
        catch (InvalidArgumentException) {
            return null;
        }

        // Guard against INF/NAN reaching a blueprint field
        return is_finite($result) ? $result : null;
    }

    /**
     * Split an expression into tokens.
     *
     * @param  string  $expression  The expression to tokenize
     * @return list<array{type: string, value: string}>
     *
     * @throws InvalidArgumentException When the expression contains a character the grammar does not allow
     */
    private function tokenize(
        string $expression
    ): array {
        $tokens = [];
        $offset = 0;
        $length = \strlen($expression);

        // Reading one past the end yields an empty string, which the whitespace
        // branch skips, so `<=` would only add a warning
        // @pest-mutate-ignore: SmallerToSmallerOrEqual
        while ($offset < $length) {
            $character = $expression[$offset];

            // Skip insignificant whitespace between tokens
            if (\trim($character) === '') {
                $offset++;

                continue;
            }

            // Read a number, accepting a leading decimal point and an exponent
            if (
                ctype_digit($character)
                || $character === '.'
            ) {
                $matched = \preg_match(
                    '/\G(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/',
                    $expression,
                    $matches,
                    0,
                    $offset
                );

                if ($matched !== 1) {
                    throw new InvalidArgumentException('Malformed number');
                }

                $tokens[] = [
                    'type' => 'number',
                    'value' => $matches[0],
                ];
                $offset += \strlen($matches[0]);

                continue;
            }

            // Read a function name
            if (ctype_alpha($character)) {
                $matched = \preg_match(
                    '/\G[A-Za-z_][A-Za-z0-9_]*/',
                    $expression,
                    $matches,
                    0,
                    $offset
                );

                if ($matched !== 1) {
                    throw new InvalidArgumentException('Malformed identifier');
                }

                // Reject any identifier that is not on the whitelist, rather
                // than deferring to the parser
                $name = \strtolower($matches[0]);

                if (! \array_key_exists($name, self::ALLOWED_FUNCTIONS)) {
                    // evaluate() catches every InvalidArgumentException and returns null, so no
                    // message leaves the class and none of its wording can be observed
                    // @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
                    throw new InvalidArgumentException('Unknown function: '.$name);
                }

                $tokens[] = [
                    'type' => 'name',
                    'value' => $name,
                ];
                $offset += \strlen($matches[0]);

                continue;
            }

            // Read a single-character operator, parenthesis, or separator
            $type = match ($character) {
                '+', '-', '*', '/' => 'operator',
                '(' => 'lparen',
                ')' => 'rparen',
                ',' => 'comma',
                default => throw new InvalidArgumentException(
                    // The message never leaves the class (see the identifier check)
                    // @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
                    'Unexpected character: '.$character
                ),
            };

            $tokens[] = [
                'type' => $type,
                'value' => $character,
            ];
            $offset++;
        }

        return $tokens;
    }

    /**
     * Parse and evaluate an additive expression.
     *
     * @return float The evaluated result
     *
     * @throws InvalidArgumentException When the token stream violates the grammar
     */
    private function parseExpression(): float
    {
        $value = $this->parseTerm();

        // Fold left-associatively over any run of + and - operators
        while ($this->peekIsOperator(['+', '-'])) {
            $operator = $this->consume()['value'];
            $right = $this->parseTerm();

            $value = $operator === '+'
                ? $value + $right
                : $value - $right;
        }

        return $value;
    }

    /**
     * Parse and evaluate a multiplicative term.
     *
     * @return float The evaluated result
     *
     * @throws InvalidArgumentException When the token stream violates the grammar
     */
    private function parseTerm(): float
    {
        $value = $this->parseUnary();

        // Fold left-associatively over any run of * and / operators
        while ($this->peekIsOperator(['*', '/'])) {
            $operator = $this->consume()['value'];
            $right = $this->parseUnary();

            if ($operator === '*') {
                $value *= $right;

                continue;
            }

            // Division by zero is a failed evaluation, not a fatal error
            if ($right === 0.0) {
                throw new InvalidArgumentException('Division by zero');
            }

            $value /= $right;
        }

        return $value;
    }

    /**
     * Parse and evaluate an optionally sign-prefixed value.
     *
     * @return float The evaluated result
     *
     * @throws InvalidArgumentException When the token stream violates the grammar
     */
    private function parseUnary(): float
    {
        if ($this->peekIsOperator(['+', '-'])) {
            $operator = $this->consume()['value'];
            $value = $this->parseUnary();

            return $operator === '-' ? -$value : $value;
        }

        return $this->parsePrimary();
    }

    /**
     * Parse and evaluate a number, a parenthesized expression, or a call to a
     * whitelisted function.
     *
     * @return float The evaluated result
     *
     * @throws InvalidArgumentException When the token stream violates the grammar
     */
    private function parsePrimary(): float
    {
        $token = $this->consume();

        // A literal number
        if ($token['type'] === 'number') {
            return (float) $token['value'];
        }

        // A parenthesized sub-expression
        if ($token['type'] === 'lparen') {
            $value = $this->parseExpression();
            $this->expect('rparen');

            return $value;
        }

        // A call to a whitelisted function
        if ($token['type'] === 'name') {
            return $this->parseFunctionCall($token['value']);
        }

        // evaluate() catches every InvalidArgumentException and returns null, so no
        // message leaves the class and none of its wording can be observed
        // @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
        throw new InvalidArgumentException('Unexpected token: '.$token['value']);
    }

    /**
     * Parse and evaluate a call to a whitelisted function.
     *
     * @param  string  $name  The function name, already known to be whitelisted
     * @return float The evaluated result
     *
     * @throws InvalidArgumentException When the call violates the grammar or the arity
     */
    private function parseFunctionCall(
        string $name
    ): float {
        $this->expect('lparen');

        // Collect the comma-separated argument list
        $arguments = [$this->parseExpression()];

        while ($this->peek() !== null && $this->peek()['type'] === 'comma') {
            $this->consume();
            $arguments[] = $this->parseExpression();
        }

        $this->expect('rparen');

        // Enforce the arity the whitelist declares for this function; both sides are
        // integers, so strict and loose comparison agree
        // @pest-mutate-ignore: TrueToFalse
        if (! \in_array(\count($arguments), self::ALLOWED_FUNCTIONS[$name], true)) {
            // evaluate() catches every InvalidArgumentException and returns null, so no
            // message leaves the class and none of its wording can be observed
            // @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
            throw new InvalidArgumentException(
                'Wrong argument count for '.$name.'()'
            );
        }

        return match ($name) {
            'round' => round(
                $arguments[0],
                (int) ($arguments[1] ?? 0)
            ),
            // The tokenizer admits only whitelisted names, so this arm is never reached
            // @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
            default => throw new InvalidArgumentException('Unknown function: '.$name),
        };
    }

    /**
     * The next token without consuming it, or null at the end of the stream.
     *
     * @return ?array{type: string, value: string}
     */
    private function peek(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    /**
     * Whether the next token is one of the given operators.
     *
     * @param  list<string>  $operators  The operator characters to test for
     */
    private function peekIsOperator(
        array $operators
    ): bool {
        $token = $this->peek();

        // Only an operator token carries a value from $operators, and the values are
        // strings on both sides, so neither the type check's `&&` nor strictness
        // changes the answer
        // @pest-mutate-ignore: BooleanAndToBooleanOr, TrueToFalse
        return $token !== null
            && $token['type'] === 'operator'
            && \in_array($token['value'], $operators, true);
    }

    /**
     * Consume and return the next token.
     *
     * @return array{type: string, value: string}
     *
     * @throws InvalidArgumentException When the stream is already exhausted
     */
    private function consume(): array
    {
        $token = $this->peek();

        if ($token === null) {
            throw new InvalidArgumentException('Unexpected end of expression');
        }

        $this->position++;

        return $token;
    }

    /**
     * Consume the next token, requiring it to be of the given type.
     *
     * @param  string  $type  The token type required
     *
     * @throws InvalidArgumentException When the next token is of another type
     */
    private function expect(
        string $type
    ): void {
        $token = $this->consume();

        if ($token['type'] !== $type) {
            // evaluate() catches every InvalidArgumentException and returns null, so no
            // message leaves the class and none of its wording can be observed
            // @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
            throw new InvalidArgumentException(
                'Expected '.$type.', got '.$token['value']
            );
        }
    }
}
