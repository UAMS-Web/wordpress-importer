<?php

declare(strict_types=1);

/**
 * Unit tests for `FieldtypeMappingTrait::applyValueCalculation()` (#2).
 *
 * Exercises the method on the real Formidable mapping class that hosts the
 * trait — rather than a stand-in harness — covering the `[value]`
 * substitution, the pass-through cases that must leave the value untouched,
 * and the `max_limit` calculation the old regex sanitizer silently corrupted.
 *
 * @command  php vendor/bin/pest tests/Unit/ApplyValueCalculationTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping;

/**
 * Invoke the trait's protected static calculation helper on the concrete class.
 *
 * @param  mixed  $value  The value to transform
 * @param  string  $expression  The expression with the `[value]` placeholder
 * @return mixed The calculated result, or the original value
 */
function applyValueCalculationForTest(
    mixed $value,
    string $expression
): mixed {
    $method = new ReflectionMethod(
        FieldtypeMapping::class,
        'applyValueCalculation'
    );

    return $method->invoke(
        null,
        $value,
        $expression
    );
}

it('substitutes [value] and evaluates the expression', function (): void {
    expect(applyValueCalculationForTest(10, '[value] * 2'))->toBe(20);
    expect(applyValueCalculationForTest(7, '[value] + 3'))->toBe(10);
    expect(applyValueCalculationForTest(10, '100 - [value]'))->toBe(90);
});

it('computes the Formidable word-to-character limit correctly', function (
    int $words,
    int $expected
): void {
    // The real config: 'word' => 'round([value] * 4.79)'. The old sanitizer
    // stripped `round(`, yielding 1197.5 for 250 words and a float for 3.
    expect(applyValueCalculationForTest($words, 'round([value] * 4.79)'))->toBe($expected);
})->with([
    '250 words' => [250, 1198],
    '3 words' => [3, 14],
    '1 word' => [1, 5],
    '100 words' => [100, 479],
]);

it('returns a whole result as an int, not a float', function (): void {
    expect(applyValueCalculationForTest(250, 'round([value] * 4.79)'))
        ->toBeInt()
        ->toBe(1198);
});

it('keeps a fractional result as a float', function (): void {
    expect(applyValueCalculationForTest(5, '[value] / 2'))
        ->toBeFloat()
        ->toBe(2.5);
});

it('handles a negative value substituted after an operator', function (): void {
    expect(applyValueCalculationForTest(-5, '10 - [value]'))->toBe(15);
    expect(applyValueCalculationForTest(-5, '[value] * 3'))->toBe(-15);
});

it('reads a value in exponent notation', function (): void {
    // is_numeric('1e5') is true; the old sanitizer stripped the `e`, so this
    // evaluated as 15 instead of 100000
    expect(applyValueCalculationForTest('1e5', '[value] + 0'))->toBe(100000);
});

it('leaves a non-numeric value untouched', function (mixed $value): void {
    expect(applyValueCalculationForTest($value, '[value] * 2'))->toBe($value);
})->with([
    'a word' => ['abc'],
    'an empty string' => [''],
    'null' => [null],
    'a boolean' => [true],
    'an array' => [[1, 2]],
]);

it('leaves the value untouched when the expression is not valid arithmetic', function (
    string $expression
): void {
    // Every one of these previously either silently mis-evaluated or raised an
    // Error that aborted the import
    expect(applyValueCalculationForTest(5, $expression))->toBe(5);
})->with([
    'a word operator' => ['[value] mod 3'],
    'division by zero' => ['[value] / 0'],
    'a non-whitelisted function' => ['sqrt([value])'],
    'a shell call' => ['system("id")'],
    'a modulo operator' => ['[value] % 3'],
    'an empty expression' => [''],
    'no placeholder and no arithmetic' => ['not an expression'],
]);
