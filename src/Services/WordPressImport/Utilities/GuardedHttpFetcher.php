<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Performs the importer's link-resolution HTTP requests against URLs taken from
 * the WXR being imported, refusing any hop that targets a non-public address.
 *
 * Link targets in post content are attacker-controllable in exactly the way
 * asset URLs are: an export can point the importer at `http://127.0.0.1:…`, at
 * `http://169.254.169.254/…` cloud metadata, or at any RFC1918 host reachable
 * from the importer but not from the internet. Automatic redirect-following
 * widens that surface, because a public URL that passes a front-door check can
 * redirect into a private address.
 *
 * So redirects are followed **manually**: automatic following is disabled and
 * every hop is re-checked with {@see OutboundUrlGuard::isAllowedUrl()} before
 * its request is sent. This mirrors the treatment the asset download path
 * already applies (wordpress-importer-archive#394); this class extends it to link resolution (wordpress-importer-archive#534).
 *
 * TLS certificate verification is left at the verifying default (#4).
 */
class GuardedHttpFetcher
{
    /**
     * Total time budget in seconds for resolving one URL, across every hop of
     * its redirect chain.
     *
     * This is a whole-chain budget rather than a per-request one, matching the
     * `CURLOPT_TIMEOUT` the cURL implementations this class replaced used: that
     * option bounded the entire `curl_exec()` call, redirects included. Applying
     * it per hop instead would let a single link burn
     * `TIMEOUT_SECONDS * (MAX_REDIRECT_HOPS + 1)` seconds, a six-fold worst case
     * for an importer that resolves thousands of links in one process.
     */
    // A constant initializer is never reported as an executed line, so Pest runs no
    // test against a mutant here. Changing the value by hand fails the "an exhausted
    // budget" row of GuardedHttpFetcherReportingTest.
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    public const TIMEOUT_SECONDS = 10;

    /**
     * Resolves a URL's redirect chain to its final destination.
     *
     * Issues `HEAD` requests (cheaper than `GET` when only the destination is
     * wanted) and returns the URL of the terminal hop when that hop answered
     * with a non-error status.
     *
     * @param  string  $url  The URL to resolve
     * @return ?string The final URL, or null if the chain was refused or failed
     */
    public static function resolveFinalUrl(
        string $url
    ): ?string {
        $result = self::followChain(
            $url,
            false,
            null
        );

        if ($result === null) {
            return null;
        }

        // Preserve the previous cURL behavior: any non-error status resolves
        $status = $result['response']->status();

        return (
            $status >= 200
            && $status < 400
        ) ? $result['url'] : null;
    }

    /**
     * Fetches a URL's response body, following its redirect chain.
     *
     * @param  string  $url  The URL to fetch
     * @param  string  $userAgent  The User-Agent header to send
     * @return ?string The response body, or null if the fetch was refused or failed
     */
    public static function fetchBody(
        string $url,
        string $userAgent
    ): ?string {
        $result = self::followChain(
            $url,
            true,
            $userAgent
        );

        if ($result === null) {
            return null;
        }

        // Preserve the previous cURL behavior: any non-error status is a body
        $status = $result['response']->status();

        if (
            $status < 200
            || $status >= 400
        ) {
            return null;
        }

        return $result['response']->body();
    }

    /**
     * Walks a URL's redirect chain, re-validating every hop against the egress
     * guard before its request is sent.
     *
     * Returns the terminal hop's URL and response — the caller decides what
     * status counts as success — or null when a hop targets a non-public
     * address, the chain loops back on itself, it exceeds
     * {@see OutboundUrlGuard::MAX_REDIRECT_HOPS}, or the transport fails.
     *
     * The walk itself lives in {@see GuardedRedirectWalk}, shared with the
     * asset download and existence-check paths (wordpress-importer-archive#542); this method only
     * supplies link resolution's own reporting and transport-failure policy.
     *
     * @param  string  $url  The URL to start from
     * @param  bool  $wantsBody  Whether to issue GET (true) or HEAD (false)
     * @param  ?string  $userAgent  Optional User-Agent header to send
     * @return ?array{url: string, response: Response} The terminal hop, or null
     */
    private static function followChain(
        string $url,
        bool $wantsBody,
        ?string $userAgent
    ): ?array {
        // A transport failure is not fatal to the import — the link simply does
        // not resolve — so the walk swallows it and reports through the log
        return new GuardedRedirectWalk(
            timeoutSeconds: self::TIMEOUT_SECONDS,
            wantsBody: $wantsBody,
            userAgent: $userAgent,
            onRefusal: self::warn(...),
        )->follow($url);
    }

    /**
     * Logs a refused or failed fetch, naming the entry being imported so a
     * legitimately unreachable target is visible rather than silently skipped.
     *
     * @param  GuardedFetchRefusal  $reason  Why the chain was abandoned
     * @param  string  $rejectedUrl  The URL of the hop that was refused
     * @param  string  $originalUrl  The URL the chain started from
     * @param  ?string  $detail  The underlying error message, where there is one
     */
    private static function warn(
        GuardedFetchRefusal $reason,
        string $rejectedUrl,
        string $originalUrl,
        ?string $detail = null
    ): void {
        // Phrase the structured reason as this path's own log sentence
        $sentence = match ($reason) {
            GuardedFetchRefusal::NonPublicTarget => 'refused a non-public or non-HTTP fetch target',
            GuardedFetchRefusal::BudgetExhausted => 'exhausted the '.self::TIMEOUT_SECONDS.'s fetch budget',
            GuardedFetchRefusal::TransportFailure => 'failed to fetch: '.$detail,
            GuardedFetchRefusal::UnparseableLocation => 'could not parse the redirect target: '.$detail,
            GuardedFetchRefusal::RedirectLoop => 'detected a redirect loop',
            GuardedFetchRefusal::TooManyRedirects => 'exceeded '.OutboundUrlGuard::MAX_REDIRECT_HOPS.' redirects',
        };

        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'rejected_url' => $rejectedUrl,
                'original_url' => $originalUrl,
            ]
        );

        // Log the refusal but don't fail the import
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                'GuardedHttpFetcher: '.$sentence,
                $logData['page_url']
            ),
            $logData['context']
        );
    }
}
