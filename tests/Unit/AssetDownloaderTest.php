<?php

declare(strict_types=1);

/**
 * Unit tests for asset download error handling in WordPress Import.
 *
 * Tests application-specific functionality in AssetDownloader:
 *   - Error handling for failed asset downloads
 *   - Empty response handling
 *   - Error message clarity across HTTP error status codes
 *   - skipOnFailure flag behavior
 *   - Missing asset container handling (fails fast instead of fataling)
 *   - Per-hop redirect re-validation against the egress guard (SSRF — wordpress-importer-archive#394)
 *
 * @command  php vendor/bin/pest tests/Unit/AssetDownloaderTest.php
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\MockInterface;
use Statamic\Assets\AssetContainer as StatamicAssetContainer;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\AssetContainer as AssetContainerFacade;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetDownloader;
use UAMSWeb\WordPressImporter\Tests\LaravelHttpTestCase;

uses(LaravelHttpTestCase::class);

afterEach(function (): void {
    Mockery::close();
});

/**
 * Return the sole concrete Expectation held inside a CompositeExpectation so its
 * fluent methods (->once(), ->andReturn(), …) are visible to PHPStan — the
 * CompositeExpectation only proxies them through __call.
 *
 * File-local rather than shared: Pest's uses(Trait::class) is not modelled by the
 * static analyser, so a trait written for this would read as used zero times and
 * fail trait.unused. tests/Feature/ImportWordPressCommandTest.php carries its own
 * copy for the same reason.
 */
function unwrapExpectation(CompositeExpectation $composite): Expectation
{
    $reflection = new ReflectionClass(CompositeExpectation::class);
    $property = $reflection->getProperty('_expectations');
    $property->setAccessible(true);

    /** @var list<ExpectationInterface> $expectations */
    $expectations = $property->getValue($composite);

    if (\count($expectations) !== 1) {
        throw new RuntimeException('Expected exactly one Mockery expectation.');
    }

    $expectation = $expectations[0];

    if (! $expectation instanceof Expectation) {
        throw new RuntimeException('Expected a Mockery Expectation instance.');
    }

    return $expectation;
}

it('returns null and reports the failure when a download errors', function (): void {
    $downloader = new AssetDownloader;
    $output = new BufferedOutput;

    // Failed download (empty body, 500)
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    $result = $downloader->download(
        'https://example.com/test.jpg',
        'test/test.jpg',
        'images',
        $output,
        60,
        1,
        false,
        'https://example.com/page'
    );

    expect($result)->toBeNull();
    expect($output->fetch())->toContain('Failed to download asset');
});

it('returns null and reports the failure for an empty response', function (): void {
    $downloader = new AssetDownloader;
    $output = new BufferedOutput;

    // Empty response with no body
    Http::fake([
        '*' => Http::response(null, 500),
    ]);

    $result = $downloader->download(
        'https://example.com/test.jpg',
        'test/test.jpg',
        'images',
        $output,
        60,
        1,
        false,
        null
    );

    expect($result)->toBeNull();
    expect($output->fetch())->toContain('Failed to download asset');
});

it('returns null for every HTTP error status code', function (int $code): void {
    $downloader = new AssetDownloader;
    $output = new BufferedOutput;

    Http::fake([
        '*' => Http::response('Error message', $code),
    ]);

    $result = $downloader->download(
        'https://example.com/test.jpg',
        'test/test.jpg',
        'images',
        $output,
        60,
        1,
        false,
        null
    );

    expect($result)->toBeNull();
})->with([
    '400 Bad Request' => [400],
    '403 Forbidden' => [403],
    '404 Not Found' => [404],
    '413 Payload Too Large' => [413],
    '500 Internal Server Error' => [500],
    '503 Service Unavailable' => [503],
]);

it('returns the original URL on a failed download when skipOnFailure is set', function (): void {
    $downloader = new AssetDownloader;
    $output = new BufferedOutput;

    Http::fake([
        '*' => Http::response('', 500),
    ]);

    $originalUrl = 'https://example.com/test.jpg';
    $result = $downloader->download(
        $originalUrl,
        'test/test.jpg',
        'images',
        $output,
        60,
        1,
        true, // skipOnFailure
        null
    );

    expect($result)->toBe($originalUrl);
});

