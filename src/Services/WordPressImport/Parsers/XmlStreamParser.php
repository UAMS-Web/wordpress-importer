<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers;

use Exception;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WxrSanitizedStream;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;
use XMLReader;

/**
 * Streams WordPress <item> nodes of a given post_type using XMLReader.
 * Yields each item as a SimpleXMLElement, minimizing memory usage.
 *
 * Accepts either a single file path or the ordered parts of a split export. The
 * <item> nodes of a split export are partitioned across the parts, so the stream
 * iterates every part in turn and concatenates their items into one logical
 * sequence. Only one part is ever open at a time, so peak memory stays bounded by
 * the largest single part rather than the combined size.
 *
 * Each part is opened through WxrSanitizedStream, which repairs the
 * XML-illegal bytes WordPress writes into `wp:postmeta` CDATA as the file
 * streams. That repair is what keeps this parser's contract honest: XMLReader
 * stops iterating at such a byte and reports nothing, so an unsanitized export
 * yields a partial item sequence that is indistinguishable from a short export.
 * The filter is itself streaming, so it does not weaken the memory bound above.
 */
class XmlStreamParser
{
    /**
     * Ordered list of part file paths this stream reads, in ascending part order.
     *
     * @var list<string>
     */
    protected array $filePaths;

    /**
     * Construct a stream over a single file or an ordered set of export parts.
     *
     * @param  string|array<array-key, mixed>  $filePaths  One path, or the ordered parts of a split export
     */
    public function __construct(
        string|array $filePaths
    ) {
        // Normalize a single path to a one-element part list
        $paths = \is_array($filePaths) ? $filePaths : [$filePaths];

        // Keep only string paths and re-index to a clean ordered list
        $this->filePaths = \array_values(
            \array_filter(
                $paths,
                \is_string(...)
            )
        );
    }

    /**
     * Get the first file path of the stream (the part carrying channel metadata).
     */
    public function getFilePath(): string
    {
        return $this->filePaths[0] ?? '';
    }

    /**
     * Get every part file path this stream reads, in ascending part order.
     *
     * @return list<string> The ordered part paths
     */
    public function getFilePaths(): array
    {
        return $this->filePaths;
    }

    /**
     * Streams <item> nodes with the given post_type across all parts, yielding
     * each as SimpleXMLElement.
     *
     * @param  string  $postType  The desired post_type (e.g., 'page', 'post', 'attachment')
     * @return \Generator<int, SimpleXMLElement, mixed, void>
     */
    public function streamItems(
        string $postType
    ): \Generator {
        // Iterate every part in order, concatenating their matching items
        foreach ($this->filePaths as $filePath) {
            yield from $this->streamItemsFromFile(
                $filePath,
                $postType
            );
        }
    }

    /**
     * Streams matching <item> nodes from a single part file.
     *
     * @param  string  $filePath  The part file to stream
     * @param  string  $postType  The desired post_type
     * @return \Generator<int, SimpleXMLElement, mixed, void>
     */
    private function streamItemsFromFile(
        string $filePath,
        string $postType
    ): \Generator {
        $reader = XMLReader::open(
            WxrSanitizedStream::wrap($filePath),
            null,
            0
        );

        if ($reader === false) {
            return;
        }

        // Initialize XML streaming parser with error handling
        try {
            // Move to the first <item>
            while ($reader->read()) {
                // Check if the current node is an item element
                if (
                    $reader->nodeType === XMLReader::ELEMENT
                    && $reader->name === 'item'
                ) {
                    // Extract the complete XML for this item element
                    $xml = $reader->readOuterXml();

                    // Check if the XML extraction failed or returned empty content
                    if (
                        (\trim($xml) === '')
                    ) {
                        // Skip this item and continue to the next one
                        continue;
                    }

                    // Check if this item contains WordPress block comments that need CDATA preservation
                    $needsCdataPreservation = TextProcessor::containsWordPressBlockComments($xml);

                    // Set LIBXML flags based on whether CDATA preservation is needed
                    $flags = $needsCdataPreservation ? 0 : LIBXML_NOCDATA;

                    // Create SimpleXMLElement with conditional CDATA handling
                    try {
                        // Parse the XML string into a SimpleXMLElement object
                        $item = new SimpleXMLElement(
                            $xml,
                            $flags
                        );

                        // Get all XML namespaces from the item
                        $namespaces = $item->getNamespaces(true);

                        // Extract the WordPress namespace for accessing WP-specific elements
                        $wp = $namespaces['wp'] ?? null;

                        // Extract post type using WordPress namespace if available, otherwise without namespace
                        $postTypeNode = ($wp !== null && $wp !== '') ? $item->children($wp)->post_type : $item->post_type;

                        // Extract status using WordPress namespace if available, otherwise without namespace
                        $statusNode = ($wp !== null && $wp !== '') ? $item->children($wp)->status : $item->status;

                        // Convert post type node to string
                        $type = (string) $postTypeNode;

                        // Convert status node to string
                        $status = (string) $statusNode;

                        // Check if this item matches the requested post type and should not be skipped
                        if (
                            $type === $postType
                            && ! XpathHelper::shouldSkipStatus($status)
                        ) {
                            // Yield the item for processing
                            yield $item;
                        }

                        // Clean up variables to free up memory
                        unset(
                            $item,
                            $xml
                        );
                    } catch (Exception $e) {
                        // Enhance log context with entry information
                        $logData = LogContextHelper::enhanceWithEntryContext(
                            [
                                'error' => $e->getMessage(),
                                'xml_length' => \strlen($xml),
                                'xml_preview' => \substr(
                                    $xml,
                                    0,
                                    200
                                ),
                            ]
                        );

                        // Log the error and skip this malformed XML item
                        Log::warning(
                            LogContextHelper::formatMessageWithEntry(
                                'XmlStreamParser: Skipping malformed XML item',
                                $logData['page_url']
                            ),
                            $logData['context']
                        );

                        // Skip this malformed item and continue processing
                        continue;
                    }
                }
            }
        } finally {
            // Close the XML reader to free resources
            $reader->close();

            // Clean up variables to free up memory
            unset(
                $reader
            );

            // Force garbage collection to reclaim memory from circular references
            gc_collect_cycles();
        }
    }

