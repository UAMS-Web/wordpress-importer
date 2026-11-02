<?php

declare(strict_types=1);

/**
 * Tests that `KbLinkRegistry` records and returns the Statamic entry IDs for
 * knowledge base topics (by slug and by WordPress term ID) and posts, and that
 * clearing it empties every map (#1314).
 *
 * The registry is process-wide static state, so every case starts and ends
 * with it cleared.
 *
 * @command  php vendor/bin/pest tests/Unit/KbLinkRegistryTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;

beforeEach(function (): void {
    KbLinkRegistry::clear();
});

afterEach(function (): void {
    KbLinkRegistry::clear();
});

it('starts empty', function (): void {
    expect(KbLinkRegistry::getAllKbtopicMappings())->toBe([])
        ->and(KbLinkRegistry::getAllKbPostMappings())->toBe([])
        ->and(KbLinkRegistry::getKbtopicEntryIdBySlug('billing'))->toBeNull()
        ->and(KbLinkRegistry::getKbtopicEntryIdByTermId(5))->toBeNull()
        ->and(KbLinkRegistry::getKbPostEntryId('reset-password'))->toBeNull();
});

it('returns every topic and post mapping by slug, the latest winning', function (): void {
    KbLinkRegistry::registerKbtopicMappingBySlug('billing', 'entry-1');
    KbLinkRegistry::registerKbtopicMappingBySlug('access', 'entry-2');
    KbLinkRegistry::registerKbtopicMappingBySlug('billing', 'entry-3');
    KbLinkRegistry::registerKbPostMapping('reset-password', 'entry-4');
    KbLinkRegistry::registerKbPostMapping('vpn', 'entry-5');

    expect(KbLinkRegistry::getAllKbtopicMappings())->toBe(['billing' => 'entry-3', 'access' => 'entry-2'])
        ->and(KbLinkRegistry::getAllKbPostMappings())->toBe(['reset-password' => 'entry-4', 'vpn' => 'entry-5'])
        ->and(KbLinkRegistry::getKbtopicEntryIdBySlug('access'))->toBe('entry-2')
        ->and(KbLinkRegistry::getKbPostEntryId('vpn'))->toBe('entry-5');
});

it('keeps topic slugs, term IDs and post slugs in separate maps', function (): void {
    KbLinkRegistry::registerKbtopicMappingBySlug('12', 'topic-by-slug');
    KbLinkRegistry::registerKbtopicMappingByTermId(12, 'topic-by-term');
    KbLinkRegistry::registerKbPostMapping('12', 'post');

    expect(KbLinkRegistry::getKbtopicEntryIdBySlug('12'))->toBe('topic-by-slug')
        ->and(KbLinkRegistry::getKbtopicEntryIdByTermId(12))->toBe('topic-by-term')
        ->and(KbLinkRegistry::getKbPostEntryId('12'))->toBe('post')
        ->and(KbLinkRegistry::getAllKbtopicMappings())->toBe(['12' => 'topic-by-slug']);
});

it('matches a term ID whether it is given as an integer or a string', function (int|string $registered, int|string $looked): void {
    KbLinkRegistry::registerKbtopicMappingByTermId($registered, 'entry-1');

    expect(KbLinkRegistry::getKbtopicEntryIdByTermId($looked))->toBe('entry-1');
})->with([
    'integer, then string' => [7, '7'],
    'string, then integer' => ['7', 7],
]);

it('empties every map when cleared', function (): void {
    KbLinkRegistry::registerKbtopicMappingBySlug('billing', 'entry-1');
    KbLinkRegistry::registerKbtopicMappingByTermId(7, 'entry-2');
    KbLinkRegistry::registerKbPostMapping('vpn', 'entry-3');

    KbLinkRegistry::clear();

    expect(KbLinkRegistry::getAllKbtopicMappings())->toBe([])
        ->and(KbLinkRegistry::getAllKbPostMappings())->toBe([])
        ->and(KbLinkRegistry::getKbtopicEntryIdByTermId(7))->toBeNull();
});
