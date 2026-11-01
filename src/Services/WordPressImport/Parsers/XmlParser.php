<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers;

use SimpleXMLElement;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\WxrSanitizedStream;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Parses WordPress XML export files to extract content types, items, and terms.
 *
 * This class is responsible for loading and interpreting WordPress eXtended RSS
 * (WXR) files, providing access to content type counts, specific item sets
 * (e.g., pages, attachments), and taxonomy terms. It uses SimpleXMLElement with
 * XPath queries to navigate the XML structure, excluding items with 'trash' or
 * 'virtual' statuses from counts and retrieval.
 *
 * Every read here goes through WxrSanitizedStream rather than opening the
 * path directly. WordPress serializes attachment metadata into `wp:postmeta`
 * CDATA, and those blobs can contain raw C0 control bytes or a literal `]]>` —
 * both illegal in XML 1.0. Left alone they break the two parse paths in
 * different and equally unhelpful ways: simplexml_load_file() returns false, and
 * XMLReader silently stops iterating at the bad byte, truncating the import to
 * whatever preceded it without raising anything a caller can detect. The filter
 * repairs those bytes as the file streams, so parsing semantics are otherwise
 * unchanged.
 */
class XmlParser implements ContentSource
{
    /**
     * The parsed SimpleXMLElement object representing the XML file.
     */
    protected ?SimpleXMLElement $xml = null;

    /**
     * The ordered part paths making up this import (one for a single-file export).
     *
     * Item-level readers iterate every part and concatenate; channel-level readers
     * read only the first part, since that metadata is duplicated across parts.
     *
     * @var list<string>
     */
    protected array $filePaths = [];

    /**
     * Array storing counts of each content type found in the XML, excluding items
     * with skipped statuses.
     *
     * Keys are content type names (e.g., 'page', 'attachment'), values are counts of
     * items with valid statuses (excluding 'trash' and 'virtual').
     *
     * @var array<string, int>
     */
    protected array $contentTypes = [];

    /**
     * Array of items to process, allowing temporary overrides for specific items.
     *
     * @var list<SimpleXMLElement>|null
     */
    protected ?array $items = null;

    /**
     * Cached terms array to avoid re-parsing the XML file multiple times.
     *
     * @var list<SimpleXMLElement>|null
     */
    protected ?array $cachedTerms = null;

    /**
     * Cached attachments array to avoid re-parsing the XML file multiple times.
     *
     * @var list<SimpleXMLElement>|null
     */
    protected ?array $cachedAttachments = null;

    /**
     * Base blog URL from the XML (for streaming parser compatibility).
     */
    protected ?string $baseBlogUrl = null;

    /**
     * Parse a WordPress XML export file and extract content type counts.
     *
     * Loads the XML file, registers 'wp' and 'content' namespaces for XPath queries,
     * and populates the $contentTypes array with counts of each post type, excluding
     * items with 'trash' or 'virtual' statuses.
     *
     * @param  string  $filePath  Absolute or relative path to the WordPress XML export file
     *
     * @throws \Exception If the XML file cannot be loaded or parsed
     */
    public function parse(
        string $filePath
    ): void {
        $this->filePaths = [$filePath];
        $xml = simplexml_load_file(
            WxrSanitizedStream::wrap($filePath),
            'SimpleXMLElement',
            LIBXML_NOCDATA
        );
        if ($xml === false) {
            throw new \Exception('Failed to parse XML file.');
        }

        $this->xml = $xml;

        // Register WordPress XML namespaces for XPath queries
        XpathHelper::registerWordPressNamespaces($this->xml);

        $this->contentTypes = [];
        foreach ($this->xml->channel->item as $item) {
            // Extract the item status from WordPress XML
            $status = XpathHelper::getStringValue(
                $item,
                'wp:status'
            );

            // Check if the item should be skipped
            if (XpathHelper::shouldSkipStatus($status)) {
                // Skip items with excluded statuses from count
                continue;
            }

            // Extract the post type from WordPress XML
            $postType = XpathHelper::getStringValue(
                $item,
                'wp:post_type'
            );

            // Increment the count for this post type
            $this->contentTypes[$postType] = ($this->contentTypes[$postType] ?? 0) + 1;
        }
    }

