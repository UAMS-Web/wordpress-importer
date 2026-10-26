<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers;

/**
 * Immutable description of the WXR file(s) that make up a single logical import.
 *
 * A WordPress export that is too large for one file is written as a numbered
 * part-set (`<base>.000.xml`, `<base>.001.xml`, …). Each part is a complete,
 * standalone WXR document whose channel-level metadata is duplicated while its
 * `<item>` nodes are partitioned across the parts. This value object captures
 * the ordered parts discovered for an import, whether the input was actually a
 * split export, and any missing indices in the numbered sequence so the command
 * can refuse to silently import a partial set.
 */
final class SplitExportPartSet
{
    /**
     * Create a new part-set descriptor.
     *
     * @param  list<string>  $parts  Absolute/relative part paths in ascending part order
     * @param  list<int>  $missingParts  Part indices absent from the numbered sequence (a gap)
     * @param  bool  $isSplit  Whether the input resolved to more than one numbered part
     */
    public function __construct(
        public readonly array $parts,
        public readonly array $missingParts,
        public readonly bool $isSplit,
    ) {}

    /**
     * Determine whether the numbered sequence has a gap (a missing part).
     *
     * @return bool True when at least one expected part index is absent
     */
    public function hasGap(): bool
    {
        return $this->missingParts !== [];
    }

    /**
     * Get the first part path, which carries the channel-level metadata read once.
     *
     * @return ?string The first part path, or null when the set is empty
     */
    public function firstPart(): ?string
    {
        return $this->parts[0] ?? null;
    }
}
