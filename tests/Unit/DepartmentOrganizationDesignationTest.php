<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationDesignation;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationOutcome;

/**
 * A `DepartmentOrganizationDesignation` leaves its site designated only when the outcome is a
 * designation or one already in place (#102).
 *
 * @command  php vendor/bin/pest tests/Unit/DepartmentOrganizationDesignationTest.php
 */
it('counts the site as designated only after a designation, new or already in place', function (DepartmentOrganizationOutcome $outcome, bool $designated): void {
    expect((new DepartmentOrganizationDesignation('default', $outcome))->isDesignated())->toBe($designated);
})->with([
    'designated' => [DepartmentOrganizationOutcome::Designated, true],
    'already designated' => [DepartmentOrganizationOutcome::AlreadyDesignated, true],
    'deliberately unmapped' => [DepartmentOrganizationOutcome::DeliberatelyUnmapped, false],
    'not recorded' => [DepartmentOrganizationOutcome::NotRecorded, false],
    'a dangling reference' => [DepartmentOrganizationOutcome::DanglingReference, false],
    'a missing global set' => [DepartmentOrganizationOutcome::GlobalMissing, false],
    'an unavailable site' => [DepartmentOrganizationOutcome::SiteUnavailable, false],
]);

it('carries the organization named and its schema subject flag, empty by default', function (): void {
    $bare = new DepartmentOrganizationDesignation('default', DepartmentOrganizationOutcome::NotRecorded);
    $full = new DepartmentOrganizationDesignation('research', DepartmentOrganizationOutcome::Designated, 'college-of-medicine', true);

    expect([$bare->site, $bare->organizationSlug, $bare->schemaSubjectMissing])->toBe(['default', null, false])
        ->and([$full->site, $full->organizationSlug, $full->schemaSubjectMissing])->toBe(['research', 'college-of-medicine', true]);
});
