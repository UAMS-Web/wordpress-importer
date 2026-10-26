<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * The migration API client (issue wordpress-importer-archive#657).
 *
 * The contract is read from `UAMS-Web/uamswp-migration-api`, not inferred: namespace
 * `uamswp-migration/v1`, the `X-UAMSWP-Migration-Key` header (D-009), `?page=` / `?per_page=`
 * with `X-WP-Total` (its `Pagination`), `?site=` scoping (D-011), and records ordered by
 * immutable ID rather than date (D-006).
 *
 * Every failure mode is asserted to **raise**, never to return an empty set. That is the
 * point of the class: this codebase's recurring defect is a step that returns nothing and
 * reports success, and a client that swallowed a transport error would seat that failure
 * at the root of every importer built on it.
 *
 * No live endpoint is required — wordpress-importer-archive#657 was built before wordpress-importer-archive#656 stood the API up, so the wire
 * format here is the documented contract rather than an observed one.
 *
 * @command  php vendor/bin/pest tests/Integration/MigrationApiClientTest.php
 */
uses(StatamicTestCase::class);

/**
 * A client pointed at a fake host.
 */
function migrationApiClient(int $perPage = 100): Client
{
    return new Client('https://example.test', 'shhh', $perPage);
}

/**
 * The parsed query string of every request the fake recorded.
 *
 * Matching a query with `str_contains` is prefix-blind — `per_page=1` matches inside
 * `per_page=100` — and the page-size floor is exactly the assertion that turns on the
 * difference between the two.
 *
 * @return list<array<string, string>>
 */
function sentQueries(): array
{
    $queries = [];

    Http::assertSent(function (Request $request) use (&$queries): bool {
        \parse_str((string) \parse_url($request->url(), PHP_URL_QUERY), $parsed);

        /** @var array<string, string> $parsed */
        $queries[] = $parsed;

        return true;
    });

    return $queries;
}

it('presents the shared secret in the documented header', function (): void {
    Http::fake(['*' => Http::response([['id' => 1]], 200)]);

    migrationApiClient()->get('post-types');

    Http::assertSent(function (Request $request): bool {
        // D-009: machine-to-machine auth is this header, not a bearer token or basic auth.
        return $request->hasHeader('X-UAMSWP-Migration-Key', 'shhh');
    });
});

it('builds the documented namespace path', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    migrationApiClient()->get('posts/page');

    Http::assertSent(function (Request $request): bool {
        return \str_starts_with($request->url(), 'https://example.test/wp-json/uamswp-migration/v1/posts/page');
    });
});

it('scopes a request to a site when asked', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    migrationApiClient()->get('posts/page', 4);

    Http::assertSent(function (Request $request): bool {
        // D-011: the API switches sites before authorizing, so scoping is a request
        // parameter rather than a different base URL.
        return \str_contains($request->url(), 'site=4');
    });
});

it('raises rather than returning nothing when the endpoint is unreachable', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('host unreachable');
    });

    expect(fn () => migrationApiClient()->get('posts/page'))
        ->toThrow(MigrationApiException::class, 'could not be reached');
});

it('raises on a non-2xx response, naming the API error code', function (): void {
    // The API distinguishes "no such type" from "this deployment does not register it",
    // and the two point at different fixes — so the code matters, not just the status.
    Http::fake(['*' => Http::response(['code' => 'uamswp_migration_api_unknown_post_type'], 404)]);

    expect(fn () => migrationApiClient()->get('posts/nope'))->toThrow(
        MigrationApiException::class,
        'The migration API returned HTTP 404 for "posts/nope" '
        .'(uamswp_migration_api_unknown_post_type).'
    );
});

it('raises on a body that is not JSON', function (): void {
    Http::fake(['*' => Http::response('<html>a login page</html>', 200)]);

    // A 200 carrying HTML is what an auth redirect looks like; treating it as no records
    // would be the silent-empty failure in its most plausible disguise.
    expect(fn () => migrationApiClient()->get('posts/page'))
        ->toThrow(MigrationApiException::class, 'not JSON');
});

