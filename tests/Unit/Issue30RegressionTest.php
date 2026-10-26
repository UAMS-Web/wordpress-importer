<?php

declare(strict_types=1);

/**
 * Regression test for issue wordpress-importer-archive#30.
 *
 * AssetUtils::isSameSiteUrl() misclassified genuine same-site URLs as external
 * whenever the base blog URL carried a trailing slash. The prefix check treated
 * the character at strlen($baseBlogUrl) as the path separator; with a trailing
 * slash on the base that character was instead the first char of the next path
 * segment, so the "URL starts with base followed by '/' or end-of-string" guard
 * returned false. Downstream same-site checks (LinkProcessor internal-link
 * resolution, the same-site asset-import policy, redirect_rule target
 * resolution) then skipped resolution/import for URLs that should have matched.
 *
 * The fix strips trailing slashes from both operands — symmetric with the
 * existing leading-slash removal — before the prefix + separator check.
 *
 * These assertions exercise pure string logic on a static method; no config,
 * container, HTTP, or Statamic context is touched, so the test runs on plain
 * PHPUnit via the bare package TestCase.
 *
 * @command  php vendor/bin/pest tests/Unit/Issue30RegressionTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;

it('classifies a URL as same-site regardless of trailing slashes on either operand', function (string $url, string $baseBlogUrl, bool $expected): void {
    expect(AssetUtils::isSameSiteUrl($url, $baseBlogUrl))->toBe($expected);
})->with([
    // --- The bug: same-site sub-path across every trailing-slash combo ---

    // AC #1 — base and URL both carry a trailing slash.
    'sub-path, base slash + url slash' => [
        'https://example.com/page/',
        'https://example.com/',
        true,
    ],

    // Base carries a trailing slash, URL does not.
    'sub-path, base slash + url no slash' => [
        'https://example.com/page',
        'https://example.com/',
        true,
    ],

    // URL carries a trailing slash, base does not.
    'sub-path, base no slash + url slash' => [
        'https://example.com/page/',
        'https://example.com',
        true,
    ],

    // AC #2 — neither carries a trailing slash (pre-fix behaviour preserved).
    'sub-path, base no slash + url no slash' => [
        'https://example.com/page',
        'https://example.com',
        true,
    ],

    // Deep nested sub-path against a trailing-slash base.
    'deep sub-path, base slash' => [
        'https://example.com/a/b/c/',
        'https://example.com/',
        true,
    ],

    // --- Exact host match across every trailing-slash combo ---

    // AC #4 — URL is the bare host, base carries a trailing slash.
    'exact host, base slash + url no slash' => [
        'https://example.com',
        'https://example.com/',
        true,
    ],

    'exact host, base slash + url slash' => [
        'https://example.com/',
        'https://example.com/',
        true,
    ],

    'exact host, base no slash + url slash' => [
        'https://example.com/',
        'https://example.com',
        true,
    ],

    'exact host, base no slash + url no slash' => [
        'https://example.com',
        'https://example.com',
        true,
    ],

    // --- Cross-site: no false positives introduced by the fix (AC #3) ---

    'cross-site, base slash' => [
        'https://other.com/page',
        'https://example.com/',
        false,
    ],

    'cross-site, base no slash' => [
        'https://other.com/page',
        'https://example.com',
        false,
    ],

    // --- Prefix-overmatch guard: base is a raw prefix of the URL host but
    //     the next character is NOT a separator, so it must stay external.
    //     str_starts_with() succeeds here; only the separator check rejects.

    'overmatch, sibling TLD-ish host, base slash' => [
        'https://example.community/page',
        'https://example.com/',
        false,
    ],

    'overmatch, sibling TLD-ish host, base no slash' => [
        'https://example.community/page',
        'https://example.com',
        false,
    ],

    'overmatch, suffix-injection host, base slash' => [
        'https://example.com.evil.com/page',
        'https://example.com/',
        false,
    ],

    // --- Protocol-agnostic: http vs https must not affect the match ---

    'protocol-agnostic http url vs https base slash' => [
        'http://example.com/page/',
        'https://example.com/',
        true,
    ],

    // --- The originally reported real-world case (wordpress-importer-archive#30 summary) ---

    'reported case: supplychain trailing-slash base' => [
        'https://supplychain.uams.edu/test-page/',
        'https://supplychain.uams.edu/',
        true,
    ],

    // --- Empty base short-circuits to same-site for any URL ---

    'empty base treats any url as same-site' => [
        'https://anything.example/x',
        '',
        true,
    ],
]);

it('carries the trailing-slash fix through the isUrlOnSameSite() wrapper and short-circuits a null base', function (): void {
    // Delegation carries the trailing-slash fix through the public entry point.
    expect(AssetUtils::isUrlOnSameSite('https://example.com/page/', 'https://example.com/'))->toBeTrue();

    // A cross-site URL is still rejected through the wrapper.
    expect(AssetUtils::isUrlOnSameSite('https://other.com/page', 'https://example.com/'))->toBeFalse();

    // A null base blog URL short-circuits every URL to same-site.
    expect(AssetUtils::isUrlOnSameSite('https://anything.example/x', null))->toBeTrue();
});
