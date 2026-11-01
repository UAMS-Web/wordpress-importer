<?php

declare(strict_types=1);

/**
 * Tests that every key `KeyTracker` is meant to treat as handled stays out of its
 * "unaddressed" report: each ignored meta key that does not start with `_` (those
 * that do are filtered out of the report anyway), and each top-level element the
 * tracker collects.
 *
 * The keys are listed in `tests/Fixtures/key-tracker/`, kept apart from the class
 * on purpose: a key dropped from the class's list by mistake then fails its row
 * here, where comparing the class with itself would pass. Adding a key to the class
 * means adding it to the fixture as well.
 *
 * @command  php vendor/bin/pest tests/Unit/KeyTrackerAddressedKeysTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KeyTracker;

/**
 * The keys listed in one of the fixture files, one per line.
 *
 * @return list<string>
 */
function keyTrackerFixtureKeys(string $file): array
{
    $lines = file(__DIR__.'/../Fixtures/key-tracker/'.$file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return $lines === false ? [] : $lines;
}

/**
 * A WXR item holding the given inner XML, with the `wp` namespace declared.
 */
function keyTrackerItem(string $innerXml): SimpleXMLElement
{
    $xml = '<rss xmlns:wp="http://wordpress.org/export/1.2/"><channel><item>'.$innerXml.'</item></channel></rss>';

    return (new SimpleXMLElement($xml))->channel->item;
}

it('reads the fixture lists', function (): void {
    // A missing or empty fixture would turn both datasets below into no tests at all
    expect(keyTrackerFixtureKeys('ignored-meta-keys.txt'))->not->toBe([])
        ->and(keyTrackerFixtureKeys('top-level-xml-keys.txt'))->not->toBe([]);
});

it('does not report an ignored meta key', function (string $key): void {
    $item = keyTrackerItem("<wp:postmeta><wp:meta_key>{$key}</wp:meta_key><wp:meta_value/></wp:postmeta>");
    $tracker = new KeyTracker;

    $tracker->trackKeys($item, [$key], []);

    expect($tracker->getUnaddressedKeys())->toBe([]);
})->with(fn (): array => keyTrackerFixtureKeys('ignored-meta-keys.txt'));

it('does not report a top-level element it collects', function (string $key): void {
    $tracker = new KeyTracker;

    $tracker->trackKeys(keyTrackerItem("<{$key}/>"), [], []);

    expect($tracker->getUnaddressedKeys())->toBe([]);
})->with(fn (): array => array_values(array_filter(
    keyTrackerFixtureKeys('top-level-xml-keys.txt'),
    // Only unprefixed and wp: children are collected; the content:, dc: and
    // excerpt: entries are never read, so there is no element to test them with
    static fn (string $key): bool => ! str_contains($key, ':') || str_starts_with($key, 'wp:')
)));

it('reports an element that is not on the list, so the rows above can fail', function (): void {
    $tracker = new KeyTracker;

    $tracker->trackKeys(keyTrackerItem('<wp:not_a_handled_key/>'), ['not_a_handled_meta_key'], []);

    expect($tracker->getUnaddressedKeys())->toBe(['wp:not_a_handled_key', 'not_a_handled_meta_key']);
});