it('pages until every record the API reported has been read', function (): void {
    // `push()` takes a body, status and headers — pushing a Response object would make
    // the sequence treat that object as the body.
    Http::fake(['*' => Http::sequence()
        ->push([['id' => 1], ['id' => 2]], 200, ['X-WP-Total' => '3'])
        ->push([['id' => 3]], 200, ['X-WP-Total' => '3']),
    ]);

    $records = migrationApiClient(2)->paginate('posts/page');

    expect($records)->toHaveCount(3)
        // D-006: the API orders by immutable ID so pages cannot shift under a live site.
        // The client must preserve that order rather than re-sorting.
        ->and(\array_column($records, 'id'))->toBe([1, 2, 3]);
});

it('refuses to treat a short read as complete', function (): void {
    // The API says three records exist and serves two, then an empty page. Returning the
    // two would be the 103-of-1826 truncation all over again.
    Http::fake(['*' => Http::sequence()
        ->push([['id' => 1], ['id' => 2]], 200, ['X-WP-Total' => '3'])
        ->push([], 200, ['X-WP-Total' => '3']),
    ]);

    expect(fn () => migrationApiClient(2)->paginate('posts/page'))
        ->toThrow(MigrationApiException::class, 'Refusing to treat a partial read as complete');
});

it('clamps the requested page size to what the API will serve', function (): void {
    Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => '0'])]);

    migrationApiClient(9999)->paginate('posts/page');

    // Asking beyond the maximum is clamped server-side, which would make the client's
    // own arithmetic disagree with what arrives.
    expect(\array_column(sentQueries(), 'per_page'))->toBe(['200']);
});

it('stops on an empty page even when the total disagrees', function (): void {
    // A server reporting a total it cannot serve must not loop forever. This is the one
    // case where stopping early is correct — and it still raises, rather than returning.
    Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => '50'])]);

    expect(fn () => migrationApiClient(10)->paginate('posts/page'))
        ->toThrow(MigrationApiException::class, 'served 0');
});

it('refuses an address that is not https', function (): void {
    // The key rides an `X-UAMSWP-Migration-Key` header, so a cleartext scheme puts a live
    // credential on the wire. Refused at the start of the run rather than at request time:
    // `withBaseUrl()` pins every later hop to whatever scheme the run began on, so a run
    // started on `http://` would have that guard enforcing exposure instead of preventing
    // it.
    expect(fn () => Client::forRun('http://example.test', 'shhh'))
        ->toThrow(MigrationApiException::class, 'in cleartext');
});

it('refuses an address that is not absolute', function (string $relative): void {
    // The first three are what an operator types copying a host out of a browser bar
    // without the scheme. `https://` is here because `parse_url()` fails on it outright
    // rather than returning a scheme with no host, so it lands on this guard and not the
    // next one — which is not obvious, and was wrong here first time.
    expect(fn () => Client::forRun($relative, 'shhh'))
        ->toThrow(MigrationApiException::class, 'must be an absolute `https://` URL');
})->with(['example.test', '/wp-json', 'example.test/wp-json', 'https://']);

it('refuses an address with a scheme but no host', function (string $hostless): void {
    // A slash short. `parse_url('https:example.test')` returns scheme `https` and no host
    // at all — it parses cleanly, so the scheme guard passes it through and only this one
    // catches it. Verified against PHP rather than assumed: every `https://…` form missing
    // a host makes `parse_url()` return false, so these single-slash and no-slash spellings
    // are the only inputs that reach here, and they are ordinary typing slips.
    expect(fn () => Client::forRun($hostless, 'shhh'))
        ->toThrow(MigrationApiException::class, 'has no host');
})->with(['https:example.test', 'https:/example.test', 'https:']);

it('refuses an empty key rather than sending an anonymous request', function (): void {
    // The API answers 401 to a missing header, so letting this through would report an
    // operator's slip as an authentication failure against a network that is fine.
    expect(fn () => Client::forRun('https://example.test', ''))
        ->toThrow(MigrationApiException::class, 'No migration API key was supplied');
});

it('builds from the address and key the operator supplied', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    Client::forRun('https://example.test/', 'shhh')->get('sites');

    Http::assertSent(function (Request $request): bool {
        // The trailing slash a pasted address carries must not produce a double slash.
        return \str_starts_with($request->url(), 'https://example.test/wp-json/uamswp-migration/v1/sites');
    });
});

