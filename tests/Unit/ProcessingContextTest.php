<?php

declare(strict_types=1);

/**
 * Tests that each value `ProcessingContext` holds for the item being imported is
 * stored, read back, replaced and cleared on its own, and that the shortcode
 * context sets only what it is given (#1314).
 *
 * The context is process-wide static state that many tests use, so every value
 * a case sets is cleared again afterwards.
 *
 * @command  php vendor/bin/pest tests/Unit/ProcessingContextTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * Every value's setter, getter and clearer, with a sample value and a second one.
 *
 * @return array<string, array{0: Closure, 1: Closure, 2: Closure, 3: mixed, 4: mixed}> The values
 */
function processingContextValues(): array
{
    $config = new AudioSeriesConfig('podcast_series', 'Podcast', 'health-talk', 'Health Talk', 'podcast_series', 'health-talk');
    $other = new AudioSeriesConfig('podcast_series', 'Podcast', 'other', 'Other', 'podcast_series', 'other');

    return [
        'xml path' => [ProcessingContext::setXmlPath(...), ProcessingContext::getXmlPath(...), ProcessingContext::clearXmlPath(...), '/tmp/a.xml', '/tmp/b.xml'],
        'page link' => [ProcessingContext::setWordPressPageLink(...), ProcessingContext::getWordPressPageLink(...), ProcessingContext::clearWordPressPageLink(...), 'https://example.org/a/', 'https://example.org/b/'],
        'entry data' => [ProcessingContext::setEntryData(...), ProcessingContext::getEntryData(...), ProcessingContext::clearEntryData(...), ['post_name' => 'a'], ['post_name' => 'b']],
        'collection' => [ProcessingContext::setCollectionHandle(...), ProcessingContext::getCollectionHandle(...), ProcessingContext::clearCollectionHandle(...), 'pages', 'posts'],
        'entry id' => [ProcessingContext::setStatamicEntryId(...), ProcessingContext::getStatamicEntryId(...), ProcessingContext::clearStatamicEntryId(...), 'id-1', 'id-2'],
        'attachments' => [ProcessingContext::setAttachments(...), ProcessingContext::getAttachments(...), ProcessingContext::clearAttachments(...), ['a'], ['b']],
        'form config' => [ProcessingContext::setFormConfigContext(...), ProcessingContext::getFormConfigContext(...), ProcessingContext::clearFormConfigContext(...), ['form_id' => 1, 'source' => 'notification', 'source_name' => 'A'], ['form_id' => 2, 'source' => 'confirmation', 'source_name' => 'B']],
        'post id' => [ProcessingContext::setWordPressPostId(...), ProcessingContext::getWordPressPostId(...), ProcessingContext::clearWordPressPostId(...), '10', '11'],
        'shortcode content' => [ProcessingContext::setShortcodeContent(...), ProcessingContext::getShortcodeContent(...), ProcessingContext::clearShortcodeContent(...), '[a]', '[b]'],
        'audio series config' => [ProcessingContext::setAudioSeriesConfig(...), ProcessingContext::getAudioSeriesConfig(...), ProcessingContext::clearAudioSeriesConfig(...), $config, $other],
        'post date' => [ProcessingContext::setWordPressPostDate(...), ProcessingContext::getWordPressPostDate(...), ProcessingContext::clearWordPressPostDate(...), '2020-01-01', '2021-01-01'],
        'post title' => [ProcessingContext::setWordPressPostTitle(...), ProcessingContext::getWordPressPostTitle(...), ProcessingContext::clearWordPressPostTitle(...), 'A', 'B'],
        'segment title' => [ProcessingContext::setSegmentTitle(...), ProcessingContext::getSegmentTitle(...), ProcessingContext::clearSegmentTitle(...), 'Part 1', 'Part 2'],
        'audio asset title' => [ProcessingContext::setAudioAssetTitle(...), ProcessingContext::getAudioAssetTitle(...), ProcessingContext::clearAudioAssetTitle(...), 'Episode 1', 'Episode 2'],
        'audio asset series' => [ProcessingContext::setAudioAssetSeries(...), ProcessingContext::getAudioAssetSeries(...), ProcessingContext::clearAudioAssetSeries(...), 'health-talk', 'other'],
        'audio asset excerpt' => [ProcessingContext::setAudioAssetExcerpt(...), ProcessingContext::getAudioAssetExcerpt(...), ProcessingContext::clearAudioAssetExcerpt(...), ['a'], ['b']],
        'audio asset transcript' => [ProcessingContext::setAudioAssetTranscript(...), ProcessingContext::getAudioAssetTranscript(...), ProcessingContext::clearAudioAssetTranscript(...), ['a'], ['b']],
        'minerva kb settings' => [ProcessingContext::setMinervaKbSettings(...), ProcessingContext::getMinervaKbSettings(...), ProcessingContext::clearMinervaKbSettings(...), ['a' => 1], ['a' => 2]],
        'knowledge base id' => [ProcessingContext::setKnowledgeBaseId(...), ProcessingContext::getKnowledgeBaseId(...), ProcessingContext::clearKnowledgeBaseId(...), 'kb-1', 'kb-2'],
    ];
}

