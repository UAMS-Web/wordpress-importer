<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Entries;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Entries\Collection as EntryCollection;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Facades\Stache as StacheFacade;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Term;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\StructuredTermImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\NewsArticleResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SlugGenerator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\StacheIndexUpdateToggle;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TaxonomySlugProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TermMapBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Base class for creating Statamic entries from WordPress content.
 *
 * Provides common functionality for entry creation, such as slug and ID
 * generation, date handling, and saving entries with overwrite behavior options
 * ("Overwrite," "Skip," "Create an additional file"), adhering to DRY (Don't
 * Repeat Yourself) and SRP (Single Responsibility Principle) principles.
 * Subclasses implement type-specific mapping logic for content types like pages,
 * posts, or people.
 */
abstract class BaseEntryCreator
{
    /**
     * Collected page data for tree building.
     *
     * @var array<int|string, mixed>
     */
    protected $pageData = [];

    /**
     * The overwrite behavior for existing entries, set by the user.
     *
     * @var string
     */
    protected $entryOverwriteBehavior = 'Overwrite matching files';

    /**
     * The overwrite behavior for existing taxonomy terms, set by the user.
     *
     * @var string
     */
    protected $taxonomyOverwriteBehavior = 'Overwrite matching terms';

    /**
     * @var array<int|string, mixed> Term map built by TermMapBuilder for efficient term lookups. Maps term IDs to term data including hierarchical slugs.
     */
    protected $termMap = [];

    /**
     * @var array<int|string, mixed> Map of category nicenames to term IDs for efficient ID-based lookups. Structure: ['taxonomy' => ['nicename' => 'term_id']]
     */
    protected $nicenameToTermIdMap = [];