it('accepts a page size that configuration handed over as a string', function (): void {
    // The page size stays in configuration deliberately. It is neither a credential nor a
    // network identity, so there is nothing to gain by asking for it on every run.
    config(['wordpress-import.migration_api' => [
        // What `env('UAMSWP_MIGRATION_API_PER_PAGE', 100)` yields without an `(int)` cast.
        'per_page' => '150',
    ]]);

    Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => '0'])]);

    Client::forRun('https://example.test', 'shhh')->paginate('posts/page');

    // Falling back to the default here would be a configured value quietly ignored.
    expect(\array_column(sentQueries(), 'per_page'))->toBe(['150']);
});

it('raises on a page size that is not a number at all', function (): void {
    config(['wordpress-import.migration_api' => [
        'per_page' => 'lots',
    ]]);

    expect(fn () => Client::forRun('https://example.test', 'shhh'))->toThrow(
        MigrationApiException::class,
        'The migration API page size must be a number, got string. Check '
        .'`wordpress-import.migration_api.per_page` in the host application.'
    );
});

it('refuses a page size the API would refuse, naming the configuration key', function (mixed $perPage, string $shown): void {
    // The API answers `400 rest_invalid_param` for a non-integer or sub-1 `per_page` (its
    // D-084). Refusing at construction names the key rather than failing on the first read (wordpress-importer-archive#1213).
    config(['wordpress-import.migration_api' => [
        'per_page' => $perPage,
    ]]);

    expect(fn () => Client::forRun('https://example.test', 'shhh'))->toThrow(
        MigrationApiException::class,
        'The migration API page size must be a whole number of at least 1, got '.$shown.'. Check '
        .'`wordpress-import.migration_api.per_page` in the host application.'
    );
})->with([
    'zero' => [0, '0'],
    'negative' => [-5, '-5'],
    'a fraction, which a cast would truncate to 1' => [1.5, '1.5'],
    'a fractional string from env()' => ['1.5', "'1.5'"],
]);

it('keeps control of its own paging when a caller passes page parameters', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push([['id' => 1]], 200, ['X-WP-Total' => '2'])
        ->push([['id' => 2]], 200, ['X-WP-Total' => '2']),
    ]);

    // A caller filtering by post type has no business pinning the walk to page 1.
    migrationApiClient(1)->paginate('posts/page', null, ['page' => 1, 'per_page' => 999]);

    expect(\array_column(sentQueries(), 'page'))->toBe(['1', '2']);
});

it('treats a blank address the same as an absent one', function (string $blank): void {
    // An operator who hits return at the prompt without typing anything produces `''`, and
    // one who pastes from a selection that caught only whitespace produces the second.
    expect(fn () => Client::forRun($blank, 'shhh'))->toThrow(
        MigrationApiException::class,
        'The migration API address must be an absolute `https://` URL'
    );
})->with(['', '   ']);

it('treats a blank key the same as an absent one', function (string $blank): void {
    expect(fn () => Client::forRun('https://example.test', $blank))->toThrow(
        MigrationApiException::class,
        'No migration API key was supplied. Generate one for this network from the '
        .'plugin admin page, then paste it when the import asks for it.'
    );
})->with(['', '     ']);

it('defaults the page size when configuration omits it', function (): void {
    config(['wordpress-import.migration_api' => []]);

    Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => '0'])]);

    Client::forRun('https://example.test', 'shhh')->paginate('posts/page');

    expect(\array_column(sentQueries(), 'per_page'))->toBe(['100']);
});

it('tolerates whitespace around what was pasted', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    // A trailing slash and stray whitespace are what a copy out of a browser bar and an
    // admin page yield. An operator doing this by hand, once per network, will produce
    // both — and a key with a stray newline would otherwise fail authentication against a
    // network that is correctly configured.
    Client::forRun("  https://example.test/  \n", '  shhh  ')->get('sites');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://example.test/wp-json/uamswp-migration/v1/sites'
            && $request->hasHeader('X-UAMSWP-Migration-Key', 'shhh');
    });
});

it('does not double the separator when a path is passed with a leading slash', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    migrationApiClient()->get('/sites');

    Http::assertSent(function (Request $request): bool {
        // `//sites` is a different route to WordPress, and it 404s.
        return ! \str_contains($request->url(), 'v1//sites');
    });
});

