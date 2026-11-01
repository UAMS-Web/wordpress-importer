<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMNode;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Processes WordPress ACF uams-gallery blocks into Statamic Bard gallery sets.
 *
 * Converts ACF uams-gallery blocks into Bard gallery sets for posts,
 * importing the specified images and creating a gallery set structure.
 */
class AcfGalleryBlockProcessor implements BlockProcessorInterface
{
    /**
     * Checks if this processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if this processor can handle the node
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

        // Check if this is an ACF gallery block comment
        $canProcess = \preg_match(
            '/^wp:acf\/uams-gallery\s+/',
            // A comment node always carries a string value, so the fallback never applies.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            \trim($node->nodeValue ?? '')
        );

        // Return whether this is an ACF gallery block
        return $canProcess === 1;
    }

    /**
     * Processes the ACF gallery block into Tiptap-compatible HTML.
     *
     * @param  DOMNode  $node  The DOM node to process
     * @param  array<int|string, mixed>  $attributes  Block attributes
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of attachment data from XML
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|null Processing result with HTML, processedNode, and skipCount
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
        // Extract typed data attributes from the block payload
        $data = $this->extractDataAttributes($attributes);

        // Extract normalized gallery image IDs from the data object
        $imageIds = $this->extractImageIds($data);

        // Check if we have any image IDs to process
        if ($imageIds === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'attributes' => $attributes,
                ]
            );

            // Log warning if no image IDs are found
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'AcfGalleryBlockProcessor::process: No image IDs found in ACF gallery block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return empty gallery set
            return [
                'html' => \sprintf(
                    '<div data-set-type="gallery"%s data-set-attr-heading="Image Gallery" data-set-attr-images=\'[]\' data-set-attr-more_query="false"></div>',
                    SetMarkupNonce::attribute()
                ),
                'processedNode' => $node,
                'skipCount' => 1,
            ];
        }

        // Import images and collect paths
        $imagePaths = [];

        // Loop through each image ID to import images
        foreach ($imageIds as $imageId) {
            // Import image with disabled upload handling using utility method
            $assetPath = ImageProcessor::importImageWithDisabledUploadHandling(
                $imageId,
                $site,
                $assetImporter,
                $output,
                $attachments,
                'gallery image',
                'AcfGalleryBlockProcessor::process'
            );

            // Check if import was successful
            if ($assetPath !== null && $assetPath !== '') {
                // Add successfully imported image path to results
                $imagePaths[] = $assetPath;
            }
        }

        // Check if we have enough valid images for a gallery
        if (\count($imagePaths) < 2) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'image_count' => \count($imagePaths),
                    'expected_minimum' => 2,
                ]
            );

            // Log warning about insufficient images
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'AcfGalleryBlockProcessor::process: Insufficient images for ACF gallery block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return empty gallery set for insufficient images
            return [
                'html' => \sprintf(
                    '<div data-set-type="gallery"%s data-set-attr-heading="Image Gallery" data-set-attr-images=\'[]\' data-set-attr-more_query="false"></div>',
                    SetMarkupNonce::attribute()
                ),
                'processedNode' => $node,
                'skipCount' => 1,
            ];
        }

        // Get gallery heading from attributes in the data object
        $galleryHeading = $this->getStringValue(
            $data,
            'gallery_heading',
            'Image Gallery'
        );

        // Extract gallery description from data
        $galleryDescription = $this->getStringValue(
            $data,
            'gallery_description'
        );

        // Extract gallery more setting from data and convert to boolean
        $galleryMore = $this->getStringValue($data, 'gallery_more') === '1';

        // Extract gallery more text from data
        $galleryMoreText = $this->getStringValue($data, 'gallery_more_text');

        // Extract gallery more button text from data
        $galleryMoreButtonText = $this->getStringValue(
            $data,
            'gallery_more_button_text'
        );

        // Extract gallery more button URL container from data
        $galleryMoreButtonUrlData = $data['gallery_more_button_url'] ?? [];

        // Normalize the button URL container to an array
        if (! \is_array($galleryMoreButtonUrlData)) {
            $galleryMoreButtonUrlData = [];
        }

        // Extract gallery more button URL from data
        $galleryMoreButtonUrl = $this->getStringValue(
            $galleryMoreButtonUrlData,
            'url'
        );

        // Extract gallery more button target from data and convert to boolean
        $galleryMoreButtonTarget = $this->getStringValue(
            $galleryMoreButtonUrlData,
            'target'
        ) === '_blank';

        // Extract gallery more button description from data
        $galleryMoreButtonDescription = $this->getStringValue(
            $data,
            'gallery_more_button_description'
        );

        // Initialize array to store description content
        $descriptionContent = [];

        // Split description by double line breaks; an empty description yields
        // one empty paragraph, which the loop skips
        $descriptionParagraphs = \preg_split(
            '/\r?\n\r?\n/',
            $galleryDescription
        );

        // Normalize paragraphs to an iterable list. preg_split() returns false
        // only for a failed pattern, which this constant one cannot be.
        // @pest-mutate-ignore: FalseToTrue
        $descriptionParagraphList = $descriptionParagraphs === false
            ? []
            : $descriptionParagraphs;

        // Loop through each paragraph
        foreach ($descriptionParagraphList as $paragraph) {
            // Check if paragraph is not empty
            if (\trim($paragraph) !== '') {
                // Create paragraph content structure
                $descriptionContent[] = [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => \trim($paragraph),
                        ],
                    ],
                ];
            }
        }

        // Create the gallery values array
        $values = [
            'heading' => $galleryHeading,
            'images' => $imagePaths,
            'more_query' => $galleryMore,
            'description' => $descriptionContent,
            'more_heading' => $galleryMoreText,
            'more_button_text' => $galleryMoreButtonText,
            'more_button_url' => $galleryMoreButtonUrl,
            'more_button_target' => $galleryMoreButtonTarget,
            'more_button_link_description' => $galleryMoreButtonDescription,
        ];

        // Build Tiptap-compatible HTML attributes
        $attrs = '';

        // Loop through values to build attributes
        foreach ($values as $key => $value) {
            // Handle array values by converting to JSON
            if (\is_array($value)) {
                // Convert array to JSON. A string that is not valid UTF-8 cannot
                // encode, and the field is then written empty so the import carries on.
                $jsonValue = \json_encode($value);

                // Build attribute with proper escaping
                $attrs .= \sprintf(
                    " data-set-attr-%s='%s'",
                    $key,
                    \htmlspecialchars(
                        $jsonValue === false ? '' : $jsonValue,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                );
            } else { // Otherwise
                $attrs .= \sprintf(
                    ' data-set-attr-%s="%s"',
                    $key,
                    \htmlspecialchars(
                        (string) $value,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                );
            }
        }

        // Create Tiptap-compatible HTML for the gallery set
        $html = \sprintf(
            '<div data-set-type="gallery"%s%s></div>',
            SetMarkupNonce::attribute(),
            $attrs
        );

        // Return the processing result with HTML, processed node, and skip count
        return [
            'html' => $html, // Tiptap-compatible HTML
            'processedNode' => $node, // The processed node
            'skipCount' => 1, // Skip the comment node
        ];
    }

    /**
     * Extract typed ACF data attributes from block attributes.
     *
     * @param  array<int|string, mixed>  $attributes  Block attributes
     * @return array<int|string, mixed> Normalized data attributes
     */
    private function extractDataAttributes(
        array $attributes
    ): array {
        // Read the nested data attribute container
        $data = $attributes['data'] ?? [];

        // Return the data container only when it is an array
        return \is_array($data) ? $data : [];
    }

