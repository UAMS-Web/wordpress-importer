<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * Parses WordPress ACF action-bar blocks into Statamic uamsweb_block_action_bar
 * replicator sets.
 *
 * Converts the ACF action-bar block attributes into a Statamic replicator set
 * compatible with the page_builder field, including dynamic sections in the items
 * array.
 */
class ActionBarParser
{
    /**
     * Parse a WordPress ACF action-bar block into a Statamic replicator set.
     *
     * Maps the block attributes (heading, background color, sections) to the
     * uamsweb_block_action_bar set.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/action-bar comment
     * @param  string  $site  The site handle for asset subfolder naming
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output interface for logging
     * @return array<int|string, mixed> The Statamic replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): array {
        $rawData = $attributes['data'] ?? [];
        $data = is_array($rawData) ? $rawData : [];

        // Map top-level fields
        $blockHeading = HtmlTagStripper::strip(
            $this->getStringValue(
                $data,
                'action_bar_heading',
                'Important Links'
            )
        );
        $backgroundColor = $this->getStringValue(
            $data,
            'action_bar_background_color',
            'bg-auto'
        );

        // Map dynamic sections into items
        $items = [];
        $sectionCount = $this->getIntValue(
            $data,
            'action_bar_section'
        );
        for ($i = 0; $i < $sectionCount; $i++) {
            $sectionPrefix = "action_bar_section_{$i}_action_bar_section_";
            $buttonUrl = $this->getMixedValue(
                $data,
                "{$sectionPrefix}button_url",
                ''
            );
            $originalUrl = LinkHelper::getUrl($buttonUrl);

            // Process URL through LinkProcessor to handle internal links and file imports
            $processedUrl = LinkProcessor::processUrlString(
                $originalUrl,
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );

            $items[] = [
                'id' => (new RowId)->generate(),
                'heading' => HtmlTagStripper::strip(
                    $this->getStringValue(
                        $data,
                        "{$sectionPrefix}heading"
                    )
                ),
                'body' => HtmlTagStripper::strip(
                    $this->getStringValue(
                        $data,
                        "{$sectionPrefix}body"
                    )
                ),
                'button_text' => HtmlTagStripper::strip(
                    $this->getStringValue(
                        $data,
                        "{$sectionPrefix}button_text"
                    )
                ),
                'button_url' => $processedUrl,
                'button_target' => LinkHelper::getTarget($buttonUrl),
                'button_link_description' => $this->getStringValue(
                    $data,
                    "{$sectionPrefix}button_description"
                ),
                'type' => 'section',
                'enabled' => true,
            ];
        }

        return [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_action_bar',
            'block_heading' => $blockHeading,
            'hide_block_heading' => true,
            'background_color' => $backgroundColor,
            'items' => $items,
            'enabled' => true,
        ];
    }

    /**
     * Parses WordPress modules template action_bar data into Statamic page_builder
     * format.
     *
     * This method handles the conversion of modules template data (from
     * uamswp_modules) to Statamic page_builder format, as opposed to the parse()
     * method which handles ACF block data. The modules template uses a different data
     * structure with prefixed field names.
     *
     * @param  array<int|string, mixed>  $moduleData  The module data from uamswp_modules
     * @param  string  $site  The site handle for asset processing
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $attachments  Array of attachment data for image processing
     * @param  ?XmlStreamParser  $xmlParser  The XML stream parser for additional data access
     * @param  array<int|string, mixed>  $terms  Array of taxonomy terms (unused in this parser)
     * @return array<int|string, mixed>|null The page_builder block or null if parsing failed
     */
    public function parseModule(
        array $moduleData,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?XmlStreamParser $xmlParser = null,
        array $terms = []
    ): ?array {
        // Map basic fields from modules template format
        $blockHeading = HtmlTagStripper::strip(
            $this->getStringValue(
                $moduleData,
                'action_bar_heading',
                'Important Links'
            )
        );
        $backgroundColor = $this->getStringValue(
            $moduleData,
            'action_bar_background_color',
            'bg-auto'
        );

        // Map dynamic sections into items
        $items = [];
        $sectionCount = $this->getIntValue(
            $moduleData,
            'action_bar_section'
        );
        for ($i = 0; $i < $sectionCount; $i++) {
            $sectionPrefix = "action_bar_section_{$i}_action_bar_section_";
            $buttonUrl = $this->getMixedValue(
                $moduleData,
                "{$sectionPrefix}button_url",
                ''
            );
            $originalUrl = LinkHelper::getUrl($buttonUrl);

            // Process URL through LinkProcessor to handle internal links and file imports
            $processedUrl = LinkProcessor::processUrlString(
                $originalUrl,
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );

            $items[] = [
                'id' => (new RowId)->generate(),
                'heading' => HtmlTagStripper::strip(
                    $this->getStringValue(
                        $moduleData,
                        "{$sectionPrefix}heading"
                    )
                ),
                'body' => HtmlTagStripper::strip(
                    $this->getStringValue(
                        $moduleData,
                        "{$sectionPrefix}body"
                    )
                ),
                'button_text' => HtmlTagStripper::strip(
                    $this->getStringValue(
                        $moduleData,
                        "{$sectionPrefix}button_text"
                    )
                ),
                'button_url' => $processedUrl,
                'button_target' => LinkHelper::getTarget($buttonUrl),
                'button_link_description' => $this->getStringValue(
                    $moduleData,
                    "{$sectionPrefix}button_description"
                ),
                'type' => 'section',
                'enabled' => true,
            ];
        }

        return [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_action_bar',
            'block_heading' => $blockHeading,
            'hide_block_heading' => true,
            'background_color' => $backgroundColor,
            'items' => $items,
            'enabled' => true,
        ];
    }

    /**
     * Gets a mixed value from module or block data.
     *
     * @param  array<int|string, mixed>  $data  The source data array
     * @param  string  $key  The key to read
     * @param  mixed  $default  The default value when key does not exist
     * @return mixed The raw value from the source data
     */
    private function getMixedValue(
        array $data,
        string $key,
        mixed $default = null
    ): mixed {
        if (! array_key_exists($key, $data)) {
            return $default;
        }

        return $data[$key];
    }

    /**
     * Gets a string value from module or block data.
     *
     * @param  array<int|string, mixed>  $data  The source data array
     * @param  string  $key  The key to read
     * @param  string  $default  The default value when key is missing or invalid
     * @return string The normalized string value
     */
    private function getStringValue(
        array $data,
        string $key,
        string $default = ''
    ): string {
        $value = $this->getMixedValue(
            $data,
            $key,
            $default
        );
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Gets an integer value from module or block data.
     *
     * @param  array<int|string, mixed>  $data  The source data array
     * @param  string  $key  The key to read
     * @param  int  $default  The default value when key is missing or invalid
     * @return int The normalized integer value
     */
    private function getIntValue(
        array $data,
        string $key,
        int $default = 0
    ): int {
        $value = $this->getMixedValue(
            $data,
            $key,
            $default
        );
        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value)
            && is_numeric($value)
        ) {
            return (int) $value;
        }

        return $default;
    }
}