it('returns null and reports a missing asset container instead of fataling', function (): void {
    // Before issue #1 the container lookup result was wrapped in an `(object)`
    // cast, so a null lookup became an empty stdClass and the subsequent
    // makeAsset() call fataled. The download must now log the miss, report the
    // failed URL, and return null.
    $downloader = new AssetDownloader;
    $output = new BufferedOutput;

    // Fake a successful download so execution reaches the container lookup
    Http::fake([
        '*' => Http::response('fake-image-bytes', 200),
    ]);

    // Existing-asset lookup misses (ignored methods return null)
    AssetFacade::swap(Mockery::mock()->shouldIgnoreMissing());

    // Container lookup misses (the case the explicit null check guards)
    AssetContainerFacade::swap(Mockery::mock()->shouldIgnoreMissing());

    // The not-found branch must log an error; ignore other log calls
    $log = Mockery::mock();
    $errorComposite = $log->shouldReceive('error');

    // Narrow the shouldReceive() union to CompositeExpectation, then unwrap the
    // inner Expectation so ->once() is visible to PHPStan (the composite only
    // proxies the fluent methods through __call).
    if (! $errorComposite instanceof CompositeExpectation) {
        throw new RuntimeException('Expected a Mockery CompositeExpectation.');
    }

    unwrapExpectation($errorComposite)->once();
    $log->shouldIgnoreMissing();
    Log::swap($log);

    $result = $downloader->download(
        'https://example.com/test.jpg',
        'test/test.jpg',
        'missing-container',
        $output,
        60,
        1,
        false,
        null
    );

    // Should return null instead of fataling on stdClass::makeAsset()
    expect($result)->toBeNull();

    // Verify the console reported the missing container
    expect($output->fetch())->toContain("Asset container 'missing-container' not found");
});

it('returns the original URL for a missing asset container when skipOnFailure is set', function (): void {
    $downloader = new AssetDownloader;
    $output = new BufferedOutput;

    // Fake a successful download so execution reaches the container lookup
    Http::fake([
        '*' => Http::response('fake-image-bytes', 200),
    ]);

    // Existing-asset lookup misses (ignored methods return null)
    AssetFacade::swap(Mockery::mock()->shouldIgnoreMissing());

    // Container lookup misses
    AssetContainerFacade::swap(Mockery::mock()->shouldIgnoreMissing());

    // Ignore log calls; this test only asserts the return contract
    Log::swap(Mockery::mock()->shouldIgnoreMissing());

    $originalUrl = 'https://example.com/test.jpg';
    $result = $downloader->download(
        $originalUrl,
        'test/test.jpg',
        'missing-container',
        $output,
        60,
        1,
        true, // skipOnFailure
        null
    );

    // Should fall back to the original URL when skipOnFailure is true
    expect($result)->toBe($originalUrl);
});

it('refuses a redirect from a public URL to an internal host', function (): void {
    // The egress guard only sees the initial URL, so before wordpress-importer-archive#394 a public asset
    // URL that 30x-redirected to a loopback/link-local/private host was followed
    // and the internal response stored. Each hop must now be re-validated: the
    // internal host is never requested and the download fails. Public IP literals
    // let the guard allow the start URL without a DNS lookup, keeping this offline.
    $downloader = new AssetDownloader;
    $output = new BufferedOutput;

    // Public start URL redirects to the cloud-metadata endpoint
    Http::fake([
        'https://93.184.216.34/photo.jpg' => Http::response('', 302, [
            'Location' => 'http://169.254.169.254/latest/meta-data/',
        ]),
        '*' => Http::response('SHOULD NOT BE FETCHED', 200),
    ]);

    $result = $downloader->download(
        'https://93.184.216.34/photo.jpg',
        'test/photo.jpg',
        'images',
        $output,
        60,
        1, // single attempt — no retries/backoff
        false,
        null
    );

    // The download fails because the redirect target is refused
    expect($result)->toBeNull();

    // The internal metadata host must never be requested
    Http::assertNotSent(
        static fn (Request $request): bool => str_contains($request->url(), '169.254.169.254')
    );

    // The refusal is reported on the console
    expect($output->fetch())->toContain('Refusing non-public redirect target');
});

