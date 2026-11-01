<?php

declare(strict_types=1);

/**
 * Tests for the Ninja Forms data manager: the plugin-named store methods, and
 * the per-form store that normalizes each `.nff` export to the shape the shared
 * creation engine reads (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/NinjaFormsDataManagerTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager;

it('names its plugin and starts empty', function (): void {
    $manager = new DataManager;

    expect((new ReflectionProperty(BaseDataManager::class, 'pluginName'))->getValue($manager))->toBe('NinjaForms')
        ->and($manager->getNinjaFormsData())->toBeNull()
        ->and($manager->getNinjaForm(1))->toBeNull();
});

it('stores, returns and clears forms through the plugin-named methods', function (): void {
    $manager = new DataManager;
    $forms = [3 => ['id' => 3], 4 => ['id' => 4]];

    $manager->setNinjaFormsData($forms);

    expect($manager->getNinjaFormsData())->toBe($forms)
        ->and($manager->getFormsData())->toBe($forms);

    $manager->clearNinjaFormsData();

    expect($manager->getNinjaFormsData())->toBeNull()
        ->and($manager->hasFormsData())->toBeFalse();
});

it('lifts the settings to the top level, keeps the export\'s own keys, and stamps the form ID', function (): void {
    $manager = new DataManager;

    $manager->addNinjaForm(7, [
        'id' => 'ignored',
        'fields' => ['f'],
        'settings' => ['title' => 'Contact', 'fields' => 'from settings'],
    ]);

    expect($manager->getNinjaForm(7))->toBe([
        'title' => 'Contact',
        'fields' => ['f'],
        'id' => 7,
        'settings' => ['title' => 'Contact', 'fields' => 'from settings'],
    ]);
});

it('stores an export whose settings is not an array without lifting anything', function (): void {
    $manager = new DataManager;

    $manager->addNinjaForm('9', ['settings' => 'not an array', 'fields' => []]);

    expect($manager->getNinjaForm('9'))->toBe(['settings' => 'not an array', 'fields' => [], 'id' => '9']);
});

it('keeps every export supplied in the run', function (): void {
    $manager = new DataManager;

    $manager->addNinjaForm(1, ['fields' => []]);
    $manager->addNinjaForm(2, ['fields' => []]);

    expect($manager->getNinjaFormsData())->toBe([
        1 => ['fields' => [], 'id' => 1],
        2 => ['fields' => [], 'id' => 2],
    ]);
});

it('returns no export for a form ID nothing was supplied for', function (): void {
    $manager = new DataManager;
    $manager->addNinjaForm(1, ['fields' => []]);

    expect($manager->getNinjaForm(2))->toBeNull();
});

it('returns no export when the stored value for a form ID is not an array', function (): void {
    $manager = new DataManager;
    $manager->setNinjaFormsData([5 => 'not an export']);

    expect($manager->getNinjaForm(5))->toBeNull();
});
