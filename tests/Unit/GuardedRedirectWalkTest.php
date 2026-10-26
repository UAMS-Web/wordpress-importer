<?php

declare(strict_types=1);

/**
 * Unit tests for the shared guarded redirect walk (wordpress-importer-archive#542).
 *
 * The walk is the one implementation the asset download, existence-check and
 * link-resolution paths all reach, so the properties asserted here are the ones
 * that used to be true in only one of the three copies: a whole-chain time
 * budget rather than a per-hop one, a malformed `Location` reported as a failed
 * fetch rather than thrown, and a transport-failure policy the caller chooses.
 *
 * IP literals are used throughout so the suite never performs a DNS lookup and
 * stays offline.
 *
 * @command  php vendor/bin/pest tests/Unit/GuardedRedirectWalkTest.php
 */

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\GuardedFetchRefusal;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\GuardedRedirectWalk;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\OutboundUrlGuard;
use UAMSWeb\WordPressImporter\Tests\LaravelHttpTestCase;

uses(LaravelHttpTestCase::class);

afterEach(function (): void {
    // Restore the system resolver, or a fake leaks into every later test
    OutboundUrlGuard::resolveUsing(null);
});

// A routable public address the guard permits, used as the "allowed" target
const WALK_START_URL = 'https://93.184.216.34/start';

// A second routable public address, for legitimate cross-host redirects
const WALK_SECOND_URL = 'https://8.8.8.8/second';

/**
 * Installs a terminal Guzzle middleware that answers requests from a table and
 * records the `CURLOPT_RESOLVE` entries each one carried.
 *
 * `Http::fake()` cannot be used for this. Its stub handler is pushed last and
 * so sits outermost in the stack, short-circuiting every user middleware
 * beneath it — and the stub itself never sees Guzzle's `$options`, which is
 * exactly where the pin lives. Answering from the innermost middleware instead
 * keeps the request off the network while still observing the options the
 * client was about to connect with.
 *
 * @param  array<string, array{int, array<string, string>}>  $responses  URL => [status, headers]; anything unlisted answers 200
 * @param  list<mixed>  $captured  Filled in with the requested option per request, or null where it carried none
 * @param  string  $option  `pin` for the `CURLOPT_RESOLVE` entries, otherwise a Guzzle option key such as `timeout`
 */
function captureRequestOptions(
    array $responses,
    array &$captured,
    string $option = 'pin'
): void {
    // The outer callable must be a full closure, not an arrow function: an
    // arrow function captures by value, so a nested `use (&$captured)` would
    // bind a reference to its *copy* and every write would be discarded — which
    // reads exactly like middleware that never ran.
    Http::globalMiddleware(
        static function (callable $handler) use ($responses, &$captured, $option): Closure {
            return static function (
                RequestInterface $request,
                array $options
            ) use ($responses, &$captured, $option): PromiseInterface {
                if ($option === 'pin') {
                    $curl = $options['curl'] ?? null;

                    $captured[] = \is_array($curl)
                        ? ($curl[CURLOPT_RESOLVE] ?? null)
                        : null;
                } else {
                    $captured[] = $options[$option] ?? null;
                }

                [$status, $headers] = $responses[(string) $request->getUri()] ?? [200, []];

                return Create::promiseFor(new Psr7Response($status, $headers));
            };
        }
    );
}

/**
 * Collects the refusals a walk reports, so a test can assert on the case rather
 * than on any one call site's rendered wording.
 *
 * @param  list<GuardedFetchRefusal>  $cases  Filled in as the walk reports
 * @return Closure(GuardedFetchRefusal, string, string, ?string): void
 */
function collectRefusals(array &$cases): Closure
{
    return static function (
        GuardedFetchRefusal $reason,
        string $rejectedUrl,
        string $originalUrl,
        ?string $detail
    ) use (&$cases): void {
        $cases[] = $reason;
    };
}

