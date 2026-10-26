<?php

declare(strict_types=1);

/**
 * Tests for LogosItemCategoriesValidator, which configures the shared taxonomy
 * validator for the logo list's item categories.
 *
 * The three values are read through reflection because they are protected
 * hooks that BaseTaxonomyValidator calls; validateTerms() itself needs a
 * booted Statamic and is out of scope here.
 *
 * @command  php vendor/bin/pest tests/Unit/LogosItemCategoriesValidatorTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\Validators\LogosItemCategoriesValidator;

it('names the logos item categories taxonomy and its labels', function (string $method, string $expected): void {
    $validator = (new ReflectionClass(LogosItemCategoriesValidator::class))->newInstanceWithoutConstructor();

    expect((new ReflectionMethod($validator, $method))->invoke($validator))->toBe($expected);
})->with([
    'taxonomy handle' => ['getTaxonomyHandle', 'logos_item_categories'],
    'display name' => ['getTaxonomyDisplayName', 'logos item category'],
    'content type name' => ['getContentTypeName', 'logo list entries'],
]);
