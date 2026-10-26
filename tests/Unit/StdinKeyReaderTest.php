<?php

declare(strict_types=1);

/**
 * Covers the unattended credential route that `wordpress-importer-archive#1046` decided and `wordpress-importer-archive#588` depends on.
 *
 * **This is a Unit test deliberately, and the reason is the gate.** The command it serves,
 * `ImportWordPress`, is exercised only by `tests/Feature/`, which needs the booted host app
 * and runs nowhere in this package's CI — so logic left inline in the command ships
 * unverified by a green gate. Reading the key from a stream rather than from `STDIN`
 * directly is what makes it reachable here: a `php://memory` handle needs no terminal, no
 * pipe and no boot.
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\StdinKeyReader;

/**
 * Open an in-memory stream holding the given bytes, rewound ready to read.
 *
 * @param  string  $contents  The bytes the stream should carry.
 * @return resource
 */
function stdinKeyStream(string $contents)
{
    $stream = \fopen('php://memory', 'r+');

    if ($stream === false) {
        throw new RuntimeException('Could not open an in-memory stream for the test.');
    }

    \fwrite($stream, $contents);
    \rewind($stream);

    return $stream;
}

it('reads the key from the first line', function (): void {
    $key = StdinKeyReader::read(stdinKeyStream("b86ab563a18bc585\n"));

    expect($key)->toBe('b86ab563a18bc585');
});

it('reads a key that arrives with no trailing newline', function (): void {
    $key = StdinKeyReader::read(stdinKeyStream('b86ab563a18bc585'));

    expect($key)->toBe('b86ab563a18bc585');
});

it('strips a CRLF line ending as well as a bare LF', function (): void {
    $key = StdinKeyReader::read(stdinKeyStream("b86ab563a18bc585\r\n"));

    expect($key)->toBe('b86ab563a18bc585');
});

it('leaves surrounding whitespace for the client to trim', function (): void {
    // One place decides what a key looks like, and it is `Client::forRun()`. Trimming here
    // as well would mean two, which is how the two drift apart later.
    $key = StdinKeyReader::read(stdinKeyStream("  b86ab563a18bc585  \n"));

    expect($key)->toBe('  b86ab563a18bc585  ');
});

it('leaves the rest of the stream unread', function (): void {
    // The contract is the FIRST line, so a caller may go on using the stream. Asserting the
    // remainder is intact is what stops that quietly becoming "consumes everything".
    $stream = stdinKeyStream("b86ab563a18bc585\nsecond line\nthird line\n");

    StdinKeyReader::read($stream);

    expect(\stream_get_contents($stream))->toBe("second line\nthird line\n");
});

it('refuses an empty stream, naming the flag that expected input', function (): void {
    // The flag was passed and nothing arrived: a configuration mistake rather than an
    // anonymous request, and it must not reach the API as a missing header.
    expect(static fn () => StdinKeyReader::read(stdinKeyStream('')))
        ->toThrow(MigrationApiException::class, 'No migration API key arrived on standard input.');
});

it('refuses a blank first line', function (): void {
    expect(static fn () => StdinKeyReader::read(stdinKeyStream("\n")))
        ->toThrow(MigrationApiException::class, 'The first line of standard input was empty');
});

it('refuses a first line that is only whitespace', function (): void {
    expect(static fn () => StdinKeyReader::read(stdinKeyStream("   \t  \n")))
        ->toThrow(MigrationApiException::class, 'The first line of standard input was empty');
});

it('refuses a stream that has already been closed', function (): void {
    // The guard's reachable case, and the reason it is not dead code: `is_resource()` is
    // false for a CLOSED handle, which is a runtime state the `resource` parameter type
    // cannot express. An unusable handle and an empty one are indistinguishable downstream
    // -- both end as "no key was supplied" -- and they need opposite fixes.
    $stream = stdinKeyStream("b86ab563a18bc585\n");
    \fclose($stream);

    expect(static fn () => StdinKeyReader::read($stream))
        ->toThrow(MigrationApiException::class, 'no readable stream was available');
});