it('spends one time budget across the whole chain, not one per hop', function (): void {
    // The first hop consumes the entire budget before redirecting. Under a
    // per-hop timeout the second hop would still be sent with a fresh full
    // budget — which is exactly the six-fold worst case wordpress-importer-archive#542 closes.
    Http::fake(function (Request $request) {
        if ($request->url() === WALK_START_URL) {
            usleep(1_050_000);

            return Http::response('', 302, ['Location' => WALK_SECOND_URL]);
        }

        return Http::response('SHOULD NEVER BE REACHED', 200);
    });

    $cases = [];

    $result = (new GuardedRedirectWalk(
        timeoutSeconds: 1,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
    ))->follow(WALK_START_URL);

    expect($result)->toBeNull();
    expect($cases)->toBe([GuardedFetchRefusal::BudgetExhausted]);

    // The second hop is never sent — the budget was already spent
    Http::assertSentCount(1);
    Http::assertNotSent(
        static fn (Request $request): bool => $request->url() === WALK_SECOND_URL
    );
});

it('treats a budget of zero as no timeout, not as an exhausted one', function (
    int $budget
): void {
    // `wordpress-import.asset_timeout` is host configurable and 0 is the HTTP
    // client's own "no timeout". Turning an operator's "unbounded" into "refuse
    // every download before the first request" would be a silent inversion.
    Http::fake(['*' => Http::response('body', 200)]);

    $cases = [];

    $result = (new GuardedRedirectWalk(
        timeoutSeconds: $budget,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
    ))->follow(WALK_START_URL);

    expect($result)->not->toBeNull();
    expect($cases)->toBe([]);

    Http::assertSentCount(1);
})->with([
    'zero' => [0],
    'negative' => [-1],
]);

it('narrows each hop timeout to what is left of the chain budget', function (): void {
    // The whole-chain property has two halves: refusing once the budget is gone,
    // and never handing a later hop a fresh full timeout. Only the Guzzle
    // options show the second half.
    //
    // The first hop must burn more than a whole second, or `(int) ceil()` rounds
    // both hops to the same number and the assertion below passes whether the
    // narrowing happens or not. It burns 1.6s, so the second hop has about 28.4s
    // left: rounded up that is 29, where rounding to nearest would give 28.
    $timeouts = [];

    Http::globalMiddleware(
        static function (callable $handler) use (&$timeouts): Closure {
            return static function (
                RequestInterface $request,
                array $options
            ) use (&$timeouts): PromiseInterface {
                $timeouts[] = $options['timeout'] ?? null;

                if ((string) $request->getUri() === 'https://93.184.216.34/a') {
                    usleep(1_600_000);

                    return Create::promiseFor(
                        new Psr7Response(302, ['Location' => 'https://93.184.216.34/b'])
                    );
                }

                return Create::promiseFor(new Psr7Response(200));
            };
        }
    );

    (new GuardedRedirectWalk(
        timeoutSeconds: 30,
        wantsBody: false,
    ))->follow('https://93.184.216.34/a');

    expect($timeouts)->toHaveCount(2);

    // The first hop gets the whole budget; the second gets what the first left,
    // rounded up to whole seconds
    expect($timeouts[0])->toBe(30);
    expect($timeouts[1])->toBe(29);
});

it('passes the HTTP client zero, its own "no timeout", for an unbounded walk', function (): void {
    $captured = [];
    captureRequestOptions([], $captured, 'timeout');

    (new GuardedRedirectWalk(timeoutSeconds: 0, wantsBody: false))->follow(WALK_START_URL);

    expect($captured)->toBe([0]);
});

it('streams the body to the sink only when one is given', function (): void {
    $captured = [];
    captureRequestOptions([], $captured, 'sink');

    (new GuardedRedirectWalk(timeoutSeconds: 10, wantsBody: true, sink: '/tmp/wpimport-97-sink'))->follow(WALK_START_URL);
    (new GuardedRedirectWalk(timeoutSeconds: 10, wantsBody: true))->follow(WALK_START_URL);

    expect($captured)->toBe(['/tmp/wpimport-97-sink', null]);
});

it('refuses nothing and sends nothing for a blank URL', function (): void {
    Http::fake();

    $cases = [];

    $result = (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
    ))->follow('');

    expect($result)->toBeNull()
        ->and($cases)->toBe([]);

    Http::assertNothingSent();
});

