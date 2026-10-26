<?php

declare(strict_types=1);

/**
 * Each plugin's form ID extractor and the Ninja Forms content detector name their plugin (#98).
 *
 * The name is passed up to `BaseFormIdExtractor` or `BaseContentDetector` and stored for
 * logging. Nothing reads it yet, so without this test the `parent::__construct()` call in
 * each subclass survives mutation: dropping it changes no observable behavior. The Gravity
 * Forms and Formidable Forms detectors carry the same assertion in their own test files.
 *
 * @command  php vendor/bin/pest tests/Unit/FormPluginNameTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseContentDetector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseFormIdExtractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormIdExtractor as FormidableFormIdExtractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormIdExtractor as GravityFormIdExtractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\ContentDetector as NinjaContentDetector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormIdExtractor as NinjaFormIdExtractor;

it('names its plugin on each form ID extractor', function (string $class, string $expected): void {
    // Built inside the test, not in the dataset, so coverage attributes the constructor here.
    $extractor = new $class;

    expect($extractor)->toBeInstanceOf(BaseFormIdExtractor::class)
        ->and((new ReflectionProperty(BaseFormIdExtractor::class, 'pluginName'))->getValue($extractor))->toBe($expected);
})->with([
    'Formidable Forms' => [FormidableFormIdExtractor::class, 'FormidableForms'],
    'Gravity Forms' => [GravityFormIdExtractor::class, 'GravityForms'],
    'Ninja Forms' => [NinjaFormIdExtractor::class, 'NinjaForms'],
]);

it('names its plugin on the Ninja Forms content detector', function (): void {
    expect((new ReflectionProperty(BaseContentDetector::class, 'pluginName'))->getValue(new NinjaContentDetector))->toBe('NinjaForms');
});
