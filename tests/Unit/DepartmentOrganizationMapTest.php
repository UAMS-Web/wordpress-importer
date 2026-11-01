<?php

declare(strict_types=1);

/**
 * Unit tests for `DepartmentOrganizationMap` (wordpress-importer-archive#768).
 *
 * The map's whole job is to keep three states distinguishable — designated,
 * reviewed-and-rejected, and never-examined — because two of them produce the
 * same absent designation and only one of them is outstanding work. These tests
 * pin that distinction rather than the lookup, which is trivial.
 *
 * The recorded tables ship empty pending the authoring step the ticket describes,
 * so every case here constructs the map directly with its own data. That is the
 * seam the class exposes for exactly this reason.
 *
 * @command  php vendor/bin/pest tests/Unit/DepartmentOrganizationMapTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationMap;

it('returns the designated organization for a mapped site', function (): void {
    $map = new DepartmentOrganizationMap(
        ['medicine' => 'college-of-medicine'],
        []
    );

    expect($map->organizationFor('medicine'))->toBe('college-of-medicine');
});

it('returns null for a site that is deliberately unmapped', function (): void {
    $map = new DepartmentOrganizationMap(
        [],
        ['medicineandmeaning']
    );

    expect($map->organizationFor('medicineandmeaning'))->toBeNull();
});

it('returns null for a site nobody has examined', function (): void {
    $map = new DepartmentOrganizationMap([], []);

    expect($map->organizationFor('never-looked-at'))->toBeNull();
});

it('distinguishes a reviewed rejection from an unexamined site', function (): void {
    $map = new DepartmentOrganizationMap(
        [],
        ['medicineandmeaning']
    );

    // Both return null from organizationFor(); only one has been looked at.
    expect($map->isRecorded('medicineandmeaning'))->toBeTrue()
        ->and($map->isRecorded('never-looked-at'))->toBeFalse()
        ->and($map->isDeliberatelyUnmapped('medicineandmeaning'))->toBeTrue()
        ->and($map->isDeliberatelyUnmapped('never-looked-at'))->toBeFalse();
});

it('counts a designated site as recorded', function (): void {
    $map = new DepartmentOrganizationMap(
        ['medicine' => 'college-of-medicine'],
        []
    );

    expect($map->isRecorded('medicine'))->toBeTrue()
        ->and($map->isDeliberatelyUnmapped('medicine'))->toBeFalse();
});

it('exposes both tables for reporting', function (): void {
    $map = new DepartmentOrganizationMap(
        ['medicine' => 'college-of-medicine'],
        ['medicineandmeaning']
    );

    expect($map->designatedSites())->toBe(['medicine' => 'college-of-medicine'])
        ->and($map->deliberatelyUnmappedSites())->toBe(['medicineandmeaning']);
});

it('ships with empty recorded tables until the crosswalk is authored', function (): void {
    $map = DepartmentOrganizationMap::recorded();

    // Pins the honest state: the mechanism is built, the map is not authored.
    // A future change that authors it should update this expectation deliberately.
    expect($map->designatedSites())->toBe([])
        ->and($map->deliberatelyUnmappedSites())->toBe([]);
});