it('reports a non-2xx cleanly when the body carries no error code', function (): void {
    // A bare 500 from PHP has no JSON `code`; the message must not trail an empty paren.
    Http::fake(['*' => Http::response('', 500)]);

    expect(fn () => migrationApiClient()->get('posts/page'))->toThrow(
        MigrationApiException::class,
        'The migration API returned HTTP 500 for "posts/page".'
    );
});

it('never requests a page size below one', function (int $configured): void {
    // A zero or negative page size would make WordPress fall back to its own default,
    // silently divorcing the client's arithmetic from what arrives.
    Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => '0'])]);

    migrationApiClient($configured)->paginate('posts/page');

    expect(\array_column(sentQueries(), 'per_page'))->toBe(['1']);
})->with([0, -5, 1]);

it('keeps the transport failure as the cause', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('host unreachable');
    });

    $exception = null;

    try {
        migrationApiClient()->get('posts/page');
    } catch (MigrationApiException $caught) {
        $exception = $caught;
    }

    // Losing the cause turns a DNS failure and a TLS failure into the same message.
    expect($exception)->toBeInstanceOf(MigrationApiException::class)
        ->and($exception?->getPrevious())->toBeInstanceOf(ConnectionException::class);
});

it('pages to completion when the API reports no total at all', function (): void {
    // wordpress-importer-archive#677: the absent header used to end the walk after page one AND disable the
    // short-read guard, so the one input the guard could not see was the one that needed
    // it. 250 records at 100 a page, no `X-WP-Total` anywhere.
    $page = fn (int $from, int $count): array => \array_map(
        fn (int $i): array => ['id' => $i],
        \range($from, $from + $count - 1)
    );

    Http::fake(['*' => Http::sequence()
        ->push($page(1, 100), 200)
        ->push($page(101, 100), 200)
        ->push($page(201, 50), 200),
    ]);

    $records = migrationApiClient(100)->paginate('posts/post');

    expect($records)->toHaveCount(250)
        ->and(\array_column($records, 'id'))->toBe(\range(1, 250));
});

it('pages to completion when the total is present but not a number', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push([['id' => 1], ['id' => 2]], 200, ['X-WP-Total' => 'unknown'])
        ->push([['id' => 3]], 200, ['X-WP-Total' => 'unknown']),
    ]);

    expect(migrationApiClient(2)->paginate('posts/post'))->toHaveCount(3);
});

it('terminates on an exact multiple of the page size with no total', function (): void {
    // The boundary where "full page" and "last page" coincide: the walk cannot know it is
    // done until it asks once more and gets nothing.
    Http::fake(['*' => Http::sequence()
        ->push([['id' => 1], ['id' => 2]], 200)
        ->push([['id' => 3], ['id' => 4]], 200)
        ->push([], 200),
    ]);

    expect(migrationApiClient(2)->paginate('posts/post'))->toHaveCount(4);
});

it('returns an empty set for a genuinely empty collection', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    expect(migrationApiClient(10)->paginate('posts/post'))->toBe([]);
});

it('stops at the page count the API reported', function (): void {
    // A server that answers every page with a full one would otherwise never end. Where it
    // reports `X-WP-TotalPages`, that is the bound.
    Http::fake(['*' => Http::response(
        [['id' => 1], ['id' => 2]],
        200,
        ['X-WP-TotalPages' => '2']
    )]);

    // Two full pages of two, bounded by the reported page count rather than by a short read.
    expect(migrationApiClient(2)->paginate('posts/post'))->toHaveCount(4);
});

it('still raises when a known total is not served', function (): void {
    // The guard that already existed must survive the rewrite.
    Http::fake(['*' => Http::sequence()
        ->push([['id' => 1], ['id' => 2]], 200, ['X-WP-Total' => '5'])
        ->push([], 200, ['X-WP-Total' => '5']),
    ]);

    expect(fn () => migrationApiClient(2)->paginate('posts/post'))
        ->toThrow(MigrationApiException::class, 'reported 5 records for "posts/post" but served 2');
});

