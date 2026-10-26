<?php

declare(strict_types=1);

/**
 * Tests for the Formidable Forms export validator (#1314).
 *
 * `validate()` accepts an array holding at least one form with an `id` and a
 * `name`. It is the only check the import path runs (wordpress-importer-archive#1370).
 *
 * @command  php vendor/bin/pest tests/Unit/FormidableFormsValidatorTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Validator;

it('names its plugin', function (): void {
    expect((new ReflectionProperty(BaseFormPluginValidator::class, 'pluginName'))->getValue(new Validator))->toBe('FormidableForms');
});

it('accepts an export when at least one form has an id and a name', function (mixed $forms, bool $expected): void {
    expect((new Validator)->validate($forms))->toBe($expected);
})->with([
    'one valid form' => [[['id' => 1, 'name' => 'Contact']], true],
    'a valid form after invalid ones' => [[['id' => 1], 'x', ['id' => 2, 'name' => 'B']], true],
    'keyed by form id' => [['7' => ['id' => 7, 'name' => 'Seven']], true],
    'no name' => [[['id' => 1]], false],
    'the other plugin\'s field instead' => [[['id' => 1, 'title' => 'Contact']], false],
    'no id' => [[['name' => 'Contact']], false],
    'a null name' => [[['id' => 1, 'name' => null]], false],
    'a form that is not an array' => [['id=1'], false],
    'no forms' => [[], false],
    'not an array' => ['{"id":1}', false],
]);
