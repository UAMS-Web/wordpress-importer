<?php

declare(strict_types=1);

/**
 * Tests `WxrSanitizedStream`, the stream wrapper that serves a WXR file through the
 * sanitizing filter: how it registers, how it names a wrapped path, which files it
 * filters, and what each stream method answers before a file is open.
 *
 * Whether the filter ran is read from a C0 control byte (`\x01`), which the filter
 * strips and nothing else touches. Stream methods are also called directly on an
 * instance, because PHP never calls them on a wrapper it failed to open.
 *
 * @command  php vendor/bin/pest tests/Unit/WxrSanitizedStreamTest.php
 */

use PHPUnit\Framework\Assert;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\WxrSanitizedStream;

/**
 * Remember a temporary file for cleanup, or hand back and forget every one so far.
 *
 * @return list<string> The files remembered before this call cleared them, or [] when adding
 */
function wxrStreamTracked(?string $path = null): array
{
    /** @var list<string> $paths */
    static $paths = [];

    if ($path !== null) {
        $paths[] = $path;

        return [];
    }

    $tracked = $paths;
    $paths = [];

    return $tracked;
}

/**
 * Write bytes to a temporary file and remember it for cleanup.
 */
function wxrStreamFile(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'wxs');

    if ($path === false) {
        throw new RuntimeException('Could not reserve a temporary file.');
    }

    file_put_contents($path, $bytes);
    wxrStreamTracked($path);

    return $path;
}

/**
 * Read a file whole through the wrapper.
 */
function wxrStreamRead(string $path): string|false
{
    return file_get_contents(WxrSanitizedStream::wrap($path));
}

/**
 * An instance opened on a file, as PHP would open it.
 */
function wxrStreamOpened(string $path, int $options = 0, ?string &$openedPath = null): WxrSanitizedStream
{
    $stream = new WxrSanitizedStream;

    if (! $stream->stream_open(WxrSanitizedStream::wrap($path), 'rb', $options, $openedPath)) {
        throw new RuntimeException("Could not open {$path}.");
    }

    return $stream;
}

/**
 * Run a callback and return the messages of any warnings it raised and did not
 * suppress with `@`. A handler still receives a suppressed warning, with
 * error_reporting() masked, so reporting is set to E_ALL for the run (the test
 * runner lowers it) and a masked level is left out.
 *
 * @return list<string>
 */
function wxrStreamWarnings(callable $work): array
{
    $warnings = [];
    $reporting = error_reporting(E_ALL);

    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        if ((error_reporting() & $level) !== 0) {
            $warnings[] = $message;
        }

        return true;
    });

    try {
        $work();
    } finally {
        restore_error_handler();
        error_reporting($reporting);
    }

    return $warnings;
}

