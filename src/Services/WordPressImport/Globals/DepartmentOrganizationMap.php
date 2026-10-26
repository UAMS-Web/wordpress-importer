<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Globals;

/**
 * Records which `organizations` entry each migrated site is a news hub for.
 *
 * `uams_multisite_settings` carries two coordinated per-site designations, and
 * the host app reads them together: `department_hub_page` names the page that is
 * the site's news surface, and `department_organization` names the organization
 * that page is a hub *for*. Setting the second is what opts a site in as a
 * Departmental News Hub — leaving it empty renders the site's news page as a
 * plain posts listing, which is the documented degrade.
 *
 * WordPress has no equivalent concept and the migration API's `/sites` serves no
 * organization field, so there is **no mechanical source** for this designation.
 * It is a crosswalk onto entries that already exist in the host app and that this
 * importer never creates. The strongest machine signal is a site's title or
 * domain fuzzy-matched against an organization title, which produces a draft
 * rather than an answer.
 *
 * That is why this table is authored rather than derived, and why an absent site
 * is not the same as a rejected one:
 *
 *   - a site in {@see self::$designations} has a confirmed destination;
 *   - a site in {@see self::$deliberatelyUnmapped} was **reviewed and rejected**
 *     because it denotes no college, institute, department, academic division or
 *     service line;
 *   - a site in neither has simply **never been looked at**.
 *
 * {@see self::isRecorded()} is the distinction that matters to a caller, and it
 * is deliberately not the same question as {@see self::organizationFor()}
 * returning null — which is true for a rejected site and an unexamined one
 * alike. Ask which one you mean.
 *
 * The destination field is scoped by `entries_by_organization_role_department_unit`,
 * so only organizations at those tiers are valid targets; a designation naming an
 * organization outside that scope is a dangling reference and is reported rather
 * than written.
 */
final class DepartmentOrganizationMap
{
    /**
     * Build a map over the designations recorded in this package.
     *
     * The recorded tables are empty pending the authoring step described in the
     * class docblock. Callers that need to exercise the mechanism against known
     * data — tests, and a dry-run over a candidate draft — construct the class
     * directly with their own tables instead.
     */
    public static function recorded(): self
    {
        return new self(
            self::DESIGNATIONS,
            self::DELIBERATELY_UNMAPPED
        );
    }

    /**
     * Site handle to `organizations` entry slug, for sites confirmed to denote
     * an organization at an accepted tier.
     *
     * Empty until the crosswalk is authored and confirmed. See the class
     * docblock: a machine draft is not a designation.
     *
     * @var array<string, string>
     */
    private const array DESIGNATIONS = [];

    /**
     * Site handles reviewed and confirmed to denote no organization at an
     * accepted tier.
     *
     * Recorded explicitly so a later audit can distinguish a site that was
     * examined and rejected from one nobody has reached yet.
     *
     * @var list<string>
     */
    private const array DELIBERATELY_UNMAPPED = [];

    /**
     * @param  array<string, string>  $designations  Site handle to organization entry slug
     * @param  list<string>  $deliberatelyUnmapped  Site handles reviewed and rejected
     */
    public function __construct(
        private array $designations,
        private array $deliberatelyUnmapped
    ) {}

    /**
     * The organization slug designated for a site, or null when none is.
     *
     * Returns null both for a site that was reviewed and rejected and for one
     * that has never been examined. Use {@see self::isRecorded()} to tell those
     * apart.
     *
     * @param  string  $site  The Statamic site handle
     * @return string|null The `organizations` entry slug, or null
     */
    public function organizationFor(string $site): ?string
    {
        return $this->designations[$site] ?? null;
    }

    /**
     * Whether a site was reviewed and confirmed to denote no organization.
     *
     * @param  string  $site  The Statamic site handle
     */
    public function isDeliberatelyUnmapped(string $site): bool
    {
        return in_array($site, $this->deliberatelyUnmapped, true);
    }

    /**
     * Whether a site has been examined at all, designated or rejected.
     *
     * @param  string  $site  The Statamic site handle
     */
    public function isRecorded(string $site): bool
    {
        return isset($this->designations[$site])
            || $this->isDeliberatelyUnmapped($site);
    }

    /**
     * Every site with a designated organization, keyed by site handle.
     *
     * @return array<string, string>
     */
    public function designatedSites(): array
    {
        return $this->designations;
    }

    /**
     * Every site reviewed and confirmed to denote no organization.
     *
     * @return list<string>
     */
    public function deliberatelyUnmappedSites(): array
    {
        return $this->deliberatelyUnmapped;
    }
}
