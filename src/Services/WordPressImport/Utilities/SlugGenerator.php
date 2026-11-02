<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Illuminate\Support\Str as IlluminateStr;
use Statamic\Support\Str as StatamicStr;

/**
 * Utility class for generating slugs and IDs for entries.
 *
 * This class consolidates slug generation logic from various entry creators.
 * It generates clean slugs without suffixes, allowing Statamic's automatic file
 * conflict resolution to handle duplicate filenames (e.g., test.md, test.1.md).
 *
 * Statamic's CollectionEntriesStore automatically appends numeric suffixes to
 * filenames when file path conflicts occur, while keeping the slug unchanged.
 * This matches the behavior of the Statamic control panel.
 */
class SlugGenerator
{
    /**
     * Generate a slug and ID for entries.
     *
     * Generates a clean slug without suffixes. Statamic automatically handles
     * file path conflicts by appending numeric suffixes to filenames (e.g.,
     * test.md, test.1.md) while keeping the slug unchanged.
     *
     * @param  string  $baseSlug  The base slug from WordPress
     * @param  string  $postId  The WordPress post ID
     * @param  ?string  $title  The post title to use for slug generation when baseSlug is empty
     * @return list{string, string} Entry slug and unique entry ID
     */
    public static function generateSlugAndId(
        string $baseSlug,
        string $postId,
        ?string $title = null
    ): array {
        // Generate entry slug: use base slug if provided, otherwise generate from title or fallback
        if ($baseSlug !== '') {
            $entrySlug = $baseSlug;
        } elseif ($title !== null && $title !== '') {
            // Generate slug from title, limiting to 80 characters
            $entrySlug = IlluminateStr::slug($title);
            if (\strlen($entrySlug) > 80) {
                $entrySlug = \substr(
                    $entrySlug,
                    0,
                    80
                );
                // Ensure we don't cut in the middle of a word if possible
                $lastHyphen = strrpos(
                    $entrySlug,
                    '-'
                );
                if ($lastHyphen > 60) { // Only truncate at hyphen if it's not too early
                    $entrySlug = \substr(
                        $entrySlug,
                        0,
                        $lastHyphen
                    );
                }
            }
        } else { // Otherwise
            // Fallback to post ID if no slug or title available
            $entrySlug = "item-$postId";
        }

        // ---
        // Generate the entry ID: use slug-postId-random format.
        // This ensures unique IDs across the entire collection.
        // ---
        $entryId = "$entrySlug-$postId-".StatamicStr::random();

        // ---
        // Return the slug and unique entry ID.
        //
        // Note: Statamic will automatically handle file path conflicts by appending
        // numeric suffixes to filenames (e.g., test.md, test.1.md) while keeping the slug
        // unchanged.
        // ---
        return [$entrySlug, $entryId];
    }
}