    /**
     * Streams all <item> nodes across all parts without filtering by post_type,
     * yielding each as SimpleXMLElement.
     *
     * @return \Generator<int, SimpleXMLElement, mixed, void>
     */
    public function streamAllItems(): \Generator
    {
        // Iterate every part in order, concatenating all of their items
        foreach ($this->filePaths as $filePath) {
            yield from $this->streamAllItemsFromFile($filePath);
        }
    }

    /**
     * Streams all non-skipped <item> nodes from a single part file.
     *
     * @param  string  $filePath  The part file to stream
     * @return \Generator<int, SimpleXMLElement, mixed, void>
     */
    private function streamAllItemsFromFile(
        string $filePath
    ): \Generator {
        $reader = XMLReader::open(
            WxrSanitizedStream::wrap($filePath),
            null,
            0
        );

        if ($reader === false) {
            return;
        }

        // Initialize XML streaming parser for all items with error handling
        try {
            // Move to the first <item>
            while ($reader->read()) {
                // Check if the current node is an item element
                if (
                    $reader->nodeType === XMLReader::ELEMENT
                    && $reader->name === 'item'
                ) {
                    // Extract the complete XML for this item element
                    $xml = $reader->readOuterXml();
                    if (
                        (\trim($xml) === '')
                    ) {
                        // Skip this item and continue to the next one
                        continue;
                    }

                    // Check if this item contains WordPress block comments that need CDATA preservation
                    $needsCdataPreservation = TextProcessor::containsWordPressBlockComments($xml);

                    // Set LIBXML flags based on whether CDATA preservation is needed
                    $flags = $needsCdataPreservation ? 0 : LIBXML_NOCDATA;

                    // Create SimpleXMLElement with conditional CDATA handling
                    $item = new SimpleXMLElement(
                        $xml,
                        $flags
                    );

                    // Get all XML namespaces from the item
                    $namespaces = $item->getNamespaces(true);

                    // Extract the WordPress namespace for accessing WP-specific elements
                    $wp = $namespaces['wp'] ?? null;

                    // ---
                    // Extract status using WordPress namespace if available, otherwise without
                    // namespace
                    // ---
                    $statusNode = ($wp !== null && $wp !== '') ? $item->children($wp)->status : $item->status;

                    // Convert status node to string
                    $status = (string) $statusNode;

                    // Check if the item should not be skipped before yielding
                    if (! XpathHelper::shouldSkipStatus($status)) {
                        // Yield the item for processing
                        yield $item;
                    }

                    // Clean up variables to free up memory
                    unset(
                        $item,
                        $xml
                    );
                }
            }
        } finally {
            // Close the XML reader to free resources
            $reader->close();

            // Clean up variables to free up memory
            unset(
                $reader
            );

            // Force garbage collection to reclaim memory from circular references
            gc_collect_cycles();
        }
    }
}
