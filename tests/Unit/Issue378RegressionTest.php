<?php

declare(strict_types=1);

/**
 * Regression test for issue wordpress-importer-archive#378.
 *
 * The PHPStan cleanup regression introduced a stray nested assignment in
 * LinkProcessor::findPostDataByUrl():
 *
 *     $searchUrl = $finalUrl !== null ? $searchUrl = $finalUrl : $wordPressUrl;
 *
 * Besides the malformed nested assignment, this dropped the empty-string
 * fallback: when $finalUrl is an empty string ('') the buggy code would select
 * the empty string as the search URL instead of falling back to $wordPressUrl.
 * That broke URL-to-post resolution whenever a redirect-resolution step yielded
 * an empty final URL.
 *
 * The fix restores the empty-string guard:
 *
 *     $searchUrl = ($finalUrl !== null && $finalUrl !== '') ? $finalUrl : $wordPressUrl;
 *
 * This test exercises the private method via reflection without booting
 * Laravel/Statamic. It relies only on the cached URL->post-ID map and a plain
 * WXR XML file on disk, neither of which require facades or the container.
 *
 * @command  php vendor/bin/pest tests/Unit/Issue378RegressionTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\TemporaryFileRegistry;

// Reset the protected static URL->post-ID map before and after each test so
// state does not leak across the suite.
beforeEach(function (): void {
    resetUrlToPostIdMap();
});

afterEach(function (): void {
    resetUrlToPostIdMap();
});

it('falls back to the WordPress URL when the final URL is an empty string', function (): void {
    $wordPressUrl = 'https://example.com/sample-page/';
    $xmlPath = writeWxrFixture();

    // Map only the WordPress URL (not the empty string) to the post ID
    // present in the fixture.
    setUrlToPostIdMap([$wordPressUrl => '101']);

    // Pass an empty string as the final URL. With the regression this selected
    // '' as the search URL, missed the map, and returned null. With the fix it
    // falls back to $wordPressUrl, hits the map, and resolves the post data.
    $result = invokeFindPostDataByUrl($wordPressUrl, $xmlPath, '');

    expect($result)->toBeArray();

    // toBeArray() does not narrow for PHPStan; narrow before indexing.
    $result = is_array($result) ? $result : [];

    expect($result['post_id'] ?? null)->toBe('101')
        ->and($result['post_name'] ?? null)->toBe('sample-page')
        ->and($result['post_type'] ?? null)->toBe('page');

    unlink($xmlPath);
});

it('uses a non-empty final URL for the lookup', function (): void {
    $wordPressUrl = 'https://example.com/original/';
    $finalUrl = 'https://example.com/redirected/';
    $xmlPath = writeWxrFixture();

    // Map only the (non-empty) final URL to the post ID. This confirms the fix
    // still prefers a real $finalUrl over $wordPressUrl.
    setUrlToPostIdMap([$finalUrl => '101']);

    $result = invokeFindPostDataByUrl($wordPressUrl, $xmlPath, $finalUrl);

    expect($result)->toBeArray();

    // toBeArray() does not narrow for PHPStan; narrow before indexing.
    $result = is_array($result) ? $result : [];

    expect($result['post_id'] ?? null)->toBe('101');

    unlink($xmlPath);
});

it('falls back to the WordPress URL when the final URL is null', function (): void {
    $wordPressUrl = 'https://example.com/sample-page/';
    $xmlPath = writeWxrFixture();

    setUrlToPostIdMap([$wordPressUrl => '101']);

    $result = invokeFindPostDataByUrl($wordPressUrl, $xmlPath, null);

    expect($result)->toBeArray();

    // toBeArray() does not narrow for PHPStan; narrow before indexing.
    $result = is_array($result) ? $result : [];

    expect($result['post_id'] ?? null)->toBe('101');

    unlink($xmlPath);
});

/**
 * Invoke the private LinkProcessor::findPostDataByUrl() via reflection.
 *
 * @param  string  $wordPressUrl  The original WordPress URL to resolve
 * @param  string  $xmlPath  Path to the WXR export part on disk
 * @param  ?string  $finalUrl  The post-redirect final URL, or null
 * @return array{post_id: string, post_name: string, post_type: string}|null
 */
function invokeFindPostDataByUrl(
    string $wordPressUrl,
    string $xmlPath,
    ?string $finalUrl
): ?array {
    $processor = (new ReflectionClass(LinkProcessor::class))
        ->newInstanceWithoutConstructor();

    $method = new ReflectionMethod(LinkProcessor::class, 'findPostDataByUrl');
    $method->setAccessible(true);

    /** @var array{post_id: string, post_name: string, post_type: string}|null $result */
    $result = $method->invoke($processor, $wordPressUrl, $xmlPath, $finalUrl);

    return $result;
}

/**
 * Overwrite the protected static URL->post-ID map on LinkProcessor.
 *
 * @param  array<string, string>  $map
 */
function setUrlToPostIdMap(array $map): void
{
    $property = new ReflectionProperty(LinkProcessor::class, 'urlToPostIdMap');
    $property->setAccessible(true);
    $property->setValue(null, $map);
}

/**
 * Clear the cached URL->post-ID map so state does not leak across tests.
 */
function resetUrlToPostIdMap(): void
{
    setUrlToPostIdMap([]);
}

/**
 * Write a minimal but valid single-item WXR export to a temp file.
 *
 * The item's wp:post_id (101) is what the cached map resolves to.
 *
 * @return string Absolute path to the written WXR file
 */
function writeWxrFixture(): string
{
    // The shared registry, not `tempnam(...).'.xml'` — that form leaves the stub
    // behind on every call (wordpress-importer-archive#783)
    $xmlPath = TemporaryFileRegistry::create('issue378_');

    file_put_contents($xmlPath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <item>
            <title>Sample Page</title>
            <wp:post_id>101</wp:post_id>
            <wp:post_name>sample-page</wp:post_name>
            <wp:post_type>page</wp:post_type>
        </item>
    </channel>
</rss>
XML);

    return $xmlPath;
}
