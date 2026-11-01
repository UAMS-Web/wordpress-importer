<?php

declare(strict_types=1);

/**
 * Unit tests for the arithmetic evaluator that replaced the `eval()` in
 * `FieldtypeMappingTrait::applyValueCalculation()` (#2).
 *
 * Beyond the grammar itself, these pin the three defects the old regex
 * sanitizer caused: `round()` being stripped, exponent notation being mangled,
 * and `Error` subclasses escaping a `catch (Exception)`.
 *
 * @command  php vendor/bin/pest tests/Unit/SafeMathEvaluatorTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SafeMathEvaluator;

it('evaluates the four operators with correct precedence', function (string $expression, float $expected): void {
    expect(SafeMathEvaluator::evaluate($expression))->toBe($expected);
})->with([
    'addition' => ['1 + 2', 3.0],
    'subtraction' => ['10 - 4', 6.0],
    'multiplication' => ['6 * 7', 42.0],
    'division' => ['9 / 2', 4.5],
    'multiplication binds tighter than addition' => ['2 + 3 * 4', 14.0],
    'division binds tighter than subtraction' => ['10 - 6 / 2', 7.0],
    'parentheses override precedence' => ['(2 + 3) * 4', 20.0],
    'nested parentheses' => ['((1 + 2) * (3 + 4)) / 3', 7.0],
    'left-associative subtraction' => ['10 - 3 - 2', 5.0],
    'left-associative division' => ['100 / 5 / 2', 10.0],
    'decimals' => ['1.5 + 2.25', 3.75],
    'leading decimal point' => ['.5 * 4', 2.0],
    'whitespace is insignificant' => ['  2   *   ( 3 + 1 )  ', 8.0],
]);

it('applies unary sign, including after a binary operator', function (string $expression, float $expected): void {
    expect(SafeMathEvaluator::evaluate($expression))->toBe($expected);
})->with([
    'leading minus' => ['-5', -5.0],
    'leading plus' => ['+5', 5.0],
    'minus after an operator' => ['10 - -5', 15.0],
    'minus before a parenthesis' => ['-(2 + 3)', -5.0],
    'double negation' => ['--7', 7.0],
    'negative multiplication' => ['-3 * -4', 12.0],
]);

it('evaluates the whitelisted round() function', function (string $expression, float $expected): void {
    expect(SafeMathEvaluator::evaluate($expression))->toBe($expected);
})->with([
    'rounds up at .5' => ['round(1197.5)', 1198.0],
    'rounds down below .5' => ['round(14.37)', 14.0],
    'rounds a sub-expression' => ['round(250 * 4.79)', 1198.0],
    'accepts a precision argument' => ['round(3.14159, 2)', 3.14],
    'nests inside arithmetic' => ['round(2.6) + 1', 4.0],
    'nests within itself' => ['round(round(2.6) * 1.5)', 5.0],
]);

it('evaluates the Formidable max_limit calculation the sanitizer used to corrupt', function (): void {
    // The regex sanitizer stripped `round(`, leaving `(250 * 4.79)` = 1197.5
    expect(SafeMathEvaluator::evaluate('round(250 * 4.79)'))->toBe(1198.0);

    // ...and turned a value of 3 into 14.370000000000001
    expect(SafeMathEvaluator::evaluate('round(3 * 4.79)'))->toBe(14.0);
});

it('reads exponent notation rather than dropping the exponent', function (): void {
    // The sanitizer stripped the `e`, turning 1e5 into the literal 15
    expect(SafeMathEvaluator::evaluate('1e5'))->toBe(100000.0);
    expect(SafeMathEvaluator::evaluate('1.5e3 + 1'))->toBe(1501.0);
    expect(SafeMathEvaluator::evaluate('2e-2'))->toBe(0.02);
});

it('returns null for division by zero instead of raising an Error', function (string $expression): void {
    // DivisionByZeroError extends Error, so the old catch (Exception) missed it
    // and the import aborted
    expect(SafeMathEvaluator::evaluate($expression))->toBeNull();
})->with([
    'integer zero' => ['10 / 0'],
    'decimal zero' => ['10 / 0.0'],
    'zero from a sub-expression' => ['10 / (5 - 5)'],
    'zero divided by zero' => ['0 / 0'],
]);

it('returns null for expressions outside the grammar', function (string $expression): void {
    expect(SafeMathEvaluator::evaluate($expression))->toBeNull();
})->with([
    // `'[value] mod 3'` stripped to `5  3` and raised an uncaught ParseError
    'a word operator' => ['5 mod 3'],
    'adjacent numbers' => ['5  3'],
    'a non-whitelisted function' => ['sqrt(16)'],
    'a dangerous function' => ['phpinfo()'],
    'a shell call' => ['system("id")'],
    'a variable' => ['$x + 1'],
    'a constant' => ['PHP_INT_MAX'],
    'a modulo operator' => ['10 % 3'],
    'an exponent operator' => ['2 ** 8'],
    'string concatenation' => ['"a" . "b"'],
    'a semicolon' => ['1 + 1;'],
    'a statement separator' => ['1; phpinfo()'],
    'a trailing operator' => ['5 +'],
    'a leading binary operator' => ['* 5'],
    'unbalanced open parenthesis' => ['(1 + 2'],
    'unbalanced close parenthesis' => ['1 + 2)'],
    'empty parentheses' => ['()'],
    'an empty string' => [''],
    'whitespace only' => ['   '],
    'a bare comma' => ['1, 2'],
    'round with no arguments' => ['round()'],
    'round with too many arguments' => ['round(1, 2, 3)'],
    'a function name without a call' => ['round'],
]);

it('reproduces native IEEE 754 float behavior', function (): void {
    // Not a rounding helper: the evaluator computes in float exactly as the
    // `eval()` it replaced did, so 0.1 + 0.2 - 0.3 is still not quite zero
    expect(SafeMathEvaluator::evaluate('0.1 + 0.2 - 0.3'))
        ->toEqualWithDelta(0.0, 1.0e-9)
        ->not->toBe(0.0);
});

it('refuses an expression longer than the cap', function (): void {
    $tooLong = str_repeat('1+', SafeMathEvaluator::MAX_EXPRESSION_LENGTH).'1';

    expect(SafeMathEvaluator::evaluate($tooLong))->toBeNull();
});

it('returns null rather than INF or NAN', function (): void {
    // 1e308 * 10 overflows to INF; a blueprint field must not receive it
    expect(SafeMathEvaluator::evaluate('1e308 * 10'))->toBeNull();
});

it('reads no byte past the end of the expression', function (): void {
    // A read one past the end raises a warning, which the host app turns into an
    // exception that evaluate() does not catch, aborting the import
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $result = SafeMathEvaluator::evaluate('1+1');
    } finally {
        restore_error_handler();
    }

    expect($result)->toBe(2.0);
});

it('accepts an expression of exactly the maximum length', function (): void {
    // 1 followed by 127 copies of "+1" is 255 characters
    $expression = '1'.str_repeat('+1', 127);

    expect(strlen($expression))->toBe(SafeMathEvaluator::MAX_EXPRESSION_LENGTH)
        ->and(SafeMathEvaluator::evaluate($expression))->toBe(128.0);
});

it('refuses an expression one character too long', function (): void {
    expect(SafeMathEvaluator::evaluate(' 1'.str_repeat('+1', 127)))->toBeNull();
});

it('reads a function name case-insensitively', function (): void {
    expect(SafeMathEvaluator::evaluate('ROUND(1.5)'))->toBe(2.0)
        ->and(SafeMathEvaluator::evaluate('Round(2.5, 0)'))->toBe(3.0);
});

it('folds a run of multiplications and divisions', function (): void {
    expect(SafeMathEvaluator::evaluate('2 * 3 * 4'))->toBe(24.0)
        ->and(SafeMathEvaluator::evaluate('2 * 3 / 4'))->toBe(1.5);
});