it('refuses to follow a redirect rather than carrying the secret to it', function (): void {
    // wordpress-importer-archive#678: Guzzle strips only Authorization and Cookie across origins, so a custom header
    // rides along. One Location would hand a full-read credential to another host.
    Http::fake([
        'https://example.test/*' => Http::response('', 302, [
            'Location' => 'https://elsewhere.test/wp-json/uamswp-migration/v1/options',
        ]),
    ]);

    expect(fn () => migrationApiClient()->get('options'))->toThrow(
        MigrationApiException::class,
        'redirected "options" to "https://elsewhere.test/wp-json/uamswp-migration/v1/options"'
    );
});

it('sends nothing at all to the redirect target', function (): void {
    Http::fake([
        'https://example.test/*' => Http::response('', 302, ['Location' => 'https://elsewhere.test/x']),
        'https://elsewhere.test/*' => Http::response(['options' => []], 200),
    ]);

    try {
        migrationApiClient()->get('options');
    } catch (MigrationApiException) {
        // expected
    }

    // The credential never leaves for the other origin, and the target's body is never
    // accepted as the API's own.
    Http::assertNotSent(fn (Request $request): bool => \str_contains($request->url(), 'elsewhere.test'));
});

it('reports a redirect as a redirect, not as a malformed body', function (): void {
    // `failed()` is serverError() || clientError(), so a 3xx slips past it. Without an
    // explicit check the operator is told the body is not JSON — the wrong problem.
    Http::fake(['*' => Http::response('', 301, ['Location' => 'https://example.test/moved'])]);

    expect(fn () => migrationApiClient()->get('options'))
        ->toThrow(MigrationApiException::class, 'Refusing to follow it');
});

it('names the absent Location rather than printing an empty string', function (): void {
    Http::fake(['*' => Http::response('', 302)]);

    expect(fn () => migrationApiClient()->get('options'))
        ->toThrow(MigrationApiException::class, '(no Location header)');
});

it('surfaces the guidance the API puts in a validation error', function (): void {
    // The real shape of a rejected `modified_after`. WordPress puts a summary in `message`
    // and the sentence that actually helps in `data.params.<name>` — reporting only
    // `rest_invalid_param` throws the fix away (wordpress-importer-archive#691).
    Http::fake(['*' => Http::response([
        'code' => 'rest_invalid_param',
        'message' => 'Invalid parameter(s): modified_after',
        'data' => [
            'status' => 400,
            'params' => [
                'modified_after' => '`modified_after` is not a timestamp this endpoint can read. Use `YYYY-MM-DD`, `YYYY-MM-DD HH:MM:SS` (GMT), or an ISO 8601 timestamp.',
            ],
        ],
    ], 400)]);

    expect(fn () => migrationApiClient()->get('posts/post'))->toThrow(
        MigrationApiException::class,
        'The migration API returned HTTP 400 for "posts/post" (rest_invalid_param). '
        .'Invalid parameter(s): modified_after '
        .'modified_after: `modified_after` is not a timestamp this endpoint can read. '
        .'Use `YYYY-MM-DD`, `YYYY-MM-DD HH:MM:SS` (GMT), or an ISO 8601 timestamp.'
    );
});

it('surfaces a plain message when there are no parameter details', function (): void {
    Http::fake(['*' => Http::response([
        'code' => 'uamswp_migration_api_registration_is_not_site_scoped',
        'message' => 'Ask site 2 through its own REST root instead.',
    ], 400)]);

    expect(fn () => migrationApiClient()->get('post-types'))->toThrow(
        MigrationApiException::class,
        '(uamswp_migration_api_registration_is_not_site_scoped). Ask site 2 through its own REST root instead.'
    );
});

it('stays readable when the API offers only a code', function (): void {
    // Plenty of responses have nothing else. The message must not grow a dangling space or
    // an empty clause.
    Http::fake(['*' => Http::response(['code' => 'uamswp_migration_api_unknown_post_type'], 404)]);

    expect(fn () => migrationApiClient()->get('posts/nope'))->toThrow(
        MigrationApiException::class,
        'The migration API returned HTTP 404 for "posts/nope" (uamswp_migration_api_unknown_post_type).'
    );
});