afterEach(function (): void {
    foreach (wxrStreamTracked() as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('registers once and reports success when the scheme is already registered', function (): void {
    // With the flag cleared but the wrapper still registered, registering again would
    // fail with a warning; the scheme being present already counts as registered
    WxrSanitizedStream::register();
    (new ReflectionProperty(WxrSanitizedStream::class, 'registered'))->setValue(null, false);

    $warnings = wxrStreamWarnings(static function () use (&$result): void {
        $result = WxrSanitizedStream::register();
    });

    expect($result)->toBeTrue()
        ->and($warnings)->toBe([])
        ->and(stream_get_wrappers())->toContain(WxrSanitizedStream::SCHEME);
});

it('names a wrapped path by a 32-character token, the same each time', function (): void {
    $wrapped = WxrSanitizedStream::wrap('/some/export.xml');

    expect($wrapped)->toMatch('#^uamswxr://[0-9a-f]{32}$#')
        ->and(WxrSanitizedStream::wrap('/some/export.xml'))->toBe($wrapped)
        ->and(WxrSanitizedStream::resolve($wrapped))->toBe('/some/export.xml');
});

it('does not wrap a wrapped path again, and wraps a path that only starts with the scheme name', function (): void {
    $wrapped = WxrSanitizedStream::wrap('/some/export.xml');

    expect(WxrSanitizedStream::wrap($wrapped))->toBe($wrapped)
        ->and(WxrSanitizedStream::wrap('uamswxr-export.xml'))->toStartWith('uamswxr://');
});

it('repairs an ASCII-compatible file as it is read', function (): void {
    expect(wxrStreamRead(wxrStreamFile("<rss>a\x01b</rss>")))->toBe('<rss>ab</rss>');
});

it('passes a wide-encoded file through unrepaired', function (string $bytes): void {
    // Byte-level repair would corrupt UTF-16 or UTF-32, so the filter is not applied
    expect(wxrStreamRead(wxrStreamFile($bytes)))->toBe($bytes);
})->with([
    // U+4E01 has no zero byte, so only the byte-order mark identifies these
    'UTF-16 LE with a BOM' => ["\xFF\xFE\x01\x4E\x01\x4E"],
    'UTF-16 BE with a BOM' => ["\xFE\xFF\x4E\x01\x4E\x01"],
    'UTF-32 BE with a BOM' => ["\x00\x00\xFE\xFF\x00\x00\x4E\x01"],
    'a zero byte in the first four' => ["abc\x00\x01"],
]);

it('reads only the first four bytes to decide', function (): void {
    // A zero byte at the fifth position is past the check, so the file is repaired
    expect(wxrStreamRead(wxrStreamFile("abcd\x00\x01")))->toBe('abcd');
});

it('serves a directory as empty', function (): void {
    // fopen() opens a directory on macOS and Linux and fread() then fails; Windows
    // refuses the fopen() itself, which another path already covers
    if (PHP_OS_FAMILY === 'Windows') {
        Assert::markTestSkipped('fopen() refuses a directory on Windows.');
    }

    $directory = sys_get_temp_dir().'/wxs-dir-'.bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        wxrStreamWarnings(static function () use (&$read, $directory): void {
            $read = wxrStreamRead($directory);
        });
    } finally {
        rmdir($directory);
    }

    expect($read)->toBe('');
});

it('serves an empty file as empty', function (): void {
    expect(wxrStreamRead(wxrStreamFile('')))->toBe('');
});

it('refuses an unknown token, warning only when asked to report errors', function (): void {
    $openedPath = null;

    $reported = wxrStreamWarnings(static function () use (&$reportedResult, &$openedPath): void {
        $reportedResult = (new WxrSanitizedStream)->stream_open('uamswxr://unknown', 'rb', STREAM_REPORT_ERRORS, $openedPath);
    });

    $silent = wxrStreamWarnings(static function () use (&$silentResult, &$openedPath): void {
        $silentResult = (new WxrSanitizedStream)->stream_open('uamswxr://unknown', 'rb', 0, $openedPath);
    });

    expect($reportedResult)->toBeFalse()
        ->and($reported)->toBe(['Unknown sanitized-stream token in "uamswxr://unknown".'])
        ->and($silentResult)->toBeFalse()
        ->and($silent)->toBe([]);
});

it('refuses a wrapped path whose file is missing, silently', function (): void {
    $openedPath = null;
    $wrapped = WxrSanitizedStream::wrap(sys_get_temp_dir().'/wxs-missing-'.bin2hex(random_bytes(4)).'.xml');

    $warnings = wxrStreamWarnings(static function () use (&$result, $wrapped, &$openedPath): void {
        $result = (new WxrSanitizedStream)->stream_open($wrapped, 'rb', STREAM_REPORT_ERRORS, $openedPath);
    });

    expect($result)->toBeFalse()
        ->and($warnings)->toBe([]);
});

it('reports the real path only when the caller asks for it', function (): void {
    $path = wxrStreamFile('<rss/>');
    $withPath = null;
    $withoutPath = null;

    wxrStreamOpened($path, STREAM_USE_PATH, $withPath);
    wxrStreamOpened($path, 0, $withoutPath);

    expect($withPath)->toBe($path)
        ->and($withoutPath)->toBeNull();
});

it('reads, tells, stats and closes an open file', function (): void {
    $stream = wxrStreamOpened(wxrStreamFile('<rss/>'));

    $one = $stream->stream_read(1);
    $none = $stream->stream_read(0);
    $tell = $stream->stream_tell();
    $stat = $stream->stream_stat();
    $rest = $stream->stream_read(100);
    $eof = $stream->stream_eof();
    $stream->stream_close();

    expect($one)->toBe('<')
        ->and($none)->toBe('')
        ->and($tell)->toBe(1)
        ->and(is_array($stat) ? $stat['size'] : null)->toBe(6)
        ->and($rest)->toBe('rss/>')
        ->and($eof)->toBeTrue()
        ->and($stream->stream_read(5))->toBe('');
});

it('answers safely before any file is open', function (): void {
    $stream = new WxrSanitizedStream;

    expect($stream->stream_read(5))->toBe('')
        ->and($stream->stream_eof())->toBeTrue()
        ->and($stream->stream_tell())->toBe(0)
        ->and($stream->stream_stat())->toBeFalse()
        ->and($stream->stream_set_option(STREAM_OPTION_BLOCKING, 1, 0))->toBeFalse();

    // Closing an unopened stream is a no-op, not an error
    $stream->stream_close();
});

it('stats a wrapped file, and nothing for an unknown token or a missing file', function (): void {
    $path = wxrStreamFile('<rss/>');
    $missing = WxrSanitizedStream::wrap(sys_get_temp_dir().'/wxs-missing-'.bin2hex(random_bytes(4)).'.xml');
    $stream = new WxrSanitizedStream;

    $stat = $stream->url_stat(WxrSanitizedStream::wrap($path), 0);

    expect(is_array($stat) ? $stat['size'] : null)->toBe(6)
        ->and($stream->url_stat('uamswxr://unknown', 0))->toBeFalse()
        ->and($stream->url_stat($missing, 0))->toBeFalse()
        ->and(is_file(WxrSanitizedStream::wrap($path)))->toBeTrue();
});
