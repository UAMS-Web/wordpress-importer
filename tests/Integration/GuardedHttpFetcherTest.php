<?php

declare(strict_types=1);

/**
 * Integration tests for the guarded link-resolution fetcher (wordpress-importer-archive#534).
 *
 * The load-bearing assertion in the refusal cases is not the null return value
 * but `Http::assertNothingSent()` / the recorded request count: a guard that
 * fetched an internal host and then discarded the response would still return
 * null, so only asserting the return value would pass with the vulnerability
 * intact.
 *
 * IP literals are used throughout so the suite never performs a DNS lookup and
 * stays offline.
 *
 * @command  php vendor/bin/pest tests/Integration/GuardedHttpFetcherTest.php
 */

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\GuardedHttpFetcher;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\OutboundUrlGuard;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

// A routable public address the guard permits, used as the "allowed" target
const PUBLIC_URL = 'http://93.184.216.34/some-page/';

// The cloud metadata endpoint — the canonical SSRF target
const METADATA_URL = 'http://169.254.169.254/latest/meta-data/';

it('resolves a public URL and returns its final address', function (): void {
    Http::fake([
        '*' => Http::response(
            '<html lang="en"><body class="page-id-42"></body></html>',
            200
        ),
    ]);

    expect(GuardedHttpFetcher::resolveFinalUrl(PUBLIC_URL))->toBe(PUBLIC_URL);

    Http::assertSentCount(1);
});

it('follows a public redirect chain to its final address', function (): void {
    $second = 'http://93.184.216.34/moved/';

    Http::fake([
        'http://93.184.216.34/some-page/' => Http::response(
            '',
            301,
            ['Location' => $second]
        ),
        'http://93.184.216.34/moved/' => Http::response(
            '',
            200
        ),
    ]);

    expect(GuardedHttpFetcher::resolveFinalUrl(PUBLIC_URL))->toBe($second);

    Http::assertSentCount(2);
});

it('resolves a relative Location against the current hop', function (): void {
    Http::fake([
        'http://93.184.216.34/some-page/' => Http::response(
            '',
            302,
            ['Location' => '/elsewhere/']
        ),
        'http://93.184.216.34/elsewhere/' => Http::response(
            '',
            200
        ),
    ]);

    expect(GuardedHttpFetcher::resolveFinalUrl(PUBLIC_URL))
        ->toBe('http://93.184.216.34/elsewhere/');
});

it('never requests a URL whose host is a non-public address', function (string $url): void {
    Http::fake();

    expect(GuardedHttpFetcher::resolveFinalUrl($url))->toBeNull();

    // The request must be refused before it is sent, not after
    Http::assertNothingSent();
})->with([
    'cloud metadata link-local' => [METADATA_URL],
    'loopback' => ['http://127.0.0.1:6379/'],
    'RFC1918 10.0.0.0/8' => ['http://10.0.0.5/internal'],
    'RFC1918 192.168.0.0/16' => ['http://192.168.1.1/admin'],
    'IPv6 loopback literal' => ['http://[::1]/'],
    'unspecified address' => ['http://0.0.0.0/'],
]);

it('never requests a URL with a non-HTTP scheme', function (string $url): void {
    Http::fake();

    expect(GuardedHttpFetcher::resolveFinalUrl($url))->toBeNull();
    Http::assertNothingSent();
})->with([
    'file' => ['file:///etc/passwd'],
    'gopher to loopback' => ['gopher://127.0.0.1:6379/'],
    'ftp' => ['ftp://93.184.216.34/pub/'],
    'relative path with no scheme' => ['/relative/path'],
    'empty string' => [''],
]);

it('refuses a public URL that redirects to a private address', function (): void {
    Http::fake([
        'http://93.184.216.34/some-page/' => Http::response(
            '',
            302,
            ['Location' => METADATA_URL]
        ),
        '*' => Http::response(
            'SHOULD NEVER BE REACHED',
            200
        ),
    ]);

    expect(GuardedHttpFetcher::resolveFinalUrl(PUBLIC_URL))->toBeNull();

    // The public hop is sent; the private hop it points at never is
    Http::assertSentCount(1);
    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), '169.254.169.254')
    );
});

it('refuses a body fetch that redirects to a private address', function (): void {
    Http::fake([
        'http://93.184.216.34/some-page/' => Http::response(
            '',
            302,
            ['Location' => 'http://127.0.0.1:6379/']
        ),
        '*' => Http::response(
            'SHOULD NEVER BE REACHED',
            200
        ),
    ]);

    expect(GuardedHttpFetcher::fetchBody(PUBLIC_URL, 'Test Agent'))->toBeNull();

    Http::assertSentCount(1);
    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), '127.0.0.1')
    );
});