it('ignores a message that is not a string', function (): void {
    Http::fake(['*' => Http::response([
        'code' => 'weird',
        'message' => ['not', 'a', 'string'],
        'data' => ['params' => ['x' => ['also', 'not']]],
    ], 400)]);

    expect(fn () => migrationApiClient()->get('posts/post'))
        ->toThrow(MigrationApiException::class, 'HTTP 400 for "posts/post" (weird).');
});

// --- retrying a transient failure (wordpress-importer-archive#1064) ----------------------------------
//
// A 503 from a WordPress maintenance window used to abort a whole import run, because
// `failed()` treats every non-2xx alike. These assert the two halves that have to hold
// together: a transient status is repeated, and a status describing the request is not.
//
// `Sleep::fake()` is what makes them runnable — the real backoff is 1s + 2s + 4s.

/**
 * A client that records what it would have told the operator.
 *
 * @param  list<string>  $messages  Filled with each retry announcement, by reference
 */
function retryingClient(array &$messages): Client
{
    return migrationApiClient()->withRetryReporter(function (string $message) use (&$messages): void {
        $messages[] = $message;
    });
}

it('retries a 503 and completes when the window closes inside the budget', function (): void {
    Sleep::fake();

    Http::fake([
        '*' => Http::sequence()
            ->push(['code' => 'wp_die'], 503)
            ->push(['code' => 'wp_die'], 503)
            ->push([['id' => 7]], 200),
    ]);

    $messages = [];

    expect(retryingClient($messages)->get('posts/attachment'))->toBe([['id' => 7]]);

    Http::assertSentCount(3);
    expect($messages)->toHaveCount(2);
});

it('announces each retry, because a silent pause reads as a hang', function (): void {
    Sleep::fake();

    Http::fake(['*' => Http::sequence()->push('', 503)->push([], 200)]);

    $messages = [];
    retryingClient($messages)->get('posts/post');

    expect($messages[0])
        ->toContain('answered 503 for "posts/post"')
        ->toContain('Waiting 1s before trying again');
});

it('does not retry a 404, so a misconfiguration fails fast', function (): void {
    Sleep::fake();

    Http::fake(['*' => Http::response(['code' => 'uamswp_migration_api_unknown_post_type'], 404)]);

    $messages = [];

    expect(fn () => retryingClient($messages)->get('posts/nope'))
        ->toThrow(MigrationApiException::class, 'returned HTTP 404');

    Http::assertSentCount(1);
    expect($messages)->toBe([]);
});

it('does not retry a 401, so a bad key is reported rather than repeated', function (): void {
    Sleep::fake();

    Http::fake(['*' => Http::response(['code' => 'rest_forbidden'], 401)]);

    $messages = [];

    expect(fn () => retryingClient($messages)->get('options'))
        ->toThrow(MigrationApiException::class, 'returned HTTP 401');

    Http::assertSentCount(1);
});

it('prefers a Retry-After shorter than the budget over the computed backoff', function (): void {
    Sleep::fake();

    Http::fake([
        '*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '5'])
            ->push([], 200),
    ]);

    $messages = [];
    retryingClient($messages)->get('posts/post');

    // The computed first backoff is 1s; the server asked for 5 and is believed.
    expect($messages[0])->toContain('Waiting 5s before trying again');
});

it('refuses a Retry-After longer than the budget, naming what the server asked for', function (): void {
    Sleep::fake();

    // WordPress core's maintenance path sends exactly this: wp-includes/load.php:411.
    Http::fake(['*' => Http::response(['code' => 'wp_die'], 503, ['Retry-After' => '600'])]);

    $messages = [];

    expect(fn () => retryingClient($messages)->get('posts/attachment'))->toThrow(
        MigrationApiException::class,
        'asked for 600s via Retry-After'
    );

    // Not waited out, and not ignored either — one attempt, then a message with the number.
    Http::assertSentCount(1);
    expect($messages)->toBe([]);
});

it('names the status, the attempts and the backoff when the budget is exhausted', function (): void {
    Sleep::fake();

    Http::fake(['*' => Http::response(['code' => 'wp_die'], 503)]);

    $messages = [];

    expect(fn () => retryingClient($messages)->get('posts/post'))->toThrow(
        MigrationApiException::class,
        'Gave up after 4 attempts over 7s of backoff'
    );

    Http::assertSentCount(4);
});

