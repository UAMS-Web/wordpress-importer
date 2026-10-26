<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Globals;

/**
 * The result of applying one site's `department_organization` designation.
 *
 * Carries the outcome alongside the two facts a caller needs in order to report
 * it without re-querying: which organization was named, and whether that
 * organization is missing its `schema_subject`.
 *
 * The `schema_subject` flag is deliberately part of the *success* path rather
 * than an error. Designating a site whose organization has no schema subject is
 * the accepted outcome recorded on `UAMS-Web/wordpress-importer-archive#768` — the
 * host app's `multisite:audit-department-hubs` command will report it, and the
 * authoring that clears it belongs to `UAMS-Web/uams-statamic#2095`. The flag
 * exists so those failures are *attributed* by this import rather than
 * discovered later by whoever runs the audit.
 */
final readonly class DepartmentOrganizationDesignation
{
    /**
     * @param  string  $site  The Statamic site handle
     * @param  DepartmentOrganizationOutcome  $outcome  What happened
     * @param  string|null  $organizationSlug  The organization named, when one was
     * @param  bool  $schemaSubjectMissing  Whether that organization has no `schema_subject`
     */
    public function __construct(
        public string $site,
        public DepartmentOrganizationOutcome $outcome,
        public ?string $organizationSlug = null,
        public bool $schemaSubjectMissing = false
    ) {}

    /**
     * Whether this designation left the site opted in as a hub.
     */
    public function isDesignated(): bool
    {
        return $this->outcome === DepartmentOrganizationOutcome::Designated
            || $this->outcome === DepartmentOrganizationOutcome::AlreadyDesignated;
    }
}
