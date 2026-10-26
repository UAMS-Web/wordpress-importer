<?php

declare(strict_types=1);

/**
 * Tests for the FormidableForms data manager, the store for the forms an export
 * supplied, read and written through its plugin-named methods (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/FormidableFormsDataManagerTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\DataManager;

it('names its plugin and starts empty', function (): void {
    $manager = new DataManager;

    expect((new ReflectionProperty(BaseDataManager::class, 'pluginName'))->getValue($manager))->toBe('FormidableForms')
        ->and($manager->getFormidableFormsData())->toBeNull()
        ->and($manager->hasFormsData())->toBeFalse();
});

it('stores, returns and clears forms through the plugin-named methods', function (): void {
    $manager = new DataManager;
    $forms = [['id' => 1], ['id' => 2]];

    $manager->setFormidableFormsData($forms);

    expect($manager->getFormidableFormsData())->toBe($forms)
        ->and($manager->getFormsData())->toBe($forms)
        ->and($manager->hasFormsData())->toBeTrue();

    $manager->clearFormidableFormsData();

    expect($manager->getFormidableFormsData())->toBeNull()
        ->and($manager->hasFormsData())->toBeFalse();
});

it('stores an empty list as data, and null as none', function (): void {
    $manager = new DataManager;

    $manager->setFormidableFormsData([]);
    expect($manager->getFormidableFormsData())->toBe([]);

    $manager->setFormidableFormsData(null);
    expect($manager->getFormidableFormsData())->toBeNull();
});

it('reports whether any forms data is held, an empty list included', function (): void {
    $manager = new DataManager;

    expect($manager->hasFormidableFormsData())->toBeFalse();

    $manager->setFormidableFormsData([]);
    expect($manager->hasFormidableFormsData())->toBeTrue();

    $manager->setFormidableFormsData([['id' => 1]]);
    expect($manager->hasFormidableFormsData())->toBeTrue();

    $manager->clearFormidableFormsData();
    expect($manager->hasFormidableFormsData())->toBeFalse();
});