    /**
     * Create entries for a specific WordPress content type.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  Console output interface
     * @param  MetadataMapper  $metadataMapper  Metadata mapper instance
     * @param  ContentProcessor  $contentProcessor  Content parser instance
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Optional MinervaKB settings from JSON file
     * @return array<int|string, mixed> The page data for tree building
     */
    abstract public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        MetadataMapper $metadataMapper,
        ContentProcessor $contentProcessor,
        ?array $minervaKbSettings = null
    ): array;

    /**
     * Check if the content type uses ShowPeople shortcodes.
     */
    abstract public function hasShowPeople(): bool;

    /**
     * Get post categories for validation (if applicable).
     *
     * @return array<int|string, mixed>
     */
    abstract public function getPostCategories(): array;

    /**
     * Get people groups for validation (if applicable).
     *
     * @return array<int|string, mixed>
     */
    public function getPeopleGroups(): array
    {
        return [];
    }

    /**
     * Get post tags for validation (if applicable).
     *
     * @return array<int|string, mixed>
     */
    abstract public function getPostTags(): array;

    /**
     * Validates taxonomy terms specific to this entry creator's content type.
     *
     * Each entry creator is responsible for validating its own taxonomy terms
     * (e.g., people_group for PeopleEntryCreator, post_category/post_tag for
     * PostEntryCreator). This method is called after entry creation to ensure
     * all referenced taxonomy terms exist in Statamic.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    abstract public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void;

    /**
     * Set the overwrite behavior for entry imports.
     *
     * @param  string  $behavior  The behavior: 'Overwrite matching files', 'Skip matching files', or
     *                            'Create an additional file'
     */
    public function setEntryOverwriteBehavior(
        string $behavior
    ): void {
        $this->entryOverwriteBehavior = $behavior;
    }

    /**
     * Set the overwrite behavior for taxonomy term imports.
     *
     * @param  string  $behavior  The behavior: 'Overwrite matching terms', 'Skip matching terms', or
     *                            'Create an additional term'
     */
    public function setTaxonomyOverwriteBehavior(
        string $behavior
    ): void {
        $this->taxonomyOverwriteBehavior = $behavior;
    }

    /**
     * Read an item's WP-Members `_wpmem_block` postmeta, or '' when it has none (wordpress-importer-archive#1240).
     *
     * @param  SimpleXMLElement  $item  The WordPress `<item>` element
     * @return string The raw meta value, trimmed
     */
    protected function readWpMembersBlock(
        SimpleXMLElement $item
    ): string {
        return \trim(XpathHelper::getStringValue(
            $item,
            "wp:postmeta[wp:meta_key='_wpmem_block']/wp:meta_value"
        ));
    }

    /**
     * Whether a `_wpmem_block` value gates the item: `1` blocks it for logged-out
     * visitors and `2` hides it (wordpress-importer-archive#1240). `0`, '' and anything else leave it open.
     *
     * @param  string  $wpmemBlock  The `_wpmem_block` postmeta value
     * @return bool True when WP-Members blocks or hides the item
     */
    protected static function isWpMembersGated(
        string $wpmemBlock
    ): bool {
        // WP-Members writes only '1' and '2', so a loose match differs only on numeric
        // variants it never produces.
        // @pest-mutate-ignore: TrueToFalse
        return \in_array(\trim($wpmemBlock), ['1', '2'], true);
    }

    /**
     * Decide whether an item whose blueprint has no `status_and_visibility` imports published (wordpress-importer-archive#1241).
     *
     * Knowledge-base articles, adverts and reusable blocks set the published flag
     * straight from `wp:status`, and none of their blueprints carries the `private`
     * state `mapStatusAndVisibility()` uses for a gated item. So a published item that
     * WP-Members blocks (`_wpmem_block` `1`) or hides (`2`) imports unpublished instead.
     * That keeps it out of public view until its owner decides how it migrates, which
     * is the same fail-closed rule, and the import names it the same way.
     *
     * @param  bool  $published  Whether the item would import published from its status alone
     * @param  string  $wpmemBlock  The item's `_wpmem_block` postmeta value, or '' when absent
     * @param  string  $caller  The creator method deciding, used in the log message
     * @param  OutputInterface  $output  Console output interface
     * @return bool False for a published item WP-Members gates, otherwise $published unchanged
     */
    protected function publishUnlessWpMembersGated(
        bool $published,
        string $wpmemBlock,
        string $caller,
        OutputInterface $output
    ): bool {
        // Leave an unpublished item, or one WP-Members leaves open, as it is
        if (! $published || ! self::isWpMembersGated($wpmemBlock)) {
            return $published;
        }

        // Name the item, so its owner can decide how it migrates
        $this->reportWpMembersGate($caller, $wpmemBlock, 'unpublished', $output);

        return false;
    }

    /**
     * Report a published item that WP-Members gates, by URL, in the log and the console.
     *
     * @param  string  $caller  The method deciding, used as the log message prefix
     * @param  string  $wpmemBlock  The `_wpmem_block` postmeta value
     * @param  string  $importedAs  What the item imports as instead: `private` or `unpublished`
     * @param  OutputInterface  $output  Console output interface
     */
    private function reportWpMembersGate(
        string $caller,
        string $wpmemBlock,
        string $importedAs,
        OutputInterface $output
    ): void {
        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            ['wpmem_block' => $wpmemBlock]
        );

        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                "{$caller}: WP-Members gates this item (_wpmem_block='{$wpmemBlock}'), importing it as {$importedAs} rather than published",
                $logData['page_url']
            ),
            $logData['context']
        );

        $pageUrl = $logData['page_url'];

        $output->writeln(\sprintf(
            '<comment>Imported as %s: WP-Members gates %s (_wpmem_block=%s).</comment>',
            $importedAs,
            $pageUrl !== null && $pageUrl !== '' ? $pageUrl : 'this item',
            $wpmemBlock
        ));
    }

    /**
     * Map WordPress status and visibility fields to Statamic fields.
     *
     * Maps wp:status, wp:post_password, and wp:post_date to Statamic's
     * status_and_visibility, password_protected, visibility_password, and date
     * fields based on the blueprint. Hashes the post password for secure storage.
     * Sets status_and_visibility to reflect the WordPress status without explicitly
     * setting a published flag. Sets password_protected to true when a password is
     * present, or false when no password is provided, ensuring consistent output
     * across all content types.
     *
     * A `future` post is held back in one of two ways, chosen by the blueprint. A
     * blueprint with a `publish_at` field schedules through that field, as the host
     * app's undated `people` collection does (wordpress-importer-archive#1233): the entry imports as `draft`
     * with `publish_at` set, and the host's scheduled job publishes it. Any other
     * blueprint gets `scheduled` and a dated entry, as before.
     *
     * A published item that WP-Members holds behind login (`_wpmem_block` `1`) or hides
     * (`2`) imports as `private` instead, so the import does not make public what
     * WordPress gated before its owner has decided how it migrates (wordpress-importer-archive#1240). Other
     * statuses keep their mapping.
     *
     * @param  string  $status  The wp:status value (e.g., 'draft', 'pending', 'private', 'future', 'publish')
     * @param  string  $postPassword  The wp:post_password value
     * @param  string  $postDate  The wp:post_date value
     * @param  array<int|string, mixed>  &$data  The entry data array to populate
     * @param  string  $collectionHandle  The collection handle
     * @param  OutputInterface  $output  Console output interface
     * @param  string  $postDateGmt  The wp:post_date_gmt value, used for `publish_at`
     * @param  string  $wpmemBlock  The item's `_wpmem_block` postmeta value, or '' when absent
     * @return bool True if date should be used as filename prefix (for wp:status=future)
     */
    protected function mapStatusAndVisibility(
        string $status,
        string $postPassword,
        string $postDate,
        array &$data,
        string $collectionHandle,
        OutputInterface $output,
        string $postDateGmt = '',
        // A missing block value gates nothing, and neither does any other a mutant gives
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        string $wpmemBlock = ''
    ): bool {
        $collection = CollectionFacade::findByHandle($collectionHandle);
        $blueprint = $collection?->entryBlueprint();
        $isScheduledPost = false;

        if (\is_object($blueprint) && \method_exists($blueprint, 'hasField')) {
            // Map wp:status to status_and_visibility
            if ($blueprint->hasField('status_and_visibility')) {
                switch ($status) {
                    case 'draft':
                        $data['status_and_visibility'] = 'draft';
                        break;
                    case 'pending':
                        $data['status_and_visibility'] = 'pending';
                        break;
                    case 'private':
                        $data['status_and_visibility'] = 'private';
                        break;
                    case 'future':
                        // Schedule through publish_at where the blueprint has it, since no status holds such an entry back
                        if ($blueprint->hasField('publish_at')) {
                            $data['status_and_visibility'] = 'draft';

                            // Leave publish_at unset when no date can be read, so the entry stays a draft
                            $publishAt = $this->resolveScheduledPublishAt($postDateGmt, $postDate);
                            if ($publishAt !== null) {
                                $data['publish_at'] = $publishAt;
                            }

                            break;
                        }

                        $data['status_and_visibility'] = 'scheduled';
                        $isScheduledPost = true;
                        break;
                    case 'publish':
                        $data['status_and_visibility'] = 'published';
                        break;
                    default:
                        // Enhance log context with entry information
                        $logData = LogContextHelper::enhanceWithEntryContext(
                            ['status' => $status]
                        );

                        // Log warning for unsupported status
                        Log::warning(
                            LogContextHelper::formatMessageWithEntry(
                                "BaseEntryCreator::mapStatusAndVisibility: Unsupported wp:status='$status', defaulting to draft",
                                $logData['page_url']
                            ),
                            $logData['context']
                        );
                        $data['status_and_visibility'] = 'draft';
                }

                // Fail closed on a published item WP-Members gates, rather than make it public
                if ($data['status_and_visibility'] === 'published' && self::isWpMembersGated($wpmemBlock)) {
                    $data['status_and_visibility'] = 'private';

                    // Name the item, so its owner can decide how it migrates
                    $this->reportWpMembersGate(
                        'BaseEntryCreator::mapStatusAndVisibility',
                        $wpmemBlock,
                        'private',
                        $output
                    );
                }
            }

            // Map wp:post_password to password_protected and visibility_password
            if ($blueprint->hasField('password_protected')) {
                if ($postPassword !== '') {
                    $data['password_protected'] = true;
                    if ($blueprint->hasField('visibility_password')) {
                        $hashedPassword = Hash::make($postPassword);
                        $data['visibility_password'] = $hashedPassword;
                        $data['is_password_hashed'] = true;
                    }
                } else {
                    $data['password_protected'] = false;
                }
            }

            // Map wp:post_date to date for a post scheduled by status rather than by publish_at
            if ($status === 'future' && ! $blueprint->hasField('publish_at') && $blueprint->hasField('date') && $postDate !== '') {
                $data['date'] = $postDate;
            }
        } else {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['collection_handle' => $collectionHandle]
            );

            // Log warning for missing blueprint
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "BaseEntryCreator::mapStatusAndVisibility: No blueprint found for collection '$collectionHandle'",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        return $isScheduledPost;
    }

    /**
     * Resolve the `publish_at` value for a `future` post.
     *
     * Returns the time in UTC, in the Date fieldtype's `Y-m-d H:i` save format. The
     * host app runs with `app.timezone` UTC, and the fieldtype reads a saved string
     * in that zone, so `wp:post_date_gmt` is the right source. WordPress writes an
     * all-zero GMT date for a post it never scheduled, and the export records no
     * site timezone, so the fallback reads `wp:post_date` as UTC and logs that the
     * time may be off by the site's offset. A date before year 1000, which is what
     * WordPress's all-zero placeholder parses to, counts as unreadable, so it can
     * never produce a publish time that is already due.
     *
     * @param  string  $postDateGmt  The wp:post_date_gmt value
     * @param  string  $postDate  The wp:post_date value
     * @return ?string The publish time, or null when neither date can be read
     */
    protected function resolveScheduledPublishAt(
        string $postDateGmt,
        string $postDate
    ): ?string {
        $utc = new DateTimeZone('UTC');

        // Prefer the GMT date
        $publishAt = $this->formatPublishAt($postDateGmt, $utc);
        if ($publishAt !== null) {
            return $publishAt;
        }

        // Fall back to the local date, read as UTC
        $publishAt = $this->formatPublishAt($postDate, $utc);

        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'post_date_gmt' => $postDateGmt,
                'post_date' => $postDate,
                'publish_at' => $publishAt,
            ]
        );

        // Log that the scheduled time is approximate, or missing
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                $publishAt !== null
                    ? 'BaseEntryCreator::resolveScheduledPublishAt: No usable wp:post_date_gmt, so publish_at reads wp:post_date as UTC and may be off by the site offset'
                    : 'BaseEntryCreator::resolveScheduledPublishAt: No readable post date, so publish_at is unset and the entry stays a draft',
                $logData['page_url']
            ),
            $logData['context']
        );

        return $publishAt;
    }

    /**
     * Format a WordPress date string as a UTC `publish_at` value.
     *
     * @param  string  $date  The date string
     * @param  DateTimeZone  $utc  The UTC zone
     * @return ?string The `Y-m-d H:i` value, or null when the string is empty, unparsable or before year 1000
     */
    private function formatPublishAt(string $date, DateTimeZone $utc): ?string
    {
        // An empty string would parse as now; whitespace alone fails to parse below
        if ($date === '') {
            return null;
        }

        try {
            $time = new DateTimeImmutable($date, $utc)->setTimezone($utc);
        } catch (Exception) {
            return null;
        }

        // ---
        // WordPress's all-zero placeholder parses to a year before 1. A year string
        // compares as a number either way.
        // ---
        // @pest-mutate-ignore: RemoveIntegerCast
        if ((int) $time->format('Y') < 1000) {
            return null;
        }

        return $time->format('Y-m-d H:i');
    }

    /**
     * Extract the post's GMT date string from WordPress XML.
     *
     * @param  SimpleXMLElement  $xmlElement  The WordPress XML element (post, page, etc.)
     * @return string The wp:post_date_gmt value, or an empty string if not found
     */
    protected function extractPostDateGmtString(
        SimpleXMLElement $xmlElement
    ): string {
        // Extract wp:post_date_gmt using XpathHelper for consistency
        return XpathHelper::getStringValue(
            $xmlElement,
            'wp:post_date_gmt'
        );
    }

    /**
     * Generate a slug and ID for entries.
     *
     * @param  string  $baseSlug  The base slug from WordPress
     * @param  string  $postId  The WordPress post ID
     * @param  ?string  $title  The post title to use for slug generation when baseSlug is empty
     * @return list{string, string} Entry slug and unique entry ID
     */
    protected function generateSlugAndId(
        string $baseSlug,
        string $postId,
        ?string $title = null
    ): array {
        return SlugGenerator::generateSlugAndId(
            $baseSlug,
            $postId,
            $title
        );
    }

    /**
     * Extract post date string from WordPress XML with fallback to pubDate.
     *
     * Extracts the wp:post_date value from the XML element and falls back to
     * the pubDate element if post_date is empty. This ensures consistent date
     * handling across all entry creators.
     *
     * @param  SimpleXMLElement  $xmlElement  The WordPress XML element (post, page, etc.)
     * @return string The post date string or empty string if not found
     */
    protected function extractPostDateString(
        SimpleXMLElement $xmlElement
    ): string {
        // Extract wp:post_date using XpathHelper for consistency
        $postDate = XpathHelper::getStringValue(
            $xmlElement,
            'wp:post_date'
        );

        // Check if post_date is empty
        if ($postDate === '') {
            // Fall back to pubDate
            return (string) ($xmlElement->pubDate ?? '');
        }

        // Return the post date string
        return $postDate;
    }

    /**
     * Extract post modified date string from WordPress XML.
     *
     * Extracts the wp:post_modified value from the XML element. This ensures
     * consistent date handling across all entry creators, matching the logic
     * used for wp:post_date.
     *
     * @param  SimpleXMLElement  $xmlElement  The WordPress XML element (post, page, etc.)
     * @return string The post modified date string or empty string if not found
     */
    protected function extractPostModifiedDateString(
        SimpleXMLElement $xmlElement
    ): string {
        // Extract wp:post_modified using XpathHelper for consistency
        $postModified = XpathHelper::getStringValue(
            $xmlElement,
            'wp:post_modified'
        );

        // Return the post modified date string
        return $postModified;
    }

    /**
     * Add updated_at field to entry data from WordPress XML element.
     *
     * Extracts the wp:post_modified value from the WordPress XML element,
     * converts it to a UNIX timestamp, and adds it to the entry data array as
     * the updated_at field. Handles date extraction and parsing errors with
     * appropriate logging.
     *
     * @param  array<int|string, mixed>  &$data  The entry data array (passed by reference)
     * @param  SimpleXMLElement  $xmlElement  The WordPress XML element (post, page, etc.)
     * @param  string  $methodName  The name of the calling method for logging
     *                              (e.g., 'PostEntryCreator::createEntries')
     */
    protected function addUpdatedAtField(
        array &$data,
        SimpleXMLElement $xmlElement,
        string $methodName
    ): void {
        // Extract post modified date string from WordPress XML
        $postModifiedDate = $this->extractPostModifiedDateString($xmlElement);

        // Check if post modified date is available
        if ($postModifiedDate === '') {
            // Return early if no modified date available
            return;
        }

        try {
            // Convert modified date string to UNIX timestamp
            $modifiedDateTime = new DateTime($postModifiedDate);
            $data['updated_at'] = $modifiedDateTime->getTimestamp();
        } catch (Exception $exception) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'post_modified_date' => $postModifiedDate,
                    'error' => $exception->getMessage(),
                ]
            );

            // Log warning for failed date parsing
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "{$methodName}: Failed to parse post modified date string for updated_at field",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }
    }

    /**
     * Create a DateTime object for scheduled posts.
     *
     * @param  string  $dateStr  The date string from WordPress
     * @param  bool  $isScheduledPost  Whether to create a date object (e.g., for wp:status=future)
     * @return ?DateTime The DateTime object or null if not needed
     */
    protected function createEntryDate(
        string $dateStr,
        bool $isScheduledPost
    ): ?DateTime {
        if ($isScheduledPost && $dateStr !== '') {
            return new DateTime($dateStr);
        }

        return null;
    }

    /**
     * Save an entry with date handling and overwrite behavior.
     *
     * Handles entry saving based on the configured overwrite behavior:
     *   - "Overwrite": Deletes existing entry and saves new one.
     *   - "Skip": Skips saving if entry exists.
     *   - "Create an additional file": Generates a new slug/ID and saves as a new
     *     entry.
     *
     * Determines the published status from status_and_visibility if $published is
     * null, defaulting to false for drafts or pending entries.
     *
     * Checks the collection configuration to determine if dates are enabled
     * (`date: true`). If the collection is dated, sets the entry date to ensure
     * entries are created with dated filenames (e.g., `2025-11-05.foo.md`). If the
     * collection is not dated, entries are created with non-dated filenames (e.g.,
     * `foo.md`).
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  string  $entrySlug  The entry slug
     * @param  array<int|string, mixed>  $data  Entry data
     * @param  OutputInterface  $output  Console output interface
     * @param  ?DateTime  $entryDate  The entry date object or null
     * @param  ?bool  $published  Published status, or null to infer from status_and_visibility
     * @param  ?string  $postDateStr  Optional WordPress post date string to use if collection is dated but
     *                                $entryDate is null
     * @return ?Entry The created entry object, the existing entry if found with
     *                "Skip matching files" behavior, or null if skipped for other reasons
     */
    protected function saveEntry(
        string $collectionHandle,
        string $site,
        string $entrySlug,
        array $data,
        OutputInterface $output,
        ?DateTime $entryDate = null,
        ?bool $published = null,
        ?string $postDateStr = null
    ): ?Entry {
        $entryIdValue = $data['id'] ?? null;
        if (! \is_string($entryIdValue) || $entryIdValue === '') {
            $entryIdValue = $entrySlug;
        }

        $entryId = $entryIdValue;
        $originalSlug = $entrySlug;

        // Determine published status if not explicitly set
        if ($published === null) {
            // Check status_and_visibility field (e.g., for pages/posts)
            if (isset($data['status_and_visibility'])) {
                $published = \in_array(
                    $data['status_and_visibility'],
                    [
                        'published',
                        'private',
                        'scheduled',
                    ],
                    true
                );
            } elseif (isset($data['published'])) { // Check if published is explicitly set in data array (e.g., for reusable_content)
                $published = $data['published'];
            } else { // Default to false if neither is set
                $published = false;
            }
        }

        // Check if an entry already exists
        $existingEntry = $this->findExistingEntry(
            $collectionHandle,
            $site,
            $entryId,
            $entrySlug
        );

        // Check if an existing entry was found
        if ($existingEntry instanceof Entry) {
            // Handle the entry based on the configured overwrite behavior
            switch ($this->entryOverwriteBehavior) {
                // Handle case where existing entries should be skipped
                case 'Skip matching files':
                    // Display skip message to user
                    $output->writeln("<comment>Skipped entry '$entrySlug' as it already exists.</comment>");

                    // Return the existing entry so the caller can add it to pageData
                    return $existingEntry;

                    // Handle case where additional entries should be created with unique slugs
                case 'Create an additional file':
                    // ---
                    // Number the copy `{slug}.1`, dropping a number the slug already
                    // carries. This is all the path does until #265 is fixed: Statamic
                    // stores a slug without its dot, so no stored slug matches a numbered
                    // one and every copy takes `.1`, and the copy keeps the ID set above.
                    // ---
                    $entrySlug = \preg_replace(
                        '/\.\d+$/',
                        '',
                        $originalSlug
                    ).'.1';

                    // Exit switch statement after handling additional file creation
                    break;

                    // Handle case where existing entries should be overwritten
                case 'Overwrite matching files':
                    // ---
                    // Adopt the id the matched entry already carries, rather than the
                    // one `SlugGenerator` produced for this run.
                    //
                    // `SlugGenerator` appends a fresh `Str::random()` per run, so the
                    // replacement used to land on a different id every time. Two
                    // importer surfaces write an entry id INTO content during the
                    // `page` pass — `LinkProcessor::convertInternalPageLink()`'s
                    // `statamic://entry::<id>` and `NewsParser::resolveArticleId()`'s
                    // `article_id` — and `ImportWordPress::$supportedContentTypes`
                    // lists `page` before `post`. So within one run the page pass
                    // resolved post N to an id, the post pass replaced that entry
                    // with another, and the run ended with the page naming an entry
                    // that no longer existed. Re-running never converged, because
                    // every run repeated the delete-and-recreate.
                    //
                    // Preserving the id fixes references written by PREVIOUS runs
                    // too, which re-resolving at the end of a run would not: those
                    // ids stay valid because the entry keeps them.
                    //
                    // `PostCategoryEntryResolver`'s ids were never affected for the
                    // mirror-image reason — `StructuredTermImporter::createOrUpdateEntry()`
                    // re-saves in place. The difference was the entry-creation model,
                    // not the lookups (wordpress-importer-archive#767).
                    // ---
                    $existingEntryId = $existingEntry->id();
                    $entryId = \is_string($existingEntryId) ? $existingEntryId : $entryId;

                    // Delete the existing entry to make room for the new one
                    $existingEntry->delete();

                    // Exit switch statement after handling overwrite
                    break;
            }
        }

        // The facade always makes an Entry; the check only narrows the type
        $entry = EntryFacade::make();
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveEarlyReturn
        if (! $entry instanceof Entry) {
            return null;
        }

        $entry->collection($collectionHandle);
        $entry->locale($site);
        $entry->published($published);
        $entry->slug($entrySlug);

        // Set the custom ID on the entry object
        $entry->id($entryId);

        // Remove ID from data array since it's set on the entry object
        unset($data['id']);

        // Check if the collection is dated based on configuration
        $entryCollection = $entry->collection();
        // ---
        // The cast keeps a truthy dated value, such as 1 read from YAML, reading as
        // dated. An entry set to a collection always has it, so the check only narrows.
        // ---
        // @pest-mutate-ignore: RemoveBooleanCast, InstanceOfToTrue
        $isCollectionDated = $entryCollection instanceof EntryCollection && (bool) $entryCollection->dated();

        // Determine the date to use for the entry
        $finalEntryDate = $entryDate;

        // If collection is dated but no entry date is provided, create one
        if (
            $isCollectionDated
            && ! $finalEntryDate instanceof DateTime
        ) {
            // ---
            // Try to create date from post date string if provided. A blank string
            // would read as now, which the fallback below gives anyway.
            // ---
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            if ($postDateStr !== null && $postDateStr !== '') {
                try {
                    $finalEntryDate = new DateTime($postDateStr);
                } catch (Exception $e) {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'collection_handle' => $collectionHandle,
                            'post_date_str' => $postDateStr,
                            'error' => $e->getMessage(),
                        ]
                    );

                    // Log warning for failed date parsing
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'BaseEntryCreator::saveEntry: Failed to parse post date string',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }
            }

            $finalEntryDate ??= new DateTime;
        }

        // Set the date on the entry if we have one
        if ($finalEntryDate instanceof DateTime) {
            // Only set date if the collection is dated
            if ($isCollectionDated) {
                $entry->date(Carbon::instance($finalEntryDate));
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'collection_handle' => $collectionHandle,
                        'collection_dated' => $isCollectionDated,
                    ]
                );

                // Log warning for skipping date setting
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'BaseEntryCreator::saveEntry: Skipping date setting - collection not dated',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }
        }

        // Unset published from data array if it's true (only want false in YAML)
        if (
            isset($data['published'])
            && $data['published'] === true
        ) {
            unset($data['published']);
        }

        // Save the entry with all data
        $entry->data($data);
        $entry->save();

        // ---
        // Refresh Stache cache to ensure the entry is available for immediate queries.
        // This is necessary because file watchers may not have updated the cache yet;
        // the Stache in a test indexes the entry on save, so nothing here can show it.
        // ---
        // @pest-mutate-ignore: InstanceOfToTrue, InstanceOfToFalse, IfNegated, RemoveMethodCall, RemoveArrayItem
        if ($entryCollection instanceof EntryCollection) {
            $entryCollection->updateEntryUris([$entry->id()]);
        }

        $output->writeln(
            "  - <info>Created entry '$entrySlug'".($finalEntryDate instanceof DateTime ? ' (date: '.$finalEntryDate->format('Y-m-d H:i:s').')' : '')." in Statamic for the '$site' site in the '$collectionHandle' collection.</info>"
        );

        return $entry;
    }

    /**
     * Check if an entry already exists, handling both exact ID matches and slug/post
     * ID matches.
     *
     * This method checks for existing entries using two approaches:
     *   1. Exact ID match (for entries with the same random string)
     *   2. Slug and WordPress post ID match (for entries where the random string has
     *      changed)
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  string  $entryId  The entry ID to check
     * @param  string  $entrySlug  The entry slug to check
     * @return ?Entry The existing entry if found, null otherwise
     */
    protected function findExistingEntry(
        string $collectionHandle,
        string $site,
        string $entryId,
        string $entrySlug
    ): ?Entry {
        // ---
        // Check if an entry with the same ID exists. Statamic keeps entry IDs unique
        // across collections, so the collection criterion changes nothing it can find.
        // ---
        $existingEntryQuery = $this->newEntryQuery();
        // @pest-mutate-ignore: RemoveMethodCall
        $existingEntryQuery->where(
            'collection',
            $collectionHandle
        );
        $existingEntryQuery->where(
            'site',
            $site
        );
        $existingEntryQuery->where(
            'id',
            $entryId
        );
        $existingEntry = $existingEntryQuery->first();

        if ($existingEntry instanceof Entry) {
            return $existingEntry;
        }

        // ---
        // If no exact ID match, check for existing entry with same slug and WordPress
        // post ID. This handles cases where the random part of the ID has changed
        // between imports. The entry ID's format is slug-postId-random.
        // ---
        $idParts = \explode(
            '-',
            $entryId
        );

        // An ID with no post ID and random part has nothing to match on
        if (\count($idParts) < 3) {
            return null;
        }

        // Extract the WordPress post ID from the second-to-last part
        $wpPostId = $idParts[\count($idParts) - 2];

        // Reconstruct the base slug from all parts except the last two (postId and random)
        $baseSlug = \implode(
            '-',
            \array_slice(
                $idParts,
                0,
                -2
            )
        );

        // Look for existing entries whose slug starts with the base slug
        $matchingEntryQuery = $this->newEntryQuery();
        $matchingEntryQuery->where(
            'collection',
            $collectionHandle
        );
        $matchingEntryQuery->where(
            'site',
            $site
        );
        $matchingEntryQuery->where(
            'slug',
            'like',
            $baseSlug.'%'
        );

        // ---
        // Take the first whose ID has a 16-character random part (Statamic's random
        // string length), the same WordPress post ID, and the same base slug, by ID or
        // by slug with trailing numbers removed. A query's get() returns a collection;
        // the check only narrows the type.
        // ---
        $matchingEntries = $matchingEntryQuery->get();
        // @pest-mutate-ignore: InstanceOfToTrue
        $matchingEntry = ! $matchingEntries instanceof Collection ? null : $matchingEntries->first(
            static function (mixed $entry) use ($wpPostId, $baseSlug): bool {
                // A query for entries returns entries; this only narrows the type
                // @pest-mutate-ignore: InstanceOfToTrue, RemoveEarlyReturn, FalseToTrue
                if (! $entry instanceof Entry) {
                    return false;
                }

                // Split the existing entry's ID into parts using hyphens
                $entryIdParts = \explode(
                    '-',
                    self::mixedToStringValue($entry->id())
                );

                // An existing ID with no post ID and random part does not match
                if (\count($entryIdParts) < 3) {
                    return false;
                }

                // Reconstruct the base slug from the existing entry's ID
                $entryBaseSlug = \implode(
                    '-',
                    \array_slice(
                        $entryIdParts,
                        0,
                        -2
                    )
                );

                // Remove trailing numbers from both slugs for comparison
                $entrySlugBase = \preg_replace(
                    '/\.?\d+$/',
                    '',
                    self::mixedToStringValue($entry->slug())
                );
                $baseSlugClean = \preg_replace(
                    '/\.?\d+$/',
                    '',
                    $baseSlug
                );

                return \strlen(end($entryIdParts)) === 16
                    && $entryIdParts[\count($entryIdParts) - 2] === $wpPostId
                    && (
                        $entryBaseSlug === $baseSlug
                        || $entrySlugBase === $baseSlugClean
                    );
            }
        );

        // ---
        // Return the found existing entry or null if none exists. A collection's
        // first() gives null when nothing matches, so the check only narrows the type.
        // ---
        // @pest-mutate-ignore: InstanceOfToTrue
        return $matchingEntry instanceof Entry ? $matchingEntry : null;
    }

    /**
     * Read a value an entry accessor returns as a string, or '' when it is not one.
     *
     * @param  mixed  $value  The value
     * @return string The string, or ''
     */
    private static function mixedToStringValue(
        mixed $value
    ): string {
        // A saved entry's id and slug are strings; the fallback only narrows the type
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        return \is_string($value) ? $value : '';
    }

    /**
     * Stores site-specific taxonomy data for later term creation.
     *
     * @param  array<int|string, mixed>  $siteSpecificData  Array to store data in (passed by reference)
     * @param  string  $siteSpecificSlug  The site-specific slug
     * @param  string  $originalSlug  The original slug before site prefix
     * @param  string  $termName  The term name
     */
    protected function storeSiteSpecificTaxonomyData(
        array &$siteSpecificData,
        string $siteSpecificSlug,
        string $originalSlug,
        string $termName
    ): void {
        $termData = [
            'site_specific' => $siteSpecificSlug,
            'original' => $originalSlug,
            'name' => $termName,
        ];

        // Check if this term data is already stored to avoid duplicates
        if (
            ! \in_array(
                $termData,
                $siteSpecificData,
                true
            )
        ) {
            $siteSpecificData[] = $termData;
        }
    }

    /**
     * Creates taxonomy terms from stored site-specific data.
     *
     * Processes multiple taxonomy terms one at a time to avoid memory issues.
     * Uses caching to avoid duplicate operations and Statamic facade calls.
     * Groups terms by operation type (create, update, already exist) for
     * efficient processing with proper memory management.
     *
     * @param  array<int|string, mixed>  $siteSpecificData  Array of term data with 'original', 'name' keys
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle (e.g., 'post_categories', 'people_groups')
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function createTaxonomyTermsFromStoredData(
        array $siteSpecificData,
        string $statamicTaxonomyHandle,
        string $site,
        OutputInterface $output
    ): void {
        if ($siteSpecificData === []) {
            return;
        }

        // ---
        // Refuse to write taxonomy terms to a handle that is a structured
        // COLLECTION. `UAMS-Web/uams-statamic-archive#1863` converted `people_groups` and
        // `logos_item_categories` into collections alongside `post_categories`,
        // and terms written to one of those went nowhere: the preload below
        // guards on TaxonomyFacade::find(), which returns null for a collection,
        // so every term was silently discarded. Those taxonomies are imported by
        // StructuredTermImporter instead. This guard covers the callers that
        // reach this method directly — PostEntryCreator::validateTaxonomies() and
        // the per-creator overrides — rather than through the routed term pass.
        // ---
        if (TaxonomyDispositions::isStructuredDestination($statamicTaxonomyHandle)) {
            return;
        }

        // Get site name from cache once
        $siteName = self::getCachedSiteName($site);

        // Group terms by operation type for batch processing
        $termsToCreate = [];
        $termsToUpdate = [];
        $termsAlreadyExist = [];

        // Preload every existing term for this taxonomy in a single query rather than
        // calling TermFacade::find() once per term. Term::find() routes through
        // TermRepository::query(), which unconditionally forces every taxonomy's
        // associations index to load — and on a cold cache that rebuild scans all entries
        // in all collections. Doing that N times (once per term) is the dominant cost of
        // term creation. One whereTaxonomy() pass collapses the storm to a single query.
        // The map is keyed by term id ("{handle}::{slug}"), which is identical to the
        // $cacheKey computed per term below. whereTaxonomy() throws if the taxonomy does
        // not exist yet, so guard with find() (a missing taxonomy is reported later).
        $existingTerms = [];
        if (TaxonomyFacade::find($statamicTaxonomyHandle) !== null) {
            foreach (TermFacade::whereTaxonomy($statamicTaxonomyHandle) as $existingTermEntry) {
                $existingTermId = self::extractObjectId($existingTermEntry);
                if ($existingTermId === null) {
                    continue;
                }

                $existingTerms[$existingTermId] = $existingTermEntry;
            }
        }

        // Track cache keys queued for creation during this run so the "Create an
        // additional term" uniqueness loop (and repeated input slugs) don't collide on a
        // slug that exists only in memory and has not been persisted yet.
        $reservedKeys = [];

        foreach (ImportProgress::track($output, "{$statamicTaxonomyHandle} terms checked", $siteSpecificData) as $index => $termData) {
            if (! \is_array($termData)) {
                continue;
            }

            $originalSlug = isset($termData['original']) && \is_string($termData['original'])
                ? $termData['original']
                : '';
            $termName = isset($termData['name']) && \is_string($termData['name'])
                ? $termData['name']
                : '';
            $siteSpecificSlug = TaxonomySlugProcessor::addSitePrefixToSlug(
                $originalSlug,
                $site
            );
            $cacheKey = "{$statamicTaxonomyHandle}::{$siteSpecificSlug}";

            // Check if we've already processed this term in this session
            if (isset(self::$processedTermsCache[$cacheKey])) {
                continue;
            }

            // Skip terms already queued for creation this run (duplicate input slugs)
            if (isset($reservedKeys[$cacheKey])) {
                continue;
            }

            // Check if term already exists (from the preloaded map, not a per-term query)
            $existingTerm = $existingTerms[$cacheKey] ?? null;
            if ($existingTerm === null) {
                foreach ($existingTerms as $preloadedTerm) {
                    $preloadedTermId = self::extractObjectId($preloadedTerm);
                    if ($preloadedTermId !== $cacheKey) {
                        continue;
                    }

                    $existingTerm = $preloadedTerm;

                    break;
                }
            }

            // The preload yields localized terms (Statamic 6's term query returns
            // LocalizedTerm); work on the term each one localizes (#188)
            if ($existingTerm instanceof LocalizedTerm) {
                $existingTerm = $existingTerm->term();
            }

            if ($existingTerm !== null) {
                // Handle existing term based on overwrite behavior
                switch ($this->taxonomyOverwriteBehavior) {
                    // Handle case where existing terms should be skipped
                    case 'Skip matching terms':
                        // Display skip message to user
                        $output->writeln("      - <comment>Skipped taxonomy term '$siteSpecificSlug' as it already exists.</comment>");

                        // Mark term as processed to avoid duplicate processing
                        self::$processedTermsCache[$cacheKey] = false;

                        // Skip to next term in the loop
                        continue 2; // Skip to next term

                        // Handle case where additional terms should be created with unique slugs
                    case 'Create an additional term':
                        if (! $existingTerm instanceof Term) {
                            continue 2;
                        }

                        // Generate a new slug with counter
                        $counter = 1;

                        // Initialize new slug with original site-specific slug
                        $newSlug = $siteSpecificSlug;

                        // Initialize new cache key with original cache key
                        $newCacheKey = $cacheKey;

                        // Loop until we find a unique slug
                        do {
                            // Append counter to create unique slug
                            $newSlug = "{$siteSpecificSlug}.{$counter}";

                            // Update cache key with new slug
                            $newCacheKey = "{$statamicTaxonomyHandle}::{$newSlug}";

                            // Increment counter for next iteration
                            $counter++;

                            // Check if term with new slug already exists (persisted in
                            // the preloaded map, or reserved by a create queued this run)
                            $termExists = isset($existingTerms[$newCacheKey])
                                || isset($reservedKeys[$newCacheKey]);
                        } while ($termExists);

                        // Generate expected term name from original slug
                        $expectedTermName = $termName !== ''
                            ? $termName
                            : \ucwords(
                                \str_replace(
                                    ['-', '_'],
                                    ' ',
                                    $originalSlug
                                )
                            );

                        // Create title with site name prefix
                        $title = "{$siteName} — {$expectedTermName}";

                        // Add new term to creation queue
                        $termsToCreate[] = [
                            'slug' => $newSlug,
                            'title' => $title,
                            'cacheKey' => $newCacheKey,
                        ];

                        // Reserve the slug so later iterations treat it as taken
                        $reservedKeys[$newCacheKey] = true;

                        // Exit the switch statement after processing additional term creation
                        break;

                        // Handle case where existing terms should be overwritten or updated
                    case 'Overwrite matching terms':
                    default:
                        if (! $existingTerm instanceof Term) {
                            continue 2;
                        }

                        // Generate expected term name from original slug
                        $expectedTermName = $termName !== ''
                            ? $termName
                            : \ucwords(
                                \str_replace(
                                    ['-', '_'],
                                    ' ',
                                    $originalSlug
                                )
                            );

                        // Create expected title with site name prefix
                        $expectedTitle = "{$siteName} — {$expectedTermName}";

                        // Check if the existing term needs updating
                        if (
                            self::getTermStringValue(
                                $existingTerm,
                                'title'
                            ) !== $expectedTitle
                            || self::getTermStringValue(
                                $existingTerm,
                                'blueprint'
                            ) !== $statamicTaxonomyHandle
                        ) {
                            // Add term to update queue if changes are needed
                            $termsToUpdate[] = [
                                'term' => $existingTerm,
                                'slug' => $siteSpecificSlug,
                                'title' => $expectedTitle,
                                'cacheKey' => $cacheKey,
                            ];
                        } else { // Otherwise
                            // Mark term as already existing if no changes needed
                            $termsAlreadyExist[] = $siteSpecificSlug;

                            // Mark term as processed to avoid duplicate processing
                            self::$processedTermsCache[$cacheKey] = false;
                        }

                        // Exit the switch statement after processing overwrite behavior
                        break;
                }
            } else { // Otherwise
                // Term needs to be created
                $expectedTermName = $termName !== ''
                    ? $termName
                    : \ucwords(
                        \str_replace(
                            [
                                '-',
                                '_',
                            ],
                            ' ',
                            $originalSlug
                        )
                    );
                $title = "{$siteName} — {$expectedTermName}";

                $termsToCreate[] = [
                    'slug' => $siteSpecificSlug,
                    'title' => $title,
                    'cacheKey' => $cacheKey,
                ];

                // Reserve the slug so repeated input slugs are not queued twice
                $reservedKeys[$cacheKey] = true;
            }

            // Clean up variables to free up memory
            unset(
                $termData,
                $existingTerm
            );

            // Force garbage collection every 20 terms to prevent memory buildup
            if (((int) $index + 1) % 20 === 0) {
                gc_collect_cycles();
            }
        }

        // Persist the queued updates and creations with Stache index updating paused (when
        // the installed statamic/cms supports a safe re-enable). Pausing skips the costly
        // full index rebuilds that scan every entry; terms are still written to disk on
        // save, and the wrapper re-enables indexing and rebuilds this taxonomy's term store
        // from disk afterward, so the Stache is correct before entry creation continues.
        // Only enter the paused window when there is actually something to write.
        if ($termsToUpdate !== [] || $termsToCreate !== []) {
            $this->withTermIndexUpdatesDisabled(
                $statamicTaxonomyHandle,
                fn () => $this->persistQueuedTermChanges(
                    $termsToUpdate,
                    $termsToCreate,
                    $statamicTaxonomyHandle,
                    $site,
                    $output
                )
            );
        }

        // Log terms that already exist (only if there are any)
        if ($termsAlreadyExist !== []) {
            $output->writeln('      - <comment>Taxonomy terms that already exist in Statamic:</comment>');

            foreach ($termsAlreadyExist as $slug) {
                $output->writeln("          - <comment>{$statamicTaxonomyHandle}::{$slug}</comment>");
            }
        }

        // Clean up variables to free up memory
        unset(
            $siteSpecificData,
            $termsToCreate,
            $termsToUpdate,
            $termsAlreadyExist,
            $siteName
        );

        // Force final garbage collection
        gc_collect_cycles();
    }

    /**
     * Persist the queued taxonomy term updates and creations.
     *
     * Extracted from {@see self::createTaxonomyTermsFromStoredData()} so the
     * save phase can be wrapped by {@see self::withTermIndexUpdatesDisabled()}.
     * An early return here only skips the remaining saves (the caller still runs
     * its trailing logging/cleanup), matching the original control flow.
     *
     * @param  array<int|string, mixed>  $termsToUpdate  Existing terms queued for update
     * @param  array<int|string, mixed>  $termsToCreate  New terms queued for creation
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    private function persistQueuedTermChanges(
        array $termsToUpdate,
        array $termsToCreate,
        string $statamicTaxonomyHandle,
        string $site,
        OutputInterface $output
    ): void {
        // Update existing terms individually
        if ($termsToUpdate !== []) {
            $output->writeln('      - Updating existing taxonomy terms...');
        }

        // Loop through each term that needs to be updated
        foreach (ImportProgress::track($output, "{$statamicTaxonomyHandle} terms updated", $termsToUpdate) as $index => $termData) {
            if (
                ! \is_array($termData)
                || ! isset($termData['term'], $termData['slug'], $termData['title'], $termData['cacheKey'])
                || ! $termData['term'] instanceof Term
                || ! \is_string($termData['slug'])
                || ! \is_string($termData['title'])
                || ! \is_string($termData['cacheKey'])
            ) {
                continue;
            }

            // ---
            // Set the data directly on the term
            // ---

            // Set the title on the term from the term data
            $termData['term']->set(
                'title',
                $termData['title']
            );

            // Set the blueprint on the term to match the taxonomy handle
            $termData['term']->set(
                'blueprint',
                $statamicTaxonomyHandle
            );

            // Save the term safely, handling search index JSON errors gracefully
            $saveSuccess = self::saveTaxonomyTermSafely(
                $termData['term'],
                $statamicTaxonomyHandle,
                $termData['slug'],
                $output
            );

            // Check if save was successful
            if ($saveSuccess) {
                // Log the successful update to console output
                $output->writeln("          - <info>Updated taxonomy term: {$statamicTaxonomyHandle}::{$termData['slug']} with title: {$termData['title']}</info>");

                // Cache the processed term to avoid duplicate processing
                self::$processedTermsCache[$termData['cacheKey']] = true;
            }

            // Clean up variables to free up memory
            unset($termData);

            // Check if we've processed a multiple of 10 terms
            if (((int) $index + 1) % 10 === 0) {
                // Force garbage collection to reclaim memory from circular references
                gc_collect_cycles();
            }
        }

        // Create new terms individually
        if ($termsToCreate !== [] && $termsToUpdate === []) {
            $output->writeln('      - Creating new taxonomy terms...');
        }

        // Validate that the taxonomy exists in Statamic before creating terms
        $taxonomyExists = TaxonomyFacade::find($statamicTaxonomyHandle);

        // Check if the taxonomy was found
        if ($taxonomyExists === null) {
            // Log error and exit if taxonomy doesn't exist
            Log::error("BaseEntryCreator::createTaxonomyTermsFromStoredData - Taxonomy '{$statamicTaxonomyHandle}' does not exist");

            // Exit early to prevent term creation attempts
            return;
        }

        // Validate that the site exists in Statamic before creating terms
        $siteExists = SiteFacade::get($site);

        // Check if the site was found
        if ($siteExists === null) {
            // Log error and exit if site doesn't exist
            Log::error("BaseEntryCreator::createTaxonomyTermsFromStoredData - Site '{$site}' does not exist");

            // Exit early to prevent term creation attempts
            return;
        }

        foreach (ImportProgress::track($output, "{$statamicTaxonomyHandle} terms created", $termsToCreate) as $index => $termData) {
            if (
                ! \is_array($termData)
                || ! isset($termData['slug'], $termData['title'], $termData['cacheKey'])
                || ! \is_string($termData['slug'])
                || ! \is_string($termData['title'])
                || ! \is_string($termData['cacheKey'])
            ) {
                continue;
            }

            /** @var Term $term */
            $term = TermFacade::make();
            $term->taxonomy($statamicTaxonomyHandle);
            $term->slug($termData['slug']);
            $term->in($site);
            $term->set(
                'title',
                $termData['title']
            );
            $term->set(
                'blueprint',
                $statamicTaxonomyHandle
            );
            $saveSuccess = self::saveTaxonomyTermSafely(
                $term,
                $statamicTaxonomyHandle,
                $termData['slug'],
                $output
            );

            // Check if save was successful
            if ($saveSuccess) {
                $output->writeln("          - <info>Created '{$statamicTaxonomyHandle}::{$termData['slug']}'</info>");
            }

            self::$processedTermsCache[$termData['cacheKey']] = true;

            // Clean up variables to free up memory
            unset(
                $term,
                $termData
            );

            // Force garbage collection every 10 terms to prevent memory buildup
            if (((int) $index + 1) % 10 === 0) {
                gc_collect_cycles();
            }
        }
    }

    /**
     * Run the term-save work with Stache index updating paused, then restored.
     *
     * Disabling `Stache::updateIndexes` skips the expensive full index rebuilds
     * (which scan every entry in every collection) that would otherwise fire
     * while saving terms. Statamic offers no public way to turn the flag back
     * on, so this is gated behind {@see StacheIndexUpdateToggle::isSupported()},
     * which fails safe: when re-enabling cannot be verified, the work simply runs
     * with indexing left on (no speedup, never a corrupted Stache).
     *
     * Because terms are written to disk on save regardless of the flag, any cache
     * staleness during the paused window is harmless — the `finally` block
     * re-enables indexing and rebuilds this taxonomy's term store from disk before
     * the caller (and subsequent entry creation) reads from the Stache again.
     *
     * @param  string  $statamicTaxonomyHandle  Taxonomy whose term store to rebuild
     * @param  callable  $work  The term-save work to perform
     */
    private function withTermIndexUpdatesDisabled(
        string $statamicTaxonomyHandle,
        callable $work
    ): void {
        // Fall back to the safe path (indexing stays on) when the toggle is not
        // supported on the installed statamic/cms.
        if (! StacheIndexUpdateToggle::isSupported()) {
            $work();

            return;
        }

        StacheIndexUpdateToggle::disable();

        try {
            $work();
        } finally {
            // Re-enabling is critical: subsequent entry creation in this same run
            // queries the Stache and needs indexing live again.
            if (! StacheIndexUpdateToggle::enable()) {
                Log::error('BaseEntryCreator: failed to re-enable Stache index updating after term import; the Stache may be stale until the next full warm.');
            }

            // Rebuild this taxonomy's term store from disk so the indexes reflect
            // the bulk saves made while indexing was paused.
            $this->rebuildTermStoreFromDisk($statamicTaxonomyHandle);
        }
    }

    /**
     * Clear and warm a single taxonomy's term store so its indexes are rebuilt
     * from the term files on disk.
     *
     * Scoped to one taxonomy (not the whole Stache) to keep the rebuild cheap.
     * Failures are logged but never thrown — re-enabling the index flag already
     * happened in the caller, and the next full warm will reconcile anything left.
     *
     * **The find() guard below cannot be reached with a structured handle**, and
     * would be harmless if it were. Its callers are
     * {@see self::withTermIndexUpdatesDisabled()}, itself called only from
     * {@see self::createTaxonomyTermsFromStoredData()}, which returns early for
     * a structured destination, and `AdvertEntryCreator`, which passes the flat
     * `classified_categories`. Were it reached, a collection has no term store
     * to rebuild and needs none, so returning early is the right answer rather
     * than a false report — unlike {@see self::createTaxonomyTerm()}, where the
     * same null answer was being reported to an operator as a missing taxonomy
     * (wordpress-importer-archive#1144).
     *
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     */
    protected function rebuildTermStoreFromDisk(string $statamicTaxonomyHandle): void
    {
        try {
            // Nothing to rebuild if the taxonomy does not exist.
            if (TaxonomyFacade::find($statamicTaxonomyHandle) === null) {
                return;
            }

            $termStoreRoot = StacheFacade::store('terms');
            if (! \is_object($termStoreRoot) || ! \method_exists($termStoreRoot, 'store')) {
                return;
            }

            $store = $termStoreRoot->store($statamicTaxonomyHandle);
            if (! \is_object($store) || ! \method_exists($store, 'clear') || ! \method_exists($store, 'warm')) {
                return;
            }

            $store->clear();
            $store->warm();
        } catch (\Throwable $throwable) {
            Log::warning("BaseEntryCreator: failed to rebuild term store '{$statamicTaxonomyHandle}' after bulk term save: ".$throwable->getMessage());
        }
    }

    /**
     * Cache for site names to avoid repeated Statamic facade calls.
     *
     * @var array<string, string>
     */
    private static array $siteNamesCache = [];

    /**
     * Cache for processed taxonomy terms to avoid duplicate operations.
     *
     * @var array<string, bool>
     */
    private static array $processedTermsCache = [];

    /**
     * Cache for processed taxonomy imports to avoid duplicate processing.
     *
     * @var array<string, bool>
     */
    private static array $processedTaxonomyImports = [];

    /**
     * Unmapped taxonomies already reported, keyed "{site}::{taxonomy}".
     *
     * The term pass runs once per entry creator, and every creator sees the same
     * export, so without this the same discard is reported once per creator.
     *
     * @var array<string, string>
     */
    private static array $reportedUnmappedTaxonomies = [];

    /**
     * Converts a WXR `<wp:category>` element into the `<wp:term>` shape.
     *
     * WordPress serializes the built-in `category` taxonomy as `<wp:category>` with
     * `<wp:category_nicename>`, not as the `<wp:term>` shape every other taxonomy uses.
     * `XmlParser::getTerms()` matches only `term`, so categories are invisible to it —
     * in the news export that is 136 `<wp:category>` elements against 6 `<wp:term>`.
     *
     * **`<wp:category_parent>` has to travel with it.** It holds the parent's
     * *nicename*, which is exactly the shape `<wp:term_parent>` carries and the
     * shape {@see StructuredTermImporter} resolves back to a term id when it
     * builds the collection tree. Dropping it made every category import as a
     * root: the tree came out flat and `stampParents()` had no edge to stamp, so
     * a nested category's `:parent:is` child query returned nothing — the exact
     * behavior wordpress-importer-archive#422 requires. Measured on the 259-export corpus: 19 exports carry
     * 146 nested categories, and 16 of those 19 declare categories ONLY as
     * `<wp:category>`, so for them the hierarchy was lost outright.
     *
     * @param  SimpleXMLElement  $category  A `<wp:category>` element
     * @return SimpleXMLElement The equivalent pseudo `<wp:term>` element
     */
    protected static function categoryToPseudoTerm(
        SimpleXMLElement $category
    ): SimpleXMLElement {
        // Build the term element with the WordPress export namespace declared
        $pseudoTerm = new SimpleXMLElement('<wp:term xmlns:wp="http://wordpress.org/export/1.2/"></wp:term>');

        // Carry the term id across unchanged
        $pseudoTerm->addChild(
            'wp:term_id',
            XpathHelper::getStringValue(
                $category,
                'wp:term_id'
            )
        );

        // Categories are, by definition, the `category` taxonomy
        $pseudoTerm->addChild(
            'wp:term_taxonomy',
            'category'
        );

        // The slug lives under a category-specific element name
        $pseudoTerm->addChild(
            'wp:term_slug',
            XpathHelper::getStringValue(
                $category,
                'wp:category_nicename'
            )
        );

        // The display name likewise
        $pseudoTerm->addChild(
            'wp:term_name',
            XpathHelper::getStringValue(
                $category,
                'wp:cat_name'
            )
        );

        // ---
        // The parent edge, under yet another category-specific element name. Both
        // sides hold a nicename, so this is a straight carry-over; a top-level
        // category yields an empty string, which the tree builder reads as "no
        // parent" rather than as a parent it could not find.
        // ---
        $pseudoTerm->addChild(
            'wp:term_parent',
            XpathHelper::getStringValue(
                $category,
                'wp:category_parent'
            )
        );

        return $pseudoTerm;
    }

    /**
     * Returns every `<wp:category>` in the export as a pseudo `<wp:term>`.
     *
     * Lets the term maps see built-in categories. Without this,
     * `nicenameToTermIdMap['category']` is empty on every real export, silently
     * disabling anything that resolves a category by term id (wordpress-importer-archive#441).
     *
     * @param  XmlParser  $xmlParser  Parser for the WordPress export
     * @return list<SimpleXMLElement> Category elements in the `<wp:term>` shape
     */
    protected static function categoryPseudoTerms(
        XmlParser $xmlParser
    ): array {
        $pseudoTerms = [];

        // Convert each category element, skipping anything not parseable
        foreach ($xmlParser->getCategories() as $category) {
            // Guard against a malformed entry in the parsed list
            if (! $category instanceof SimpleXMLElement) {
                continue;
            }

            $pseudoTerms[] = self::categoryToPseudoTerm($category);
        }

        return $pseudoTerms;
    }

    /**
     * Converts a WXR `<wp:tag>` element into the `<wp:term>` shape.
     *
     * WordPress serializes the built-in `post_tag` taxonomy as `<wp:tag>` with
     * `<wp:tag_slug>` and `<wp:tag_name>`, the tag-side counterpart of
     * {@see self::categoryToPseudoTerm()}. Tags are flat, so there is no parent
     * element to carry.
     *
     * @param  SimpleXMLElement  $tag  A `<wp:tag>` element
     * @return SimpleXMLElement The equivalent pseudo `<wp:term>` element
     */
    protected static function tagToPseudoTerm(
        SimpleXMLElement $tag
    ): SimpleXMLElement {
        // Build the term element with the WordPress export namespace declared
        $pseudoTerm = new SimpleXMLElement('<wp:term xmlns:wp="http://wordpress.org/export/1.2/"></wp:term>');

        // Carry the term id across unchanged
        $pseudoTerm->addChild(
            'wp:term_id',
            XpathHelper::getStringValue(
                $tag,
                'wp:term_id'
            )
        );

        // Tags are, by definition, the `post_tag` taxonomy
        $pseudoTerm->addChild(
            'wp:term_taxonomy',
            'post_tag'
        );

        // The slug lives under a tag-specific element name
        $pseudoTerm->addChild(
            'wp:term_slug',
            XpathHelper::getStringValue(
                $tag,
                'wp:tag_slug'
            )
        );

        // The display name likewise
        $pseudoTerm->addChild(
            'wp:term_name',
            XpathHelper::getStringValue(
                $tag,
                'wp:tag_name'
            )
        );

        return $pseudoTerm;
    }

    /**
     * Returns every `<wp:tag>` in the export as a pseudo `<wp:term>`.
     *
     * Lets the term maps see built-in tags, as {@see self::categoryPseudoTerms()}
     * does for categories. A post names its tags by nicename, and a tag's
     * destination is recorded by term id, so without these the nicename has no
     * term id to resolve through and every tag reference is lost (wordpress-importer-archive#1220).
     *
     * @param  XmlParser  $xmlParser  Parser for the WordPress export
     * @return list<SimpleXMLElement> Tag elements in the `<wp:term>` shape
     */
    protected static function tagPseudoTerms(
        XmlParser $xmlParser
    ): array {
        $pseudoTerms = [];

        // Convert each tag element, skipping anything not parseable
        foreach ($xmlParser->getTags() as $tag) {
            // Guard against a malformed entry in the parsed list
            if (! $tag instanceof SimpleXMLElement) {
                continue;
            }

            $pseudoTerms[] = self::tagToPseudoTerm($tag);
        }

        return $pseudoTerms;
    }

    /**
     * Initialize terms for the entry creator with consistent processing.
     *
     * This method provides a standardized approach to term handling across all
     * entry creators. It:
     *   1. Imports taxonomy terms at the beginning of the import process
     *   2. Gets all terms from the XML parser
     *   3. Builds a term map using TermMapBuilder for efficient lookups
     *   4. Stores the term map in the class property for use throughout processing
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  array<int|string, mixed>  $wordpressTaxonomyHandles  Array of WordPress source taxonomy handles to import terms for
     *                                                              (e.g., ['category', 'post_tag', 'uamspeople-categories'])
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function initializeTerms(
        XmlParser $xmlParser,
        array $wordpressTaxonomyHandles,
        string $site,
        OutputInterface $output
    ): void {
        // Import taxonomy terms at the beginning of the import process
        $this->importTaxonomyTermsAtBeginning(
            $xmlParser,
            $wordpressTaxonomyHandles,
            $site,
            $output
        );

        // ---
        // Get all terms for term map building. Built-in categories arrive as
        // `<wp:category>` rather than `<wp:term>`, so `getTerms()` never returns them —
        // they are converted and appended here, otherwise every category lookup by term
        // id misses on every real export (wordpress-importer-archive#441). Built-in tags arrive as `<wp:tag>`
        // for the same reason and are appended alongside (wordpress-importer-archive#1220).
        // ---
        $terms = [
            ...$xmlParser->getTerms(),
            ...self::categoryPseudoTerms($xmlParser),
            ...self::tagPseudoTerms($xmlParser),
        ];

        // Build term map using TermMapBuilder for efficient term lookups
        $this->termMap = TermMapBuilder::buildTermMap(
            $terms,
            $wordpressTaxonomyHandles
        );

        // Build nicename to term ID mapping for efficient ID-based lookups
        $this->nicenameToTermIdMap = TermMapBuilder::buildNicenameToTermIdMap(
            $terms,
            $wordpressTaxonomyHandles
        );

        // Clean up variables to free up memory
        unset(
            $terms
        );
    }

    /**
     * Import taxonomy terms at the beginning of the import process.
     *
     * This method processes all terms from the XML export for the specified
     * WordPress taxonomies and creates/updates them in Statamic before processing
     * entries. This ensures that taxonomy terms are available when entries are
     * created.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  array<int|string, mixed>  $wordpressTaxonomyHandles  Array of WordPress source taxonomy handles to import terms for
     *                                                              (e.g., ['category', 'post_tag', 'uamspeople-categories'])
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function importTaxonomyTermsAtBeginning(
        XmlParser $xmlParser,
        array $wordpressTaxonomyHandles,
        string $site,
        OutputInterface $output
    ): void {
        // Check if taxonomies array is empty to avoid unnecessary processing
        if ($wordpressTaxonomyHandles === []) {
            // Exit early if no taxonomies to process
            return;
        }

        // Create a copy of the taxonomy handles array for sorting
        $sortedTaxonomies = $wordpressTaxonomyHandles;

        // Sort the taxonomy handles alphabetically to ensure consistent cache keys
        sort($sortedTaxonomies);
        $sortedTaxonomies = \array_values(
            \array_filter(
                $sortedTaxonomies,
                \is_string(...)
            )
        );

        // Generate a unique cache key combining site and sorted taxonomy handles
        $cacheKey = $site.'::'.\implode(
            ',',
            $sortedTaxonomies
        );

        // Check if we've already processed these taxonomies for this site
        if (isset(self::$processedTaxonomyImports[$cacheKey])) {
            // Skip processing if already done
            return;
        }

        // Mark this taxonomy import as processed
        self::$processedTaxonomyImports[$cacheKey] = true;

        // Display progress message to inform user of current operation
        $output->writeln('Importing taxonomy terms at beginning of import process...');

        // Get all terms from the XML export
        $terms = $xmlParser->getTerms();

        // Initialize array to group terms by taxonomy type
        $termsByTaxonomy = [];

        // Initialize array to track seen term IDs and prevent duplicates
        $seenTermIds = [];

        // Tally terms belonging to taxonomies recorded as having no destination.
        // These never enter $termsByTaxonomy — no caller lists them — so they are
        // counted here, where every term in the export is still visible.
        $unmappedTermCounts = [];

        // Loop through all terms from the XML export to categorize them
        foreach ($terms as $term) {
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the taxonomy type from the current term
            $termTaxonomy = XpathHelper::getStringValue(
                $term,
                'wp:term_taxonomy'
            );

            // Check if this term belongs to one of the taxonomies we need to process
            if (
                \in_array(
                    $termTaxonomy,
                    $wordpressTaxonomyHandles,
                    true
                )
            ) {
                // Add term to taxonomy group with deduplication
                $this->addTermToTaxonomyGroup(
                    $termsByTaxonomy,
                    $seenTermIds,
                    $termTaxonomy,
                    $term
                );
            } elseif (TaxonomyDispositions::isUnmapped($termTaxonomy)) {
                // ---
                // Recorded as having no Statamic destination. No caller lists
                // these handles, so the term is dropped by the filter above and
                // never reaches the per-taxonomy loop below. Count it here — the
                // last point at which every term in the export is still visible
                // — so the discard can be reported rather than being silent.
                // ---
                $unmappedTermCounts[$termTaxonomy] =
                    ($unmappedTermCounts[$termTaxonomy] ?? 0) + 1;
            }
        }

        // Check if category taxonomy is in the list of taxonomies to process
        if (
            \in_array(
                'category',
                $wordpressTaxonomyHandles,
                true
            )
        ) {
            // Get all wp:category elements from the XML parser
            $categories = $xmlParser->getCategories();

            $termsByTaxonomy['category'] ??= [];

            // Loop through each wp:category element to convert to wp:term format
            foreach ($categories as $category) {
                if (! $category instanceof SimpleXMLElement) {
                    continue;
                }

                // Convert the category element into the wp:term shape
                $pseudoTerm = self::categoryToPseudoTerm($category);

                // Add converted pseudo-term to taxonomy group with deduplication
                $this->addTermToTaxonomyGroup(
                    $termsByTaxonomy,
                    $seenTermIds,
                    'category',
                    $pseudoTerm
                );
            }
        }

        // Check if post_tag taxonomy is in the list of taxonomies to process
        if (
            \in_array(
                'post_tag',
                $wordpressTaxonomyHandles,
                true
            )
        ) {
            // Get all wp:tag elements from the XML parser
            $tags = $xmlParser->getTags();

            $termsByTaxonomy['post_tag'] ??= [];

            // Loop through each wp:tag element to convert to wp:term format
            foreach ($tags as $tag) {
                if (! $tag instanceof SimpleXMLElement) {
                    continue;
                }

                // Convert the tag element into the wp:term shape
                $pseudoTerm = self::tagToPseudoTerm($tag);

                // Add converted pseudo-term to taxonomy group with deduplication
                $this->addTermToTaxonomyGroup(
                    $termsByTaxonomy,
                    $seenTermIds,
                    'post_tag',
                    $pseudoTerm
                );
            }
        }

        // Report anything the export carries that is recorded as unimportable.
        $this->reportUnmappedTaxonomies($unmappedTermCounts, $site, $output);

        // Loop through each taxonomy group to process terms
        foreach ($termsByTaxonomy as $taxonomy => $taxonomyTerms) {
            if (! \is_string($taxonomy) || ! \is_array($taxonomyTerms)) {
                continue;
            }

            // Check if there are any terms to process for this taxonomy
            if ($taxonomyTerms === []) {
                // Display message that there are no terms to process for this taxonomy
                $output->writeln("  - No '{$taxonomy}' taxonomy terms to process.");

                // Continue to the next taxonomy
                continue;
            }

            // ---
            // A deliberately unmapped taxonomy has no Statamic destination by
            // decision rather than by omission. Left to fall through, its terms
            // reach createTaxonomyTermsFromStoredData(), whose
            // TaxonomyFacade::find() guard can never resolve a taxonomy that
            // does not exist — so they vanish with nothing written and nothing
            // said. Report the loss instead, and skip the work.
            // ---
            if (TaxonomyDispositions::isUnmapped($taxonomy)) {
                $this->reportUnmappedTaxonomies(
                    [$taxonomy => \count($taxonomyTerms)],
                    $site,
                    $output
                );

                // Continue to the next taxonomy
                continue;
            }

            // Display progress message for current taxonomy
            $output->writeln("  - Processing '{$taxonomy}' taxonomy terms...");

            // Build hierarchical term map for this taxonomy
            $termMap = $this->buildHierarchicalTermMap($taxonomyTerms);

            // Initialize array to store processed term data for this taxonomy
            $siteSpecificData = [];

            // Loop through all terms in the current taxonomy group
            foreach ($taxonomyTerms as $term) {
                if (! $term instanceof SimpleXMLElement) {
                    continue;
                }

                // Extract the term slug from the XML element
                $termSlug = XpathHelper::getStringValue(
                    $term,
                    'wp:term_slug'
                );

                // Extract the term name from the XML element
                $termName = XpathHelper::getStringValue(
                    $term,
                    'wp:term_name'
                );

                // Check if the term has a valid slug before processing
                if ($termSlug !== '') {
                    // Get the full hierarchical slug from termMap or use the original slug as fallback
                    $mappedTerm = $termMap[$termSlug] ?? null;
                    $fullSlug = \is_array($mappedTerm)
                        && isset($mappedTerm['full_slug'])
                        && \is_string($mappedTerm['full_slug'])
                        ? $mappedTerm['full_slug']
                        : $termSlug;

                    // Add term data to the processing array with hierarchical slug
                    $siteSpecificData[] = [
                        'original' => $fullSlug,
                        'name' => $termName,
                    ];
                }
            }

            // ---
            // Map the WordPress taxonomy to its Statamic destination handle. The
            // mapping table stays the single source of truth for the HANDLE; the
            // destination's SHAPE lives in TaxonomyDispositions, because
            // `UAMS-Web/uams-statamic-archive#1863` converted four of these destinations
            // into collections without changing their handles.
            // ---
            $statamicTaxonomyHandle = ImportWordPress::mapWordPressToStatamicHandle(
                $taxonomy,
                'taxonomy'
            ) ?? $taxonomy;

            // ---
            // A structured destination is a collection of entries carrying a
            // tree, not a flat taxonomy of terms. Terms written to one of those
            // handles went nowhere: createTaxonomyTermsFromStoredData() guards on
            // TaxonomyFacade::find(), which returns null for a collection, so the
            // hierarchy was silently dropped rather than reported.
            // ---
            if (TaxonomyDispositions::isStructured($taxonomy)) {
                $this->structuredTermImporter()->import(
                    $taxonomyTerms,
                    $taxonomy,
                    $statamicTaxonomyHandle,
                    $site,
                    $output
                );

                continue;
            }

            // Create taxonomy terms from the collected data
            $this->createTaxonomyTermsFromStoredData(
                $siteSpecificData,
                $statamicTaxonomyHandle,
                $site,
                $output
            );

            // Record where each flat term landed so anything downstream can
            // resolve a WordPress term id to its Statamic destination.
            self::registerFlatTermDestinations(
                $taxonomyTerms,
                $taxonomy,
                $statamicTaxonomyHandle,
                $termMap,
                $site
            );
        }

        // Clean up variables to free up memory
        unset(
            $terms,
            $termsByTaxonomy,
            $taxonomyTerms,
            $termMap,
            $siteSpecificData,
            $term,
            $termTaxonomy,
            $termSlug,
            $termName,
            $fullSlug,
            $sortedTaxonomies,
            $cacheKey,
            $seenTermIds
        );

        // Display completion message to inform user that import is finished
        $output->writeln('  - <info>Taxonomy terms import completed.</info>');
    }

    /**
     * Resolve the importer used for structured, collection-backed taxonomies.
     *
     * Resolved from the container rather than constructor-injected so the seven
     * existing entry-creator constructors keep their signatures; overridable so a
     * test can substitute a probe.
     *
     * @return StructuredTermImporter The structured term importer
     */
    protected function structuredTermImporter(): StructuredTermImporter
    {
        return app(StructuredTermImporter::class);
    }

    /**
     * Record the Statamic destination of every term written to a flat taxonomy.
     *
     * The slug recorded here is the one {@see self::createTaxonomyTermsFromStoredData()}
     * actually writes — the hierarchical full slug with the site prefix applied —
     * so a later lookup resolves to a term that exists rather than to the raw
     * WordPress slug.
     *
     * @param  array<int|string, mixed>  $taxonomyTerms  The `<wp:term>` elements for one taxonomy
     * @param  string  $wordpressTaxonomy  The WordPress taxonomy handle
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @param  array<int|string, mixed>  $termMap  Map of term slugs to full slugs and parents
     * @param  string  $site  The Statamic site handle
     */
    protected static function registerFlatTermDestinations(
        array $taxonomyTerms,
        string $wordpressTaxonomy,
        string $statamicTaxonomyHandle,
        array $termMap,
        string $site
    ): void {
        foreach ($taxonomyTerms as $term) {
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the term id, which is the key callers resolve against.
            $termId = XpathHelper::getStringValue(
                $term,
                'wp:term_id'
            );

            // Extract the term slug used to look up its hierarchical form.
            $termSlug = XpathHelper::getStringValue(
                $term,
                'wp:term_slug'
            );

            if ($termId === '' || $termSlug === '') {
                continue;
            }

            // Prefer the hierarchical full slug when one was computed for it.
            $mappedTerm = $termMap[$termSlug] ?? null;
            $fullSlug = \is_array($mappedTerm)
                && isset($mappedTerm['full_slug'])
                && \is_string($mappedTerm['full_slug'])
                ? $mappedTerm['full_slug']
                : $termSlug;

            // Record the destination against the site-prefixed slug that was written.
            TermDestinationRegistry::registerTaxonomyTerm(
                $wordpressTaxonomy,
                $termId,
                $statamicTaxonomyHandle,
                TaxonomySlugProcessor::addSitePrefixToSlug(
                    $fullSlug,
                    $site
                )
            );
        }
    }

    /**
     * Build hierarchical term map for a given set of taxonomy terms.
     *
     * This method processes terms to build a map that includes hierarchical
     * relationships and full slug paths, similar to the logic used in
     * individual entry creators.
     *
     * @param  array<int|string, mixed>  $wordPressTaxonomyTermObjects  Array of WordPress XML taxonomy term elements
     * @return array<int|string, mixed> Map of term slugs to their full slugs and parent slugs
     */
    protected function buildHierarchicalTermMap(
        array $wordPressTaxonomyTermObjects
    ): array {
        // Initialize empty term map for storing processed terms
        $termMap = [];

        // Loop through all terms to extract and process taxonomy data
        foreach ($wordPressTaxonomyTermObjects as $term) {
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the term slug from the XML term
            $slug = XpathHelper::getStringValue(
                $term,
                'wp:term_slug'
            );

            // Extract the parent term slug from the XML term
            $parent = XpathHelper::getStringValue(
                $term,
                'wp:term_parent'
            );

            // Check if the term has a valid slug before processing
            if ($slug !== '') {
                // Store the term data in the term map
                $termMap[$slug] = [
                    'full_slug' => $slug,
                    'parent' => $parent,
                ];
            }
        }

        // Loop through the term map to build full hierarchical slugs
        foreach ($termMap as $slug => &$data) {
            // Calculate the full hierarchical slug for each term
            $data['full_slug'] = $this->getFullTermSlug(
                $slug,
                $termMap
            );
        }

        // Clean up variables to free up memory
        unset(
            $data,
            $parent,
            $slug,
            $term
        );

        // Return the completed term map with hierarchical slugs
        return $termMap;
    }

    /**
     * Constructs the full slug path for a term, recursively prepending ancestor slugs.
     *
     * This method recursively builds hierarchical slugs by prepending parent
     * slugs to create full hierarchical paths (e.g., 'grandparent_parent_term').
     *
     * @param  string  $termSlug  The term slug to process
     * @param  array<int|string, mixed>  $termMap  Map of term slugs to their full slugs and parent slugs
     * @param  array<int|string, mixed>  $visited  Array to track visited term slugs to prevent infinite recursion
     * @return string The full slug path or term slug if no parent
     */
    protected function getFullTermSlug(
        string $termSlug,
        array $termMap,
        array &$visited = []
    ): string {
        // Check if the term slug is valid and exists in the term map
        if (
            $termSlug === ''
            || ! isset($termMap[$termSlug])
        ) {
            // Return the original slug if term is not found or empty
            return $termSlug;
        }

        // Get the term data from the term map
        $term = $termMap[$termSlug];
        if (! \is_array($term)) {
            return $termSlug;
        }

        // ---
        // Use the base slug (termSlug) instead of full_slug to avoid duplication. The
        // full_slug may already contain parent paths from previous iterations.
        // ---
        $baseSlug = $termSlug;

        // Check if we've already visited this term to prevent infinite recursion
        if (
            \in_array(
                $termSlug,
                $visited,
                true
            )
        ) {
            // ---
            // Return the base slug if we've already processed this term (to break circular
            // references)
            // ---
            return $baseSlug;
        }

        // Mark this term as visited to prevent circular references
        $visited[] = $termSlug;

        // Get the parent slug from the term data
        $parentSlug = isset($term['parent']) && \is_string($term['parent'])
            ? $term['parent']
            : '';

        // Check if the term has a parent and the parent exists in the term map
        if (
            $parentSlug !== ''
            && isset($termMap[$parentSlug])
        ) {
            // Recursively get the full slug for the parent term
            $ancestorSlug = $this->getFullTermSlug(
                $parentSlug,
                $termMap,
                $visited
            );

            // Combine the ancestor slug with the current base slug
            return $ancestorSlug !== '' ? "{$ancestorSlug}_{$baseSlug}" : $baseSlug;
        }

        // Return the base slug if no parent exists
        return $baseSlug;
    }

    /**
     * Safely saves a taxonomy term, handling search index JSON errors gracefully.
     *
     * When saving taxonomy terms during import, Statamic automatically updates
     * the search index. If the search index file contains corrupted JSON, this
     * can cause the import to fail. This method wraps the save operation in a
     * try-catch to handle such errors gracefully, allowing the import to
     * continue.
     *
     * @param  Term  $term  The term to save
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle for logging
     * @param  string  $termSlug  The term slug for logging
     * @param  OutputInterface  $output  Console output for logging
     * @return bool True if save was successful, false if an error occurred
     */
    private static function saveTaxonomyTermSafely(
        $term,
        string $statamicTaxonomyHandle,
        string $termSlug,
        OutputInterface $output
    ): bool {
        try {
            $term->save();

            return true;
        } catch (\JsonException $e) {
            // Log the JSON error with context
            Log::warning(
                "BaseEntryCreator::saveTaxonomyTermSafely: Search index JSON error when saving taxonomy term '{$statamicTaxonomyHandle}::{$termSlug}'. The term was saved but search indexing failed.",
                [
                    'taxonomy' => $statamicTaxonomyHandle,
                    'term_slug' => $termSlug,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                ]
            );

            // Display warning to user
            $output->writeln(
                "<comment>Warning: Taxonomy term '{$statamicTaxonomyHandle}::{$termSlug}' was saved but search index update failed (corrupted search index). Import will continue.</comment>"
            );

            // Return true because the term was actually saved, just the search
            // index update failed
            return true;
        } catch (Exception $e) {
            // Log other exceptions
            Log::error(
                "BaseEntryCreator::saveTaxonomyTermSafely: Unexpected error when saving taxonomy term '{$statamicTaxonomyHandle}::{$termSlug}'",
                [
                    'taxonomy' => $statamicTaxonomyHandle,
                    'term_slug' => $termSlug,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            // Display error to user
            $output->writeln(
                "<error>Error: Failed to save taxonomy term '{$statamicTaxonomyHandle}::{$termSlug}': {$e->getMessage()}</error>"
            );

            // Return false for unexpected errors
            return false;
        }
    }

    /**
     * Creates taxonomy terms with intelligent slug processing and caching.
     *
     * This method provides a unified interface for creating taxonomy terms from
     * parsers, processors, and entry creators. It intelligently handles both
     * simple and hierarchical slugs, automatically adding site prefixes while
     * preserving hierarchy structure.
     *
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle (e.g., 'people_groups', 'logos_item_categories')
     * @param  string  $termSlug  The term slug to create (may be hierarchical or simple)
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $termName  Optional term name. If not provided, generates from slug
     * @return bool True if term was created, false if it already existed
     */
    public static function createTaxonomyTerm(
        string $statamicTaxonomyHandle,
        string $termSlug,
        string $site,
        OutputInterface $output,
        ?string $termName = null
    ): bool {
        // Add site prefix to create site-specific taxonomy slug (handles hierarchy automatically)
        $siteSpecificSlug = TaxonomySlugProcessor::addSitePrefixToSlug(
            $termSlug,
            $site
        );

        // Create cache key for this term operation
        $cacheKey = "{$statamicTaxonomyHandle}::{$siteSpecificSlug}";

        // ---
        // Short-circuit only when this term has already been brought to its
        // final, correct state (cached `true`). A cached `false` means the term
        // still needs work — a prior save failed, or it was found with a
        // slug-as-title / wrong blueprint that was never reconciled — so fall
        // through and re-attempt rather than returning the stale failure. This
        // lets a later call with an explicit term name correct an auto-created
        // term whose title was left as its slug.
        // ---
        if (
            isset(self::$processedTermsCache[$cacheKey])
            && self::$processedTermsCache[$cacheKey]
        ) {
            return true;
        }

        // ---
        // Answer for a structured destination here rather than letting the
        // TaxonomyFacade::find() guard below answer for it.
        //
        // `UAMS-Web/uams-statamic-archive#1863` converted `people_groups` and
        // `logos_item_categories` into collections alongside `post_categories`,
        // and find() returns null for every one of them. So that guard reports
        // "the taxonomy does not exist" for a handle that does exist, and
        // reports it for every referenced term rather than for a genuinely
        // missing one - a run asserting a loss that did not occur (wordpress-importer-archive#1144).
        //
        // A term reaching here for a structured handle IS absent from the
        // collection: {@see BaseTaxonomyValidator::getExistingTerms()} resolves
        // existence against the collection before calling this. It cannot be
        // created from here either, because {@see StructuredTermImporter} owns
        // those entries and holds the tree position and WordPress term id this
        // call site has neither of. So the loss is reported and nothing is
        // written - the alternative being a parentless entry or silence.
        // ---
        if (TaxonomyDispositions::isStructuredDestination($statamicTaxonomyHandle)) {
            // Distinguish an absent term from an absent destination, since the
            // two need different fixes and the old message named neither
            $destinationMissing = CollectionFacade::findByHandle($statamicTaxonomyHandle) === null;

            $reason = $destinationMissing
                ? "'{$statamicTaxonomyHandle}' exists as neither a taxonomy nor a collection"
                : "the '{$statamicTaxonomyHandle}' collection has no entry for it";

            // Report the loss rather than writing a term to a handle that is
            // not a taxonomy, which would go nowhere
            $output->writeln("<comment>Warning: cannot create '{$siteSpecificSlug}': {$reason}. Structured terms are created by the term pass, so this reference will not resolve.</comment>");

            // Cache so a repeated reference does not repeat the warning, which
            // is what the taxonomy-missing path below does for the same reason
            self::$processedTermsCache[$cacheKey] = true;

            // Return false to indicate no term was created
            return false;
        }

        // Check if term already exists
        /** @var ?Term $existingTerm */
        $existingTerm = TermFacade::find($cacheKey);
        if ($existingTerm !== null) {
            // Get site name from cache
            $siteName = self::getCachedSiteName($site);
            $expectedTermName = $termName !== ''
                ? $termName
                : \ucwords(
                    \str_replace(
                        [
                            '-',
                            '_',
                        ],
                        ' ',
                        $termSlug
                    )
                );
            $expectedTitle = "{$siteName} — {$expectedTermName}";

            // If the existing term has the wrong title or blueprint, update it
            if (
                self::getTermStringValue(
                    $existingTerm,
                    'title'
                ) !== $expectedTitle
                || self::getTermStringValue(
                    $existingTerm,
                    'blueprint'
                ) !== $statamicTaxonomyHandle
            ) {
                $existingTerm->set(
                    'title',
                    $expectedTitle
                );
                $existingTerm->set(
                    'blueprint',
                    $statamicTaxonomyHandle
                );
                $saveSuccess = self::saveTaxonomyTermSafely(
                    $existingTerm,
                    $statamicTaxonomyHandle,
                    $siteSpecificSlug,
                    $output
                );

                // Check if save was successful
                if ($saveSuccess) {
                    $output->writeln("  - <info>Updated taxonomy term: {$statamicTaxonomyHandle}::{$siteSpecificSlug} with title: {$expectedTitle}</info>");
                    self::$processedTermsCache[$cacheKey] = true;

                    return true;
                }

                // If save failed, return false
                self::$processedTermsCache[$cacheKey] = false;

                return false;
            }

            $output->writeln("<comment>Taxonomy term already exists: {$statamicTaxonomyHandle}::{$siteSpecificSlug}</comment>");
            self::$processedTermsCache[$cacheKey] = false;

            return false;
        }

        // Get site name from cache
        $siteName = self::getCachedSiteName($site);

        // Use provided term name or generate from slug
        if ($termName === '') {
            $termName = \ucwords(
                \str_replace(
                    [
                        '-',
                        '_',
                    ],
                    ' ',
                    $termSlug
                )
            );
        }

        $title = "{$siteName} — {$termName}";

        // Check if the taxonomy exists in Statamic
        $taxonomyExists = TaxonomyFacade::find($statamicTaxonomyHandle);

        // Skip term creation if taxonomy doesn't exist
        if ($taxonomyExists === null) {
            // Log warning about missing taxonomy
            $output->writeln("<comment>Warning: Taxonomy '{$statamicTaxonomyHandle}' does not exist. Skipping term creation for '{$siteSpecificSlug}'.</comment>");

            // Cache the failed attempt to avoid repeated warnings
            self::$processedTermsCache[$cacheKey] = true;

            // Return false to indicate term creation failed
            return false;
        }

        // Create the term using Statamic facade
        /** @var Term $term */
        $term = TermFacade::make();
        $term->taxonomy($statamicTaxonomyHandle);
        $term->slug($siteSpecificSlug);
        $term->in($site);
        $term->set(
            'title',
            $title
        );
        $term->set(
            'blueprint',
            $statamicTaxonomyHandle
        );

        // Save the term safely, handling search index errors
        $saveSuccess = self::saveTaxonomyTermSafely(
            $term,
            $statamicTaxonomyHandle,
            $siteSpecificSlug,
            $output
        );

        // Check if save was successful
        if ($saveSuccess) {
            // Log the creation of the term
            $output->writeln("  - <info>Created '{$statamicTaxonomyHandle}::{$siteSpecificSlug}'</info>");

            // Clear the taxonomy validator cache to include the newly created term
            BaseTaxonomyValidator::clearCaches();

            self::$processedTermsCache[$cacheKey] = true;

            return true;
        }

        // If save failed, return false
        self::$processedTermsCache[$cacheKey] = false;

        return false;
    }

    /**
     * Creates a taxonomy term from term map data with consolidated logic.
     *
     * This method provides a unified approach for creating taxonomy terms from
     * term map data, eliminating duplication between slug-based and ID-based
     * term creation methods. It validates the term exists in the map, extracts
     * the appropriate data, and creates the term using the core creation logic.
     *
     * @param  string  $termKey  The term key (slug or ID) to look up in the term map
     * @param  array<int|string, mixed>  $termMap  Map of term keys to term data
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle (e.g., 'people_groups', 'logos_item_categories')
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     * @param  string  $nameField  The field name to use for term name extraction (default: 'term_name')
     * @return bool True if term was created, false if it already existed or term data was missing
     */
    public static function createTaxonomyTermFromMap(
        string $termKey,
        array $termMap,
        string $statamicTaxonomyHandle,
        string $site,
        OutputInterface $output,
        string $nameField = 'term_name'
    ): bool {
        // Check if term data exists in the term map
        if (! isset($termMap[$termKey])) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'taxonomy' => $statamicTaxonomyHandle,
                    'term_key' => $termKey,
                    'available_term_keys' => \array_keys($termMap),
                ]
            );

            // Log a warning with detailed context about the missing term
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "BaseEntryCreator::createTaxonomyTermFromMap: Term key '$termKey' not found in term map for taxonomy '$statamicTaxonomyHandle'",
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Display a warning message to the user
            $output->writeln("<comment>Warning: Term key '$termKey' not found for taxonomy '$statamicTaxonomyHandle' - skipping term creation</comment>");

            // Return false to indicate the operation failed
            return false;
        }

        // Extract the full hierarchical slug from the term map
        $termMapEntry = $termMap[$termKey] ?? null;
        if (! \is_array($termMapEntry)) {
            return false;
        }

        $fullSlug = isset($termMapEntry['full_slug']) && \is_string($termMapEntry['full_slug'])
            ? $termMapEntry['full_slug']
            : (
                isset($termMapEntry['term_slug']) && \is_string($termMapEntry['term_slug'])
                    ? $termMapEntry['term_slug']
                    : $termKey
            );

        // Extract the term name from the term map, or generate one from the slug if not available
        $termName = isset($termMapEntry[$nameField]) && \is_string($termMapEntry[$nameField])
            ? $termMapEntry[$nameField]
            : (
                isset($termMapEntry['name']) && \is_string($termMapEntry['name'])
                    ? $termMapEntry['name']
                    : \ucwords(
                        \str_replace(
                            ['-', '_'],
                            ' ',
                            $fullSlug
                        )
                    )
            );

        // The full_slug from the term map is already the complete hierarchical path,
        // so we use the unified method which handles hierarchy automatically
        return self::createTaxonomyTerm(
            $statamicTaxonomyHandle,
            $fullSlug,
            $site,
            $output,
            $termName
        );
    }

    /**
     * On-demand taxonomy terms queued during content processing for a single
     * batched creation pass per taxonomy.
     *
     * Structure: [statamicTaxonomyHandle][site][slug] => ['original' => slug,
     * 'name' => name]. Keyed by slug so repeated references to the same term
     * (across pages/shortcodes) dedupe in memory. The value shape is exactly what
     * {@see self::createTaxonomyTermsFromStoredData()} consumes.
     *
     * @var array<string, array<string, array<string, array{original: string, name: string}>>>
     */
    private static array $deferredTermQueue = [];

    /**
     * Queue a taxonomy term for batched creation instead of creating it inline.
     *
     * This is the taxonomy- and shortcode-agnostic counterpart to
     * {@see self::createTaxonomyTerm()}. On-demand callers (people/team and logo
     * shortcodes, and any future parser) enqueue here during the content loop;
     * {@see self::flushDeferredTaxonomyTerms()} then creates everything in one
     * preload + paused-index pass per taxonomy. Creating terms one at a time
     * inline forced a cold Stache associations rebuild per term (~7–9s each when
     * the taxonomy is attached to a collection); batching collapses that cost.
     *
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle (e.g. 'people_groups')
     * @param  string  $termSlug  The full (pre-site-prefix) hierarchical term slug
     * @param  string  $site  The Statamic site handle
     * @param  ?string  $termName  Optional term name; the batch pass falls back to the slug when empty
     */
    public static function queueTaxonomyTerm(
        string $statamicTaxonomyHandle,
        string $termSlug,
        string $site,
        ?string $termName = null
    ): void {
        self::$deferredTermQueue[$statamicTaxonomyHandle][$site][$termSlug] = [
            'original' => $termSlug,
            'name' => $termName ?? '',
        ];
    }

    /**
     * Queue a taxonomy term resolved from a term map for batched creation.
     *
     * Mirrors the slug/name extraction in {@see self::createTaxonomyTermFromMap()}
     * so queued terms produce the identical slug and title, then enqueues via
     * {@see self::queueTaxonomyTerm()}. Missing keys are skipped (callers guard
     * membership before calling), matching the defensive behavior of the
     * immediate path.
     *
     * @param  string  $termKey  The term key (slug or ID) to look up in the term map
     * @param  array<int|string, mixed>  $termMap  Map of term keys to term data
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @param  string  $site  The Statamic site handle
     * @param  string  $nameField  The field name to use for term name extraction (default: 'term_name')
     */
    public static function queueTaxonomyTermFromMap(
        string $termKey,
        array $termMap,
        string $statamicTaxonomyHandle,
        string $site,
        string $nameField = 'term_name'
    ): void {
        // Skip silently when the key is absent; on-demand callers already verify
        // membership and the immediate path only logged this as a defensive guard.
        if (! isset($termMap[$termKey])) {
            return;
        }

        // Extract the full hierarchical slug from the term map (same precedence
        // as createTaxonomyTermFromMap()).
        $termMapEntry = $termMap[$termKey] ?? null;
        if (! \is_array($termMapEntry)) {
            return;
        }

        $fullSlug = isset($termMapEntry['full_slug']) && \is_string($termMapEntry['full_slug'])
            ? $termMapEntry['full_slug']
            : (
                isset($termMapEntry['term_slug']) && \is_string($termMapEntry['term_slug'])
                    ? $termMapEntry['term_slug']
                    : $termKey
            );

        // Extract the term name; leave empty when absent so the batch pass derives
        // it from the slug, matching the immediate path's fallback.
        $termName = isset($termMapEntry[$nameField]) && \is_string($termMapEntry[$nameField])
            ? $termMapEntry[$nameField]
            : (
                isset($termMapEntry['name']) && \is_string($termMapEntry['name'])
                    ? $termMapEntry['name']
                    : ''
            );

        self::queueTaxonomyTerm(
            $statamicTaxonomyHandle,
            $fullSlug,
            $site,
            $termName
        );
    }

    /**
     * Create every queued taxonomy term for a site, one batched pass per taxonomy.
     *
     * Drains {@see self::$deferredTermQueue} for the given site and routes each
     * taxonomy's terms through {@see self::createTaxonomyTermsFromStoredData()},
     * which preloads existing terms once and saves with Stache index updates
     * paused. Respects this creator's configured taxonomy overwrite behavior, so
     * deferring does not change create/skip/overwrite semantics. Called by the
     * orchestrator after the content loop and before taxonomy validation.
     *
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function flushDeferredTaxonomyTerms(
        string $site,
        OutputInterface $output
    ): void {
        foreach (\array_keys(self::$deferredTermQueue) as $statamicTaxonomyHandle) {
            $queued = self::$deferredTermQueue[$statamicTaxonomyHandle][$site] ?? [];

            // Nothing queued for this taxonomy/site combination.
            if ($queued === []) {
                continue;
            }

            // Create the whole taxonomy's queued terms in a single batched pass.
            $this->createTaxonomyTermsFromStoredData(
                \array_values($queued),
                $statamicTaxonomyHandle,
                $site,
                $output
            );

            // Drop the drained entries so a later flush (another content type in
            // the same run) does not reprocess them.
            unset(self::$deferredTermQueue[$statamicTaxonomyHandle][$site]);
        }
    }

    /**
     * Clear the deferred taxonomy term queue (import lifecycle reset / tests).
     */
    public static function clearDeferredTermQueue(): void
    {
        self::$deferredTermQueue = [];
    }

    /**
     * Gets site name from cache or Statamic facade.
     *
     * @param  string  $site  The site handle
     * @return string The site name
     */
    private static function getCachedSiteName(
        string $site
    ): string {
        if (! isset(self::$siteNamesCache[$site])) {
            $siteModel = SiteFacade::get($site);
            $siteName = \is_object($siteModel) && \method_exists($siteModel, 'name')
                ? $siteModel->name()
                : null;
            self::$siteNamesCache[$site] = \is_string($siteName) && $siteName !== ''
                ? $siteName
                : \ucwords(
                    \str_replace(
                        '_',
                        ' ',
                        $site
                    )
                );
        }

        return self::$siteNamesCache[$site];
    }

    /**
     * Adds a term to the taxonomy group with deduplication.
     *
     * Checks if the term ID has already been seen and skips adding if it's a
     * duplicate. Initializes the taxonomy array if it doesn't exist yet.
     *
     * @param  array<int|string, mixed>  $termsByTaxonomy  Reference to the terms by taxonomy array
     * @param  array<int|string, mixed>  $seenTermIds  Reference to the seen term IDs tracking array
     * @param  string  $taxonomy  The taxonomy type (e.g., 'category', 'post_tag')
     * @param  SimpleXMLElement  $term  The term element to add
     */
    private function addTermToTaxonomyGroup(
        array &$termsByTaxonomy,
        array &$seenTermIds,
        string $taxonomy,
        SimpleXMLElement $term
    ): void {
        // Extract the term ID to check for duplicates
        $termId = XpathHelper::getStringValue(
            $term,
            'wp:term_id'
        );

        // Check if this term ID has already been processed
        if (
            $termId !== ''
            && isset($seenTermIds[$termId])
        ) {
            // Skip adding this duplicate term
            return;
        }

        // Check if term ID exists before tracking it
        if ($termId !== '') {
            // Mark this term ID as seen to prevent future duplicates
            $seenTermIds[$termId] = true;
        }

        $termsByTaxonomy[$taxonomy] ??= [];

        // Add the term to the appropriate taxonomy group
        if (! \is_array($termsByTaxonomy[$taxonomy])) {
            $termsByTaxonomy[$taxonomy] = [];
        }

        $termsByTaxonomy[$taxonomy][] = $term;
    }

    /**
     * Create a typed entry query builder.
     */
    private function newEntryQuery(): EntryQueryBuilder
    {
        return EntryQueryHelper::query();
    }

    /**
     * Read a string field from a term's default locale.
     *
     * @param  Term  $term  The term to read from
     * @param  string  $field  The field handle to read
     * @return string The field value, or an empty string when unavailable
     */
    private static function getTermStringValue(
        Term $term,
        string $field
    ): string {
        // Resolve the default-locale term representation.
        $localizedTerm = $term->inDefaultLocale();

        // Return an empty string when the localized term cannot be read.
        if (
            ! \is_object($localizedTerm)
            || ! \method_exists(
                $localizedTerm,
                'get'
            )
        ) {
            return '';
        }

        // Read the requested field value.
        $value = $localizedTerm->get($field);

        return \is_string($value)
            ? $value
            : '';
    }

    /**
     * Extract an object identifier from term-like values.
     *
     * @param  mixed  $candidate  Candidate object with an id() accessor
     * @return string|null The string ID, or null when unavailable
     */
    private static function extractObjectId(mixed $candidate): ?string
    {
        if (! \is_object($candidate)) {
            return null;
        }

        if (
            \is_callable(
                [
                    $candidate,
                    'id',
                ]
            )
        ) {
            $id = \call_user_func(
                [
                    $candidate,
                    'id',
                ]
            );

            return \is_string($id) && $id !== ''
                ? $id
                : null;
        }

        if (! \method_exists($candidate, 'id')) {
            return null;
        }

        $id = $candidate->id();

        return \is_string($id) && $id !== ''
            ? $id
            : null;
    }

    /**
     * Clears the taxonomy term caches for a new import session.
     *
     * The name undersells it: the two *entry-id* resolvers reset here — the one
     * that maps a category slug to a `post_categories` entry, and the one that
     * maps a WordPress post id to a `posts` entry — are not taxonomy caches, but
     * they have exactly this lifetime, and this is the one call every caller
     * already makes at an import boundary.
     *
     * {@see TermDestinationRegistry} and {@see StructuredTermImporter} are reset
     * with them, and must be. All three hold state the term pass produces, and
     * `$processedTaxonomyImports` records that the pass already ran. Clearing one
     * without the others leaves the pass free to run again while the previous
     * session's output survives: a consumer resolving a term id gets the earlier
     * session's entry id, and `saveTree()` publishes a collection tree still
     * carrying the earlier session's nodes. Inert in production, where one command
     * run imports one export and the process exits — live in the host-app suite,
     * where many imports share a process (wordpress-importer-archive#422).
     */
    public static function clearTaxonomyCaches(): void
    {
        self::$siteNamesCache = [];
        self::$processedTermsCache = [];
        self::$processedTaxonomyImports = [];
        self::$reportedUnmappedTaxonomies = [];

        TermDestinationRegistry::clear();
        StructuredTermImporter::reset();
        PostCategoryEntryResolver::clear();
        NewsArticleResolver::clearCache();
    }

    /**
     * Report taxonomies the export carries that have no Statamic destination.
     *
     * Reached two ways, which are mutually exclusive for a given handle: the
     * handle filter counts terms whose taxonomy no caller listed, and the
     * per-taxonomy loop catches one a caller did list. Both funnel here so the
     * wording and the once-per-site guard live in one place.
     *
     * @param  array<string, int>  $unmappedTermCounts  Discarded term counts keyed by WordPress taxonomy handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    protected function reportUnmappedTaxonomies(
        array $unmappedTermCounts,
        string $site,
        OutputInterface $output
    ): void {
        foreach ($unmappedTermCounts as $taxonomy => $discardedTermCount) {
            // The key is unique to its site and taxonomy whichever way round it is joined
            // @pest-mutate-ignore: ConcatSwitchSides
            $reportKey = $site.'::'.$taxonomy;

            // Every creator sees the same export; report each discard once.
            if (isset(self::$reportedUnmappedTaxonomies[$reportKey])) {
                continue;
            }

            self::$reportedUnmappedTaxonomies[$reportKey] = $reportKey;

            $output->writeln(
                "  - <comment>Skipping '{$taxonomy}': recorded as having no "
                ."Statamic destination. {$discardedTermCount} term(s) not imported.</comment>"
            );

            ImportWarningManager::addWarning(
                'content',
                'taxonomy_deliberately_unmapped',
                [
                    'taxonomy' => $taxonomy,
                    'term_count' => $discardedTermCount,
                    'site' => $site,
                ]
            );
        }
    }

    /**
     * Add page data for tree building.
     *
     * Stores metadata for an entry, including its Statamic entry ID, parent post
     * ID, menu order, title, and optional link URL, used for constructing
     * collection trees and front-page detection during import.
     *
     * @param  string  $postId  The WordPress post ID
     * @param  string  $id  The Statamic entry ID
     * @param  string  $parentId  The parent post ID
     * @param  int  $menuOrder  The menu order
     * @param  string  $title  The entry title
     * @param  ?string  $link  The page's <link> URL (optional, for front-page detection)
     * @param  ?Entry  $entry  The entry object (optional, for avoiding re-queries)
     */
    protected function addPageData(
        string $postId,
        string $id,
        string $parentId,
        int $menuOrder,
        string $title,
        ?string $link = null,
        ?Entry $entry = null
    ): void {
        // ---
        // Use the actual entry ID from the entry object if available, otherwise fall back
        // to the provided ID. This ensures we use the correct ID for existing entries
        // that may have different random suffixes than the generated ID.
        // ---
        $actualEntryId = $entry instanceof Entry ? $entry->id() : $id;

        $this->pageData[$postId] = [
            'entry' => $actualEntryId,
            'parent' => $parentId,
            'menu_order' => $menuOrder,
            'title' => $title,
        ];
        if ($link !== null) {
            $this->pageData[$postId]['link'] = $link;
        }

        if ($entry instanceof Entry) {
            $this->pageData[$postId]['entry_object'] = $entry;
        }
    }

    /**
     * Check if content is empty or the default empty message.
     *
     * @param  array<int|string, mixed>  $content  The parsed content
     * @return bool True if empty or default message, false otherwise
     */
    protected function isEmptyContent(
        array $content
    ): bool {
        return $content === [] || $content === [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Imported from WordPress XML. Content empty.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get the collected page data.
     *
     * @return array<int|string, mixed> The page data array
     */
    public function getPageData(): array
    {
        return $this->pageData;
    }
}
