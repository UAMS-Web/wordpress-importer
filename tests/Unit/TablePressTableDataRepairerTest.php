<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TablePressTableDataRepairer;

/**
 * Tests recovery of TablePress cell JSON whose HTML attributes carry unescaped quotes.
 *
 * Covers the three behaviors required by issue wordpress-importer-archive#316: the table-7 unescaped-`style`-quote
 * payload decoding to the full row set with markup intact, an already-valid payload passing
 * through untouched (byte-identical, no repair), and an irreparable payload reporting the
 * parse error and a failure offset before skipping.
 *
 * @command  php vendor/bin/pest tests/Unit/TablePressTableDataRepairerTest.php
 */

/**
 * Return decoded table rows after asserting the repair result includes array data.
 *
 * @param  array{data: mixed, repaired: bool, error: mixed, offset: mixed}  $result
 * @return array<mixed>
 */
function requireTableData(array $result): array
{
    $data = $result['data'];
    expect($data)->toBeArray();

    // toBeArray() does not narrow for PHPStan; narrow before returning as array.
    return is_array($data) ? $data : [];
}

/**
 * Return a string table cell at the given coordinates.
 *
 * @param  array<mixed>  $data
 */
function requireTableCell(
    array $data,
    int $row,
    int $column
): string {
    expect($data)->toHaveKey($row);

    $rowData = $data[$row];
    expect($rowData)->toBeArray();

    // toBeArray() does not narrow for PHPStan; narrow before the column lookup.
    $rowData = is_array($rowData) ? $rowData : [];
    expect($rowData)->toHaveKey($column);

    $cell = $rowData[$column];
    expect($cell)->toBeString();

    // toBeString() does not narrow for PHPStan; narrow before returning.
    return is_string($cell) ? $cell : '';
}

it('repairs unescaped style quotes to the full row set', function (): void {
    // The table-7 payload: a 6x6 table whose "Total Energy Intake" cell carries an
    // inline `<span style="font-size: small">` with WordPress's unescaped attribute
    // quotes. Every other escape (`\n`, `\/`) is already valid JSON — the stray
    // `style` quotes are the only defect.
    $payload = <<<'JSON'
[
["Nutrient","Birth","Day 1","Day 3","Day 7","Goal"],
["<strong><sup><span style="font-size: small">1Total Energy Intake\n<strong>(kcal\/kg\/day)","60","70","80","90","100"],
["Protein (g\/kg\/day)","1.5","2","2.5","3","3.5"],
["Fat (g\/kg\/day)","2","3","3.5","4","4.5"],
["Carbohydrate (g\/kg\/day)","5","6","7","8","9"],
["Sodium (mEq\/kg\/day)","2","3","3","4","4"]
]
JSON;

    // The raw payload must be invalid JSON on its own, or there is nothing to repair
    json_decode(
        $payload,
        true
    );
    expect(json_last_error())->not->toBe(JSON_ERROR_NONE);

    // Decode through the repairer
    $result = TablePressTableDataRepairer::decode($payload);

    // The repair pass should have recovered the table without error
    expect($result['repaired'])->toBeTrue()
        ->and($result['error'])->toBeNull()
        ->and($result['offset'])->toBeNull();

    // All six rows survive, each with six columns
    $data = requireTableData($result);
    expect($data)->toHaveCount(6);
    foreach ($data as $row) {
        expect($row)->toBeArray()->toHaveCount(6);
    }

    // The offending cell keeps its inline span markup and decoded escapes
    $cell = requireTableCell($data, 1, 0);
    expect($cell)->toContain('<span style="font-size: small">')
        ->and($cell)->toContain("Total Energy Intake\n")
        ->and($cell)->toContain('(kcal/kg/day)');
});

it('repairs unescaped quotes in any attribute', function (): void {
    // A cell with two distinct attributes, both quoted with stray double quotes
    $payload = <<<'JSON'
[["<span class="lead" style="color: red">Heading","tail"]]
JSON;

    // Decode through the repairer
    $result = TablePressTableDataRepairer::decode($payload);

    // Both attributes survive intact in the recovered cell
    expect($result['repaired'])->toBeTrue()
        ->and(requireTableCell(requireTableData($result), 0, 0))
        ->toBe('<span class="lead" style="color: red">Heading');
});

it('passes a valid payload through untouched', function (): void {
    // A well-formed payload, including a properly escaped HTML attribute
    $payload = <<<'JSON'
[["Header 1","Header 2"],["<span style=\"font-weight: bold\">A","B"]]
JSON;

    // Decode through the repairer
    $result = TablePressTableDataRepairer::decode($payload);

    // The repair pass never ran
    expect($result['repaired'])->toBeFalse()
        ->and($result['error'])->toBeNull()
        ->and($result['offset'])->toBeNull();

    // The data matches a direct decode exactly
    expect($result['data'])->toBe(json_decode(
        $payload,
        true
    ));

    // The escape pass is a true no-op on a payload with no stray attribute quotes
    expect(TablePressTableDataRepairer::escapeHtmlAttributeQuotes($payload))->toBe($payload);
});

