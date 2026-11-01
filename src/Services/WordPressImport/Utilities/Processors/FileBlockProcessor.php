<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FileProcessor as FileProcessorUtility;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Processes WordPress wp:file blocks into Tiptap-compatible HTML for Statamic
 * Bard sets.
 */
class FileBlockProcessor implements BlockProcessorInterface
{
    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:file comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        // Check if the node is a comment node
        if ($node->nodeType !== XML_COMMENT_NODE) {
            // Return false for non-comment nodes
            return false;
        }

        // Define regex pattern to match wp:file blocks with JSON attributes
        $pattern = '/\s*wp:file\s*({.+?})\s*(?:\/)?$/s';

        $commentValue = $node->nodeValue;

        if (! \is_string($commentValue)) {
            return false;
        }

        // Check if the comment content matches the wp:file pattern
        return \preg_match(
            $pattern,
            $commentValue
        ) === 1;
    }

    /**
     * Processes a wp:file block into Tiptap-compatible HTML.
     *
     * @param  DOMNode  $node  The DOM node (wp:file comment)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:file comment
     * @param  string  $site  Site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects (unused)
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|string|null Array with 'html', 'processedNode', and 'skipCount', Tiptap HTML string, or null if invalid
     */
    public function process(
        DOMNode $node,
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): array|string|null {
        // Get file URL from attributes
        $fileUrl = $attributes['href'] ?? '';

        if (! \is_string($fileUrl)) {
            $fileUrl = \is_scalar($fileUrl) ? (string) $fileUrl : '';
        }

        // Check if file URL is available
        if ($fileUrl === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'attributes' => $attributes,
                ]
            );

            // Log warning about missing file URL
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'FileBlockProcessor::process: No file URL found in wp:file block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null if no file URL available
            return null;
        }

        // ---
        // Import file with disabled upload handling using utility method
        //
        // The block is dropped when no path comes back, and an author can point one at
        // a file on a sibling site, so a file on another site is imported. Linking it
        // instead would leave a URL that stops working when WordPress is retired. 41 of
        // the corpus's 1,341 `wp:file` blocks point off their own site (#232).
        // ---
        $importedPath = FileProcessorUtility::importFileWithDisabledUploadHandling(
            $fileUrl,
            $site,
            $assetImporter,
            $output,
            'files',
            'wp:file block',
            'FileBlockProcessor::process',
            sameSitePolicy: SameSitePolicy::ALWAYS_IMPORT
        );

        // Check if import was successful
        if ($importedPath === null || $importedPath === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'file_url' => $fileUrl,
                ]
            );

            // Log warning about failed import
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'FileBlockProcessor::process: Failed to import file for wp:file block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null if import failed
            return null;
        }

        // Extract filename from URL for title
        $title = basename($fileUrl);

        // Create file set values using imported path or original URL
        $values = [
            'file' => $importedPath, // Use imported path or original URL
            'title' => $title,
            'new_window_query' => true, // Default to new window for external URLs
            'download_button_query' => true,
        ];

        // Initialize attributes string for Tiptap HTML
        $attrs = '';

        // Loop through values to build data attributes
        foreach ($values as $key => $value) {
            // Add data attribute for this value
            $attrs .= \sprintf(
                ' data-set-attr-%s="%s"',
                $key,
                htmlspecialchars((string) $value)
            );
        }

        // Create Tiptap-compatible HTML for file set
        $html = \sprintf(
            '<div data-set-type="file"%s%s></div>',
            SetMarkupNonce::attribute(),
            $attrs
        );

        // Get the DOM document for XPath queries
        $doc = $node->ownerDocument;

        if (! $doc instanceof \DOMDocument) {
            return [
                'html' => $html,
                'processedNode' => null,
                'skipCount' => 2,
            ];
        }

        // Create XPath instance for querying DOM nodes
        $xpath = new DOMXPath($doc);

        // Find the associated div node to mark as processed
        $divNodes = $xpath->query(
            'following-sibling::div[contains(@class, "wp-block-file")][1]',
            $node
        );

        // Get the first matching div node, or null if none follows. query()
        // returns false only for a malformed expression or context node, which
        // this constant expression and in-document node cannot be.
        // @pest-mutate-ignore: FalseToTrue
        $divNode = $divNodes === false ? null : $divNodes->item(0);

        // Return processing result with HTML and node information
        return [
            'html' => $html,
            'processedNode' => $divNode,
            'skipCount' => 2, // Skip comment and div node
        ];
    }
}
