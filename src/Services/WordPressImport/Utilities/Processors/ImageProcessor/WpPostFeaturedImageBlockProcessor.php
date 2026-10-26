<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor;

use DOMComment;
use DOMNode;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Utilities\Alignment;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\BlockProcessorInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Processes WordPress wp:post-featured-image blocks for the image processing
 * pipeline.
 *
 * Handles wp:post-featured-image block comments by treating them as wp:image
 * blocks with an id attribute matching the post item's _thumbnail_id meta_key
 * value. This processor creates a virtual <figure> element with the featured
 * image and delegates processing to the ImageProcessor coordinator.
 */
class WpPostFeaturedImageBlockProcessor implements BlockProcessorInterface
{
    /**
     * Instance of ImageProcessor for processing images.
     */
    private ImageProcessor $imageProcessor;

    /**
     * Constructs a new WpPostFeaturedImageBlockProcessor instance.
     *
     * Initializes the ImageProcessor dependency, creating a new instance if none
     * is provided.
     *
     * @param  ?ImageProcessor  $imageProcessor  Optional ImageProcessor instance
     */
    public function __construct(
        ?ImageProcessor $imageProcessor = null
    ) {
        $this->imageProcessor = $imageProcessor ?? new ImageProcessor;
    }

    /**
     * Determines if the processor can handle the given DOM node.
     *
     * Checks if the node is a comment node starting with 'wp:post-featured-image',
     * indicating a WordPress post featured image block.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:post-featured-image comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        // Check if the node is a comment node
        if ($node->nodeType !== XML_COMMENT_NODE) {
            // Only process comment nodes
            return false;
        }

        // Return whether this node can be processed
        if (! ($node instanceof DOMComment)) {
            return false;
        }

        $commentValue = $node->nodeValue;

        return \preg_match(
            '/^wp:post-featured-image\s*\/?$/',
            \is_string($commentValue) ? \trim($commentValue) : ''
        ) === 1;
    }

    /**
     * Processes a wp:post-featured-image block into Tiptap-compatible HTML.
     *
     * Treats wp:post-featured-image blocks as wp:image blocks with an id
     * attribute matching the post item's _thumbnail_id meta_key value. Creates
     * a virtual <figure> element using the post's featured image data from the
     * entry data and delegates processing to ImageProcessor to create a Statamic
     * Bard image set. Returns an array with Tiptap-compatible HTML, the processed
     * node, and nodes to skip, or null if processing fails.
     *
     * @param  DOMNode  $node  The wp:post-featured-image comment node
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:post-featured-image comment
     * @param  string  $site  Site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments for alt text lookup
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|null Array with 'html', 'processedNode', 'skipCount', or null
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
    ): ?array {
        // Get the entry data from the processing context
        $entryData = ProcessingContext::getEntryData();

        // Extract attachment ID from entry data _thumbnail_id, if available
        $thumbnailId = $entryData['_thumbnail_id'] ?? null;

        // Check if thumbnail ID is available
        if (! \is_scalar($thumbnailId) || (string) $thumbnailId === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                ]
            );

            // Log warning for missing thumbnail ID
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'WpPostFeaturedImageBlockProcessor::process: No _thumbnail_id found in entry data',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate processing failure
            return null;
        }

        $attachmentId = (string) $thumbnailId;

        // Get the featured image data from attachments using thumbnail ID
        $featuredImageData = $this->getFeaturedImageDataFromAttachments(
            $attachmentId,
            $attachments
        );

        // Check if we found featured image data
        if ($featuredImageData === null || $featuredImageData === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'thumbnailId' => $attachmentId,
                ]
            );

            // Log warning for missing featured image data
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'WpPostFeaturedImageBlockProcessor::process: No featured image data found for thumbnail ID',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate processing failure
            return null;
        }

        // Extract the image source URL from the featured image data
        $imgSrcRaw = $featuredImageData['url'] ?? '';
        $imgSrc = \is_string($imgSrcRaw) ? $imgSrcRaw : '';

        // Get the base blog URL for same-site comparison
        $baseBlogUrl = $assetImporter->getBaseBlogUrl();

        // Create a flag to check if image is on the same site
        $isSameSite = AssetUtils::isUrlOnSameSite(
            $imgSrc,
            $baseBlogUrl
        );

        // Check if image is not on the same site
        if (! $isSameSite) {
            // Disregard asset ID for different-site images
            $attachmentId = null;
        }

        // Create a copy of attributes to modify for processing
        $modifiedAttributes = $attributes;

        // Check if attachment ID is available. A different-site image has already
        // had its $attachmentId cleared above, so a truthy ID implies same-site.
        if ($attachmentId !== null) {
            // Add the attachment ID as the id attribute, treating this as wp:image
            $modifiedAttributes['id'] = $attachmentId;
        }

        // Create a virtual <figure> element with the featured image
        $figureHtml = $this->createVirtualFigureElement(
            $featuredImageData,
            $modifiedAttributes
        );

        // Delegate image processing to ImageProcessor with block attributes
        $set = $this->imageProcessor->processImage(
            $figureHtml,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $pageSlug,
            $modifiedAttributes
        );

        // Check if ImageProcessor failed to generate a Bard image set
        if ($set === null || $set === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'featured_image_data' => $featuredImageData,
                ]
            );

            // Log a warning for failed image set generation
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'WpPostFeaturedImageBlockProcessor::process: ImageProcessor failed to generate image set',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate processing failure
            return null;
        }

        // Initialize empty attributes string for Tiptap HTML
        $attrs = '';

        $setAttrs = $set['attrs'] ?? null;
        $setValues = \is_array($setAttrs) ? ($setAttrs['values'] ?? null) : null;

        // Iterate through image set attributes to build Tiptap-compatible data attributes
        if (\is_array($setValues)) {
            foreach ($setValues as $key => $value) {
                // Exclude the 'type' key to avoid redundant data-set-type attribute
                if ($key !== 'type') {
                    $keyString = \is_string($key) ? $key : (string) $key;
                    $valueString = \is_scalar($value) ? (string) $value : '';

                    // Format each attribute as data-set-attr-<key> with escaped values
                    $attrs .= \sprintf(
                        ' data-set-attr-%s="%s"',
                        htmlspecialchars($keyString),
                        htmlspecialchars($valueString)
                    );
                }
            }
        }

        // Construct Tiptap-compatible HTML div for the image set
        $html = \sprintf(
            '<div data-set-type="image"%s%s></div>',
            SetMarkupNonce::attribute(),
            $attrs
        );

        // Return processing result with HTML and skip count
        return [
            'html' => $html,
            'processedNode' => $node,
            'skipCount' => 0, // Only process the comment node itself
        ];
    }

    /**
     * Gets the featured image data from attachments using thumbnail ID.
     *
     * Looks up the attachment in the attachments array using the thumbnail ID
     * and extracts the attachment URL. Returns an array with the featured image
     * information or null if not found.
     *
     * @param  string  $thumbnailId  The thumbnail ID from _thumbnail_id meta_key
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @return array<int|string, mixed>|null The featured image data with 'url' key, or null if not found
     */
    private function getFeaturedImageDataFromAttachments(
        string $thumbnailId,
        array $attachments
    ): ?array {
        // Find the attachment by ID using XpathHelper
        $attachment = XpathHelper::findAttachmentById(
            $attachments,
            $thumbnailId
        );

        // Check if attachment was found
        if (! $attachment instanceof \SimpleXMLElement) {
            // Return null if attachment not found
            return null;
        }

        // Extract attachment data using XpathHelper
        $attachmentData = XpathHelper::extractAttachmentData($attachment);

        // Check if attachment URL exists
        if ($attachmentData['url'] === null || $attachmentData['url'] === '') {
            // Return null if no attachment URL is available
            return null;
        }

        // Return the featured image data array
        return [
            'url' => $attachmentData['url'],
        ];
    }