it('does not treat a valid empty array as a parse error', function (): void {
    // An empty table array is valid JSON and must not be reported as an error
    $result = TablePressTableDataRepairer::decode('[]');

    expect($result['data'])->toBe([])
        ->and($result['repaired'])->toBeFalse()
        ->and($result['error'])->toBeNull()
        ->and($result['offset'])->toBeNull();
});

it('reports the error and offset for an irreparable payload', function (): void {
    // A stray quote that is not part of an HTML attribute cannot be repaired
    $payload = '[["ok"],["a " b"]]';

    // Decode through the repairer
    $result = TablePressTableDataRepairer::decode($payload);

    // No data is recovered and the repair did not falsely claim success
    expect($result['data'])->toBeNull()
        ->and($result['repaired'])->toBeFalse();

    // The JSON parse error message is surfaced for the operator
    expect($result['error'])->toBeString();

    // toBeString() does not narrow for PHPStan; narrow before the case-insensitive check.
    $error = is_string($result['error']) ? $result['error'] : '';
    expect(strtolower($error))->toContain('syntax');

    // The offset points at the stray byte where the string structure first broke
    $offset = $result['offset'];
    expect($offset)->toBeInt();

    // toBeInt() does not narrow for PHPStan; narrow before the string offset lookup.
    $offset = is_int($offset) ? $offset : -1;
    expect($payload[$offset])->toBe('b');
});

it('returns null from the offset finder for well-formed strings', function (): void {
    // A structurally sound payload has no stray-quote break to locate
    expect(TablePressTableDataRepairer::findUnescapedQuoteBreakOffset(
        '[["a","b"],["c","d"]]'
    ))->toBeNull();
});

/**
 * Run a callback with PHP warnings turned into exceptions.
 *
 * The suite does not fail on a warning, so an out-of-range string read in the offset
 * scan would otherwise pass unnoticed.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function tablePressRepairerFailingOnWarnings(callable $callback): mixed
{
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    }, E_WARNING);

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

it('returns exactly the four result keys for a valid payload', function (): void {
    expect(TablePressTableDataRepairer::decode('[["a"]]'))->toBe([
        'data' => [['a']],
        'repaired' => false,
        'error' => null,
        'offset' => null,
    ]);
});

it('returns exactly the four result keys for a repaired payload', function (): void {
    expect(TablePressTableDataRepairer::decode('[["<b class="x">y"]]'))->toBe([
        'data' => [['<b class="x">y']],
        'repaired' => true,
        'error' => null,
        'offset' => null,
    ]);
});

it('returns exactly the four result keys for an irreparable payload', function (): void {
    $result = TablePressTableDataRepairer::decode('[["a " b"]]');

    expect(array_keys($result))->toBe(['data', 'repaired', 'error', 'offset'])
        ->and($result['data'])->toBeNull()
        ->and($result['repaired'])->toBeFalse()
        ->and($result['offset'])->toBe(7);
});

it('decodes a JSON object payload to an associative array', function (): void {
    // An object only decodes to an array when the decode asks for associative arrays
    expect(TablePressTableDataRepairer::decode('{"rows":[["a"]]}')['data'])
        ->toBe(['rows' => [['a']]]);
});

it('decodes a repaired JSON object payload to an associative array', function (): void {
    $result = TablePressTableDataRepairer::decode('{"cell":"<b class="x">y"}');

    expect($result['repaired'])->toBeTrue()
        ->and($result['data'])->toBe(['cell' => '<b class="x">y']);
});

it('reports no data for a valid payload that is not an array', function (): void {
    expect(TablePressTableDataRepairer::decode('"just a string"'))->toBe([
        'data' => null,
        'repaired' => false,
        'error' => null,
        'offset' => null,
    ]);
});

it('skips an escaped quote when locating the break', function (): void {
    // The escaped quote does not close the string; the unescaped one after `b` does
    expect(TablePressTableDataRepairer::findUnescapedQuoteBreakOffset('["a\"b" c]'))->toBe(8);
});

it('closes an empty string at its second quote when locating the break', function (): void {
    // Nothing is escaped before the first byte of a string
    expect(TablePressTableDataRepairer::findUnescapedQuoteBreakOffset('["" x]'))->toBe(4);
});

it('reads the first byte of the payload when locating the break', function (): void {
    expect(TablePressTableDataRepairer::findUnescapedQuoteBreakOffset('"a" b'))->toBe(4);
});

it('does not read the last byte as the start of the payload', function (): void {
    // A trailing quote opens a string; read first it would close one before `b`
    expect(TablePressTableDataRepairer::findUnescapedQuoteBreakOffset('a" b"'))->toBeNull();
});

it('stops the scan at the end of the payload', function (): void {
    // A string closing on the last byte, and one followed only by whitespace, both end
    // the scan without reading past the payload
    expect(tablePressRepairerFailingOnWarnings(
        static fn (): ?int => TablePressTableDataRepairer::findUnescapedQuoteBreakOffset('["a"]"a"')
    ))->toBeNull()
        ->and(tablePressRepairerFailingOnWarnings(
            static fn (): ?int => TablePressTableDataRepairer::findUnescapedQuoteBreakOffset('"a"  ')
        ))->toBeNull()
        ->and(tablePressRepairerFailingOnWarnings(
            static fn (): ?int => TablePressTableDataRepairer::findUnescapedQuoteBreakOffset('"a\\')
        ))->toBeNull();
});
