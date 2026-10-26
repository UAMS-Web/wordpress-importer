<?php

declare(strict_types=1);

/**
 * Tests that the Gravity Forms and Formidable Forms selectors hand their own name,
 * validator and loader to the shared selector, and name the slug their file path
 * suggestion is built from (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/FormPluginSelectorIdentityTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Selector as FormidableFormsSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Validator as FormidableFormsValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\GravityForms\Selector as GravityFormsSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\GravityForms\Validator as GravityFormsValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;

/**
 * Read a property or call a method the shared selector keeps protected.
 *
 * @param  BaseFormPluginSelector  $selector  The selector
 * @param  string  $member  The property or method name
 * @return mixed The value
 */
function formPluginSelectorIdentity(BaseFormPluginSelector $selector, string $member): mixed
{
    return method_exists($selector, $member)
        ? (new ReflectionMethod($selector, $member))->invoke($selector)
        : (new ReflectionProperty($selector, $member))->getValue($selector);
}

it('builds the Gravity Forms selector from its own parts', function (): void {
    $validator = new GravityFormsValidator;
    $loader = new GravityFormsLoader;
    $selector = new GravityFormsSelector($validator, $loader);

    expect(formPluginSelectorIdentity($selector, 'pluginName'))->toBe('GravityForms')
        ->and(formPluginSelectorIdentity($selector, 'validator'))->toBe($validator)
        ->and(formPluginSelectorIdentity($selector, 'fileLoader'))->toBe($loader)
        ->and(formPluginSelectorIdentity($selector, 'getPluginSlug'))->toBe('gravity_forms');
});

it('builds the Formidable Forms selector from its own parts', function (): void {
    $validator = new FormidableFormsValidator;
    $loader = new FormidableFormsLoader;
    $selector = new FormidableFormsSelector($validator, $loader);

    expect(formPluginSelectorIdentity($selector, 'pluginName'))->toBe('FormidableForms')
        ->and(formPluginSelectorIdentity($selector, 'validator'))->toBe($validator)
        ->and(formPluginSelectorIdentity($selector, 'fileLoader'))->toBe($loader)
        ->and(formPluginSelectorIdentity($selector, 'getPluginSlug'))->toBe('formidable_forms');
});
