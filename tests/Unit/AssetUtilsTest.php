<?php

declare(strict_types=1);

/**
 * Unit tests for AssetUtils outbound-fetch behavior.
 *
 * Covers the per-hop redirect re-validation on AssetUtils::fileExists(): the
 * HEAD must not be allowed to reach an internal host via a redirect from a
 * public URL (SSRF — wordpress-importer-archive#394), while a redirect between two public hosts is still
 * followed. Uses public IP literals so the guard allows them without a DNS
 * lookup, keeping the suite offline.
 *
 * @command  php vendor/bin/pest tests/Unit/AssetUtilsTest.php
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;
use UAMSWeb\WordPressImporter\Tests\LaravelHttpTestCase;

uses(LaravelHttpTestCase::class);

it('refuses a redirect from a public URL to an internal host', function (): void {
    // Public host HEAD redirects to the cloud-metadata endpoint
    Http::fake([
        'https://93.184.216.34/file.pdf' => Http::response('', 302, [
            'Location' => 'http://169.254.169.254/latest/meta-data/',
        ]),
        '*' => Http::response('SHOULD NOT BE FETCHED', 200),
    ]);

    // The redirect target is internal, so the file is reported as missing
    expect(AssetUtils::fileExists('https://93.184.216.34/file.pdf'))->toBeFalse();

    // The internal metadata host must never be requested
    Http::assertNotSent(
        static fn (Request $request): bool => str_contains($request->url(), '169.254.169.254')
    );
});

it('reports a malformed redirect target as missing rather than throwing', function (
    string $location
): void {
    // Guzzle's URI parser throws instead of reporting failure, and this call
    // site parsed outside any try — so one malformed redirect in a WXR aborted
    // the whole import (wordpress-importer-archive#542).
    Http::fake([
        'https://93.184.216.34/file.pdf' => Http::response('', 302, [
            'Location' => $location,
        ]),
    ]);

    expect(AssetUtils::fileExists('https://93.184.216.34/file.pdf'))->toBeFalse();
})->with([
    'scheme with no host' => ['http://'],
    'bare scheme' => ['https://'],
    'unmatched bracket' => ['http://[::1'],
    'space in the authority' => ['http://exa mple.com/'],
]);

it('follows a redirect between two public hosts', function (): void {
    // First public host redirects to a second public host that returns 200
    Http::fake([
        'https://93.184.216.34/file.pdf' => Http::response('', 301, [
            'Location' => 'https://8.8.8.8/file.pdf',
        ]),
        'https://8.8.8.8/file.pdf' => Http::response('', 200),
    ]);

    // The redirect is followed and the final 200 reports the file as present
    expect(AssetUtils::fileExists('https://93.184.216.34/file.pdf'))->toBeTrue();

    // The redirect target was actually requested
    Http::assertSent(
        static fn (Request $request): bool => $request->url() === 'https://8.8.8.8/file.pdf'
    );
});

it('checks a file exists with a HEAD request and reports a failing status as missing', function (int $status, bool $expected): void {
    Http::fake(['https://93.184.216.34/file.pdf' => Http::response('', $status)]);

    expect(AssetUtils::fileExists('https://93.184.216.34/file.pdf'))->toBe($expected);

    // An existence check never needs the body
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'HEAD');
})->with([
    'found' => [200, true],
    'not found' => [404, false],
    'a server error' => [500, false],
]);

it('lets a transport failure reach the caller rather than reporting the file missing', function (): void {
    Http::fake(static function (): never {
        throw new ConnectionException('Connection refused');
    });

    expect(fn (): bool => AssetUtils::fileExists('https://93.184.216.34/file.pdf'))
        ->toThrow(ConnectionException::class, 'Connection refused');
});

it('gives an existence check thirty seconds', function (): void {
    expect(AssetUtils::EXISTENCE_CHECK_TIMEOUT_SECONDS)->toBe(30);
});
