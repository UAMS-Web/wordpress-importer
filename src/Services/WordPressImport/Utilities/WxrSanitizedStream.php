<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Presents a WordPress export to libxml as a sanitized, opaquely-named stream.
 *
 * The obvious way to splice WxrSanitizer in front of libxml is a php://filter
 * URI, and it does not survive contact with real paths: libxml runs any filename
 * that looks like a URI through its own canonicalization, which percent-escapes
 * spaces and other unsafe characters. A path such as `/Users/me/GitHub Repos/…`
 * therefore reaches the PHP streams layer as `…/GitHub%20Repos/…`, which does not
 * exist, and every parse fails with "failed to load … No such file or directory".
 *
 * This wrapper sidesteps that by never putting the path in the URI at all. The
 * real path is held in a registry and the URI handed to libxml is
 * `uamswxr://<hash>` — pure lowercase hex, so canonicalization is a no-op and the
 * document's own characters can never be mangled. Reads are served from the real
 * file with WxrSanitizingStreamFilter appended, so the sanitizing stays
 * streaming and the memory bound is unchanged.
 */
class WxrSanitizedStream
{
    /**
     * The URI scheme this wrapper is registered under.
     */
    public const SCHEME = 'uamswxr';

    /**
     * Real file paths keyed by the opaque token that stands in for them.
     *
     * @var array<string, string>
     */
    private static array $paths = [];

    /**
     * Whether the wrapper has already been registered this process.
     */
    // A property initializer is never reported as an executed line, and the suite
    // shares one process, so no test can start from the unregistered state twice.
    // @pest-mutate-ignore: FalseToTrue
    private static bool $registered = false;

    /**
     * The stream context, assigned by PHP's stream-wrapper machinery.
     *
     * @var resource|null
     */
    public $context;

    /**
     * The underlying file handle with the sanitizing filter attached.
     *
     * @var resource|null
     */
    private $handle;

    /**
     * Register the wrapper once per process.
     *
     * @return bool Whether the wrapper is available for use
     */
    public static function register(): bool
    {
        // Registering twice emits a warning and fails, so only ever do it once. The
        // check below finds the scheme registered too, so this return only saves work.
        if (self::$registered) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return true;
        }

        // The wrapper list holds only strings, so strict and loose comparison agree
        // @pest-mutate-ignore: TrueToFalse
        self::$registered = \in_array(
            self::SCHEME,
            \stream_get_wrappers(),
            true
        ) || \stream_wrapper_register(
            self::SCHEME,
            self::class
        );