/**
 * Clear every value the context holds.
 */
function processingContextClearAll(): void
{
    foreach (processingContextValues() as [, , $clear]) {
        $clear();
    }
}

beforeEach(function (): void {
    processingContextClearAll();
});

afterEach(function (): void {
    processingContextClearAll();
});

it('stores, replaces and clears each value', function (Closure $set, Closure $get, Closure $clear, mixed $value, mixed $other): void {
    expect($get())->toBeNull();

    $set($value);
    expect($get())->toBe($value);

    $set($other);
    expect($get())->toBe($other);

    $clear();
    expect($get())->toBeNull();
})->with(processingContextValues());

it('keeps each value separate from every other', function (): void {
    $values = processingContextValues();

    foreach ($values as [$set, , , $value]) {
        $set($value);
    }

    // Clearing each value in turn leaves every one after it as it was set
    $names = \array_keys($values);

    foreach ($names as $index => $name) {
        [, , $clear] = $values[$name];
        $clear();

        foreach (\array_slice($names, $index + 1) as $later) {
            [, $get, , $value] = $values[$later];
            expect($get())->toBe($value);
        }
    }
});

it('sets an optional value back to null', function (): void {
    ProcessingContext::setXmlPath('/tmp/a.xml');
    ProcessingContext::setXmlPath(null);

    expect(ProcessingContext::getXmlPath())->toBeNull();
});

it('sets only the parts of the shortcode context it is given', function (): void {
    ProcessingContext::setShortcodeContext(['att'], '[gallery]', '42');

    expect(ProcessingContext::getAttachments())->toBe(['att'])
        ->and(ProcessingContext::getShortcodeContent())->toBe('[gallery]')
        ->and(ProcessingContext::getWordPressPostId())->toBe('42');

    // Nothing given leaves what was set
    ProcessingContext::setShortcodeContext();

    expect(ProcessingContext::getAttachments())->toBe(['att'])
        ->and(ProcessingContext::getShortcodeContent())->toBe('[gallery]')
        ->and(ProcessingContext::getWordPressPostId())->toBe('42');
});

it('sets each part of the shortcode context on its own', function (): void {
    ProcessingContext::setShortcodeContext(null, null, '7');

    expect(ProcessingContext::getWordPressPostId())->toBe('7')
        ->and(ProcessingContext::getAttachments())->toBeNull()
        ->and(ProcessingContext::getShortcodeContent())->toBeNull();

    ProcessingContext::setShortcodeContext(null, '[x]');

    expect(ProcessingContext::getShortcodeContent())->toBe('[x]')
        ->and(ProcessingContext::getAttachments())->toBeNull();

    ProcessingContext::setShortcodeContext(['a']);

    expect(ProcessingContext::getAttachments())->toBe(['a']);
});