it('follows a redirect between two public hosts', function (): void {
    // Per-hop re-validation must not break legitimate redirects (CDN host move,
    // http→https on another host, a plain 301). The fetch follows the redirect
    // and the download proceeds — here the container lookup misses, which proves
    // execution reached past the (successful) redirected fetch.
    $downloader = new AssetDownloader;
    $output = new BufferedOutput;

    // First public host issues a permanent redirect to a second public host
    Http::fake([
        'https://93.184.216.34/photo.jpg' => Http::response('', 301, [
            'Location' => 'https://8.8.8.8/photo.jpg',
        ]),
        'https://8.8.8.8/photo.jpg' => Http::response('fake-image-bytes', 200),
    ]);

    // Existing-asset and container lookups miss so the flow stops right after
    // the (successful) redirected fetch
    AssetFacade::swap(Mockery::mock()->shouldIgnoreMissing());
    AssetContainerFacade::swap(Mockery::mock()->shouldIgnoreMissing());
    Log::swap(Mockery::mock()->shouldIgnoreMissing());

    $result = $downloader->download(
        'https://93.184.216.34/photo.jpg',
        'test/photo.jpg',
        'missing-container',
        $output,
        60,
        1,
        false,
        null
    );

    // Reaching the container lookup means the redirected fetch succeeded
    expect($result)->toBeNull();
    expect($output->fetch())->toContain("Asset container 'missing-container' not found");

    // The redirect target was actually requested
    Http::assertSent(
        static fn (Request $request): bool => $request->url() === 'https://8.8.8.8/photo.jpg'
    );
});

it('treats a malformed redirect target as a failed download rather than throwing', function (
    string $location
): void {
    // Guzzle's URI parser throws instead of reporting failure, and this call
    // site parsed outside any try — so one malformed redirect in a WXR aborted
    // the whole import (wordpress-importer-archive#542).
    $downloader = new AssetDownloader;
    $output = new BufferedOutput;

    Http::fake([
        'https://93.184.216.34/photo.jpg' => Http::response('', 302, [
            'Location' => $location,
        ]),
    ]);

    $result = $downloader->download(
        'https://93.184.216.34/photo.jpg',
        'test/photo.jpg',
        'images',
        $output,
        60,
        1,
        false,
        null
    );

    expect($result)->toBeNull();
    expect($output->fetch())->toContain('Unparseable redirect target');
})->with([
    'scheme with no host' => ['http://'],
    'bare scheme' => ['https://'],
    'unmatched bracket' => ['http://[::1'],
    'space in the authority' => ['http://exa mple.com/'],
]);

it('treats a redirect loop as a failed download', function (): void {
    // Two public hosts redirect to each other; the fetch must detect the loop
    // and return null rather than hanging or following indefinitely.
    $downloader = new AssetDownloader;
    $output = new BufferedOutput;

    // Two public hosts that bounce back and forth
    Http::fake([
        'https://93.184.216.34/x.jpg' => Http::response('', 302, [
            'Location' => 'https://8.8.8.8/x.jpg',
        ]),
        'https://8.8.8.8/x.jpg' => Http::response('', 302, [
            'Location' => 'https://93.184.216.34/x.jpg',
        ]),
    ]);

    $result = $downloader->download(
        'https://93.184.216.34/x.jpg',
        'test/x.jpg',
        'images',
        $output,
        60,
        1,
        false,
        null
    );

    // The loop is detected and treated as a failed download
    expect($result)->toBeNull();
    expect($output->fetch())->toContain('Redirect loop detected');
});

/**
 * A typed expectation for one mocked method.
 *
 * Mockery's `shouldReceive()` returns a union PHPStan cannot see fluent calls through, so the
 * composite is narrowed and unwrapped exactly as `unwrapExpectation()` above does for logs.
 */
function assetDownloaderExpect(MockInterface $mock, string $method): Expectation
{
    $composite = $mock->shouldReceive($method);

    if (! $composite instanceof CompositeExpectation) {
        throw new RuntimeException('Expected a Mockery CompositeExpectation.');
    }

    return unwrapExpectation($composite);
}

/**
 * A container whose `makeAsset()` returns the given asset double.
 */
