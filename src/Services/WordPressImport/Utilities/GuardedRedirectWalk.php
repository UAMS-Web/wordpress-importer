<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The importer's single guarded redirect walk: follows an HTTP redirect chain
 * by hand, re-checking every hop against {@see OutboundUrlGuard} before its
 * request is sent.
 *
 * Automatic redirect-following is disabled for every outbound fetch the
 * importer makes against a WXR-supplied URL, because a public URL that passes a
 * front-door check can redirect into a private address (wordpress-importer-archive#394, wordpress-importer-archive#534). Following
 * the chain manually is therefore the load-bearing part of the SSRF defense,
 * not an implementation detail.
 *
 * That walk previously existed three times — in `AssetDownloader`, in
 * `AssetUtils`, and in {@see GuardedHttpFetcher} — and the copies diverged: two
 * defects fixed in the newest copy were still live in the other two (wordpress-importer-archive#542).
 * This class is the one implementation all three now call, parameterized over
 * the axes that genuinely differ between them:
 *
 * - **verb and sink** — `HEAD` for an existence or destination check, `GET` for
 *   a body, `GET` streamed to a temporary file for an asset download;
 * - **how a refusal is reported** — a console `OutputInterface` mid-import, the
 *   application log with entry context, or nothing at all;
 * - **whether a transport failure is swallowed** — the asset download path
 *   drives its retry and HTTPS-fallback logic off `ConnectionException` and so
 *   must keep seeing it, while link resolution treats an unreachable link as a
 *   link that simply does not resolve.
 *
 * The timeout is a whole-chain budget rather than a per-request one. Applied
 * per hop instead, a single URL could burn
 * `$timeoutSeconds * (MAX_REDIRECT_HOPS + 1)` seconds — a six-fold worst case
 * for an importer that resolves thousands of links in one process.
 */
class GuardedRedirectWalk
{
    /**
     * Builds a walk.
     *
     * @param  int  $timeoutSeconds  Total time budget for the whole chain
     * @param  bool  $wantsBody  Whether to issue GET (true) or HEAD (false)
     * @param  ?string  $sink  Path to stream the response body to, or null to buffer it
     * @param  ?string  $userAgent  User-Agent header to send, or null to send none
     * @param  ?int  $connectTimeoutSeconds  Per-hop connection timeout, or null for the client default
     * @param  ?Closure(GuardedFetchRefusal, string, string, ?string): void  $onRefusal  Called with the reason, the refused URL, the URL the chain started from, and any underlying error message
     * @param  bool  $swallowsTransportFailures  Whether a transport exception ends the walk (true) or propagates to the caller (false)
     */
    public function __construct(
        private readonly int $timeoutSeconds,
        private readonly bool $wantsBody,
        private readonly ?string $sink = null,
        private readonly ?string $userAgent = null,
        private readonly ?int $connectTimeoutSeconds = null,
        private readonly ?Closure $onRefusal = null,
        private readonly bool $swallowsTransportFailures = true,
    ) {}

    /**
     * Walks a URL's redirect chain, re-validating every hop against the egress
     * guard before its request is sent.
     *
     * Returns the terminal hop's URL and response — the caller decides which
     * statuses count as success — or null when a hop targets a non-public
     * address, the chain loops, it exceeds
     * {@see OutboundUrlGuard::MAX_REDIRECT_HOPS}, the whole-chain budget runs
     * out, the `Location` header cannot be parsed, or (when this walk swallows
     * them) the transport fails.
     *
     * A 3xx carrying no `Location` is terminal rather than a failure: there is
     * no next hop to follow, so the response is handed back for the caller to
     * judge, exactly as a 2xx would be.
     *
     * @param  string  $url  The URL to start from
     * @return ?array{url: string, response: Response} The terminal hop, or null
     */
    public function follow(string $url): ?array
    {
        if ($url === '') {
            return null;
        }

        // Track the current hop and the hops already visited so a redirect loop
        // terminates instead of spinning to the hop limit
        $currentUrl = $url;
        $seen = [];

        // Spend one budget across the whole chain, not one per hop. A budget of
        // zero or less means "no timeout", matching what the HTTP client does
        // with the same value — `wordpress-import.asset_timeout` is host
        // configurable, and turning an operator's "unbounded" into "refuse
        // every download before the first request" would be a silent inversion.
        $deadline = $this->timeoutSeconds > 0
            ? microtime(true) + $this->timeoutSeconds
            : null;

        for ($hop = 0; $hop <= OutboundUrlGuard::MAX_REDIRECT_HOPS; $hop++) {
            // Re-validate every hop, not just the first: a redirect can point a
            // public start URL at an internal host. The guard hands back the
            // addresses it validated so this hop connects to those exact
            // addresses rather than resolving the name a second time (wordpress-importer-archive#541).
            $pinnedResolution = OutboundUrlGuard::pinnedResolutionFor($currentUrl);

            if ($pinnedResolution === null) {
                $this->refuse(
                    GuardedFetchRefusal::NonPublicTarget,
                    $currentUrl,
                    $url
                );

                return null;
            }

            // Record this hop before requesting it so a loop is detectable. The loop
            // check reads the key with isset(), which a false value passes as well.
            // @pest-mutate-ignore: TrueToFalse
            $seen[$currentUrl] = true;

            // Give this hop whatever is left of the chain's budget
            $remaining = $deadline === null
                ? null
                : $deadline - microtime(true);

            // The two comparisons differ only at exactly 0.0 seconds left, which a
            // float difference of two microtime() readings does not land on
            // @pest-mutate-ignore: SmallerOrEqualToSmaller
            if (
                $remaining !== null
                && $remaining <= 0.0
            ) {
                $this->refuse(
                    GuardedFetchRefusal::BudgetExhausted,
                    $currentUrl,
                    $url
                );

                return null;
            }

            $response = $this->send(
                $currentUrl,
                $remaining,
                $url,
                $pinnedResolution
            );

            // A swallowed transport failure ends the walk
            if (! $response instanceof Response) {
                return null;
            }

            // A non-redirect response is terminal; let the caller judge it
            if (! $response->redirect()) {
                return [
                    'url' => $currentUrl,
                    'response' => $response,
                ];
            }

            $location = $response->header('Location');

            // A redirect with no target is terminal too — there is nothing to
            // follow, so the caller judges the 3xx as it would any other status
            if ($location === '') {
                return [
                    'url' => $currentUrl,
                    'response' => $response,
                ];
            }

            // Resolve the target against the current URL so relative and
            // scheme-relative Locations are handled correctly. A broken or
            // hostile `Location` is unparseable, and Guzzle's URI parser throws
            // rather than reporting failure — which, left uncaught, aborted the
            // whole import over one bad link.
            try {
                $currentUrl = (string) UriResolver::resolve(
                    new Uri($currentUrl),
                    new Uri($location)
                );
            } catch (Throwable $throwable) {
                $this->refuse(
                    GuardedFetchRefusal::UnparseableLocation,
                    $location,
                    $url,
                    $throwable->getMessage()
                );

                return null;
            }

            // Stop if the chain points back at a URL already visited
            if (isset($seen[$currentUrl])) {
                $this->refuse(
                    GuardedFetchRefusal::RedirectLoop,
                    $currentUrl,
                    $url
                );

                return null;
            }
        }

        // The chain exceeded the hop limit without reaching a final response
        $this->refuse(
            GuardedFetchRefusal::TooManyRedirects,
            $currentUrl,
            $url
        );

        return null;
    }

    /**
     * Issues one hop's request with automatic redirect-following disabled, so
     * each 3xx comes back to {@see self::follow()} and is re-checked before the
     * next send.
     *
     * Returns null only when a transport failure was swallowed; when this walk
     * propagates them, the exception reaches the caller instead.
     *
     * @param  string  $currentUrl  The URL of this hop
     * @param  ?float  $remainingBudget  Seconds left in the whole-chain budget, or null when the walk is unbounded
     * @param  string  $originalUrl  The URL the chain started from
     * @param  list<string>  $pinnedResolution  `CURLOPT_RESOLVE` entries pinning the host to the addresses the guard validated
     * @return ?Response The hop's response, or null if a swallowed transport failure ended the walk
     */
    private function send(
        string $currentUrl,
        ?float $remainingBudget,
        string $originalUrl,
        array $pinnedResolution
    ): ?Response {
        // Bound this hop by whatever is left of the chain's budget, never by a
        // fresh full timeout
        $options = ['allow_redirects' => false];

        // Pin the name to the addresses the guard just validated, so the client
        // does not resolve it again and reach somewhere else. This rides on the
        // cURL handler; a non-cURL handler ignores it and falls back to the
        // front-door check alone.
        //
        // `CURLOPT_RESOLVE` comes from ext-curl, which neither Guzzle nor
        // Laravel hard-requires — Guzzle falls back to the stream handler when
        // it is absent. Referencing the constant unguarded would raise an
        // `Error` (not an `Exception`, so the download retry ladder would not
        // catch it) and abort the import on the first asset, in exactly the
        // environment where pinning is meant to be merely inert.
        if (
            $pinnedResolution !== []
            && \defined('CURLOPT_RESOLVE')
        ) {
            $options['curl'] = [\CURLOPT_RESOLVE => $pinnedResolution];
        }

        // Zero is the HTTP client's own "no timeout", so an unbounded walk
        // passes it through rather than inventing a bound
        $request = Http::timeout(
            $remainingBudget === null ? 0 : (int) ceil($remainingBudget)
        )->withOptions($options);

        if ($this->connectTimeoutSeconds !== null) {
            $request = $request->connectTimeout($this->connectTimeoutSeconds);
        }

        if ($this->sink !== null) {
            $request = $request->sink($this->sink);
        }

        if ($this->userAgent !== null) {
            $request = $request->withHeaders(['User-Agent' => $this->userAgent]);
        }

        try {
            return $this->wantsBody
                ? $request->get($currentUrl)
                : $request->head($currentUrl);
        } catch (Throwable $throwable) {
            // The asset download path reads `ConnectionException` to decide
            // whether to retry and whether to fall back from HTTPS to HTTP, so
            // swallowing it there would silently disable both
            if (! $this->swallowsTransportFailures) {
                throw $throwable;
            }

            $this->refuse(
                GuardedFetchRefusal::TransportFailure,
                $currentUrl,
                $originalUrl,
                $throwable->getMessage()
            );

            return null;
        }
    }

    /**
     * Reports a refused or failed hop through whatever reporter the caller
     * supplied, if any.
     *
     * The reason is handed over as a case rather than a sentence so each call
     * site phrases its own message — see {@see GuardedFetchRefusal}.
     *
     * @param  GuardedFetchRefusal  $reason  Why the chain was abandoned
     * @param  string  $rejectedUrl  The URL of the hop that was refused
     * @param  string  $originalUrl  The URL the chain started from
     * @param  ?string  $detail  The underlying error message, where there is one
     */
    private function refuse(
        GuardedFetchRefusal $reason,
        string $rejectedUrl,
        string $originalUrl,
        ?string $detail = null
    ): void {
        if (! $this->onRefusal instanceof Closure) {
            return;
        }

        ($this->onRefusal)(
            $reason,
            $rejectedUrl,
            $originalUrl,
            $detail
        );
    }
}
