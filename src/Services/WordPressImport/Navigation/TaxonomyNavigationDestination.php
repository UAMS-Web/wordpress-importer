<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Navigation;

/**
 * The outcome of resolving a WordPress taxonomy navigation item.
 *
 * A Statamic navigation branch carries an **entry reference** or a literal URL
 * and nothing else — there is no term reference — so a WordPress nav item that
 * links to a taxonomy term either lands as the entry that term imported into,
 * or does not land at all. This type is that two-way answer, and it carries the
 * *reason* for the second case so the importer can say why an item was dropped
 * instead of logging the same sentence for every cause.
 *
 * The reason travels in two forms deliberately: {@see self::$skipCode} is a
 * stable token for log context and filtering, {@see self::$skipReason} is the
 * sentence an operator reads in the import summary. Neither is derived from the
 * other, because a code that reads well in a log makes a poor sentence.
 */
final class TaxonomyNavigationDestination
{
    /**
     * Build a resolved or skipped destination.
     *
     * @param  ?string  $entryId  The Statamic entry id, or null when the item was skipped
     * @param  ?string  $skipCode  A stable token naming the cause, or null when resolved
     * @param  ?string  $skipReason  The operator-facing explanation, or null when resolved
     */
    private function __construct(
        public readonly ?string $entryId,
        public readonly ?string $skipCode,
        public readonly ?string $skipReason,
    ) {}

    /**
     * Build a destination that resolved to a Statamic entry.
     *
     * @param  string  $entryId  The Statamic entry id the navigation item points at
     * @return self The resolved destination
     */
    public static function entry(
        string $entryId
    ): self {
        return new self(
            $entryId,
            null,
            null
        );
    }

    /**
     * Build a destination the importer could not resolve.
     *
     * @param  string  $skipCode  A stable token naming the cause
     * @param  string  $skipReason  The operator-facing explanation
     * @return self The skipped destination
     */
    public static function skipped(
        string $skipCode,
        string $skipReason
    ): self {
        return new self(
            null,
            $skipCode,
            $skipReason
        );
    }

    /**
     * Determine whether the item resolved to an entry the navigation can reference.
     *
     * @return bool True when {@see self::$entryId} names a Statamic entry
     */
    public function isResolved(): bool
    {
        return $this->entryId !== null;
    }
}
