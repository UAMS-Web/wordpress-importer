<?php

declare(strict_types=1);

/**
 * Tests for the GravityForms data manager, the store for the forms an export
 * supplied, read and written through its plugin-named methods (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/GravityFormsDataManagerTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\DataManager;

it('names its plugin and starts empty', function (): void {
    $manager = new DataManager;

    expect((new ReflectionProperty(BaseDataManager::class, 'pluginName'))->getValue($manager))->toBe('GravityForms')
        ->and($manager->getGravityFormsData())->toBeNull()
        ->and($manager->hasFormsData())->toBeFalse();
});

it('stores, returns and clears forms through the plugin-named methods', function (): void {
    $manager = new DataManager;
    $forms = [['id' => 1], ['id' => 2]];

    $manager->setGravityFormsData($forms);

    expect($manager->getGravityFormsData())->toBe($forms)
        ->and($manager->getFormsData())->toBe($forms)
        ->and($manager->hasFormsData())->toBeTrue();

    $manager->clearGravityFormsData();

    expect($manager->getGravityFormsData())->toBeNull()
        ->and($manager->hasFormsData())->toBeFalse();
});

it('stores an empty list as data, and null as none', function (): void {
    $manager = new DataManager;

    $manager->setGravityFormsData([]);
    expect($manager->getGravityFormsData())->toBe([]);

    $manager->setGravityFormsData(null);
    expect($manager->getGravityFormsData())->toBeNull();
});
