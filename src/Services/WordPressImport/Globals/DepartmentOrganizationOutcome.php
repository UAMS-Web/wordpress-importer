<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Globals;

/**
 * What happened when a site's `department_organization` designation was applied.
 *
 * The cases exist to keep four genuinely different situations distinguishable at
 * the call site, because three of them look identical from the outside — the
 * global ends up carrying no designation — and only one of them is a defect:
 *
 *   - {@see self::DeliberatelyUnmapped} is a recorded decision;
 *   - {@see self::NotRecorded} is work nobody has done yet;
 *   - {@see self::DanglingReference} is a designation naming an organization
 *     that does not exist, which the ticket requires be reported rather than
 *     silently skipped.
 *
 * Collapsing them into a boolean is what makes an unauthored crosswalk
 * indistinguishable from a reviewed one.
 */
enum DepartmentOrganizationOutcome: string
{
    /**
     * The designation was written to the site's localized global.
     */
    case Designated = 'designated';

    /**
     * The site already carried this designation; nothing was written.
     */
    case AlreadyDesignated = 'already_designated';

    /**
     * The site was reviewed and denotes no organization at an accepted tier.
     */
    case DeliberatelyUnmapped = 'deliberately_unmapped';

    /**
     * The site appears in neither table, so it has never been examined.
     */
    case NotRecorded = 'not_recorded';

    /**
     * The site is designated, but the named organization does not exist.
     */
    case DanglingReference = 'dangling_reference';

    /**
     * The `uams_multisite_settings` global set does not exist.
     */
    case GlobalMissing = 'global_missing';

    /**
     * The global set exists but is not localized for this site.
     */
    case SiteUnavailable = 'site_unavailable';

    /**
     * Whether this outcome should be surfaced to whoever ran the import.
     *
     * A recorded rejection is not worth a line of output; everything that leaves
     * a site undesignated for a reason somebody may need to act on is.
     */
    public function isReportable(): bool
    {
        return match ($this) {
            self::Designated,
            self::AlreadyDesignated,
            self::DeliberatelyUnmapped => false,
            self::NotRecorded,
            self::DanglingReference,
            self::GlobalMissing,
            self::SiteUnavailable => true,
        };
    }
}
