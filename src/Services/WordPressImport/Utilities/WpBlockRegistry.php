<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Registry for mapping WordPress wp_block post IDs to Statamic entry IDs.
 *
 * Provides a centralized way to store and retrieve mappings between WordPress
 * reusable block post IDs and their corresponding Statamic entry IDs. This is
 * used by WpBlockBlockProcessor to create relationships when processing
 * wp:block blocks in content.
 */
class WpBlockRegistry
{
    /**
     * Map of WordPress wp_block post IDs to Statamic entry IDs.
     *
     * @var array<string, string>
     */
    private static array $wpBlockIdToEntryIdMap = [];

    /**
     * Registers a mapping between a WordPress wp_block post ID and a Statamic
     * entry ID.
     *
     * @param  string  $wpBlockPostId  The WordPress wp_block post ID
     * @param  string  $entryId  The Statamic entry ID
     */
    public static function registerMapping(
        string $wpBlockPostId,
        string $entryId
    ): void {
        self::$wpBlockIdToEntryIdMap[$wpBlockPostId] = $entryId;
    }

    /**
     * Gets the Statamic entry ID for a WordPress wp_block post ID.
     *
     * @param  string  $wpBlockPostId  The WordPress wp_block post ID
     * @return ?string The Statamic entry ID, or null if not found
     */
    public static function getEntryId(
        string $wpBlockPostId
    ): ?string {
        return self::$wpBlockIdToEntryIdMap[$wpBlockPostId] ?? null;
    }

    /**
     * Gets all registered mappings.
     *
     * @return array<int|string, mixed> Map of wp_block post IDs to entry IDs
     */
    public static function getAllMappings(): array
    {
        return self::$wpBlockIdToEntryIdMap;
    }

    /**
     * Clears all registered mappings.
     */
    public static function clear(): void
    {
        self::$wpBlockIdToEntryIdMap = [];
    }
}
