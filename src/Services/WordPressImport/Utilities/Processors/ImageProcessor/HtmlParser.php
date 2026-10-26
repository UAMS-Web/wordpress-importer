<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor;

use DOMElement;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Parses HTML to extract image elements for processing.
 *
 * Extracts <img> tags and their parent <figure> elements from HTML fragments,
 * storing them in the processing context for further steps. Handles cases where
 * <img> tags are wrapped in additional elements (e.g., <a>).
 */
class HtmlParser implements ImageProcessingStepInterface
{
    /**
     * Parses HTML to extract <img> tag and related elements.
     *
     * Loads HTML using TextProcessor, extracts the first <img> tag, and stores it
     * with its parent <figure> (if present) in the context. Traverses up the DOM to
     * find the nearest <figure> ancestor. Logs warnings if no <img> tag or src
     * attribute is found.
     *
     * @param  array<int|string, mixed>  $context  The context with 'html' and 'blockAttributes'
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  ?string  $pageSlug  The page slug for logging
     * @return array<int|string, mixed> The updated context with 'img', 'src', 'imgAlt', and 'figure'
     */
    public function process(
        array $context,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?string $pageSlug = null
    ): array {
        // Retrieve HTML fragment from context, defaulting to empty string
        $html = $context['html'] ?? '';

        if (! \is_string($html)) {
            return $context;
        }

        // Check if HTML is empty or only whitespace
        if (\trim($html) === '') {
            // Return unchanged context to skip processing
            return $context;
        }

        // Load HTML into DOMDocument using TextProcessor for consistent parsing
        $doc = TextProcessor::loadHtml($html);
        // Find all <img> tags in the document
        $images = $doc->getElementsByTagName('img');

        // Validate presence of at least one <img> tag
        if ($images->length === 0) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'html_snippet' => \substr($html, 0, 100).(\strlen($html) > 100 ? '...' : ''),
                ]
            );

            // Log warning if no <img> tag is found, including HTML snippet
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'HtmlParser::process: No <img> tag found in HTML',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return unchanged context to skip further processing
            return $context;
        }

        // Select the first <img> tag as a DOMElement
        /** @var DOMElement $img */
        $img = $images->item(0);
        // Extract the src attribute (image URL)
        $src = $img->getAttribute('src');
        // Extract and trim the alt attribute for accessibility text
        $imgAlt = \trim($img->getAttribute('alt'));

        // Extract attachment ID from class attribute (e.g., 'wp-image-8944')
        $attachmentId = null;
        if ($img->hasAttribute('class')) {
            $classAttr = $img->getAttribute('class');
            if (
                \preg_match(
                    '/wp-image-(\d+)/',
                    $classAttr,
                    $matches
                ) === 1
            ) {
                $attachmentId = $matches[1];
            }
        }

        // Validate presence of src attribute
        if ($src === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'html_snippet' => \substr($html, 0, 100).(\strlen($html) > 100 ? '...' : ''),
                ]
            );

            // Log warning if <img> lacks src attribute, including HTML snippet
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'HtmlParser::process: <img> tag has no src attribute',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return unchanged context to skip further processing
            return $context;
        }

        // Initialize figure variable for potential <figure> ancestor
        $figure = null;
        // Start traversal from the <img> tag's parent node
        $currentNode = $img->parentNode;
        // Traverse up the DOM until <body> or <figure> is found
        while ($currentNode instanceof DOMElement && $currentNode->tagName !== 'body') {
            // Check if current node is a <figure> element
            if ($currentNode->tagName === 'figure') {
                // Store <figure> node and exit loop
                $figure = $currentNode;
                break;
            }

            // Move to the next parent node
            $currentNode = $currentNode->parentNode;
        }

        // Update context with extracted image elements and attributes
        $context['img'] = $img; // Store the <img> DOMElement
        $context['src'] = $src; // Store the image URL
        $context['imgAlt'] = $imgAlt; // Store the alt text
        $context['figure'] = $figure; // Store the <figure> DOMElement or null

        // Add attachment ID to blockAttributes if found
        if ($attachmentId !== null) {
            $blockAttributes = $context['blockAttributes'] ?? [];

            if (! \is_array($blockAttributes)) {
                $blockAttributes = [];
            }

            $blockAttributes['id'] = $attachmentId;
            $context['blockAttributes'] = $blockAttributes;
        }

        // Return updated context for further processing in the pipeline
        return $context;
    }
}
