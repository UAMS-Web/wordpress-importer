<?php

declare(strict_types=1);

/**
 * The container hands each plugin ONE `DataManager`, and different plugins different ones (wordpress-importer-archive#1103).
 *
 * With the store per instance, this binding is the only thing that makes the coordinator,
 * the user-interaction handler and the form-creation coordinator — which all take a
 * plugin's manager by injection — read what the others wrote. Without it the live flow
 * breaks silently: the handler binds forms into one instance and the coordinator reads an
 * empty other. Under the old static store an unbound manager still worked, which is why
 * the binding did not exist and why nothing noticed.
 *
 * @command  php vendor/bin/pest tests/Integration/DataManagerBindingTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\DataManager as FormidableFormsDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\DataManager as GravityFormsDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager as NinjaFormsDataManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

it('resolves one manager per plugin, so separately injected consumers share it', function (): void {
    foreach ([NinjaFormsDataManager::class, GravityFormsDataManager::class, FormidableFormsDataManager::class] as $class) {
        $first = app($class);
        $second = app($class);

        expect($second)->toBe($first);

        // The sharing is real, not merely identity: a write through one resolution is read
        // through the other, which is the property the coordinator and handler depend on.
        $first->setFormsData([1 => ['id' => 1, 'title' => $class]]);

        expect($second->getFormsData())->toBe([1 => ['id' => 1, 'title' => $class]]);
    }
});

it('resolves different plugins to different managers with independent stores', function (): void {
    $ninja = app(NinjaFormsDataManager::class);
    $gravity = app(GravityFormsDataManager::class);

    expect($gravity)->not->toBe($ninja);

    $ninja->setFormsData([3 => ['id' => 3, 'title' => 'Contact Us']]);

    // A second plugin's load must not appear in the first's manager, and vice versa.
    expect($gravity->hasFormsData())->toBeFalse();

    $gravity->setFormsData([12 => ['id' => 12, 'title' => 'Referral']]);

    expect($ninja->getFormsData())->toBe([3 => ['id' => 3, 'title' => 'Contact Us']]);
});