it('does not follow a Location header on a response that is not a redirect', function (): void {
    // A 201 Created carries a Location naming what it created; it is a final
    // response, not a hop
    Http::fake([
        WALK_START_URL => Http::response('created', 201, ['Location' => WALK_SECOND_URL]),
        '*' => Http::response('SHOULD NEVER BE REACHED', 200),
    ]);

    $result = (new GuardedRedirectWalk(timeoutSeconds: 10, wantsBody: true))->follow(WALK_START_URL);

    if ($result === null) {
        throw new RuntimeException('The walk returned no terminal hop for a 201.');
    }

    expect($result['url'])->toBe(WALK_START_URL)
        ->and($result['response']->status())->toBe(201);

    Http::assertSentCount(1);
});

it('reports an unparseable Location as a refusal instead of throwing', function (
    string $location
): void {
    // Guzzle's URI parser throws rather than reporting failure. Two of the three
    // walks called it outside any try, so one malformed redirect aborted the
    // whole import.
    Http::fake([
        WALK_START_URL => Http::response('', 302, ['Location' => $location]),
    ]);

    $cases = [];

    $result = (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
    ))->follow(WALK_START_URL);

    expect($result)->toBeNull();
    expect($cases)->toBe([GuardedFetchRefusal::UnparseableLocation]);
})->with([
    'scheme with no host' => ['http://'],
    'bare scheme' => ['https://'],
    'unmatched bracket' => ['http://[::1'],
    'space in the authority' => ['http://exa mple.com/'],
]);

it('swallows a transport failure when the caller asked it to', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('cURL error 28: timeout');
    });

    $cases = [];

    $result = (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
        swallowsTransportFailures: true,
    ))->follow(WALK_START_URL);

    expect($result)->toBeNull();
    expect($cases)->toBe([GuardedFetchRefusal::TransportFailure]);
});

it('propagates a transport failure when the caller drives retries off it', function (): void {
    // The asset download path reads ConnectionException to decide whether to
    // retry and whether to fall back from HTTPS to HTTP, so swallowing it there
    // would silently disable both.
    Http::fake(function (): void {
        throw new ConnectionException('cURL error 28: timeout');
    });

    $cases = [];

    $walk = new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
        swallowsTransportFailures: false,
    );

    expect(static fn (): ?array => $walk->follow(WALK_START_URL))
        ->toThrow(ConnectionException::class);

    // A propagated failure is the caller's to report, not the walk's
    expect($cases)->toBe([]);
});

it('refuses a public URL that redirects to a private address', function (): void {
    Http::fake([
        WALK_START_URL => Http::response('', 302, [
            'Location' => 'http://169.254.169.254/latest/meta-data/',
        ]),
        '*' => Http::response('SHOULD NEVER BE REACHED', 200),
    ]);

    $cases = [];

    $result = (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
    ))->follow(WALK_START_URL);

    expect($result)->toBeNull();
    expect($cases)->toBe([GuardedFetchRefusal::NonPublicTarget]);

    // The public hop is sent; the private hop it points at never is
    Http::assertSentCount(1);
    Http::assertNotSent(
        static fn (Request $request): bool => str_contains($request->url(), '169.254.169.254')
    );
});

it('treats a redirect carrying no Location as terminal', function (): void {
    // There is no next hop to follow, so the 3xx is handed back for the caller
    // to judge exactly as a 2xx would be, rather than counted as a failure
    Http::fake([
        WALK_START_URL => Http::response('', 302),
    ]);

    $cases = [];

    $result = (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
    ))->follow(WALK_START_URL);

    expect($result['url'] ?? null)->toBe(WALK_START_URL);
    expect(($result['response'] ?? null)?->status())->toBe(302);
    expect($cases)->toBe([]);
});

it('stops a redirect loop and reports it', function (): void {
    Http::fake([
        WALK_START_URL => Http::response('', 302, ['Location' => WALK_SECOND_URL]),
        WALK_SECOND_URL => Http::response('', 302, ['Location' => WALK_START_URL]),
    ]);

    $cases = [];

    $result = (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
    ))->follow(WALK_START_URL);

    expect($result)->toBeNull();
    expect($cases)->toBe([GuardedFetchRefusal::RedirectLoop]);

    Http::assertSentCount(2);
});

it('gives up once the chain exceeds the hop limit', function (): void {
    // Every hop redirects to a distinct new URL, so loop detection never fires
    // and only the hop cap can stop the walk
    Http::fake(function (Request $request) {
        preg_match('/(\d+)$/', $request->url(), $matches);
        $next = (int) ($matches[1] ?? 0) + 1;

        return Http::response('', 302, ['Location' => 'https://93.184.216.34/hop'.$next]);
    });

    $cases = [];

    $result = (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
    ))->follow('https://93.184.216.34/hop0');

    expect($result)->toBeNull();
    expect($cases)->toBe([GuardedFetchRefusal::TooManyRedirects]);

    Http::assertSentCount(OutboundUrlGuard::MAX_REDIRECT_HOPS + 1);
});

