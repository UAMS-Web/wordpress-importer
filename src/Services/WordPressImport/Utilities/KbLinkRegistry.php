<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Registry for mapping WordPress kbtopic taxonomy term slugs and kb post slugs
 * to Statamic entry IDs.
 *
 * Provides a centralized way to store and retrieve mappings between WordPress
 * kbtopic taxonomy term slugs (nicenames) and kb post slugs and their
 * corresponding Statamic entry IDs. This is used by LinkProcessor to resolve
 * /kbtopic/* and /kb/* links in content.
 */
class KbLinkRegistry
{
    /**
     * Map of WordPress kbtopic taxonomy term slugs (nicenames) to Statamic
     * entry IDs.
     *
     * @var array<string, string>
     */
    private static array $kbtopicSlugToEntryIdMap = [];

    /**
     * Map of WordPress kbtopic taxonomy term IDs to Statamic entry IDs.
     *
     * @var array<string, string>
     */
    private static array $kbtopicTermIdToEntryIdMap = [];

    /**
     * Map of WordPress kb post slugs to Statamic entry IDs.
     *
     * @var array<string, string>
     */
    private static array $kbPostSlugToEntryIdMap = [];

    /**
     * Registers a mapping between a WordPress kbtopic taxonomy term slug
     * (nicename) and a Statamic entry ID.
     *
     * @param  string  $kbtopicSlug  The WordPress kbtopic taxonomy term slug (nicename)
     * @param  string  $entryId  The Statamic entry ID
     */
    public static function registerKbtopicMappingBySlug(
        string $kbtopicSlug,
        string $entryId
    ): void {
        // Store the kbtopic slug to entry ID mapping in the registry
        self::$kbtopicSlugToEntryIdMap[$kbtopicSlug] = $entryId;
    }

    /**
     * Registers a mapping between a WordPress kb post slug and a Statamic entry
     * ID.
     *
     * @param  string  $kbPostSlug  The WordPress kb post slug
     * @param  string  $entryId  The Statamic entry ID
     */
    public static function registerKbPostMapping(
        string $kbPostSlug,
        string $entryId
    ): void {
        // Store the kb post slug to entry ID mapping in the registry
        self::$kbPostSlugToEntryIdMap[$kbPostSlug] = $entryId;
    }

    /**
     * Registers a mapping between a WordPress kbtopic taxonomy term ID and a
     * Statamic entry ID.
     *
     * @param  string|int  $termId  The WordPress kbtopic taxonomy term ID
     * @param  string  $entryId  The Statamic entry ID
     */
    public static function registerKbtopicMappingByTermId(
        $termId,
        string $entryId
    ): void {
        // Convert term ID to string for consistent key type. PHP keys an integer and
        // its decimal string alike, so for the string|int this takes the cast only
        // matches the map's declared string keys (#1314)
        // @pest-mutate-ignore: RemoveStringCast
        $termIdKey = (string) $termId;

        // Store the kbtopic term ID to entry ID mapping in the registry
        self::$kbtopicTermIdToEntryIdMap[$termIdKey] = $entryId;
    }

    /**
     * Gets the Statamic entry ID for a WordPress kbtopic taxonomy term slug
     * (nicename).
     *
     * @param  string  $kbtopicSlug  The WordPress kbtopic taxonomy term slug (nicename)
     * @return ?string The Statamic entry ID, or null if not found
     */
    public static function getKbtopicEntryIdBySlug(
        string $kbtopicSlug
    ): ?string {
        // Look up the entry ID for the given kbtopic slug in the registry
        return self::$kbtopicSlugToEntryIdMap[$kbtopicSlug] ?? null;
    }

    /**
     * Gets the Statamic entry ID for a WordPress kbtopic taxonomy term ID.
     *
     * @param  string|int  $termId  The WordPress kbtopic taxonomy term ID
     * @return ?string The Statamic entry ID, or null if not found
     */
    public static function getKbtopicEntryIdByTermId(
        $termId
    ): ?string {
        // Convert term ID to string for consistent key type. PHP keys an integer and
        // its decimal string alike, so for the string|int this takes the cast only
        // matches the map's declared string keys (#1314)
        // @pest-mutate-ignore: RemoveStringCast
        $termIdKey = (string) $termId;

        // Look up the entry ID for the given kbtopic term ID in the registry
        return self::$kbtopicTermIdToEntryIdMap[$termIdKey] ?? null;
    }

    /**
     * Gets the Statamic entry ID for a WordPress kb post slug.
     *
     * @param  string  $kbPostSlug  The WordPress kb post slug
     * @return ?string The Statamic entry ID, or null if not found
     */
    public static function getKbPostEntryId(
        string $kbPostSlug
    ): ?string {
        // Look up the entry ID for the given kb post slug in the registry
        return self::$kbPostSlugToEntryIdMap[$kbPostSlug] ?? null;
    }

    /**
     * Gets all registered kbtopic mappings.
     *
     * @return array<int|string, mixed> Map of kbtopic slugs to entry IDs
     */
    public static function getAllKbtopicMappings(): array
    {
        // Return all registered kbtopic slug to entry ID mappings
        return self::$kbtopicSlugToEntryIdMap;
    }

    /**
     * Gets all registered kb post mappings.
     *
     * @return array<int|string, mixed> Map of kb post slugs to entry IDs
     */
    public static function getAllKbPostMappings(): array
    {
        // Return all registered kb post slug to entry ID mappings
        return self::$kbPostSlugToEntryIdMap;
    }

    /**
     * Clears all registered mappings.
     */
    public static function clear(): void
    {
        // Clear all kbtopic slug to entry ID mappings
        self::$kbtopicSlugToEntryIdMap = [];

        // Clear all kbtopic term ID to entry ID mappings
        self::$kbtopicTermIdToEntryIdMap = [];

        // Clear all kb post slug to entry ID mappings
        self::$kbPostSlugToEntryIdMap = [];
    }
}