    /**
     * Retrieve the content types and their counts from the parsed XML.
     *
     * Returns an array formatted for display (e.g., in a table), where each element
     * is an array containing the content type and its count, excluding trashed items.
     *
     * @return list<array{0: string, 1: int}> Content type and count pairs for table display
     */
    public function getContentTypes(): array
    {
        $types = \array_keys($this->contentTypes);
        sort($types); // Sort content types alphabetically

        return \array_map(
            fn (string $type): array => [$type, $this->contentTypes[$type]],
            $types
        );
    }

    /**
     * Retrieve items of a specific content type from the parsed XML.
     *
     * Returns overridden items if set via setItems, otherwise uses streaming to
     * filter items by their 'wp:post_type' value, excluding items with 'trash' or
     * 'virtual' statuses, and returns matching SimpleXMLElement objects.
     *
     * @param  string  $contentType  The content type to retrieve (e.g., 'page', 'attachment')
     * @return list<SimpleXMLElement> Items matching the content type with valid statuses
     */
    public function getItems(
        string $contentType
    ): array {
        // Check if items override is set
        if ($this->items !== null) {
            return $this->items;
        }

        // ---
        // Use streaming parser when XML hasn't been loaded
        // ---

        // Check if XML is null and at least one part path exists
        if (! $this->xml instanceof SimpleXMLElement && $this->filePaths !== []) {
            // Create streaming parser over every part (items are partitioned across parts)
            $streamParser = new XmlStreamParser($this->filePaths);

            // Initialize items array
            $items = [];

            // Loop through streamed items
            foreach ($streamParser->streamItems($contentType) as $item) {
                // Add item to array
                $items[] = $item;
            }

            // Return collected items
            return $items;
        }

        if (! $this->xml instanceof SimpleXMLElement) {
            return [];
        }

        // Build XPath query excluding skipped statuses
        $query = XpathHelper::buildStatusExclusionXPath($contentType);

        // Execute XPath query and get nodes
        $nodes = $this->xml->xpath($query);

        // An invalid query yields false (or null) rather than a list
        if (! \is_array($nodes)) {
            return [];
        }

        // xpath() already returns a list, but its declared type is a plain
        // array, so the declared list return needs the reindex.
        // @pest-mutate-ignore: UnwrapArrayValues
        return \array_values($nodes);
    }

    /**
     * Retrieve all attachment items from the parsed XML.
     *
     * Filters items with 'wp:post_type' set to 'attachment' and excludes those with
     * 'trash' or 'virtual' statuses, for use in thumbnail or file imports. Uses
     * streaming parser to minimize memory usage when XML hasn't been fully loaded.
     *
     * @return list<SimpleXMLElement> Array of SimpleXMLElement objects representing attachments with valid statuses, or empty array if none found
     */
    public function getAttachments(): array
    {
        // Check if cached attachments are available
        if ($this->cachedAttachments !== null) {
            // Return the previously loaded attachments to avoid re-parsing
            return $this->cachedAttachments;
        }

        // Stream attachments across parts when the XML isn't loaded, or whenever a
        // multi-part set is present (so cross-part attachments resolve even when a
        // single part was loaded in-memory for an isolated sub-parse).
        if (
            $this->filePaths !== []
            && (
                ! $this->xml instanceof SimpleXMLElement
                || \count($this->filePaths) > 1
            )
        ) {
            // Create streaming parser over every part for memory-efficient processing
            $streamParser = new XmlStreamParser($this->filePaths);

            // Initialize empty array to store attachment objects
            $this->cachedAttachments = [];

            // Stream through all attachment items and collect them
            foreach ($streamParser->streamItems('attachment') as $attachment) {
                // Add each attachment to the cached array
                $this->cachedAttachments[] = $attachment;
            }

            // Return the collected attachments
            return $this->cachedAttachments;
        }

        // Check if XML is already loaded
        if ($this->xml instanceof SimpleXMLElement) {
            // Build XPath query to find all attachment items excluding skipped statuses
            $query = XpathHelper::buildStatusExclusionXPath('attachment');

            // Execute XPath query and cache the results
            $attachmentNodes = $this->xml->xpath($query);

            // An invalid query yields false (or null) rather than a list.
            // xpath() already returns a list, but its declared type is a plain
            // array, so the declared list property needs the reindex.
            // @pest-mutate-ignore: UnwrapArrayValues
            $this->cachedAttachments = \is_array($attachmentNodes) ? \array_values($attachmentNodes) : [];

            // Return the found attachments
            return $this->cachedAttachments;
        }

        // Return empty array if no XML or file path available
        return [];
    }

