<?php

declare(strict_types=1);

/**
 * Unit tests for `BaseDataManager`'s store lifetime (wordpress-importer-archive#1103).
 *
 * The store is per instance: two managers are two stores, whatever their class. Every case
 * here writes DIFFERENT values into different managers and asserts the other side did not
 * move, because equal values cannot expose a shared store — a comparison of two managers
 * holding the same value passes whether they share it or not. That is the false green the
 * ticket was filed about, and it is why the equivalence arm in
 * `tests/Integration/NinjaFormsSourcingTest.php` cannot stand in for this file.
 *
 * Under the static store these fail: the second manager reads what the first wrote.
 *
 * @command  php vendor/bin/pest tests/Unit/BaseDataManagerTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\DataManager as GravityFormsDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\DataManager as NinjaFormsDataManager;

it('gives a second manager of the same plugin its own empty store', function (): void {
    $first = new NinjaFormsDataManager;
    $second = new NinjaFormsDataManager;

    $first->setFormsData([3 => ['id' => 3, 'title' => 'Contact Us']]);

    expect($first->hasFormsData())->toBeTrue()
        ->and($second->hasFormsData())->toBeFalse()
        ->and($second->getFormsData())->toBeNull();
});

it('keeps two managers of the same plugin holding different values', function (): void {
    $first = new NinjaFormsDataManager;
    $second = new NinjaFormsDataManager;

    $first->setFormsData([3 => ['id' => 3, 'title' => 'Contact Us']]);
    $second->setFormsData([7 => ['id' => 7, 'title' => 'Feedback']]);

    // Read the FIRST after the second wrote: a shared store would hand back the second's value.
    expect($first->getFormsData())->toBe([3 => ['id' => 3, 'title' => 'Contact Us']])
        ->and($second->getFormsData())->toBe([7 => ['id' => 7, 'title' => 'Feedback']]);
});

it('keeps one plugin\'s load out of another plugin\'s manager', function (): void {
    // No subclass redeclared the old static, so every plugin shared ONE slot and a second
    // plugin's load overwrote the first's. Two plugins, two managers, two stores.
    $ninja = new NinjaFormsDataManager;
    $gravity = new GravityFormsDataManager;

    $ninja->setFormsData([3 => ['id' => 3, 'title' => 'Contact Us']]);
    $gravity->setFormsData([12 => ['id' => 12, 'title' => 'Referral']]);

    expect($ninja->getFormsData())->toBe([3 => ['id' => 3, 'title' => 'Contact Us']])
        ->and($gravity->getFormsData())->toBe([12 => ['id' => 12, 'title' => 'Referral']]);
});

it('clears only the manager it is called on', function (): void {
    $first = new NinjaFormsDataManager;
    $second = new NinjaFormsDataManager;

    $first->setFormsData([3 => ['id' => 3]]);
    $second->setFormsData([7 => ['id' => 7]]);

    $first->clearFormsData();

    expect($first->hasFormsData())->toBeFalse()
        ->and($second->getFormsData())->toBe([7 => ['id' => 7]]);
});
