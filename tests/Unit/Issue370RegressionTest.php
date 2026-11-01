<?php

declare(strict_types=1);

/**
 * Regression test for wordpress-importer-archive#370 / wordpress-importer-archive#376 / wordpress-importer-archive#369.
 *
 * StackedParser::allItemsHaveNoImages() reads each section's image field as a
 * STRING ($normalizedData[...] ?? ''). A PHPStan cleanup regression changed the
 * "not empty" guard to compare against [] instead of '', so the string image
 * value never matched and the method always reported that items had no images.
 * This caused text-stacked blocks that DO have images to be misclassified as
 * link-list candidates.
 *
 * @command  php vendor/bin/pest tests/Unit/Issue370RegressionTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\StackedParser;

it('reports whether every stacked section lacks an image', function (array $normalizedData, bool $expected): void {
    expect(StackedParser::allItemsHaveNoImages($normalizedData))->toBe($expected);
})->with([
    // A non-empty string image in any section means not all sections lack one.
    'string image in first section' => [
        [
            'stacked_section' => 2,
            'stacked_section_0_stacked_section_image' => '123',
            'stacked_section_1_stacked_section_image' => '',
        ],
        false,
    ],
    'string image in second section' => [
        [
            'stacked_section' => 2,
            'stacked_section_0_stacked_section_image' => '',
            'stacked_section_1_stacked_section_image' => '456',
        ],
        false,
    ],
    'every section image empty' => [
        [
            'stacked_section' => 2,
            'stacked_section_0_stacked_section_image' => '',
            'stacked_section_1_stacked_section_image' => '',
        ],
        true,
    ],
]);
