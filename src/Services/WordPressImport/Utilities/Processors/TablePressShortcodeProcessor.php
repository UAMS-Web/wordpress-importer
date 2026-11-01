<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\DefaultTiptapHtmlTrait;

/**
 * Processes WordPress [table id=X /] shortcodes for Statamic Bard sets.
 *
 * Converts TablePress shortcodes into table Bard sets, extracting the id
 * attribute and ensuring it matches the data type of _tablepress_export_table_id.
 * Stores all id occurrences in order for later table entry linking, allowing
 * duplicates to reflect the content structure. Provides methods to manage a
 * static cache of table IDs for import triggering, used by both shortcode and
 * block processors.
 */
class TablePressShortcodeProcessor implements ShortcodeProcessorInterface
{
    /**
     * Provides default implementation of toTiptapHtml method.
     *
     * This trait handles the standard conversion of shortcode data to
     * Tiptap-compatible HTML, including content processing and HTML
     * structure generation.
     */
    use DefaultTiptapHtmlTrait;

    /**
     * Cache of extracted table IDs for import triggering and linking.
     *
     * Stores all table IDs in order of appearance, including duplicates, to
     * ensure correct linking to table sets.
     *
     * @var string[]
     */
    protected static $tableIds = [];

    /**
     * Cache of table IDs per page for linking.
     *
     * Stores table IDs grouped by page ID to ensure correct linking
     * of table sets to their respective pages.
     *
     * @var array<string, string[]>
     */
    protected static $tableIdsByPage = [];

    /**
     * Parses shortcode attributes into a Bard set structure.
     *
     * Extracts the id attribute, converts it to a string for consistency with
     * _tablepress_export_table_id, and generates a table Bard set with initial
     * field values for Tiptap parsing. Stores the id in a static cache for table
     * import triggering and linking. Logs warnings for missing id attributes and
     * minimal info for extracted IDs to reduce verbosity. Page ID is accessed
     * through ProcessingContext.
     *
     * @param  array<int|string, mixed>  $attributes  Shortcode attributes (e.g., ['id' => '1'])
     * @param  string  $site  Site handle for entry context
     * @param  AssetImporter  $assetImporter  Asset importer (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Taxonomy terms (unused)
     * @param  ?string  $pageSlug  Page slug for logging
     * @return array<int|string, mixed> Bard set structure for table set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        // Extract and convert id to string
        $tableId = self::tableIdFromAttributes($attributes);
        if ($tableId === null || $tableId === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                ]
            );

            // Log warning about missing id attribute
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "TablePressShortcodeProcessor::parse: Missing 'id' attribute in [table] shortcode",
                    $logData['page_url']
                ),
                $logData['context']
            );
            $tableId = '';
        }

        // Store table ID for import and linking
        static::addTableId(
            $tableId,
            $site,
            $pageSlug,
            ProcessingContext::getWordPressPostId()
        );

        // Generate Bard set with complete values
        $values = [
            'type' => 'table',
            'create_query' => 'collection',
            'table_entry' => null, // Populated later
            'heading_query' => false, // Set after import
            'description_query' => false, // Set after import
            'heading_level' => 'h2', // Default, adjusted later
            'align' => 'aligncenter', // Default
        ];

        return [
            'type' => 'set',
            'attrs' => [
                'id' => (new RowId)->generate(),
                'values' => $values,
            ],
        ];
    }

    /**
     * Adds a table ID to the cache for import triggering and linking.
     *
     * Appends the provided table ID to the static cache, allowing duplicates to
     * preserve the order of table references in the content. Also tracks table
     * IDs per page for proper linking. Prevents duplicate tracking when the same
     * table ID is added multiple times for the same page (e.g., when shortcodes
     * are processed both by TextProcessor and ShortcodeProcessor). No logging is
     * performed to minimize verbosity.
     *
     * @param  ?string  $tableId  The table ID to add
     * @param  ?string  $site  Site handle for context (optional)
     * @param  ?string  $pageSlug  Page slug for context (optional)
     * @param  ?string  $pageId  Page ID for per-page tracking (optional)
     */
    public static function addTableId(
        ?string $tableId,
        ?string $site = null,
        ?string $pageSlug = null,
        ?string $pageId = null
    ): void {
        // Only process non-null and non-empty table IDs
        if (
            $tableId !== null
            && $tableId !== ''
        ) {
            // Track table ID per page if page ID is provided
            if ($pageId !== null) {
                static::$tableIdsByPage[$pageId] ??= [];

                // ---
                // Check if this table ID was already added for this page (prevents duplicate
                // tracking when shortcodes are processed multiple times, e.g., by TextProcessor
                // and ShortcodeProcessor)
                // ---
                $alreadyTracked = \in_array(
                    $tableId,
                    static::$tableIdsByPage[$pageId],
                    true
                );

                // ---
                // Only add to page-specific array and global cache if not already tracked for
                // this page. This prevents duplicate entries when the same shortcode is
                // processed multiple times (e.g., in wp:group blocks).
                // ---
                if (! $alreadyTracked) {
                    // Add table ID to the specific page's array
                    static::$tableIdsByPage[$pageId][] = $tableId;

                    // Add table ID to the global cache for import triggering
                    static::$tableIds[] = $tableId;
                }
            } else {
                // ---
                // Otherwise, add to global cache if no page ID provided (for backward
                // compatibility when page ID is not available)
                // ---

                // Add table ID to global cache for import triggering
                static::$tableIds[] = $tableId;
            }
        }
    }

