<?php

declare(strict_types=1);

/**
 * Integration tests for `DepartmentOrganizationDesignator` (wordpress-importer-archive#768).
 *
 * These live here rather than in the host suite because everything they assert
 * is reachable from a minimal Statamic: a collection, a global set with one
 * localization, and the value the designator writes. The host app contributes
 * the real `organizations` content and the hub audit, neither of which changes
 * what this class decides.
 *
 * The stored shape is the assertion worth reading twice. `department_organization`
 * is an `entries` field with `max_items: 1`, and `Relationship::process()` returns
 * `$data[0]` in that case, so the Control Panel writes a bare id. Asserting
 * `toBe($id)` rather than `toBe([$id])` is what pins this import to the same
 * shape a hand-authored designation takes.
 *
 * @command  php vendor/bin/pest tests/Integration/DepartmentOrganizationDesignatorTest.php
 */

use Statamic\Entries\Entry as ConcreteEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as ConcreteGlobalSet;
use Statamic\Globals\Variables as StatamicVariables;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationDesignator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationMap;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\DepartmentOrganizationOutcome;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Create an organization entry and return its id.
 *
 * @param  string  $slug  The entry slug the crosswalk names
 * @param  array<string, mixed>  $data  Additional field data
 * @return string The created entry's id
 */
function makeOrganization(string $slug, array $data = []): string
{
    // The facade declares the CONTRACT, which has no fluent setters; narrow to the
    // concrete class by control flow rather than by suppressing the error.
    $entry = EntryFacade::make();

    if (! $entry instanceof ConcreteEntry) {
        throw new RuntimeException('Entry::make() did not return a concrete entry.');
    }

    $entry->collection('organizations');
    $entry->slug($slug);
    $entry->data(array_merge(['title' => $slug], $data));
    $entry->save();

    $id = $entry->id();

    if (! is_string($id)) {
        throw new RuntimeException("Organization '$slug' saved without an id.");
    }

    return $id;
}

/**
 * The localized global the designator writes into.
 */
function multisiteSettings(): StatamicVariables
{
    $global = GlobalSet::findByHandle(
        DepartmentOrganizationDesignator::GLOBAL_HANDLE
    );

    if ($global === null) {
        throw new RuntimeException('The multisite settings global was not created.');
    }

    $localized = $global->in('default');

    if (! $localized instanceof StatamicVariables) {
        throw new RuntimeException('The multisite settings global has no default localization.');
    }

    return $localized;
}

beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    // The collection the designation points into
    $collection = CollectionFacade::make('organizations');
    $collection->sites(['default']);
    $collection->save();

    // The global set carrying the per-site designation
    // The GlobalSet facade declares no make(), so construct the concrete set.
    // `in()` creates the localization on demand for any site in sites(), which
    // defaults to the default site — so no explicit localization is needed.
    $global = new ConcreteGlobalSet;
    $global->handle(DepartmentOrganizationDesignator::GLOBAL_HANDLE);
    $global->sites(['default']);
    $global->save();
});

it('writes the designation as a bare entry id', function (): void {
    $id = makeOrganization('college-of-medicine');

    $designator = new DepartmentOrganizationDesignator(
        new DepartmentOrganizationMap(['default' => 'college-of-medicine'], [])
    );

    $result = $designator->designate('default');

    expect($result->outcome)->toBe(DepartmentOrganizationOutcome::Designated)
        ->and($result->organizationSlug)->toBe('college-of-medicine');

    // The shape, not merely the presence — see the file docblock.
    expect(multisiteSettings()->get(DepartmentOrganizationDesignator::FIELD_HANDLE))
        ->toBe($id);
});

it('does not rewrite an identical designation on a re-import', function (): void {
    $id = makeOrganization('college-of-medicine');

    $designator = new DepartmentOrganizationDesignator(
        new DepartmentOrganizationMap(['default' => 'college-of-medicine'], [])
    );

    $designator->designate('default');
    $second = $designator->designate('default');

    expect($second->outcome)->toBe(DepartmentOrganizationOutcome::AlreadyDesignated)
        ->and($second->isDesignated())->toBeTrue()
        ->and(multisiteSettings()->get(DepartmentOrganizationDesignator::FIELD_HANDLE))
        ->toBe($id);
});

it('reports a designation naming an organization that does not exist', function (): void {
    $designator = new DepartmentOrganizationDesignator(
        new DepartmentOrganizationMap(['default' => 'no-such-organization'], [])
    );

    $result = $designator->designate('default');

    expect($result->outcome)->toBe(DepartmentOrganizationOutcome::DanglingReference)
        ->and($result->outcome->isReportable())->toBeTrue()
        ->and($result->organizationSlug)->toBe('no-such-organization');

    // Reported rather than silently skipped, and nothing was written
    expect(multisiteSettings()->get(DepartmentOrganizationDesignator::FIELD_HANDLE))
        ->toBeNull();
});

it('leaves a deliberately unmapped site undesignated without reporting it', function (): void {
    $designator = new DepartmentOrganizationDesignator(
        new DepartmentOrganizationMap([], ['default'])
    );

    $result = $designator->designate('default');

    expect($result->outcome)->toBe(DepartmentOrganizationOutcome::DeliberatelyUnmapped)
        ->and($result->outcome->isReportable())->toBeFalse()
        ->and(multisiteSettings()->get(DepartmentOrganizationDesignator::FIELD_HANDLE))
        ->toBeNull();
});

it('reports a site that has never been examined', function (): void {
    $designator = new DepartmentOrganizationDesignator(
        new DepartmentOrganizationMap([], [])
    );

    $result = $designator->designate('default');

    // Same absent designation as the rejected case, different meaning
    expect($result->outcome)->toBe(DepartmentOrganizationOutcome::NotRecorded)
        ->and($result->outcome->isReportable())->toBeTrue();
});

it('flags a designated organization that has no schema subject', function (): void {
    makeOrganization('college-of-medicine');

    $designator = new DepartmentOrganizationDesignator(
        new DepartmentOrganizationMap(['default' => 'college-of-medicine'], [])
    );

    $result = $designator->designate('default');

    // The designation still succeeds; the gap is attributed, not prevented
    expect($result->outcome)->toBe(DepartmentOrganizationOutcome::Designated)
        ->and($result->schemaSubjectMissing)->toBeTrue();
});

it('does not flag an organization that has a schema subject', function (): void {
    makeOrganization('college-of-medicine', [
        DepartmentOrganizationDesignator::SCHEMA_SUBJECT_FIELD => 'schema-entity-id',
    ]);

    $designator = new DepartmentOrganizationDesignator(
        new DepartmentOrganizationMap(['default' => 'college-of-medicine'], [])
    );

    $result = $designator->designate('default');

    expect($result->schemaSubjectMissing)->toBeFalse();
});
