<?php

declare(strict_types=1);

/**
 * Tests for the Gravity Forms export validator (#1314).
 *
 * `validate()` accepts an array holding at least one form with an `id` and a
 * `title`. It is the only check the import path runs (wordpress-importer-archive#1370).
 *
 * @command  php vendor/bin/pest tests/Unit/GravityFormsValidatorTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\GravityForms\Validator;

it('names its plugin', function (): void {
    expect((new ReflectionProperty(BaseFormPluginValidator::class, 'pluginName'))->getValue(new Validator))->toBe('GravityForms');
});

it('accepts an export when at least one form has an id and a title', function (mixed $forms, bool $expected): void {
    expect((new Validator)->validate($forms))->toBe($expected);
})->with([
    'one valid form' => [[['id' => 1, 'title' => 'Contact']], true],
    'a valid form after invalid ones' => [[['id' => 1], 'x', ['id' => 2, 'title' => 'B']], true],
    'keyed by form id' => [['7' => ['id' => 7, 'title' => 'Seven']], true],
    'no title' => [[['id' => 1]], false],
    'the other plugin\'s field instead' => [[['id' => 1, 'name' => 'Contact']], false],
    'no id' => [[['title' => 'Contact']], false],
    'a null title' => [[['id' => 1, 'title' => null]], false],
    'a form that is not an array' => [['id=1'], false],
    'no forms' => [[], false],
    'not an array' => ['{"id":1}', false],
]);