    /**
     * Retrieves cached table IDs.
     *
     * Provides the list of table IDs extracted from shortcodes or blocks in order
     * of appearance for linking and import triggering.
     *
     * @return string[] Array of table IDs
     */
    public static function getTableIds(): array
    {
        return static::$tableIds;
    }

    /**
     * Retrieves cached table IDs for a specific page.
     *
     * Provides the list of table IDs extracted from shortcodes or blocks for
     * a specific page in order of appearance for linking.
     *
     * @param  string  $pageId  The page ID to get table IDs for
     * @return string[] Array of table IDs for the page
     */
    public static function getTableIdsForPage(
        string $pageId
    ): array {
        return static::$tableIdsByPage[$pageId] ?? [];
    }

    /**
     * Clears cached table IDs.
     *
     * Resets the cache of table IDs to prevent reuse across import cycles. No
     * logging is performed to minimize verbosity.
     */
    public static function clearTableIds(): void
    {
        // Clear global table IDs cache
        static::$tableIds = [];

        // Clear per-page table IDs cache
        static::$tableIdsByPage = [];
    }

    /**
     * Build a page builder block for a table shortcode.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Asset importer (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Taxonomy terms (unused)
     * @param  ?string  $pageSlug  Page slug for logging
     * @return array<int|string, mixed> The page builder block structure
     */
    public function toPageBuilderBlock(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        $set = $this->parse(
            $attributes,
            $site,
            $assetImporter,
            $output,
            $terms,
            $pageSlug
        );

        $attrs = $set['attrs'] ?? null;
        $setValues = is_array($attrs) ? ($attrs['values'] ?? null) : null;
        $values = is_array($setValues) ? $setValues : [];

        $tableId = self::tableIdFromAttributes($attributes);
        $align = $values['align'] ?? 'aligncenter';

        return [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_table',
            'enabled' => true,
            'table_id' => $tableId,
            'align' => is_string($align) ? $align : 'aligncenter',
        ];
    }

    /**
     * Extract a TablePress table ID from shortcode attributes.
     *
     * @param  array<int|string, mixed>  $attributes  Shortcode attributes
     * @return string|null The table ID, or null when unavailable
     */
    private static function tableIdFromAttributes(
        array $attributes
    ): ?string {
        if (! isset($attributes['id'])) {
            return null;
        }

        $id = $attributes['id'];

        if (is_string($id)) {
            return $id;
        }

        if (is_int($id)) {
            return (string) $id;
        }

        return null;
    }
}
