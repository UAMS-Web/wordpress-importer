<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\KeyTracker;

/**
 * How `KeyTracker::trackKeys()` sorts an item's keys: hero slide and module keys are
 * ignored as they arrive, and anything neither a known top-level element, a mapped key,
 * an ignored key nor underscore-prefixed is reported as unaddressed, accumulating across
 * items.
 *
 * @command  php vendor/bin/pest tests/Unit/KeyTrackerTrackKeysTest.php
 */

/**
 * Build a WordPress `<item>` with the given extra elements and postmeta keys.
 *
 * @param  list<string>  $metaKeys  The postmeta keys
 * @param  string  $extra  Further child elements, as XML
 * @return SimpleXMLElement The item
 */
function keyTrackerTrackItem(array $metaKeys, string $extra = ''): SimpleXMLElement
{
    $postmeta = '';

    foreach ($metaKeys as $key) {
        $postmeta .= "<wp:postmeta><wp:meta_key>{$key}</wp:meta_key><wp:meta_value>x</wp:meta_value></wp:postmeta>";
    }

    return new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/"><title>A page</title><wp:post_id>1</wp:post_id>'
        .$extra.$postmeta.'</item>'
    );
}

/**
 * Count how often a key appears in a list.
 *
 * @param  array<int|string, mixed>  $keys  The list
 * @param  string  $key  The key
 * @return int The number of occurrences
 */
function keyTrackerOccurrences(array $keys, string $key): int
{
    return \count(\array_keys($keys, $key, true));
}

it('ignores hero slide and module keys once each, however many items carry them', function (): void {
    $keys = ['_page_hero_hero_0_hero_title', 'uamswp_modules_2_heading', '_uamswp_modules_2_heading'];
    $tracker = new KeyTracker;

    $tracker->trackKeys(keyTrackerTrackItem($keys), $keys, []);
    $tracker->trackKeys(keyTrackerTrackItem($keys), $keys, []);

    $ignored = $tracker->getIgnoredMetaKeys();

    expect(keyTrackerOccurrences($ignored, '_page_hero_hero_0_hero_title'))->toBe(1)
        ->and(keyTrackerOccurrences($ignored, 'uamswp_modules_2_heading'))->toBe(1)
        ->and(keyTrackerOccurrences($ignored, '_uamswp_modules_2_heading'))->toBe(1)
        ->and($tracker->getUnaddressedKeys())->toBe([]);
});

it('leaves a key that only resembles a module key to be reported', function (): void {
    $keys = ['uamswp_modules_heading', 'x_uamswp_modules_2_heading'];
    $tracker = new KeyTracker;

    $tracker->trackKeys(keyTrackerTrackItem($keys), $keys, []);

    expect($tracker->getUnaddressedKeys())->toBe($keys)
        ->and($tracker->getIgnoredMetaKeys())->not->toContain('uamswp_modules_heading');
});

it('reports unknown elements and keys, never postmeta, mapped, ignored or underscore keys', function (): void {
    $keys = ['mapped_key', 'pgopts', '_private_key', 'unknown_key'];
    $tracker = new KeyTracker;

    $tracker->trackKeys(
        keyTrackerTrackItem($keys, '<surprise>1</surprise><wp:surprise>1</wp:surprise>'),
        $keys,
        ['mapped_key']
    );

    expect($tracker->getUnaddressedKeys())->toBe(['surprise', 'wp:surprise', 'unknown_key']);
});

it('remembers mapped keys and reports unaddressed keys across items', function (): void {
    $tracker = new KeyTracker;

    $tracker->trackKeys(keyTrackerTrackItem(['first_key']), ['first_key', 'mapped_once'], ['mapped_once']);
    $tracker->trackKeys(keyTrackerTrackItem(['second_key']), ['second_key', 'mapped_once'], []);

    expect($tracker->getUnaddressedKeys())->toBe(['first_key', 'second_key']);
});

it('passes over a meta key that is not a string', function (): void {
    $tracker = new KeyTracker;

    $tracker->trackKeys(keyTrackerTrackItem([]), [5, 'named_key'], [7]);

    expect($tracker->getUnaddressedKeys())->toBe(['named_key']);
});

it('does not let a non-string mapped key address a string key that reads the same', function (): void {
    $tracker = new KeyTracker;

    $tracker->trackKeys(keyTrackerTrackItem([]), ['7'], [7]);

    expect($tracker->getUnaddressedKeys())->toBe(['7']);
});

it('keeps every report when meta keys arrive keyed by name', function (): void {
    $tracker = new KeyTracker;

    $tracker->trackKeys(keyTrackerTrackItem([]), ['a' => 'first_key'], []);
    $tracker->trackKeys(keyTrackerTrackItem([]), ['a' => 'second_key'], []);

    expect($tracker->getUnaddressedKeys())->toBe(['first_key', 'second_key']);
});
