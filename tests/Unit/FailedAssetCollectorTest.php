<?php

declare(strict_types=1);

/**
 * Tests that `FailedAssetCollector` records each failed asset download once per
 * page, counts and lists them, and formats the report the import prints (#1314).
 *
 * The collector is process-wide static state, so every case starts and ends
 * with it cleared.
 *
 * @command  php vendor/bin/pest tests/Unit/FailedAssetCollectorTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\FailedAssetCollector;

beforeEach(function (): void {
    FailedAssetCollector::clearFailedUrls();
});

afterEach(function (): void {
    FailedAssetCollector::clearFailedUrls();
});

it('starts empty', function (): void {
    expect(FailedAssetCollector::hasFailedDownloads())->toBeFalse()
        ->and(FailedAssetCollector::getFailedCount())->toBe(0)
        ->and(FailedAssetCollector::getFailedUrls())->toBe([])
        ->and(FailedAssetCollector::getFailedUrlsByPage())->toBe([])
        ->and(FailedAssetCollector::formatFailedUrlsForDisplay())->toBe('');
});

it('records failed URLs under their page, once each', function (): void {
    FailedAssetCollector::addFailedUrl('https://example.org/b.jpg', 'https://example.org/page/');
    FailedAssetCollector::addFailedUrl('https://example.org/a.jpg', 'https://example.org/page/');
    FailedAssetCollector::addFailedUrl('https://example.org/b.jpg', 'https://example.org/page/');

    expect(FailedAssetCollector::getFailedUrlsByPage())->toBe([
        'https://example.org/page/' => ['https://example.org/b.jpg', 'https://example.org/a.jpg'],
    ])
        ->and(FailedAssetCollector::hasFailedDownloads())->toBeTrue()
        ->and(FailedAssetCollector::getFailedCount())->toBe(2);
});

it('compares URLs as strings, so two numeric-looking ones stay distinct', function (): void {
    FailedAssetCollector::addFailedUrl('1e3', 'https://example.org/page/');
    FailedAssetCollector::addFailedUrl('1000', 'https://example.org/page/');

    expect(FailedAssetCollector::getFailedCount())->toBe(2);
});

it('files a URL with no page under Unknown Page', function (): void {
    FailedAssetCollector::addFailedUrl('https://example.org/a.jpg');

    expect(FailedAssetCollector::getFailedUrlsByPage())->toBe([
        'Unknown Page' => ['https://example.org/a.jpg'],
    ]);
});

it('ignores an empty URL', function (): void {
    FailedAssetCollector::addFailedUrl('', 'https://example.org/page/');

    expect(FailedAssetCollector::hasFailedDownloads())->toBeFalse()
        ->and(FailedAssetCollector::getFailedUrlsByPage())->toBe([]);
});

it('lists every failure sorted, keeping a URL once for each page it failed on', function (): void {
    FailedAssetCollector::addFailedUrl('https://example.org/c.jpg', 'https://example.org/two/');
    FailedAssetCollector::addFailedUrl('https://example.org/a.jpg', 'https://example.org/one/');
    FailedAssetCollector::addFailedUrl('https://example.org/c.jpg', 'https://example.org/one/');

    expect(FailedAssetCollector::getFailedUrls())->toBe([
        'https://example.org/a.jpg',
        'https://example.org/c.jpg',
        'https://example.org/c.jpg',
    ])
        ->and(FailedAssetCollector::getFailedCount())->toBe(3);
});

it('formats the failures by page, with pages and assets sorted', function (): void {
    FailedAssetCollector::addFailedUrl('https://example.org/z.jpg', 'https://example.org/two/');
    FailedAssetCollector::addFailedUrl('https://example.org/b.jpg', 'https://example.org/one/');
    FailedAssetCollector::addFailedUrl('https://example.org/a.jpg', 'https://example.org/one/');

    expect(FailedAssetCollector::formatFailedUrlsForDisplay())->toBe(
        "Failed to download these assets:\n"
        ."  - https://example.org/one/\n"
        ."      - https://example.org/a.jpg\n"
        ."      - https://example.org/b.jpg\n"
        ."  - https://example.org/two/\n"
        .'      - https://example.org/z.jpg'
    );
});

it('clears every recorded failure', function (): void {
    FailedAssetCollector::addFailedUrl('https://example.org/a.jpg', 'https://example.org/page/');

    FailedAssetCollector::clearFailedUrls();

    expect(FailedAssetCollector::hasFailedDownloads())->toBeFalse()
        ->and(FailedAssetCollector::getFailedCount())->toBe(0);
});
