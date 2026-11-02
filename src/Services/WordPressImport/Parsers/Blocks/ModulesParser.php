<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Dispatcher for parsing WordPress modules template metadata into Statamic
 * page_builder format.
 *
 * This class follows the Single Responsibility Principle by acting as a
 * coordinator that delegates the parsing of individual module types to
 * specialized block parsers. It handles the conversion of WordPress modules
 * template data (uamswp_modules) to Statamic page_builder replicator sets by
 * determining the module type and routing to the appropriate parser.
 *
 * Supported module types and their corresponding parsers:
 *   - modules_text_stacked -> StackedParser
 *   - modules_link_list -> LinkListParser
 *   - modules_action_bar -> ActionBarParser
 *   - modules_call_out -> CallOutParser
 *   - modules_cta -> CtaParser
 *   - modules_news_grid -> NewsParser
 *   - modules_text_overlay -> OverlayParser
 *   - modules_uams_gallery -> GalleryParser
 *   - modules_uams_content -> ContentParser
 *   - modules_uams_livewhale -> LiveWhaleParser
 *   - modules_uams_section -> SectionParser
 *   - modules_image_side -> SideBySideParser
 *
 * Unsupported module types (not currently implemented):
 *   - modules_hero -> HeroParser (not implemented)
 *   - modules_post_tile -> PostTileParser (not implemented)
 *   - modules_post_tiles -> PostTilesParser (not implemented)
 */
class ModulesParser
{
    /**
     * Array of supported module types mapped to their parser classes.
     *
     * @var array<string, string>
     */
    private const array SUPPORTED_MODULES = [
        'modules_text_stacked' => StackedParser::class,
        'modules_link_list' => LinkListParser::class,
        'modules_action_bar' => ActionBarParser::class,
        'modules_call_out' => CallOutParser::class,
        'modules_cta' => CtaParser::class,
        'modules_news_grid' => NewsParser::class,
        'modules_text_overlay' => OverlayParser::class,
        'modules_uams_gallery' => GalleryParser::class,
        'modules_uams_content' => ContentParser::class,
        'modules_uams_livewhale' => LiveWhaleParser::class,
        'modules_uams_section' => SectionParser::class,
        'modules_image_side' => SideBySideParser::class,
    ];

    /**
     * Array of unsupported module types that need implementation.
     *
     * @var array<string>
     */
    private const array UNSUPPORTED_MODULES = [
        'modules_hero',
        'modules_post_tile',
        'modules_post_tiles',
    ];

    /**
     * Parses modules metadata and converts it to page_builder format.
     *
     * Processes the uamswp_modules metadata array and converts each module to
     * its corresponding Statamic page_builder block format by delegating to
     * specialized block parsers.
     *
     * @param  array<int|string, mixed>  $modulesData  The parsed uamswp_modules metadata array (module order/types)
     * @param  string  $site  The site handle for asset processing
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $attachments  Array of attachment data for image processing
     * @param  ?XmlStreamParser  $xmlParser  The XML stream parser for additional data access
     * @param  array<int|string, mixed>  $postmeta  The full postmeta array for the page (all uamswp_modules_* fields)
     * @return array<int|string, mixed> The page_builder formatted content array
     */
    public function parseModules(
        array $modulesData,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?XmlStreamParser $xmlParser = null,
        array $postmeta = []
    ): array {
        // Initialize page builder array to store parsed modules
        $pageBuilder = [];

        // Loop through each module in the modules data array
        foreach ($modulesData as $moduleIndex => $moduleType) {
            // Resolve numeric module index from int or digit-string keys
            if (\is_int($moduleIndex)) {
                $index = $moduleIndex;
            } elseif (\ctype_digit($moduleIndex)) {
                $index = (int) $moduleIndex;
            } else {
                continue;
            }

            if (! \is_string($moduleType)) {
                continue;
            }

            // Extract module-specific data from postmeta using module index
            $moduleData = self::extractModuleDataFromPostmeta(
                $postmeta,
                $index
            );

            // Parse the module into a page builder block format
            $block = $this->parseModule(
                $moduleType,
                $moduleData,
                $site,
                $assetImporter,
                $output,
                $attachments,
                $xmlParser
            );

            // Check if module was parsed successfully
            if ($block !== null && $block !== []) {
                // Add parsed block to page builder array
                $pageBuilder[] = $block;
            }
        }

        // Return the complete page builder array
        return $pageBuilder;
    }

    /**
     * Extracts data for a specific module from the full postmeta array.
     *
     * Static method that can be reused by other classes to extract module data from
     * postmeta using the standard module prefix pattern.
     *
     * @param  array<int|string, mixed>  $postmeta  The full postmeta array for the page
     * @param  int  $moduleIndex  The index of the module
     * @return array<int|string, mixed> The extracted module data
     */
    public static function extractModuleDataFromPostmeta(
        array $postmeta,
        int $moduleIndex
    ): array {
        $moduleData = [];
        $prefix = "uamswp_modules_{$moduleIndex}_";

        // Extract all data for this module from postmeta
        foreach ($postmeta as $key => $value) {
            if (! \is_string($key)) {
                continue;
            }

            if (
                str_starts_with($key, $prefix)
            ) {
                $fieldName = \substr(
                    $key,
                    \strlen($prefix)
                );
                $moduleData[$fieldName] = $value;
            }
        }

        return $moduleData;
    }

    /**
     * Parses a module by delegating to the appropriate specialized parser.
     *
     * @param  string  $moduleType  The type of module (e.g., 'modules_text_stacked')
     * @param  array<int|string, mixed>  $moduleData  The module data
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  The console output
     * @param  array<int|string, mixed>  $attachments  Array of attachments
     * @param  ?XmlStreamParser  $xmlParser  The XML stream parser
     * @return array<int|string, mixed>|null The page_builder block or null if parsing failed
     */
    private function parseModule(
        string $moduleType,
        array $moduleData,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?XmlStreamParser $xmlParser = null
    ): ?array {
        // Check if module type is supported
        if (! isset(self::SUPPORTED_MODULES[$moduleType])) {
            // Loose and strict comparison agree here: the type is a string and
            // no listed type is numeric, so the strict flag cannot be observed.
            // @pest-mutate-ignore: TrueToFalse
            if (
                \in_array(
                    $moduleType,
                    self::UNSUPPORTED_MODULES,
                    true
                )
            ) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    ['module_data' => $moduleData]
                );

                // Log warning about unsupported module type
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "ModulesParser::parseModule: Module type '$moduleType' is not yet implemented",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            } else {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    ['module_data' => $moduleData]
                );

                // Log warning about unknown module type
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "ModulesParser::parseModule: Unknown module type '$moduleType'",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            return null;
        }

        // Get the parser class for this module type
        $parserClass = self::SUPPORTED_MODULES[$moduleType];

        try {
            // Create parser instance and parse the module
            $parser = new $parserClass;

            // Call the parse method with module data
            return $parser->parseModule(
                $moduleData,
                $site,
                $assetImporter,
                $output,
                $attachments,
                $xmlParser,
                [] // $terms parameter
            );
        } catch (\Exception $exception) {
            Log::error(
                "ModulesParser::parseModule: Error parsing module '$moduleType'",
                [
                    'error' => $exception->getMessage(),
                    'module_data' => $moduleData,
                ]
            );

            return null;
        }
    }
}