    /**
     * Retrieve all taxonomy term items from the parsed XML.
     *
     * Retrieves all <wp:term> elements for use in taxonomy mapping and validation.
     *
     * @return array<int|string, mixed> Array of SimpleXMLElement objects representing terms, or empty array if none found
     */
    public function getTerms(): array
    {
        // Return cached terms if available
        if ($this->cachedTerms !== null) {
            return $this->cachedTerms;
        }

        // Terms are duplicated in every part, so read the first part only
        $firstPart = $this->filePaths[0] ?? null;

        if ($firstPart === null) {
            return [];
        }

        $reader = \XMLReader::open(
            WxrSanitizedStream::wrap($firstPart),
            null,
            LIBXML_NOCDATA
        );

        if ($reader === false) {
            return [];
        }

        $terms = [];

        // Iterate through all XML elements in the file
        while ($reader->read()) {
            // Check if current element is a term element
            if (
                $reader->nodeType === \XMLReader::ELEMENT
                && $reader->localName === 'term'
            ) {
                // Check if this is a WordPress term element by namespace or content
                $isWordPressTerm = false;

                // First, check if it has the expected WordPress namespace
                if ($reader->namespaceURI === 'http://wordpress.org/export/1.2/') {
                    // This is definitely a WordPress term element
                    $isWordPressTerm = true;
                } else { // If no namespace or different namespace, check the content
                    // Get the full XML content of this element
                    $xml = $reader->readOuterXml();

                    // Check if the XML contains WordPress term elements
                    if (
                        str_contains(
                            $xml,
                            '<wp:term_id>'
                        )
                        || str_contains(
                            $xml,
                            '<wp:term_taxonomy>'
                        )
                        || str_contains(
                            $xml,
                            '<wp:term_slug>'
                        )
                    ) {
                        $isWordPressTerm = true;
                    }
                }

                // Process the term if it's confirmed to be a WordPress term
                if ($isWordPressTerm) {
                    // Get the full XML content for processing
                    $xml = $reader->readOuterXml();

                    // Convert XML string to SimpleXMLElement object
                    $term = new SimpleXMLElement(
                        $xml,
                        LIBXML_NOCDATA
                    );

                    // Add the term to our results array
                    $terms[] = $term;

                    // Clean up variables to free up memory
                    unset($xml);
                }
            }
        }

        // Release the reader, which closes it
        unset($reader);

        // Cache the terms for future calls
        $this->cachedTerms = $terms;

        return $terms;
    }

