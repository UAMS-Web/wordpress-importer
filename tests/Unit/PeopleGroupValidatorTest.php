<?php

declare(strict_types=1);

/**
 * Tests for PeopleGroupValidator, which configures the shared taxonomy validator
 * for people groups and names its content type by the context it was given (#1314).
 *
 * The values are read through reflection because they are protected hooks that
 * BaseTaxonomyValidator calls; validateTerms() itself needs a booted Statamic and
 * is covered in tests/Integration/StructuredTermValidationTest.php.
 *
 * @command  php vendor/bin/pest tests/Unit/PeopleGroupValidatorTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\Validators\PeopleGroupValidator;

/**
 * Read one protected hook from a validator.
 */
function peopleGroupValidatorHook(PeopleGroupValidator $validator, string $method): mixed
{
    return (new ReflectionMethod($validator, $method))->invoke($validator);
}

it('names the people groups taxonomy and its display name', function (string $method, string $expected): void {
    expect(peopleGroupValidatorHook(new PeopleGroupValidator, $method))->toBe($expected);
})->with([
    'taxonomy handle' => ['getTaxonomyHandle', 'people_groups'],
    'display name' => ['getTaxonomyDisplayName', 'people group'],
]);

it('names people entries as the content type in the entries context', function (): void {
    $validator = new PeopleGroupValidator;
    $validator->setContext('entries');

    expect(peopleGroupValidatorHook($validator, 'getContentTypeName'))->toBe('people entries');
});

it('names people shortcodes as the content type in any other context', function (?string $context): void {
    $validator = new PeopleGroupValidator;

    if ($context !== null) {
        $validator->setContext($context);
    }

    expect(peopleGroupValidatorHook($validator, 'getContentTypeName'))->toBe('people shortcodes');
})->with([
    'no context' => [null],
    'the shortcodes context' => ['shortcodes'],
    'a context differing only in case' => ['Entries'],
]);
