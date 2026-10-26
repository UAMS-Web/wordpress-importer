<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads the UAMS WordPress migration API.
 *
 * The contract is documented in `UAMS-Web/uamswp-migration-api` and is read from there
 * rather than inferred: REST namespace `uamswp-migration/v1`, a shared secret presented in
 * the `X-UAMSWP-Migration-Key` header (its D-009), `?page=` / `?per_page=` bounded at 200
 * with `X-WP-Total` and `X-WP-TotalPages` on the response, and `?site=` selecting the
 * network site to read (its D-011).
 *
 * **Ordering is the API's guarantee, and this client must not undo it.** Every paginated
 * endpoint orders by immutable ID rather than date (its D-006), because a migration pages
 * through a live site while editors are working: under date ordering a record moves
 * between pages mid-run and is read twice or skipped, and a skipped record produces no
 * error anywhere. Records are therefore returned in the order received, never re-sorted.
 */
class Client
{
    /**
     * The REST namespace every route hangs off.
     */
    private const string NAMESPACE = 'uamswp-migration/v1';

    /**
     * The header carrying the shared secret.
     */
    private const string SECRET_HEADER = 'X-UAMSWP-Migration-Key';

    /**
     * The API's own maximum page size; asking for more is silently clamped there.
     */
    private const int MAX_PER_PAGE = 200;

    /**
     * The most pages a single walk will read before refusing to continue.
     *
     * Only reachable when every page comes back full and the API reports no total — an
     * endpoint ignoring `page` would otherwise loop forever. At the maximum page size this
     * is two million records, well beyond any collection this importer will meet.
     */
    private const int MAX_PAGES = 10000;

    /**
     * Statuses worth trying again, because the same request may succeed unchanged.
     *
     * A WordPress maintenance window answers `503` for as long as the `.maintenance` file
     * exists, which is usually seconds; a gateway answers `502`/`504` while an upstream
     * restarts; `429` is a rate limiter asking for less, not for something different.
     * Every other status describes the request rather than the moment, so repeating it
     * changes nothing and delays the message that would have fixed it.
     */
    private const array RETRYABLE_STATUSES = [429, 502, 503, 504];

    /**
     * Total attempts for one request, the first included.
     */
    private const int MAX_ATTEMPTS = 4;

    /**
     * The longest this client will spend waiting across all retries of one request.
     *
     * **This is the ceiling that makes reading `Retry-After` safe.** The header is a
     * number chosen by the server, and WordPress core's maintenance path sends 600 — ten
     * minutes of silence on a command that otherwise prints progress. A budget means an
     * unreasonable value is reported rather than obeyed, and it bounds whatever an
     * intermediate proxy or a future core release decides to send.
     */
    private const int RETRY_BUDGET_SECONDS = 30;

    /**
     * The first backoff, doubled on each subsequent attempt.
     */
    private const int FIRST_BACKOFF_MS = 1000;

    /**
     * What a retry of the last request is worth saying in its error message.
     *
     * Reset at the start of every request, so it never describes an earlier one.
     */
    private string $retryNote = '';