    /**
     * Extract gallery image IDs as a normalized string list.
     *
     * @param  array<int|string, mixed>  $data  ACF data attributes
     * @return array<int, string> List of image IDs
     */
    private function extractImageIds(
        array $data
    ): array {
        // Read the raw gallery_images value from the ACF data payload
        $rawImageIds = $data['gallery_images'] ?? [];

        // Return normalized image IDs when gallery_images is an array
        if (\is_array($rawImageIds)) {
            // Normalize each image ID value to a non-empty string. Reindexing keeps
            // the list contract; the caller only iterates, so it cannot tell.
            // @pest-mutate-ignore: UnwrapArrayValues
            return \array_values(\array_filter(
                \array_map(function (mixed $imageId): string {
                    // Return string image IDs as-is
                    if (\is_string($imageId)) {
                        return $imageId;
                    }

                    // Convert integer image IDs to string values
                    if (\is_int($imageId)) {
                        return (string) $imageId;
                    }

                    // Return an empty placeholder for unsupported values
                    return '';
                }, $rawImageIds),
                // Keep only non-empty image IDs
                fn (string $imageId): bool => $imageId !== ''
            ));
        }

        // Parse comma-delimited image IDs when gallery_images is a string; an
        // empty string splits to one empty ID, which the filter drops
        if (\is_string($rawImageIds)) {
            // Split comma-delimited IDs and trim each element
            $splitImageIds = \array_map(trim(...), \explode(',', $rawImageIds));

            // Return only non-empty parsed IDs
            return \array_filter(
                $splitImageIds,
                // Keep only non-empty parsed IDs
                fn (string $imageId): bool => $imageId !== ''
            );
        }

        // Return an empty list when no valid image IDs exist
        return [];
    }

    /**
     * Read a string value from an ACF data array.
     *
     * @param  array<int|string, mixed>  $data  ACF data attributes
     * @param  string  $key  Data key to read
     * @param  string  $default  Default value when key is missing or invalid
     * @return string The normalized string value
     */
    private function getStringValue(
        array $data,
        string $key,
        string $default = ''
    ): string {
        // Read the raw value for the requested key
        $value = $data[$key] ?? $default;

        // Return string values directly
        if (\is_string($value)) {
            return $value;
        }

        // Convert integer values to strings for text fields
        if (\is_int($value)) {
            return (string) $value;
        }

        // Return the provided default for unsupported value types
        return $default;
    }
}