it('fetches a public body and sends the given user agent', function (): void {
    Http::fake([
        '*' => Http::response(
            '<body class="page-id-99">',
            200
        ),
    ]);

    expect(GuardedHttpFetcher::fetchBody(PUBLIC_URL, 'WordPress Import Tool'))
        ->toBe('<body class="page-id-99">');

    Http::assertSent(
        fn (Request $request): bool => $request->hasHeader('User-Agent', 'WordPress Import Tool')
    );
});

it('stops a redirect loop instead of spinning to the hop limit', function (): void {
    Http::fake([
        'http://93.184.216.34/some-page/' => Http::response(
            '',
            302,
            ['Location' => 'http://93.184.216.34/loop/']
        ),
        'http://93.184.216.34/loop/' => Http::response(
            '',
            302,
            ['Location' => 'http://93.184.216.34/some-page/']
        ),
    ]);

    expect(GuardedHttpFetcher::resolveFinalUrl(PUBLIC_URL))->toBeNull();

    // Two hops sent, then the third target is recognised as already visited
    Http::assertSentCount(2);
});

it('gives up once the chain exceeds the hop limit', function (): void {
    // Every hop redirects to a distinct new URL, so loop detection never fires
    // and only the hop cap can stop the walk
    Http::fake(function (Request $request): PromiseInterface {
        preg_match('/(\d+)$/', $request->url(), $matches);
        $next = (int) ($matches[1] ?? 0) + 1;

        return Http::response(
            '',
            302,
            ['Location' => 'http://93.184.216.34/hop'.$next]
        );
    });

    expect(GuardedHttpFetcher::resolveFinalUrl('http://93.184.216.34/hop0'))->toBeNull();

    Http::assertSentCount(OutboundUrlGuard::MAX_REDIRECT_HOPS + 1);
});

it('returns null for an error status rather than the body', function (): void {
    Http::fake([
        '*' => Http::response(
            'Not Found',
            404
        ),
    ]);

    expect(GuardedHttpFetcher::fetchBody(PUBLIC_URL, 'Test Agent'))->toBeNull();
    expect(GuardedHttpFetcher::resolveFinalUrl(PUBLIC_URL))->toBeNull();
});

it('never requests a numeric encoding of a private address', function (string $url): void {
    Http::fake();

    expect(GuardedHttpFetcher::resolveFinalUrl($url))->toBeNull();
    Http::assertNothingSent();
})->with([
    'decimal loopback' => ['http://2130706433:6379/'],
    'hex loopback' => ['http://0x7f000001/'],
    'octal loopback' => ['http://017700000001/'],
    'short-form loopback' => ['http://127.1/'],
    'decimal cloud metadata' => ['http://2852039166/latest/meta-data/'],
]);

it('refuses a redirect into a numeric encoding of a private address', function (): void {
    Http::fake([
        'http://93.184.216.34/some-page/' => Http::response(
            '',
            302,
            ['Location' => 'http://2130706433:6379/']
        ),
        '*' => Http::response('SHOULD NEVER BE REACHED', 200),
    ]);

    expect(GuardedHttpFetcher::resolveFinalUrl(PUBLIC_URL))->toBeNull();

    Http::assertSentCount(1);
    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), '2130706433')
    );
});

it('returns null rather than throwing on an unparseable Location header', function (
    string $location
): void {
    // Guzzle's URI parser throws instead of reporting failure, and the throw sat
    // outside the try block — one malformed redirect aborted the whole import
    Http::fake([
        'http://93.184.216.34/some-page/' => Http::response(
            '',
            302,
            ['Location' => $location]
        ),
    ]);

    expect(GuardedHttpFetcher::resolveFinalUrl(PUBLIC_URL))->toBeNull();
    expect(GuardedHttpFetcher::fetchBody(PUBLIC_URL, 'Test Agent'))->toBeNull();
})->with([
    'scheme with no host' => ['http://'],
    'bare scheme' => ['https://'],
    'unmatched bracket' => ['http://[::1'],
    'space in the authority' => ['http://exa mple.com/'],
]);

it('issues HEAD when resolving and GET when fetching a body', function (): void {
    Http::fake([
        '*' => Http::response('body', 200),
    ]);

    GuardedHttpFetcher::resolveFinalUrl(PUBLIC_URL);
    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'HEAD'
    );

    Http::fake([
        '*' => Http::response('body', 200),
    ]);

    GuardedHttpFetcher::fetchBody(PUBLIC_URL, 'Test Agent');
    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'GET'
    );
});

it('survives a transport failure without throwing', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('cURL error 28: timeout');
    });

    expect(GuardedHttpFetcher::resolveFinalUrl(PUBLIC_URL))->toBeNull();
    expect(GuardedHttpFetcher::fetchBody(PUBLIC_URL, 'Test Agent'))->toBeNull();
});
