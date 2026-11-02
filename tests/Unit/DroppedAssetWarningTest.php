<?php

declare(strict_types=1);

/**
 * Tests that a dropped asset reaches the end-of-run warning summary.
 *
 * wordpress-importer-archive#1161: an asset whose upload failed was reported only inline, at the moment it
 * happened — position 534 of an 800-line run in the reported case — while the
 * summary an operator actually reads carried sections for unresolvable categories
 * and unrenderable galleries and none for lost images. Three irreplaceable
 * originals were dropped and the run ended `Import process complete.`
 *
 * The section must also be absent when nothing was dropped, so a clean run does not
 * grow a reassuring empty heading.
 *
 * @command  php vendor/bin/pest tests/Unit/DroppedAssetWarningTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningDisplayService;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

it('names a dropped asset in the end-of-run warning summary', function (): void {
    ImportWarningManager::addWarning('assets', 'dropped_assets', [
        'attachment_id' => '2993',
        'url' => 'https://headstart.uams.edu/wp-content/uploads/2021/04/uams-head-start-logo.jpg',
        'container' => 'images',
        'site' => 'head_start',
        'page_url' => 'https://headstart.uams.edu/locations/kennedy-head-start/',
    ]);

    $output = new BufferedOutput;
    ImportWarningDisplayService::displayAllWarnings($output);
    $rendered = $output->fetch();

    // Its own section, so it is findable rather than buried in the run log.
    expect($rendered)->toContain('assets - dropped_assets');

    // Every field an operator needs to go and fetch the image by hand.
    expect($rendered)->toContain('2993');
    expect($rendered)->toContain('uams-head-start-logo.jpg');
    expect($rendered)->toContain('images');
    expect($rendered)->toContain('kennedy-head-start');
});

it('omits the section entirely when no asset was dropped', function (): void {
    // Control: a clean run must not grow an empty heading that reads as a finding.
    ImportWarningManager::addWarning('content', 'unrenderable_gallery_blocks', [
        'entry' => 'https://example.com/a-page/',
    ]);

    $output = new BufferedOutput;
    ImportWarningDisplayService::displayAllWarnings($output);
    $rendered = $output->fetch();

    expect($rendered)->not->toContain('dropped_assets');
    // The probe can see: an unrelated section on the same run does render.
    expect($rendered)->toContain('unrenderable_gallery_blocks');
});
