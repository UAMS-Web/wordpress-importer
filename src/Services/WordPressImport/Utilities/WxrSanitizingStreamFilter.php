<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use php_user_filter;

/**
 * Exposes WxrSanitizer to the PHP streams layer as a read filter.
 *
 * The filter holds one sanitizer per stream, so scan state survives across the
 * 8 KB buckets libxml reads in, and the document is repaired without ever being
 * buffered whole or copied to a temporary file.
 *
 * Without it, a WordPress export carrying XML-illegal bytes fails in two ways
 * that look nothing alike: simplexml_load_file() returns false outright, while
 * XMLReader stops iterating at the offending byte and reports nothing at all —
 * silently truncating the import to whatever preceded the first bad byte.
 *
 * Callers do not attach this directly. WxrSanitizedStream::wrap() is the entry
 * point; it appends this filter to the handle it opens.
 */
class WxrSanitizingStreamFilter extends php_user_filter
{
    /**
     * The name this filter is registered under in the stream-filter registry.
     */
    public const FILTER_NAME = 'uams.wxr.sanitize';

    /**
     * Whether the filter has already been added to the registry this process.
     *
     * A property default is never reported as an executed line, so no test is
     * attributed to it and a mutant here can only show as uncovered.
     */
    // @pest-mutate-ignore: FalseToTrue
    private static bool $registered = false;

    /**
     * The per-stream sanitizer carrying scan state across chunk boundaries.
     */
    private ?WxrSanitizer $sanitizer = null;

    /**
     * Register the filter once per process.
     *
     * @return bool Whether the filter is available for use
     */
    public static function register(): bool
    {
        // Registering twice emits a warning and fails, so only ever do it once.
        // Falling through instead reaches the registry check below, which finds
        // the name and never registers again, so the early return saves work only.
        if (self::$registered) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return true;
        }

        self::$registered = \in_array(
            self::FILTER_NAME,
            \stream_get_filters(),
            // Every registry entry is a string, so a loose match finds the same name
            // @pest-mutate-ignore: TrueToFalse
            true
        ) || \stream_filter_register(
            self::FILTER_NAME,
            self::class
        );

        return self::$registered;
    }

    /**
     * Initialize the per-stream sanitizer when the filter is attached.
     *
     * @return bool Always true, since the filter has nothing that can fail to set up
     */
    public function onCreate(): bool
    {
        $this->sanitizer = new WxrSanitizer;

        return true;
    }

    /**
     * Sanitize each bucket of bytes as libxml reads them.
     *
     * @param  resource  $in  The inbound bucket brigade
     * @param  resource  $out  The outbound bucket brigade
     * @param  int  $consumed  Running count of bytes read from the input
     * @param  bool  $closing  Whether the stream is being torn down
     * @return int The stream-filter status telling PHP to pass the buckets on
     */
    public function filter(
        $in,
        $out,
        &$consumed,
        $closing
    ): int {
        // Guard against a filter used without onCreate having run
        $sanitizer = $this->sanitizer ??= new WxrSanitizer;

        while (($bucket = \stream_bucket_make_writeable($in)) instanceof \StreamBucket) {
            // A read filter's consumed count changes nothing a reader can see: a
            // filter that subtracts it returns the same bytes, position and EOF
            // (measured with php://temp on macOS), so the count is kept for intent
            // @pest-mutate-ignore: PlusEqualToMinusEqual
            $consumed += $bucket->datalen;

            $data = $sanitizer->feed($bucket->data);

            $bucket->data = $data;
            $bucket->datalen = \strlen($data);

            \stream_bucket_append(
                $out,
                $bucket
            );
        }

        // On teardown, emit whatever the sanitizer withheld at the last boundary
        if ($closing) {
            $tail = $sanitizer->flush();
            $stream = $this->stream;

            // An empty tail appended as a bucket adds no bytes, and the stream is
            // always open while it closes, so both mutants emit the same document
            // @pest-mutate-ignore: BooleanAndToBooleanOr, EmptyStringToNotEmpty
            if ($tail !== '' && \is_resource($stream)) {
                $bucket = \stream_bucket_new(
                    $stream,
                    $tail
                );

                \stream_bucket_append(
                    $out,
                    $bucket
                );
            }
        }

        return PSFS_PASS_ON;
    }
}