    /**
     * Creates a virtual <figure> element with the featured image data.
     *
     * Constructs HTML for a <figure> element containing an <img> tag
     * with the featured image's URL and metadata.
     *
     * @param  array<int|string, mixed>  $attachment  The attachment data
     * @param  array<int|string, mixed>  $attributes  Block attributes for alignment and other properties
     * @return string The HTML for the virtual <figure> element
     */
    private function createVirtualFigureElement(
        array $attachment,
        array $attributes
    ): string {
        // Extract image URL from attachment data
        $imageUrlRaw = $attachment['url'] ?? '';
        $imageUrl = \is_string($imageUrlRaw) ? $imageUrlRaw : '';

        // Get alignment from attributes
        $alignmentRaw = $attributes['align'] ?? 'center';
        $alignment = \is_string($alignmentRaw) ? $alignmentRaw : 'center';

        // Map alignment to CSS class using the existing Alignment utility
        $alignmentClass = Alignment::translate(
            $alignment,
            true // This is for an image set
        );

        // Build the <img> tag (alt text is handled by the asset import process)
        $imgTag = \sprintf(
            '<img src="%s" />',
            htmlspecialchars($imageUrl)
        );

        // Set the base CSS class for the figure element
        $figureClass = 'wp-block-post-featured-image';

        // Add alignment class if one is specified
        if ($alignmentClass !== '') {
            // Append the alignment class to the figure class
            $figureClass .= " {$alignmentClass}";
        }

        // Build the opening figure tag with the image
        $figureHtml = \sprintf(
            '<figure class="%s">%s',
            htmlspecialchars($figureClass),
            $imgTag
        );

        // Close the <figure> element
        $figureHtml .= '</figure>';

        // Return the complete figure HTML
        return $figureHtml;
    }
}
