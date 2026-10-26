<?php

declare(strict_types=1);

/**
 * Integration tests pinning `LinkProcessor`'s two outbound fetches to the
 * egress guard (wordpress-importer-archive#534).
 *
 * `GuardedHttpFetcherTest` proves the fetcher is safe; this file proves
 * `LinkProcessor` actually uses it. Without these, reverting either call site
 * to a raw cURL handle leaves the whole suite green — the fetcher's own tests
 * keep passing because they call the fetcher directly.
 *
 * `Http::assertSentCount()` is what makes that revert detectable: a raw cURL
 * implementation records nothing through the `Http` fake, so the count drops to
 * zero on the allowed-URL cases.
 *
 * @command  php vendor/bin/pest tests/Integration/LinkProcessorEgressGuardTest.php
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Invoke one of `LinkProcessor`'s private fetch helpers.
 *
 * @param  string  $method  The private method name
 * @param  string  $url  The URL to pass it
 * @return mixed The method's return value
 */
function invokeLinkProcessorFetch(
    string $method,
    string $url
): mixed {
    $reflection = new ReflectionMethod(
        LinkProcessor::class,
        $method
    );

    return $reflection->invoke(
        new LinkProcessor,
        $url
    );
}

it('resolves a public redirect through the guarded HTTP client', function (): void {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    expect(invokeLinkProcessorFetch('followRedirects', 'http://93.184.216.34/page/'))
        ->toBe('http://93.184.216.34/page/');

    // A raw cURL implementation would record zero requests here
    Http::assertSentCount(1);
});

it('never lets followRedirects reach a non-public address', function (string $url): void {
    Http::fake();

    expect(invokeLinkProcessorFetch('followRedirects', $url))->toBeNull();

    Http::assertNothingSent();
})->with([
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'loopback' => ['http://127.0.0.1:6379/'],
    'RFC1918' => ['http://10.0.0.5/internal'],
    'decimal loopback literal' => ['http://2130706433/'],
    'hex metadata literal' => ['http://0xa9fea9fe/'],
    'non-HTTP scheme' => ['file:///etc/passwd'],
]);

it('refuses a public URL that redirects into a private address', function (): void {
    Http::fake([
        'http://93.184.216.34/page/' => Http::response(
            '',
            302,
            ['Location' => 'http://169.254.169.254/latest/meta-data/']
        ),
        '*' => Http::response('SHOULD NEVER BE REACHED', 200),
    ]);

    expect(invokeLinkProcessorFetch('followRedirects', 'http://93.184.216.34/page/'))
        ->toBeNull();

    Http::assertSentCount(1);
    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), '169.254.169.254')
    );
});

it('extracts a page id through the guarded HTTP client', function (): void {
    Http::fake([
        '*' => Http::response(
            '<html lang="en"><body class="home page-id-4242 logged-in">x</body></html>',
            200
        ),
    ]);

    expect(invokeLinkProcessorFetch('extractPageIdFromHtml', 'http://93.184.216.34/page/'))
        ->toBe('4242');

    Http::assertSentCount(1);
});

it('never lets extractPageIdFromHtml reach a non-public address', function (string $url): void {
    Http::fake();

    expect(invokeLinkProcessorFetch('extractPageIdFromHtml', $url))->toBeNull();

    Http::assertNothingSent();
})->with([
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'loopback' => ['http://127.0.0.1:8080/'],
    'decimal loopback literal' => ['http://2130706433/'],
    'non-HTTP scheme' => ['gopher://127.0.0.1:6379/'],
]);