it('issues HEAD when no body is wanted and GET when one is', function (): void {
    Http::fake([WALK_START_URL => Http::response('body', 200)]);

    (new GuardedRedirectWalk(timeoutSeconds: 10, wantsBody: false))->follow(WALK_START_URL);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'HEAD');

    Http::fake([WALK_START_URL => Http::response('body', 200)]);

    (new GuardedRedirectWalk(timeoutSeconds: 10, wantsBody: true))->follow(WALK_START_URL);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
});

it('pins the connection to the addresses the guard validated', function (): void {
    // The load-bearing assertion is on the Guzzle options the request is
    // actually issued with, not on the guard's return value: pinning that never
    // reaches the client leaves the rebinding window exactly as wide (wordpress-importer-archive#541).
    OutboundUrlGuard::resolveUsing(
        static fn (string $host, int $type): array => $type === DNS_A
            ? ['93.184.216.34']
            : []
    );

    $captured = [];
    captureRequestOptions([], $captured);

    (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
    ))->follow('http://cdn.example/photo.jpg');

    expect($captured)->toBe([['cdn.example:80:93.184.216.34']]);
});

it('re-pins every hop of a redirect chain, not only the first', function (): void {
    // Each hop is a fresh name and a fresh validation, so each needs its own
    // pin — a chain pinned once would leave every later hop resolvable twice.
    OutboundUrlGuard::resolveUsing(
        static fn (string $host, int $type): array => $type === DNS_A
            ? [$host === 'first.example' ? '93.184.216.34' : '8.8.8.8']
            : []
    );

    $captured = [];
    captureRequestOptions(
        ['http://first.example/a' => [302, ['Location' => 'http://second.example/b']]],
        $captured
    );

    (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
    ))->follow('http://first.example/a');

    expect($captured)->toBe([
        ['first.example:80:93.184.216.34'],
        ['second.example:80:8.8.8.8'],
    ]);
});

it('sends no pin for a host that needs no name lookup', function (): void {
    // An IP literal is resolved by nobody, so there is no name to pin and the
    // option must be absent rather than present-and-meaningless
    $captured = [];
    captureRequestOptions([], $captured);

    (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
    ))->follow(WALK_START_URL);

    expect($captured)->toBe([null]);
});

it('reports nothing when the caller supplied no reporter', function (): void {
    Http::fake();

    // A walk with no reporter must refuse silently rather than fataling on a
    // null closure — this is how the existence check runs
    $result = (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
    ))->follow('http://127.0.0.1:6379/');

    expect($result)->toBeNull();

    Http::assertNothingSent();
});

it('swallows a transport failure by default', function (): void {
    // The link-resolution and existence-check callers construct the walk without
    // naming the policy, and rely on a failure ending the walk rather than throwing
    Http::fake(function (): void {
        throw new ConnectionException('cURL error 28: timeout');
    });

    $cases = [];

    $result = (new GuardedRedirectWalk(
        timeoutSeconds: 10,
        wantsBody: false,
        onRefusal: collectRefusals($cases),
    ))->follow(WALK_START_URL);

    expect($result)->toBeNull()
        ->and($cases)->toBe([GuardedFetchRefusal::TransportFailure]);
});

it('sends the given User-Agent and leaves the client default otherwise', function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    (new GuardedRedirectWalk(timeoutSeconds: 10, wantsBody: false, userAgent: 'UAMS-Importer/97'))->follow(WALK_START_URL);
    (new GuardedRedirectWalk(timeoutSeconds: 10, wantsBody: false))->follow(WALK_SECOND_URL);

    Http::assertSent(
        static fn (Request $request): bool => $request->url() === WALK_START_URL
            && $request->header('User-Agent') === ['UAMS-Importer/97']
    );
    Http::assertSent(
        static fn (Request $request): bool => $request->url() === WALK_SECOND_URL
            && $request->header('User-Agent') !== ['UAMS-Importer/97']
    );
});
