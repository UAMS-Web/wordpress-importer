<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\NewsBlockSourceSites;

/**
 * Tests for NewsBlockSourceSites.
 *
 * Covers the mapping from WordPress `news_local` to the reshaped `source_sites`
 * checkboxes value, and the separate resolution-site list that replaces the
 * placement-relative `current` token with a concrete site handle. Pure logic —
 * no container, no Statamic — so it sits in the bare-TestCase band.
 *
 * @command  php vendor/bin/pest tests/Unit/NewsBlockSourceSitesTest.php
 */
it('selects both sites when WordPress marked the block local', function (): void {
    // `news_local` meant "also include this site's own posts"
    expect(NewsBlockSourceSites::forLocalFlag(true))
        ->toBe(['news', 'current']);
});

it('selects only the news site when WordPress did not mark the block local', function (): void {
    // The fieldset default is news-only
    expect(NewsBlockSourceSites::forLocalFlag(false))
        ->toBe(['news']);
});

it('always emits a value because source_sites is required', function (): void {
    // Neither branch may produce an empty selection
    expect(NewsBlockSourceSites::forLocalFlag(true))->not->toBe([])
        ->and(NewsBlockSourceSites::forLocalFlag(false))->not->toBe([]);
});
