<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder;

use DOMNode;
use DOMNodeList;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\KbTopicsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Parses WordPress MinervaKB blocks into Statamic page_builder replicator sets.
 *
 * Handles MinervaKB blocks (wp:minervakb/topics, etc.) in mixed content scenarios
 * where WordPress pages/posts contain MinervaKB blocks. Delegates parsing to
 * specialized block parsers and supports single-line and multi-line JSON attributes
 * in XML comments. Returns parsed sets for PageBuilderParser to order.
 */
class MinervaKBBlockParser
{
    /**
     * Instance of KbTopicsParser for parsing wp:minervakb/topics blocks.
     */
    private KbTopicsParser $kbTopicsParser;

    /**
     * Constructs a new MinervaKBBlockParser instance with its dependencies.
     *
     * Initializes parsers for each supported MinervaKB block type. Optionally
     * accepts custom instances for dependency injection, defaulting to new
     * instances.
     *
     * @param  ?KbTopicsParser  $kbTopicsParser  Optional parser for wp:minervakb/topics blocks
     */
    public function __construct(
        ?KbTopicsParser $kbTopicsParser = null
    ) {
        $this->kbTopicsParser = $kbTopicsParser ?? new KbTopicsParser;
    }

    /**
     * Parses a DOM node into page_builder-compatible MinervaKB block content.
     *
     * Processes XML comment nodes containing MinervaKB block definitions
     * (e.g., wp:minervakb/topics), delegating to the appropriate block parser based
     * on the block type. Returns a result array indicating whether the node was
     * processed, how many nodes to skip, and the parsed replicator set (if any).
     * Supports both self-closing and paired MinervaKB blocks with JSON attributes.
     *
     * @param  DOMNode  $node  The current DOM node to parse (typically an XML comment)
     * @param  int  $index  The index of the current node in the node list
     * @param  DOMNodeList<DOMNode>  $nodes  The full list of nodes for context
     * @param  string  $site  The Statamic site handle for asset paths (e.g., 'student_information_system')
     * @param  AssetImporter  $assetImporter  Instance for importing images or linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from the WordPress XML
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from the WordPress XML
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array{processed: bool, skipCount: int, set: ?array<int|string, mixed>} Result with processed status, skip count, and replicator set
     */
    public function parseNode(
        DOMNode $node,
        int $index,
        DOMNodeList $nodes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        $processed = false;
        $skipCount = 0;
        $set = null;

        if ($node->nodeType === XML_COMMENT_NODE) {
            $nodeValue = $node->nodeValue;
            // A comment node's value is always a string, so the empty fallback is
            // never taken; it is there for the declared ?string type (#1314)
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $comment = is_string($nodeValue) ? \trim($nodeValue) : '';

            // Initialize attributes array
            $attributes = [];

            // Check if comment contains JSON attributes
            // Find the first opening brace after the block type
            $bracePos = \strpos(
                $comment,
                '{'
            );

            // Check if opening brace was found
            if ($bracePos !== false) {
                // Extract everything from the first brace to the end
                $jsonString = \substr(
                    $comment,
                    $bracePos
                );

                // Use TextProcessor's extractCompleteJson to properly handle nested
                // JSON objects and string literals
                $completeJson = TextProcessor::extractCompleteJson(
                    $jsonString,
                    $site,
                    'minervakb/topics',
                    $pageSlug
                );

                // Decode JSON attributes from comment; the extracted JSON is never
                // empty, since it starts at the brace found above
                $decodedAttributes = \json_decode(
                    $completeJson,
                    true
                );
                $attributes = is_array($decodedAttributes)
                    ? $decodedAttributes
                    : [];
            }

            // Check if comment is a wp:minervakb/topics block
            if (
                \preg_match(
                    '/^wp:minervakb\/topics/',
                    $comment
                ) === 1
            ) {
                // Parse wp:minervakb/topics block
                $set = $this->kbTopicsParser->parse(
                    $attributes,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments
                );

                // Mark block as processed
                $processed = true;

                // Set skip count to 1 for self-closing block
                $skipCount = 1;
            }
        }

        return ['processed' => $processed, 'skipCount' => $skipCount, 'set' => $set];
    }
}