    /**
     * Retrieve all tag items from the parsed XML.
     *
     * Retrieves all <wp:tag> elements for use in tag mapping and validation.
     *
     * @return array<int|string, mixed> Array of SimpleXMLElement objects representing tags, or empty array if none found
     */
    public function getTags(): array
    {
        // Tags are duplicated in every part, so read the first part only
        $firstPart = $this->filePaths[0] ?? null;

        if ($firstPart === null) {
            return [];
        }

        $reader = \XMLReader::open(
            WxrSanitizedStream::wrap($firstPart),
            null,
            LIBXML_NOCDATA
        );

        if ($reader === false) {
            return [];
        }

        $tags = [];

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'wp:tag') {
                $xml = $reader->readOuterXml();
                $tag = new SimpleXMLElement(
                    $xml,
                    LIBXML_NOCDATA
                );
                $tags[] = $tag;

                // Clean up variables to free up memory
                unset($xml);
            }
        }

        // Release the reader, which closes it
        unset($reader);

        return $tags;
    }

    /**
     * Retrieve all category items from the parsed XML.
     *
     * Retrieves all <wp:category> elements for use in category mapping and
     * validation.
     *
     * @return array<int|string, mixed> Array of SimpleXMLElement objects representing categories, or empty array if none found
     */
    public function getCategories(): array
    {
        // Categories are duplicated in every part, so read the first part only
        $firstPart = $this->filePaths[0] ?? null;

        if ($firstPart === null) {
            return [];
        }

        $reader = \XMLReader::open(
            WxrSanitizedStream::wrap($firstPart),
            null,
            LIBXML_NOCDATA
        );

        if ($reader === false) {
            return [];
        }

        $categories = [];

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'wp:category') {
                $xml = $reader->readOuterXml();
                $category = new SimpleXMLElement(
                    $xml,
                    LIBXML_NOCDATA
                );
                $categories[] = $category;

                // Clean up variables to free up memory
                unset($xml);
            }
        }

        // Release the reader, which closes it
        unset($reader);

        return $categories;
    }

    /**
     * Get the raw SimpleXMLElement object for advanced queries or inspection.
     *
     * Provides direct access to the parsed XML structure for custom processing.
     *
     * @return SimpleXMLElement The parsed XML object
     *
     * @throws \RuntimeException If the XML has not been parsed yet
     */
    public function getXml(): SimpleXMLElement
    {
        if (! $this->xml instanceof SimpleXMLElement) {
            throw new \RuntimeException('XML has not been parsed yet.');
        }

        return $this->xml;
    }

    /**
     * Get the base blog URL from the XML channel.
     *
     * Retrieves the <wp:base_blog_url> value, which represents the site's base URL,
     * used to identify the front page during import.
     *
     * @return string The base blog URL (e.g., 'https://sis.uams.edu')
     *
     * @throws \RuntimeException If the XML has not been parsed yet
     */
    public function getBaseBlogUrl(): string
    {
        // Return stored value if available (for streaming parser)
        if ($this->baseBlogUrl !== null) {
            return $this->baseBlogUrl;
        }

        if (! $this->xml instanceof SimpleXMLElement) {
            throw new \RuntimeException('XML has not been parsed yet.');
        }

        // Extract the base blog URL from WordPress XML channel
        return XpathHelper::getStringValue(
            $this->xml->channel,
            'wp:base_blog_url'
        );
    }

    /**
     * Set specific items to process, overriding default retrieval.
     *
     * Allows temporary override of items returned by getItems for targeted
     * processing, such as importing a single table. Use null to clear the override
     * and restore access to all items via XPath queries.
     *
     * @param  array<int|string, mixed>|null  $items  Array of SimpleXMLElement objects or null to clear
     */
    public function setItems(
        ?array $items
    ): void {
        if ($items === null) {
            $this->items = null;

            return;
        }

        $normalized = [];

        foreach ($items as $item) {
            if ($item instanceof SimpleXMLElement) {
                $normalized[] = $item;
            }
        }

        $this->items = $normalized;
    }

    /**
     * Get the primary file path — the first part, which carries channel metadata.
     *
     * Returns the first part of a split export (or the sole path of a single-file
     * export). Item-level consumers that must span every part should use
     * getFilePaths() instead.
     */
    public function getFilePath(): ?string
    {
        return $this->filePaths[0] ?? null;
    }

    /**
     * Get every part path making up this import, in ascending part order.
     *
     * @return list<string> The ordered part paths (one entry for a single-file export)
     */
    public function getFilePaths(): array
    {
        return $this->filePaths;
    }

    /**
     * Set content types manually (for streaming parser compatibility).
     *
     * @param  array<int|string, mixed>  $contentTypes  Array of content types with counts
     */
    public function setContentTypes(
        array $contentTypes
    ): void {
        $normalized = [];

        foreach ($contentTypes as $type => $count) {
            if (
                ! \is_string($type)
                || ! \is_numeric($count)
            ) {
                continue;
            }

            $normalized[$type] = (int) $count;
        }

        $this->contentTypes = $normalized;
    }

    /**
     * Set a single file path manually (for streaming parser compatibility).
     *
     * @param  string  $filePath  The file path
     */
    public function setFilePath(
        string $filePath
    ): void {
        $this->filePaths = [$filePath];
    }

    /**
     * Set the ordered part paths for a split export (or a single-file import).
     *
     * Item-level readers iterate every part; channel-level readers read the first.
     *
     * @param  array<array-key, mixed>  $filePaths  Ordered part paths, ascending by part index
     */
    public function setFilePaths(
        array $filePaths
    ): void {
        // Keep only string paths and re-index to a clean ordered list
        $this->filePaths = \array_values(
            \array_filter(
                $filePaths,
                \is_string(...)
            )
        );
    }

    /**
     * Set base blog URL manually (for streaming parser compatibility).
     *
     * @param  string  $baseBlogUrl  The base blog URL
     */
    public function setBaseBlogUrl(
        string $baseBlogUrl
    ): void {
        // Store the base blog URL for later retrieval
        $this->baseBlogUrl = $baseBlogUrl;
    }
}