it('still refuses a redirect rather than retrying it', function (): void {
    Sleep::fake();

    // A 3xx is not `failed()`, so it must not reach the retry decision at all.
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.test/x'])]);

    $messages = [];

    expect(fn () => retryingClient($messages)->get('options'))
        ->toThrow(MigrationApiException::class, 'Refusing to follow it');

    Http::assertSentCount(1);
    expect($messages)->toBe([]);
});

it('carries the credential and page size through withRetryReporter', function (): void {
    // The reporter is attached by constructing a new client, so a wrong argument order
    // would silently change what is sent rather than failing loudly.
    Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => '0'])]);

    $messages = [];
    migrationApiClient(37)
        ->withRetryReporter(function (string $message) use (&$messages): void {
            $messages[] = $message;
        })
        ->paginate('posts/post');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-UAMSWP-Migration-Key', 'shhh'));
    expect(sentQueries()[0]['per_page'])->toBe('37');
});

// --- progress while walking a collection (wordpress-importer-archive#1063) ----------------------------
//
// `paginate()` blocks until a whole collection is read - 137 pages and 170 MB on the
// largest network in the estate - and emitted nothing while it did, so a working run was
// indistinguishable from a stalled one. wordpress-importer-archive#1064's retry lengthens that silence rather than
// shortening it, which is why these two changes belong together.

/**
 * A client that records the progress it would have printed.
 *
 * @param  list<string>  $lines  Filled with each progress line, by reference
 */
function reportingClient(array &$lines, int $perPage = 100): Client
{
    return migrationApiClient($perPage)->withProgressReporter(function (string $message) use (&$lines): void {
        $lines[] = $message;
    });
}

it('emits a line for every page of a multi-page walk', function (): void {
    // Page size 2, so two full pages and a short one is a three-page walk. A single-page
    // fixture would let a per-walk line pass as if it were a per-page one.
    Http::fake([
        '*' => Http::sequence()
            ->push([['id' => 1], ['id' => 2]], 200)
            ->push([['id' => 3], ['id' => 4]], 200)
            ->push([['id' => 5]], 200),
    ]);

    $lines = [];
    $records = reportingClient($lines, 2)->paginate('posts/post');

    expect($records)->toHaveCount(5);
    expect($lines)->toBe([
        'Reading posts/post - page 1, 2 records so far.',
        'Reading posts/post - page 2, 4 records so far.',
        'Reading posts/post - page 3, 5 records so far.',
    ]);
});

it('emits a line for a single-page walk, so the smallest site is not silent', function (): void {
    Http::fake(['*' => Http::response([['id' => 1]], 200)]);

    $lines = [];
    reportingClient($lines, 2)->paginate('posts/post');

    expect($lines)->toBe(['Reading posts/post - page 1, 1 record so far.']);
});

it('names the route being walked, which is the post type', function (): void {
    Http::fake(['*' => Http::response([['id' => 1]], 200)]);

    $lines = [];
    reportingClient($lines, 2)->paginate('posts/attachment');

    expect($lines[0])->toContain('posts/attachment');
});

it('quotes no total, so progress does not depend on a header the endpoint may omit', function (): void {
    // No X-WP-Total at all. The walk terminates on the short page, and progress is a
    // running count rather than a fraction, so both still work.
    Http::fake([
        '*' => Http::sequence()
            ->push([['id' => 1], ['id' => 2]], 200)
            ->push([['id' => 3]], 200),
    ]);

    $lines = [];
    reportingClient($lines, 2)->paginate('posts/post');

    expect($lines)->toHaveCount(2);
    expect($lines[1])->toContain('3 records so far');
});

it('carries the progress reporter across withBaseUrl and withRetryReporter', function (): void {
    // The materializer re-points the client at each site's own REST root, so a reporter
    // that did not survive that hop would go silent for exactly the walks that matter.
    Http::fake(['*' => Http::response([['id' => 1]], 200)]);

    $lines = [];
    migrationApiClient(2)
        ->withProgressReporter(function (string $message) use (&$lines): void {
            $lines[] = $message;
        })
        ->withRetryReporter(function (string $message): void {})
        ->withBaseUrl('https://other.example.test')
        ->paginate('posts/post');

    expect($lines)->toHaveCount(1);
});
