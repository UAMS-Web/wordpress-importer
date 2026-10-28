<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Globals;

use Statamic\Entries\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Globals\Variables as StatamicVariables;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;

/**
 * Writes a site's `department_organization` designation into
 * `uams_multisite_settings`, opting the site in as a Departmental News Hub.
 *
 * Mirrors the write path `ImportWordPress::setManualPrimaryNavigation()`
 * already uses — find the global set, take its localization for the site, set a
 * value, save — against a different handle and with three checks that path does
 * not need.
 *
 * **The stored value is a bare entry id, not an array.** `department_organization`
 * is an `entries` field with `max_items: 1`, and Statamic's
 * `Relationship::process()` returns `$data[0]` rather than the array in that
 * case, so a single id is the shape the Control Panel itself writes. Storing
 * `[$id]` would round-trip through `preProcess()` and *look* correct in the CP
 * while differing on disk from every hand-authored designation.
 *
 * **The map keys on slug and this class resolves the id.** A crosswalk a human
 * has to confirm is unreviewable keyed by UUID, and the resolution step is
 * exactly where a designation naming a nonexistent organization becomes
 * visible — reported as {@see DepartmentOrganizationOutcome::DanglingReference}
 * rather than skipped.
 */
final class DepartmentOrganizationDesignator
{
    /**
     * The global set carrying the per-site designations.
     */
    public const string GLOBAL_HANDLE = 'uams_multisite_settings';

    /**
     * The field that opts a site in as a hub.
     */
    public const string FIELD_HANDLE = 'department_organization';

    /**
     * The collection the designation points into.
     */
    public const string ORGANIZATIONS_COLLECTION = 'organizations';

    /**
     * The organization field the host app's hub audit requires.
     */
    public const string SCHEMA_SUBJECT_FIELD = 'schema_subject';

    /**
     * @param  DepartmentOrganizationMap  $map  The authored site to organization crosswalk
     */
    public function __construct(
        private DepartmentOrganizationMap $map
    ) {}

    /**
     * Apply the recorded designation for one site.
     *
     * @param  string  $site  The Statamic site handle
     * @return DepartmentOrganizationDesignation What happened, and what to report
     */
    public function designate(string $site): DepartmentOrganizationDesignation
    {
        // A site reviewed and rejected is a recorded decision, not a gap.
        if ($this->map->isDeliberatelyUnmapped($site)) {
            return new DepartmentOrganizationDesignation(
                $site,
                DepartmentOrganizationOutcome::DeliberatelyUnmapped
            );
        }

        // A site in neither table has never been examined.
        $organizationSlug = $this->map->organizationFor($site);

        if ($organizationSlug === null) {
            return new DepartmentOrganizationDesignation(
                $site,
                DepartmentOrganizationOutcome::NotRecorded
            );
        }

        // Resolve the designated slug against the organizations collection.
        $organization = $this->findOrganization($organizationSlug);

        // A designation naming an organization that does not exist is reported.
        if (! $organization instanceof Entry) {
            return new DepartmentOrganizationDesignation(
                $site,
                DepartmentOrganizationOutcome::DanglingReference,
                $organizationSlug
            );
        }

        // Take the global set the designation lives on.
        $global = GlobalSet::findByHandle(self::GLOBAL_HANDLE);

        if ($global === null) {
            return new DepartmentOrganizationDesignation(
                $site,
                DepartmentOrganizationOutcome::GlobalMissing,
                $organizationSlug
            );
        }

        // Take its localization for this site.
        $localized = $global->in($site);

        if (! $localized instanceof StatamicVariables) {
            return new DepartmentOrganizationDesignation(
                $site,
                DepartmentOrganizationOutcome::SiteUnavailable,
                $organizationSlug
            );
        }

        // Report whether the audit in the host app will flag this organization.
        $schemaSubjectMissing = $this->schemaSubjectIsMissing($organization);

        $organizationId = $organization->id();

        // An organization we cannot address by id is unusable as a designation. A saved entry
        // always has one, so this only narrows the type for phpstan.
        if (! is_string($organizationId)) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return new DepartmentOrganizationDesignation(
                $site,
                DepartmentOrganizationOutcome::DanglingReference,
                $organizationSlug,
                $schemaSubjectMissing
            );
        }

        // Re-running the import must not rewrite an identical designation.
        if ($this->alreadyDesignated($localized, $organizationId)) {
            return new DepartmentOrganizationDesignation(
                $site,
                DepartmentOrganizationOutcome::AlreadyDesignated,
                $organizationSlug,
                $schemaSubjectMissing
            );
        }

        // Store the bare id, which is the shape a max_items:1 entries field takes.
        $localized->set(self::FIELD_HANDLE, $organizationId);
        $localized->save();

        return new DepartmentOrganizationDesignation(
            $site,
            DepartmentOrganizationOutcome::Designated,
            $organizationSlug,
            $schemaSubjectMissing
        );
    }

    /**
     * Find an organization entry by slug.
     *
     * @param  string  $slug  The organization entry slug
     * @return Entry|null The entry, or null when no such organization exists
     */
    private function findOrganization(string $slug): ?Entry
    {
        // EntryQueryHelper narrows the facade to the concrete builder, which is
        // what lets this query type-check at level max.
        $query = EntryQueryHelper::query();
        $query->where('collection', self::ORGANIZATIONS_COLLECTION);
        $query->where('slug', $slug);

        $entry = $query->first();

        // The query answers with an entry or null, so this only narrows the type for phpstan
        // @pest-mutate-ignore: InstanceOfToTrue
        return $entry instanceof Entry ? $entry : null;
    }

    /**
     * Whether the site already carries this exact designation.
     *
     * Tolerates both stored shapes when reading. The Control Panel writes a bare
     * id, but `Relationship::preProcess()` wraps whatever it finds, so a value
     * authored as a single-element array is a state this has to recognize rather
     * than overwrite.
     *
     * @param  StatamicVariables  $localized  The site's localized global
     * @param  string  $organizationId  The entry id that would be written
     */
    private function alreadyDesignated(
        StatamicVariables $localized,
        string $organizationId
    ): bool {
        $current = $localized->get(self::FIELD_HANDLE);

        if (is_string($current)) {
            return $current === $organizationId;
        }

        if (is_array($current)) {
            return $current === [$organizationId];
        }

        return false;
    }

    /**
     * Whether an organization is missing the `schema_subject` the hub audit needs.
     *
     * @param  Entry  $organization  The designated organization entry
     */
    private function schemaSubjectIsMissing(Entry $organization): bool
    {
        $subject = $organization->value(self::SCHEMA_SUBJECT_FIELD);

        return in_array($subject, [null, '', []], true);
    }
}
