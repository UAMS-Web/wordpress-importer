<?php

declare(strict_types=1);

/**
 * Tests that the legacy [subpage-list] shortcode is recognized and dropped.
 *
 * subpage-list rendered a list of a page's child pages in WordPress. The Statamic
 * page template renders subpage navigation from the page hierarchy on its own, so
 * the importer recognizes the shortcode and strips it rather than leaking the raw
 * [subpage-list ...] text into imported content or reporting it as unaddressed.
 * (See issue wordpress-importer-archive#317.)
 *
 * @command  php vendor/bin/pest tests/Unit/SubpageListShortcodeTest.php
 */

use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;

/**
 * A typed console output for the processor calls under test.
 *
 * The processor only writes progress to this sink and it is never asserted on,
 * so a NullOutput is sufficient. Returning it from a typed function (rather than
 * reading it back off a dynamic $this-> property, which PHPStan widens to mixed)
 * gives PHPStan the concrete OutputInterface each call site expects.
 */
function subpageListOutput(): OutputInterface
{
    return new NullOutput;
}

beforeEach(function (): void {
    // Start each test with a clean warning collection
    ImportWarningManager::clearWarnings();

    $this->processor = new ShortcodeProcessor;
    $this->assetImporter = $this::createStub(AssetImporter::class);
});

afterEach(function (): void {
    // Clear shared static state so it doesn't leak between tests
    ImportWarningManager::clearWarnings();
});

it('registers subpage-list as a recognized dropped shortcode', function (): void {
    expect($this->processor->isDroppedShortcode('subpage-list'))->toBeTrue();
    expect($this->processor->isDroppedShortcode('show-people'))->toBeFalse();
    expect($this->processor->isDroppedShortcode('not-a-shortcode'))->toBeFalse();
});

it('recognizes subpage-list in extract() so it is not skipped', function (): void {
    $shortcodes = $this->processor->extract('[subpage-list link=""]', subpageListOutput());

    expect($shortcodes)->toHaveCount(1);
    expect($shortcodes[0])->toBeArray();

    // toBeArray() does not narrow for PHPStan; narrow before indexing.
    $shortcode = is_array($shortcodes[0]) ? $shortcodes[0] : [];
    expect($shortcode['name'])->toBe('subpage-list');
    expect($shortcode['original'])->toBe('[subpage-list link=""]');
});

it('emits an empty string from toTiptapHtml for subpage-list', function (): void {
    $shortcode = [
        'name' => 'subpage-list',
        'attributes' => ['link' => ''],
        'content' => '',
        'original' => '[subpage-list link=""]',
    ];

    $html = $this->processor->toTiptapHtml(
        $shortcode,
        'default',
        $this->assetImporter,
        subpageListOutput(),
        [],
        [],
        'management-of-the-neonate'
    );

    expect($html)->toBe('');
});

it('strips subpage-list from inline content without tracking it as unaddressed', function (): void {
    $content = '<p>[subpage-list link=""]</p>';

    $result = $this->processor->processShortcodesInContent(
        $content,
        'default',
        $this->assetImporter,
        subpageListOutput(),
        [],
        [],
        [],
        'management-of-the-neonate'
    );

    // The literal shortcode text is removed from the content.
    expect($result)->not->toContain('[subpage-list');

    // A recognized drop is not tracked as unaddressed.
    expect(
        ImportWarningManager::getWarnings('content', 'unaddressed_shortcodes')
    )->toHaveCount(0);
    expect($this->processor->getUnaddressedNodes())->toBeEmpty();
});

it('scopes the drop to subpage-list only, leaving other unknown shortcodes intact', function (): void {
    // A different unregistered shortcode is not a recognized drop.
    expect($this->processor->isDroppedShortcode('totally-unknown-shortcode'))->toBeFalse();

    $result = $this->processor->processShortcodesInContent(
        '<p>[totally-unknown-shortcode]</p>',
        'default',
        $this->assetImporter,
        subpageListOutput(),
        [],
        [],
        [],
        'some-page'
    );

    // Unlike subpage-list, the unknown shortcode is not stripped — its raw text
    // is preserved (it is neither converted nor dropped).
    expect($result)->toContain('[totally-unknown-shortcode]');
});