function assetDownloaderContainerReturning(mixed $asset): void
{
    // Must be a mock *of the concrete class*: the downloader guards on
    // `instanceof Statamic\\Assets\\AssetContainer` and treats anything else as a missing
    // container, which is a different branch entirely.
    $container = Mockery::mock(StatamicAssetContainer::class);
    assetDownloaderExpect($container, 'makeAsset')->andReturn($asset);
    $container->shouldIgnoreMissing();

    $containers = Mockery::mock();
    assetDownloaderExpect($containers, 'find')->andReturn($container);
    $containers->shouldIgnoreMissing();

    AssetContainerFacade::swap($containers);
    AssetFacade::swap(Mockery::mock()->shouldIgnoreMissing());
}

it('keeps a stored source when a Glide preset write fails during upload', function (): void {
    // wordpress-importer-archive#1161: `Asset::upload()` writes the source and *then* saves, and saving dispatches
    // `AssetUploaded`, whose listener generates presets. A Glide write failing there throws with
    // the source already on disk — and the cleanup, written for an invalid download, deleted it.
    // Five irreplaceable originals were lost this way across two sites, at roughly one per 160
    // assets. A regenerable derivative must never cost the source.
    $asset = Mockery::mock();
    assetDownloaderExpect($asset, 'upload')->once()->andThrow(new RuntimeException(
        'Could not write the image `containers/images/head_start/x.jpg/bdb51aef/x.jpg`.'
    ));
    // The source reached the container before the preset generator ran.
    assetDownloaderExpect($asset, 'exists')->andReturn(true);
    // The assertion that matters: the stored source must not be deleted.
    assetDownloaderExpect($asset, 'delete')->never();
    $asset->shouldIgnoreMissing();

    assetDownloaderContainerReturning($asset);
    Http::fake(['*' => Http::response('fake-image-bytes', 200)]);
    Log::swap(Mockery::mock()->shouldIgnoreMissing());

    $output = new BufferedOutput;

    (new AssetDownloader)->download(
        'https://example.com/x.jpg',
        'head_start/x.jpg',
        'images',
        $output,
        60,
        1,
        false,
        null
    );

    // The loss is still reported rather than swallowed.
    expect($output->fetch())->toContain('Could not write the image');
});

it('still deletes an asset whose source never reached the container', function (): void {
    // The counter-case, so the change above is a narrowing rather than a blanket removal: when the
    // write genuinely failed there is nothing worth keeping, and the orphan cleanup must still run.
    $asset = Mockery::mock();
    assetDownloaderExpect($asset, 'upload')->once()->andThrow(new RuntimeException('disk exploded'));
    assetDownloaderExpect($asset, 'exists')->andReturn(false);
    assetDownloaderExpect($asset, 'delete')->once();
    $asset->shouldIgnoreMissing();

    assetDownloaderContainerReturning($asset);
    Http::fake(['*' => Http::response('fake-image-bytes', 200)]);
    Log::swap(Mockery::mock()->shouldIgnoreMissing());

    (new AssetDownloader)->download(
        'https://example.com/y.jpg',
        'head_start/y.jpg',
        'images',
        new BufferedOutput,
        60,
        1,
        false,
        null
    );
});

it('deletes an asset that failed image validation even when it reached the container', function (): void {
    // Validation failure is the case the cleanup was written for, and it is unaffected: a file that
    // is not the image it claims to be is not worth keeping just because it was written.
    $asset = Mockery::mock();
    assetDownloaderExpect($asset, 'upload')->once()->andThrow(new RuntimeException(
        'The file does not actually appear to be a valid image.'
    ));
    assetDownloaderExpect($asset, 'exists')->andReturn(true);
    assetDownloaderExpect($asset, 'delete')->once();
    $asset->shouldIgnoreMissing();

    assetDownloaderContainerReturning($asset);
    Http::fake(['*' => Http::response('fake-image-bytes', 200)]);
    Log::swap(Mockery::mock()->shouldIgnoreMissing());

    (new AssetDownloader)->download(
        'https://example.com/z.jpg',
        'head_start/z.jpg',
        'images',
        new BufferedOutput,
        60,
        1,
        false,
        null
    );
});
