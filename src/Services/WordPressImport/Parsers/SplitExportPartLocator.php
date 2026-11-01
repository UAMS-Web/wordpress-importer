<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers;

/**
 * Discovers the sibling parts of a split WordPress export from any one part path.
 *
 * WordPress exports that exceed the exporter's size cap are written as a numbered
 * part-set (`<base>.000.xml`, `<base>.001.xml`, …). The operator points the
 * importer at any single part; this locator recognizes the `<base>.NNN.xml`
 * convention, gathers every co-located numbered sibling, orders them by part
 * index, and reports any gap in the sequence. A path that does not match the
 * convention is returned unchanged as a one-element, non-split set, so ordinary
 * single-file imports are wholly unaffected.
 */
class SplitExportPartLocator
{
    /**
     * Regex matching a split-export part filename, capturing the base and index.
     *
     * Applied to the basename so directory components can never influence the
     * match. The index is exactly three digits per the canonical convention.
     *
     * @var string
     */
    private const string PART_PATTERN = '/^(?<base>.+)\.(?<index>\d{3})\.xml$/';

    /**
     * Resolve the full part-set for a single WXR path.
     *
     * When the path matches the `<base>.NNN.xml` convention, every numbered
     * sibling in the same directory is discovered, ordered ascending by index,
     * and checked for gaps. Otherwise the path is returned as a single,
     * non-split part.
     *
     * @param  string  $filePath  Path to any one part (or a plain single-file export)
     * @return SplitExportPartSet The ordered parts, missing indices, and split flag
     */
    public static function locate(
        string $filePath
    ): SplitExportPartSet {
        // Match the basename against the numbered-part convention
        $baseName = basename($filePath);

        // Bail to single-file behavior when the name is not a numbered part
        if (
            \preg_match(
                self::PART_PATTERN,
                $baseName,
                $matches
            ) !== 1
        ) {
            return new SplitExportPartSet(
                [$filePath],
                [],
                false
            );
        }

        // Resolve the directory and shared base to glob for siblings
        $directory = \dirname($filePath);
        $base = $matches['base'];

        // Discover every numbered sibling co-located with the given part
        $indexToPath = self::discoverSiblings(
            $directory,
            $base
        );

        // Fall back to the single given path when the directory can't be read
        if ($indexToPath === []) {
            return new SplitExportPartSet(
                [$filePath],
                [],
                false
            );
        }

        // Order the discovered parts ascending by numeric part index. scandir() already
        // returns names in ascending order, and the three-digit indices are zero-padded, so
        // the scan order is the index order and removing this sort changes no result. It
        // stays so the order does not depend on how the directory was listed.
        // @pest-mutate-ignore: RemoveFunctionCall
        ksort($indexToPath);

        // Identify any missing indices between .000 and the highest part found
        $missingParts = self::findMissingIndices($indexToPath);

        // Build the ordered part path list from the sorted index map
        $parts = \array_values($indexToPath);

        // Treat a lone `.000` part with no siblings as a plain single file
        $isSplit = \count($parts) > 1;

        return new SplitExportPartSet(
            $parts,
            $missingParts,
            $isSplit
        );
    }

    /**
     * Discover every `<base>.NNN.xml` sibling in a directory, keyed by part index.
     *
     * Uses a directory scan filtered by an anchored regex built from the escaped
     * base, avoiding glob metacharacter surprises in the base name.
     *
     * @param  string  $directory  The directory to scan for sibling parts
     * @param  string  $base  The shared base filename (without the `.NNN.xml` suffix)
     * @return array<int, string> Map of part index to full part path (unordered)
     */
    private static function discoverSiblings(
        string $directory,
        string $base
    ): array {
        // List the directory, returning empty when it cannot be read
        $entries = @scandir($directory);

        if ($entries === false) {
            return [];
        }

        // Build an anchored pattern for this specific base's numbered parts
        $siblingPattern = sprintf(
            '/^%s\.(?<index>\d{3})\.xml$/',
            preg_quote(
                $base,
                '/'
            )
        );

        // Collect matching siblings keyed by their numeric part index
        $indexToPath = [];

        foreach ($entries as $entry) {
            // Skip entries that are not a numbered part of this base
            if (
                \preg_match(
                    $siblingPattern,
                    $entry,
                    $siblingMatches
                ) !== 1
            ) {
                continue;
            }

            // Ignore anything that isn't a readable file (e.g. a matching dir)
            $candidate = $directory.DIRECTORY_SEPARATOR.$entry;

            if (! \is_file($candidate)) {
                continue;
            }

            // Record the part path under its integer index for ordering
            $indexToPath[(int) $siblingMatches['index']] = $candidate;
        }

        return $indexToPath;
    }

    /**
     * Find indices absent from the numbered sequence spanning .000 to the max part.
     *
     * The canonical WordPress splitter numbers parts contiguously from zero, so a
     * missing `.000` or an internal hole (e.g. `.000` + `.002` with no `.001`)
     * both surface here as a gap the caller can reject.
     *
     * @param  non-empty-array<int, string>  $indexToPath  Discovered parts keyed by index;
     *                                                     `locate()` returns before calling
     *                                                     this with none
     * @return list<int> Ascending list of missing part indices, empty when contiguous
     */
    private static function findMissingIndices(
        array $indexToPath
    ): array {
        // Determine the highest part index present in the set
        $maxIndex = max(\array_keys($indexToPath));

        // Walk 0..max and collect any index without a corresponding part
        $missing = [];

        // The highest index is always present, so stopping one short of it reports the
        // same gaps.
        // @pest-mutate-ignore: SmallerOrEqualToSmaller
        for ($index = 0; $index <= $maxIndex; $index++) {
            // Record the index when no part file was discovered for it
            if (! isset($indexToPath[$index])) {
                $missing[] = $index;
            }
        }

        return $missing;
    }
}
