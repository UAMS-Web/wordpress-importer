<?php

declare(strict_types=1);

/**
 * Tests for Alignment::translate(), which maps a WordPress alignment value or
 * class string to a Statamic alignment.
 *
 * Image sets keep left and right; other sets fold them into aligncenter. A
 * class string is reduced to the first `align<value>` it contains, and anything
 * unrecognized becomes aligncenter. These cases pin the mapping every caller
 * relies on (wordpress-importer-archive#1360).
 *
 * @command  php vendor/bin/pest tests/Unit/AlignmentTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Utilities\Alignment;

it('maps each alignment for an image set and for any other set', function (string $align, string $forImage, string $forOther): void {
    expect(Alignment::translate($align, true))->toBe($forImage)
        ->and(Alignment::translate($align, false))->toBe($forOther);
})->with([
    'left' => ['left', 'alignleft', 'aligncenter'],
    'right' => ['right', 'alignright', 'aligncenter'],
    'center' => ['center', 'aligncenter', 'aligncenter'],
    'full' => ['full', 'alignwide', 'alignwide'],
    'wide' => ['wide', 'alignwide', 'alignwide'],
    'empty' => ['', 'aligncenter', 'aligncenter'],
    'unrecognized value' => ['justify', 'aligncenter', 'aligncenter'],
    'uppercase value' => ['LEFT', 'aligncenter', 'aligncenter'],
    'class alignleft' => ['alignleft', 'alignleft', 'aligncenter'],
    'class alignright among others' => ['wp-block-image alignright size-large', 'alignright', 'aligncenter'],
    'class aligncenter' => ['aligncenter', 'aligncenter', 'aligncenter'],
    'class alignfull' => ['alignfull', 'alignwide', 'alignwide'],
    'class alignwide' => ['is-layout alignwide', 'alignwide', 'alignwide'],
    'class with no alignment' => ['wp-image-1953 size-full', 'aligncenter', 'aligncenter'],
    'first alignment class wins' => ['alignright alignleft', 'alignright', 'aligncenter'],
    'alignment class as a prefix' => ['alignleftish', 'alignleft', 'aligncenter'],
    'bare align' => ['align', 'aligncenter', 'aligncenter'],
]);

it('treats a call without forImage as an image set', function (): void {
    expect(Alignment::translate('left'))->toBe('alignleft')
        ->and(Alignment::translate('right'))->toBe('alignright');
});