    /**
     * Create a client.
     *
     * @param  string  $baseUrl  The WordPress site root, without the REST path
     * @param  string  $secret  The shared secret for `X-UAMSWP-Migration-Key`
     * @param  int  $perPage  Page size to request, clamped to the API's maximum
     * @param  int  $timeout  Per-request timeout in seconds
     * @param  \Closure|null  $onRetry  Called with one line of prose before each retry sleep
     * @param  \Closure|null  $onPage  Called with one line of prose as each page is read
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secret,
        private readonly int $perPage = 100,
        private readonly int $timeout = 30,
        private readonly ?\Closure $onRetry = null,
        private readonly ?\Closure $onPage = null,
    ) {}

    /**
     * Build a client from the address and key an operator supplies at the start of a run.
     *
     * **The credential is never stored, and that is the design rather than an omission.**
     * The estate is 26 separate multisite networks, and each issues its own token from its
     * own admin page — decided as option D on `uamswp-migration-api-archive#140`. Holding 26 of
     * those in configuration, or one at a time in an environment variable edited between
     * runs, puts a live credential on disk for every network the migration will ever
     * touch. An operator pasting the key for the one network they are importing, at the
     * moment they import it, leaves nothing behind when the process exits.
     *
     * **The address travels with it rather than staying in configuration**, so the network
     * and the credential that authenticates against it are chosen in one act. Split across
     * two places they can disagree, and a disagreement is a `401` at best — the token is
     * network-scoped, stored per network by the plugin — and at worst a silent import of
     * the wrong network's content, which is what `wordpress-importer-archive#982` was filed about.
     *
     * @param  string  $baseUrl  The network's WordPress root, as the operator supplied it
     * @param  string  $key  That network's API key, as the operator supplied it
     * @return self A client bound to exactly that network
     *
     * @throws MigrationApiException When the address is not an absolute `https://` URL with
     *                               a host, or the key is empty
     */
    public static function forRun(string $baseUrl, string $key): self
    {
        $trimmedUrl = \trim($baseUrl);
        $trimmedKey = \trim($key);

        $scheme = \parse_url($trimmedUrl, PHP_URL_SCHEME);
        $host = \parse_url($trimmedUrl, PHP_URL_HOST);

        // Refuse anything but `https`, and refuse it here rather than at request time.
        // `withBaseUrl()` below pins a *response's* scheme to this one, so a run started on
        // `http://` would pin every later hop to cleartext too — the guard that exists to
        // protect the credential would instead be enforcing its exposure. Nothing validated
        // the scheme before this, which was defensible while it was reviewed once in a
        // config file and is not once it is typed at a prompt on every run.
        if ($scheme !== 'https') {
            throw new MigrationApiException(\sprintf(
                'The migration API address must be an absolute `https://` URL, got "%s"%s. '
                .'Refusing to send the API key over a scheme that would put it on the wire '
                .'in cleartext.',
                $trimmedUrl,
                \is_string($scheme) ? ' (scheme "'.$scheme.'")' : ''
            ));
        }

        if (! \is_string($host) || $host === '') {
            throw new MigrationApiException(\sprintf(
                'The migration API address "%s" has no host. Refusing to send the API key to '
                .'an address that cannot be identified.',
                $trimmedUrl
            ));
        }

        // An empty key is a setup error, not an anonymous request. The API answers `401` to
        // a missing header, so letting it through would report the operator's slip as an
        // authentication failure against a network that may be perfectly reachable.
        if ($trimmedKey === '') {
            throw new MigrationApiException(
                'No migration API key was supplied. Generate one for this network from the '
                .'plugin admin page, then paste it when the import asks for it.'
            );
        }

        $perPage = config('wordpress-import.migration_api.per_page', 100);

        // `env()` returns strings, so a host writing `env('…_PER_PAGE', 100)` without an
        // `(int)` cast hands over "150". Accepting only a real int would silently ignore
        // it and page at the default — a configured value quietly not taking effect.
        if (! \is_numeric($perPage)) {
            throw new MigrationApiException(\sprintf(
                'The migration API page size must be a number, got %s. Check '
                .'`wordpress-import.migration_api.per_page` in the host application.',
                \get_debug_type($perPage)
            ));
        }

        // A whole number of at least 1, and nothing else. The API refuses any other
        // `per_page` with `400 rest_invalid_param` (its D-084), where it used to rewrite it
        // and answer `200`. So a host configured with `0`, `-5` or `1.5` would fail on the
        // first paginated read. Refusing here names the configuration key instead (wordpress-importer-archive#1213).
        // FILTER_VALIDATE_INT accepts the string "150" that `env()` hands over, and refuses
        // "1.5" and 1.5, which a cast would silently truncate to 1.
        $validated = \filter_var($perPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($validated === false) {
            throw new MigrationApiException(\sprintf(
                'The migration API page size must be a whole number of at least 1, got %s. Check '
                .'`wordpress-import.migration_api.per_page` in the host application.',
                \var_export($perPage, true)
            ));
        }

        return new self(
            \rtrim($trimmedUrl, '/'),
            $trimmedKey,
            $validated,
        );
    }

    /**
     * A client for the same API at a different site's REST root.
     *
     * A multisite serves the migration API from every site's own root, and addressing a
     * site that way is not merely an alternative to `?site=` — for the discovery endpoints
     * it is the only thing that works. `post-types` and `taxonomies` are derived from
     * process-global registration, so the API refuses to answer them for another site
     * (`uamswp_migration_api_registration_is_not_site_scoped`). See wordpress-importer-archive#671.
     *
     * **The scheme is pinned to the configured base URL's.** This URL comes from a response
     * rather than from configuration, so it is the one place where content chooses where the
     * credential goes. wordpress-importer-archive#661 cleared this client on the grounds that `baseUrl` was
     * configuration and no path could change the host; this method moves that boundary, so
     * it restores the guarantee rather than leaving it lapsed.
     *
     * @param  string  $baseUrl  The site's own root, as `/sites` reports it in `link`
     * @return self A client carrying the same credential and page size
     *
     * @throws MigrationApiException When the root downgrades the scheme or has no host
     */
    public function withBaseUrl(
        string $baseUrl
    ): self {
        $trimmed = \trim($baseUrl);

        $scheme = \parse_url($trimmed, PHP_URL_SCHEME);
        $host = \parse_url($trimmed, PHP_URL_HOST);
        $configured = \parse_url($this->baseUrl, PHP_URL_SCHEME);

        // The credential travels to this URL, and this URL came from a *response* rather
        // than from configuration. Pin the scheme to the configured one: a `/sites` payload
        // answering `http://` would otherwise put `X-UAMSWP-Migration-Key` on the wire in
        // cleartext, and nothing would surface it — the operator configured `https://` and
        // has no view of what actually left.
        //
        // The host is deliberately NOT pinned. WordPress multisite supports domain mapping,
        // so a legitimate site's root can be an entirely different domain, and requiring a
        // match would break the case this method exists for.
        if (! \is_string($scheme) || $scheme !== $configured) {
            throw new MigrationApiException(\sprintf(
                'The migration API reported a site root of "%s", which is %s rather than '
                .'"%s" as configured. Refusing to send the shared secret over a scheme the '
                .'operator did not choose.',
                $trimmed,
                \is_string($scheme) ? '"'.$scheme.'"' : 'not an absolute URL',
                \is_string($configured) ? $configured : '(none)'
            ));
        }

        if (! \is_string($host) || $host === '') {
            throw new MigrationApiException(\sprintf(
                'The migration API reported a site root of "%s", which has no host. Refusing '
                .'to send the shared secret to an address that cannot be identified.',
                $trimmed
            ));
        }

        return new self(
            \rtrim($trimmed, '/'),
            $this->secret,
            $this->perPage,
            $this->timeout,
            $this->onRetry,
            $this->onPage,
        );
    }

    /**
     * Fetch a single page from an endpoint.
     *
     * @param  string  $path  The route beneath the namespace, e.g. `posts/page`
     * @param  int|null  $site  The network site id to read, when scoping is needed
     * @param  array<string, scalar|list<scalar>>  $query  Additional query parameters
     * @return array<int|string, mixed> The decoded response body
     *
     * @throws MigrationApiException On any transport failure, non-2xx, or malformed body
     */
    public function get(
        string $path,
        ?int $site = null,
        array $query = []
    ): array {
        return $this->decode(
            $this->request($path, $site, $query),
            $path
        );
    }

    /**
     * Fetch every page of a collection endpoint.
     *
     * **Termination does not depend on `X-WP-Total`,** and that is the point. Pages while
     * each response comes back full, which is knowable without any header; a short page is
     * the end of the collection. Where the API does report a total it is used as a
     * cross-check, and a read that falls short of it raises rather than returning a partial
     * set that reads as complete.
     *
     * Reading the total as the loop condition is what wordpress-importer-archive#677 was: an absent or non-numeric
     * `X-WP-Total` ended the walk after one page *and* disabled the short-read guard, so the
     * one input the guard could not see was the one that needed it.
     *
     * @param  string  $path  The route beneath the namespace, e.g. `posts/page`
     * @param  int|null  $site  The network site id to read, when scoping is needed
     * @param  array<string, scalar|list<scalar>>  $query  Additional query parameters
     * @return array<int, mixed> Every record, in the order the API returned them
     *
     * @throws MigrationApiException On any transport failure, non-2xx, or malformed body
     */
    public function paginate(
        string $path,
        ?int $site = null,
        array $query = []
    ): array {
        $records = [];
        $page = 1;
        $total = null;
        $totalPages = null;
        $pageSize = $this->pageSize();

        do {
            $response = $this->request(
                $path,
                $site,
                // `array_merge`, not `+`: with `+` the left operand wins, so a caller
                // passing `page` would pin the walk to one page.
                \array_merge($query, ['page' => $page, 'per_page' => $pageSize])
            );

            $body = $this->decode($response, $path);

            // The first page reports the size of the whole collection, where the endpoint
            // reports it at all.
            if ($page === 1) {
                $header = $response->header('X-WP-Total');
                $total = \is_numeric($header) ? (int) $header : null;

                $pagesHeader = $response->header('X-WP-TotalPages');
                $totalPages = \is_numeric($pagesHeader) ? (int) $pagesHeader : null;
            }

            $count = \count($body);

            foreach ($body as $record) {
                $records[] = $record;
            }

            // Announce the page BEFORE the termination checks below, so the last page of a
            // walk is reported like every other one. Emitting after them would silence
            // exactly the single-page case — the smallest site, where the whole walk is one
            // page and one line is the entire output.
            $this->announcePage($path, $page, \count($records));

            // A page that comes back SHORT is the end of the collection, whatever the
            // headers did or did not say. This is the termination condition, deliberately,
            // because it is knowable without any header — and an absent `X-WP-Total` used
            // to end the walk after page one and silently return a fraction (wordpress-importer-archive#677).
            if ($count < $pageSize) {
                break;
            }

            // A known total that has been reached ends it too, without spending a request
            // to discover an empty page.
            if ($total !== null && \count($records) >= $total) {
                break;
            }

            $page++;

            // A server that answers every page with a full one — an endpoint ignoring
            // `page`, say — would otherwise loop forever. Bound it by what the API itself
            // reported, or by a ceiling far beyond any real collection.
            if ($totalPages !== null && $page > $totalPages) {
                break;
            }

            if ($page > self::MAX_PAGES) {
                throw new MigrationApiException(\sprintf(
                    'The migration API served %d full pages for "%s" without ending. '
                    .'Refusing to keep reading.',
                    self::MAX_PAGES,
                    $path
                ));
            }
        } while (true);

        // A short read is the silent-truncation failure this client exists to prevent, so
        // it raises rather than handing back a partial set that looks complete.
        if ($total !== null && \count($records) !== $total) {
            throw new MigrationApiException(\sprintf(
                'The migration API reported %d records for "%s" but served %d. Refusing to '
                .'treat a partial read as complete.',
                $total,
                $path,
                \count($records)
            ));
        }

        return $records;
    }

    /**
     * Issue a request, converting a transport failure into an exception.
     *
     * @param  string  $path  The route beneath the namespace
     * @param  int|null  $site  The network site id to read
     * @param  array<string, scalar|list<scalar>>  $query  Query parameters
     * @return Response The HTTP response
     *
     * @throws MigrationApiException When the endpoint is unreachable
     */
    private function request(
        string $path,
        ?int $site,
        array $query
    ): Response {
        // Site scoping is a query parameter the API reads before authorization, so a
        // capability check applies to the site being read rather than the one serving.
        if ($site !== null) {
            $query['site'] = $site;
        }

        $url = $this->baseUrl.'/wp-json/'.self::NAMESPACE.'/'.\ltrim($path, '/');

        // Retry telemetry for this request alone. It is reset here rather than reused so a
        // message can never describe an earlier request's retries.
        $this->retryNote = '';
        $waited = 0;
        $failures = 0;
        $askedFor = null;

        try {
            $response = Http::withHeaders([self::SECRET_HEADER => $this->secret])
                ->timeout($this->timeout)
                // `throw: false` is what keeps the existing error surface intact. The
                // framework throws between attempts to trigger a retry, and on the final
                // attempt this flag stops it throwing out — so an exhausted retry returns
                // the Response and `decode()` reports it exactly as it always has.
                ->retry(
                    self::MAX_ATTEMPTS,
                    function (int $attempt, mixed $exception) use (&$waited, $path): int {
                        $delay = $this->backoffMilliseconds($attempt, $exception, $waited);
                        $waited += $delay;
                        $this->announceRetry($path, $exception, $delay);

                        return $delay;
                    },
                    function (mixed $exception) use (&$waited, &$failures, &$askedFor): bool {
                        $failures++;

                        return $this->shouldRetry($exception, $waited, $askedFor);
                    },
                    throw: false
                )
                // Do NOT follow redirects. This request carries the shared secret, and
                // Guzzle strips only `Authorization` and `Cookie` on a cross-origin hop —
                // a custom header rides along to whatever host the `Location` names. One
                // redirect would hand a full-read credential to a third party, permit an
                // https-to-http downgrade, and let the target choose the JSON that gets
                // imported. A 3xx is surfaced below instead (wordpress-importer-archive#678).
                ->withoutRedirecting()
                ->acceptJson()
                ->get($url, $query);

            $this->retryNote = $this->retrySummary($response, $failures, $waited, $askedFor);

            return $response;
        } catch (ConnectionException $connectionException) {
            throw new MigrationApiException(
                \sprintf('The migration API at "%s" could not be reached.', $url),
                0,
                $connectionException
            );
        }
    }

    /**
     * Decode a response body, rejecting anything that is not a successful JSON array.
     *
     * @param  Response  $response  The HTTP response
     * @param  string  $path  The route, for the error message
     * @return array<int|string, mixed> The decoded body
     *
     * @throws MigrationApiException On a redirect, a non-2xx status, or a body that is not JSON
     */
    private function decode(
        Response $response,
        string $path
    ): array {
        // A redirect is not `failed()` — that is `serverError() || clientError()` — so it
        // would otherwise fall through to the JSON check and be reported as a malformed
        // body, which names the wrong problem and hides the interesting one.
        if ($response->redirect()) {
            $location = $response->header('Location');

            throw new MigrationApiException(\sprintf(
                'The migration API redirected "%s" to "%s". Refusing to follow it: this '
                .'request carries the shared secret, and a redirect can send it to another '
                .'host or downgrade the scheme. Configure the final URL as the base URL '
                .'instead.',
                $path,
                $location === '' ? '(no Location header)' : $location
            ));
        }

        if ($response->failed()) {
            // The API names its own failures — `uamswp_migration_api_unknown_post_type`,
            // `…_type_not_registered_here` — so surface the code rather than the status
            // alone, since the two point at different fixes.
            $code = $response->json('code');

            throw MigrationApiException::fromErrorResponse(\sprintf(
                'The migration API returned HTTP %d for "%s"%s.%s',
                $response->status(),
                $path,
                \is_string($code) ? ' ('.$code.')' : '',
                $this->explain($response).$this->retryNote
            ), \is_string($code) ? $code : null);
        }

        $decoded = $response->json();

        if (! \is_array($decoded)) {
            throw new MigrationApiException(\sprintf(
                'The migration API returned a body for "%s" that is not JSON.',
                $path
            ));
        }

        return $decoded;
    }

    /**
     * A client that narrates its retries.
     *
     * The client has no console of its own, and a retry that waits silently is
     * indistinguishable from a command that has hung — which is the failure the backoff
     * is otherwise introducing. The caller supplies somewhere to say so.
     *
     * @param  \Closure  $onRetry  Called with one line of prose before each retry sleep
     * @return self A client carrying the same credential, page size and reporter
     */
    public function withRetryReporter(
        \Closure $onRetry
    ): self {
        return new self(
            $this->baseUrl,
            $this->secret,
            $this->perPage,
            $this->timeout,
            $onRetry,
            $this->onPage,
        );
    }

    /**
     * A client that narrates each page of a paginated walk.
     *
     * `paginate()` blocks until a whole collection is read — 137 pages and 170 MB on the
     * largest network in the estate — and emits nothing while it does, so a working run is
     * indistinguishable from a stalled one. Retrying a transient failure lengthens that
     * silence rather than shortening it, which is what makes this worth emitting.
     *
     * @param  \Closure  $onPage  Called with one line of prose as each page is read
     * @return self A client carrying the same credential, page size and retry reporter
     */
    public function withProgressReporter(
        \Closure $onPage
    ): self {
        return new self(
            $this->baseUrl,
            $this->secret,
            $this->perPage,
            $this->timeout,
            $this->onRetry,
            $onPage,
        );
    }

    /**
     * Whether a failed attempt is worth repeating unchanged.
     *
     * @param  mixed  $exception  The failure for this attempt, or null where there is none
     * @param  int  $waited  Milliseconds already spent sleeping on this request
     * @param  int|null  $askedFor  Set to the `Retry-After` that exceeded the budget
     * @return bool Whether to try again
     */
    private function shouldRetry(
        mixed $exception,
        int $waited,
        ?int &$askedFor
    ): bool {
        // Null arrives here for a REDIRECT: a 3xx is not `successful()`, so the framework
        // consults this callback, and it is not `failed()` either, so it has no exception to
        // pass. Refusing anything that is not a request failure covers that and the transport
        // case together — `request()` already turns a transport fault into a message naming
        // the address, and repeating a DNS or TLS failure makes a clear misconfiguration slow.
        if (! $exception instanceof RequestException) {
            return false;
        }

        if (! \in_array($exception->response->status(), self::RETRYABLE_STATUSES, true)) {
            return false;
        }

        $remaining = self::RETRY_BUDGET_SECONDS * 1000 - $waited;

        if ($remaining <= 0) {
            return false;
        }

        $retryAfter = $this->retryAfterSeconds($exception->response);

        // The header is information, not an instruction. When the server asks for longer
        // than the whole budget, stopping now and reporting the number is more useful than
        // either obeying it — minutes of silence — or ignoring it and hammering a host that
        // has said it is unavailable.
        if ($retryAfter !== null && $retryAfter * 1000 > $remaining) {
            $askedFor = $retryAfter;

            return false;
        }

        return true;
    }

    /**
     * How long to wait before the next attempt, in milliseconds.
     *
     * @param  int  $attempt  The attempt just completed, counting from one
     * @param  mixed  $exception  The failure that attempt raised, narrowed here
     * @param  int  $waited  Milliseconds already spent sleeping on this request
     * @return int Milliseconds to sleep, never beyond the remaining budget
     */
    private function backoffMilliseconds(
        int $attempt,
        mixed $exception,
        int $waited
    ): int {
        $remaining = \max(0, self::RETRY_BUDGET_SECONDS * 1000 - $waited);

        // A server that named a delay knows better than the arithmetic does. `shouldRetry()`
        // has already refused anything longer than the budget, so this can only shorten or
        // lengthen the wait within it.
        if ($exception instanceof RequestException) {
            $retryAfter = $this->retryAfterSeconds($exception->response);

            if ($retryAfter !== null) {
                return \min($retryAfter * 1000, $remaining);
            }
        }

        return (int) \min(self::FIRST_BACKOFF_MS * 2 ** ($attempt - 1), $remaining);
    }

    /**
     * The `Retry-After` delay, where the server sent one this client can act on.
     *
     * Only the delta-seconds form is read. The header may also carry an HTTP-date, which
     * is not interpreted here — that returns null and the computed backoff applies, which
     * is the same behavior as no header at all rather than a wrong wait.
     *
     * @param  Response  $response  The failed response
     * @return int|null Seconds the server asked for, or null when it named none
     */
    private function retryAfterSeconds(
        Response $response
    ): ?int {
        $header = \trim($response->header('Retry-After'));

        if ($header === '' || ! \ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }

    /**
     * Say that a retry is about to wait, where the caller gave somewhere to say it.
     *
     * @param  string  $path  The route being read
     * @param  mixed  $exception  The failure being retried, narrowed here
     * @param  int  $delay  Milliseconds about to be slept
     */
    private function announceRetry(
        string $path,
        mixed $exception,
        int $delay
    ): void {
        if (! $this->onRetry instanceof \Closure) {
            return;
        }

        $status = $exception instanceof RequestException
            ? (string) $exception->response->status()
            : 'a transport failure';

        ($this->onRetry)(\sprintf(
            'The migration API answered %s for "%s". Waiting %s before trying again.',
            $status,
            $path,
            $this->humanize($delay)
        ));
    }

    /**
     * What the retries are worth appending to a failure message.
     *
     * @param  Response  $response  The response the attempts ended on
     * @param  int  $failures  Failed attempts made
     * @param  int  $waited  Milliseconds spent sleeping
     * @param  int|null  $askedFor  A `Retry-After` that exceeded the budget, where one did
     * @return string The appended sentence, empty when nothing was retried
     */
    private function retrySummary(
        Response $response,
        int $failures,
        int $waited,
        ?int $askedFor
    ): string {
        if (! $response->failed()) {
            return '';
        }

        // The server named a delay this run will not wait out. Reporting the number is what
        // lets an operator decide to come back rather than guess at the outage.
        if ($askedFor !== null) {
            return \sprintf(
                " The server asked for %ds via Retry-After, which exceeds this client's %ds "
                .'retry budget, so the run stopped rather than waiting it out. Re-run once '
                .'the window has passed.',
                $askedFor,
                self::RETRY_BUDGET_SECONDS
            );
        }

        if ($failures < 2) {
            return '';
        }

        return \sprintf(
            ' Gave up after %d attempts over %s of backoff.',
            $failures,
            $this->humanize($waited)
        );
    }

    /**
     * A duration in milliseconds, written the way an operator reads one.
     *
     * @param  int  $milliseconds  The duration
     * @return string The duration in seconds, without a trailing zero
     */
    private function humanize(
        int $milliseconds
    ): string {
        return \rtrim(\rtrim(\number_format($milliseconds / 1000, 1), '0'), '.').'s';
    }

    /**
     * Say that a page has been read, where the caller gave somewhere to say it.
     *
     * **No total is quoted, deliberately.** `X-WP-Total` is absent on some endpoints, and
     * the `counts.by_post_type` figure an operator might expect to see is a table inventory
     * rather than a fetch plan — it over-reports what the endpoint will actually serve. A
     * running count is true whatever the headers did, which is the property that matters in
     * output whose whole job is to be believed.
     *
     * @param  string  $path  The route being walked, which is the post type for a content walk
     * @param  int  $page  The page just read, counting from one
     * @param  int  $records  Records accumulated so far across this walk
     */
    private function announcePage(
        string $path,
        int $page,
        int $records
    ): void {
        if (! $this->onPage instanceof \Closure) {
            return;
        }

        ($this->onPage)(\sprintf(
            'Reading %s - page %d, %d record%s so far.',
            $path,
            $page,
            $records,
            $records === 1 ? '' : 's'
        ));
    }

    /**
     * The API's own account of what went wrong, where it gives one.
     *
     * **The plugin writes messages that contain the fix, and dropping them wastes that.**
     * `uamswp_migration_api_registration_is_not_site_scoped` said to address the site's own
     * REST root, and that sentence resolved wordpress-importer-archive#671 in one command without anyone reading the
     * plugin source. A malformed `modified_after` likewise answers with the exact formats it
     * accepts. Reporting only the code turns both into a generic WordPress identifier.
     *
     * Per-parameter guidance is included because that is where the actionable sentence
     * lives: WordPress puts a summary in `message` ("Invalid parameter(s): modified_after")
     * and the useful text in `data.params.<name>`.
     *
     * @param  Response  $response  The failed response
     * @return string The appended explanation, empty when the API offered none
     */
    private function explain(
        Response $response
    ): string {
        $parts = [];

        $message = $response->json('message');

        if (\is_string($message) && \trim($message) !== '') {
            $parts[] = \trim($message);
        }

        $params = $response->json('data.params');

        if (\is_array($params)) {
            foreach ($params as $name => $detail) {
                if (\is_string($name) && \is_string($detail) && \trim($detail) !== '') {
                    $parts[] = $name.': '.\trim($detail);
                }
            }
        }

        return $parts === [] ? '' : ' '.\implode(' ', $parts);
    }

    /**
     * The number of records one page of a walk carries.
     *
     * A caller comparing the cost of two reads needs it: a read by id is one request per
     * chunk, and a whole-collection read is one per page of this size (wordpress-importer-archive#1175).
     *
     * @return int<1, max> The page size
     */
    public function recordsPerPage(): int
    {
        return $this->pageSize();
    }

    /**
     * The page size to request, clamped to what the API will serve.
     *
     * Asking for more than the maximum is clamped server-side, which would make the
     * pagination arithmetic disagree with what arrives.
     *
     * @return int<1, max> The page size
     */
    private function pageSize(): int
    {
        return \max(1, \min($this->perPage, self::MAX_PER_PAGE));
    }
}