        return self::$registered;
    }

    /**
     * Get a libxml-safe URI that reads the given file through the sanitizer.
     *
     * Falls back to the untouched path if the wrapper or filter cannot be
     * registered, so a registry failure degrades to today's behavior rather than
     * handing libxml a URI it cannot open.
     *
     * @param  string  $path  The path to the export file
     * @return string An opaque URI that reads the file sanitized
     */
    public static function wrap(
        string $path
    ): string {
        // Both halves are required: the wrapper serves reads, the filter repairs them.
        // Each register() also succeeds when its name is already taken, so no test
        // reaches this return.
        if (
            ! self::register()
            || ! WxrSanitizingStreamFilter::register()
        ) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return $path;
        }

        // An already-wrapped path must not be wrapped twice
        if (\str_starts_with(
            $path,
            self::SCHEME.'://'
        )) {
            return $path;
        }

        // Key by content-free hash so repeated wraps of one path stay identical; the
        // token is the last 32 of the hash's 64 hex characters
        $token = \substr(
            \hash(
                'sha256',
                $path
            ),
            32
        );

        self::$paths[$token] = $path;

        return self::SCHEME.'://'.$token;
    }

    /**
     * Resolve a wrapped URI back to the real path it stands for.
     *
     * @param  string  $uri  The opaque URI
     * @return string|null The real path, or null when the token is unknown
     */
    public static function resolve(
        string $uri
    ): ?string {
        // The prefix's length is all that is read, and it is the same either way round
        // @pest-mutate-ignore: ConcatSwitchSides
        $token = \substr(
            $uri,
            \strlen(self::SCHEME.'://')
        );

        return self::$paths[$token] ?? null;
    }

    /**
     * Open the underlying export file and attach the sanitizing filter.
     *
     * @param  string  $path  The opaque URI being opened
     * @param  string  $mode  The requested mode, which is always read here
     * @param  int  $options  Stream-wrapper option flags
     * @param  string|null  $openedPath  Set to the opened path when requested
     * @return bool Whether the stream opened successfully
     */
    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath
    ): bool {
        $real = self::resolve($path);

        // An unknown token means the URI outlived its registry entry
        if ($real === null) {
            if (($options & STREAM_REPORT_ERRORS) !== 0) {
                \trigger_error(
                    \sprintf(
                        'Unknown sanitized-stream token in "%s".',
                        $path
                    ),
                    E_USER_WARNING
                );
            }

            return false;
        }

        $handle = @\fopen(
            $real,
            'rb'
        );

        // Stay silent on a missing or unreadable file: the caller already
        // surfaces that, and warning here would only duplicate its report
        if ($handle === false) {
            return false;
        }

        // Repair the bytes on the way out of the file, before libxml sees them
        if (self::isByteCompatibleWithAscii($real)) {
            \stream_filter_append(
                $handle,
                WxrSanitizingStreamFilter::FILTER_NAME,
                STREAM_FILTER_READ
            );
        }

        $this->handle = $handle;

        if (($options & STREAM_USE_PATH) !== 0) {
            $openedPath = $real;
        }

        return true;
    }

    /**
     * Report whether a document's bytes can be repaired one byte at a time.
     *
     * The sanitizer works on raw bytes, which is safe for UTF-8 and every other
     * ASCII-superset encoding but catastrophic for UTF-16 and UTF-32: there an
     * ASCII character is encoded as its byte plus one or more 0x00 bytes, so
     * stripping "illegal control bytes" would delete half of every character and
     * leave a document libxml cannot read at all. Such a file parses fine
     * untouched, so it is passed straight through rather than repaired.
     *
     * Detection is by leading bytes: a UTF-8 or ASCII XML document begins with a
     * UTF-8 BOM or the `<` of its prolog, and can never carry a 0x00 there.
     *
     * @param  string  $path  The real path to the export file
     * @return bool Whether byte-level repair is safe for this document
     */
    private static function isByteCompatibleWithAscii(
        string $path
    ): bool {
        $handle = @\fopen(
            $path,
            'rb'
        );

        // stream_open() has just opened this file, so no test reaches this return
        // @pest-mutate-ignore: FalseToTrue
        if ($handle === false) {
            // @pest-mutate-ignore: TrueToFalse, RemoveEarlyReturn
            return true;
        }

        $head = \fread(
            $handle,
            4
        );

        // Closing only releases the handle; nothing reads it again
        // @pest-mutate-ignore: RemoveFunctionCall
        \fclose($handle);

        // fread() fails on a directory, which fopen() opens on macOS and Linux. Such a
        // path reads as empty whether or not it is repaired, so the answer here is
        // only a default.
        if ($head === false) {
            // @pest-mutate-ignore: TrueToFalse
            return true;
        }

        // A UTF-16 byte-order mark rules byte-level repair out. A UTF-32 BE one begins
        // with two 0x00 bytes, which the check below already catches, and a UTF-32 LE
        // one begins with the UTF-16 LE mark. An empty head matches neither and is
        // repaired, harmlessly.
        foreach (["\xFF\xFE", "\xFE\xFF"] as $bom) {
            if (\str_starts_with($head, $bom)) {
                return false;
            }
        }

        // Without a BOM, a 0x00 this early means a wide encoding just the same
        return ! \str_contains($head, "\x00");
    }

    /**
     * Read the next sanitized bytes.
     *
     * @param  int  $count  The maximum number of bytes to return
     * @return string The sanitized bytes read
     */
    public function stream_read(
        int $count
    ): string {
        // fread rejects a non-positive length, which a caller may legitimately ask for
        if ($this->handle === null || $count < 1) {
            return '';
        }

        $data = \fread(
            $this->handle,
            $count
        );

        // fread() fails on a directory, which serves no bytes
        return $data === false ? '' : $data;
    }

    /**
     * Report whether the underlying file has been fully read.
     *
     * @return bool Whether the stream is at end of file
     */
    public function stream_eof(): bool
    {
        return $this->handle === null
            || \feof($this->handle);
    }

    /**
     * Report the current position in the sanitized stream.
     *
     * @return int The byte offset read so far
     */
    public function stream_tell(): int
    {
        if ($this->handle === null) {
            return 0;
        }

        $position = \ftell($this->handle);

        // ftell() fails only on an unusable handle, which an open stream's is not
        // @pest-mutate-ignore: FalseToTrue, DecrementInteger, IncrementInteger
        return $position === false ? 0 : $position;
    }

    /**
     * Report stream metadata for callers that stat an open handle.
     *
     * @return array<int|string, int>|false The underlying file's stat data
     */
    public function stream_stat(): array|false
    {
        if ($this->handle === null) {
            return false;
        }

        return \fstat($this->handle);
    }

    /**
     * Accept stream options without acting on them.
     *
     * Blocking and timeout settings are meaningless for a local file, but PHP
     * warns when a wrapper omits this entirely.
     *
     * @param  int  $option  The option being set
     * @param  int  $arg1  The option's first argument
     * @param  int  $arg2  The option's second argument
     * @return bool Always false, since no option is honored
     */
    public function stream_set_option(
        int $option,
        int $arg1,
        int $arg2
    ): bool {
        return false;
    }

    /**
     * Close the underlying handle.
     */
    public function stream_close(): void
    {
        if ($this->handle === null) {
            return;
        }

        // Closing only releases the handle; it is dropped below either way
        // @pest-mutate-ignore: RemoveFunctionCall
        \fclose($this->handle);

        $this->handle = null;
    }

    /**
     * Stat the real file behind an opaque URI.
     *
     * @param  string  $path  The opaque URI being stated
     * @param  int  $flags  Stat option flags
     * @return array<int|string, int>|false The real file's stat data, or false when unknown
     */
    public function url_stat(
        string $path,
        int $flags
    ): array|false {
        $real = self::resolve($path);

        // Check first rather than stat-and-suppress: the suppression operator
        // does not stop a test runner's error handler from reporting the warning
        if ($real === null || ! \is_file($real)) {
            return false;
        }

        return \stat($real);
    }
}
