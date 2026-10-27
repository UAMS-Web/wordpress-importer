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
     * Count the <item> nodes with the given post_type across all parts.
     *
     * Counts exactly what streamItems() yields (every status but `trash` and `virtual`,
     * including items the importing loop later skips), so a loop over that stream
     * reaches the count. It reads the export once more, which is what lets an import report how
     * far through a streamed loop it is. An item that cannot be parsed is left out of
     * the count without a log line, since the stream the loop reads logs it.
     *
     * @param  string  $postType  The desired post_type (e.g., 'page', 'post', 'attachment')
     * @return int The number of matching items
     */
    public function countItems(
        string $postType
    ): int {
        $count = 0;

        // Count every part in order, without logging the items the stream skips
        foreach ($this->filePaths as $filePath) {
            $count += iterator_count($this->streamItemsFromFile(
                $filePath,
                $postType,
                false
            ));
        }

        return $count;
    }

    /**
     * Streams matching <item> nodes from a single part file.
     *
     * @param  string  $filePath  The part file to stream
     * @param  string  $postType  The desired post_type
     * @param  bool  $logSkipped  Whether to log an item that cannot be parsed
     * @return \Generator<int, SimpleXMLElement, mixed, void>
     */
    private function streamItemsFromFile(
        string $filePath,
        string $postType,
        bool $logSkipped = true
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

                    // An item the reader cannot expand comes back as an empty string
                    if ($xml === '') {
                        // Skip this item and continue to the next one. A fatal
                        // error lies within reach, so no later item of the part
                        // can be read and stopping here would end it the same way.
                        // @pest-mutate-ignore: ContinueToBreak
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

                        // Read WordPress elements from the wp namespace, or unprefixed when it is absent
                        $wpChildren = $item->children($namespaces['wp'] ?? null);

                        // Extract the post type node
                        $postTypeNode = $wpChildren->post_type;

                        // Extract the status node
                        $statusNode = $wpChildren->status;

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
                        // Skip the item silently when only counting
                        if (! $logSkipped) {
                            continue;
                        }

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
            // Release the reader, which closes it
            unset(
                $reader
            );

            // Reclaim circular references between parts; no output depends on it.
            // @pest-mutate-ignore: RemoveFunctionCall
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
                    // An item the reader cannot expand comes back as an empty string
                    if ($xml === '') {
                        // Skip this item and continue to the next one. A fatal
                        // error lies within reach, so no later item of the part
                        // can be read and stopping here would end it the same way.
                        // @pest-mutate-ignore: ContinueToBreak
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

                    // Read the status from the wp namespace, or unprefixed when it is absent
                    $statusNode = $item->children($namespaces['wp'] ?? null)->status;

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
            // Release the reader, which closes it
            unset(
                $reader
            );

            // Reclaim circular references between parts; no output depends on it.
            // @pest-mutate-ignore: RemoveFunctionCall
            gc_collect_cycles();
        }
    }
}
