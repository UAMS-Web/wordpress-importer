<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Tiptap\Marks\Bold;
use Tiptap\Marks\Code;
use Tiptap\Marks\Italic;
use Tiptap\Marks\Link;
// use Tiptap\Marks\Highlight;
use Tiptap\Marks\Strike;
use Tiptap\Marks\Subscript;
use Tiptap\Marks\Superscript;
use Tiptap\Nodes\BulletList;
use Tiptap\Nodes\Document;
// use Tiptap\Marks\TextStyle;
// use Tiptap\Marks\Underline;
// use Tiptap\Nodes\Blockquote;
use Tiptap\Nodes\HardBreak;
use Tiptap\Nodes\Heading;
// use Tiptap\Nodes\CodeBlockHighlight;
// use Tiptap\Nodes\CodeBlockShiki;
use Tiptap\Nodes\HorizontalRule;
use Tiptap\Nodes\OrderedList;
use Tiptap\Nodes\Paragraph;
use Tiptap\Nodes\Text;
// use Tiptap\Nodes\Image;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Editor;
// use Tiptap\Nodes\Mention;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\CodeBlock;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\ListItem;
// use Tiptap\Nodes\Table;
// use Tiptap\Nodes\TableCell;
// use Tiptap\Nodes\TableHeader;
// use Tiptap\Nodes\TableRow;
// use Tiptap\Nodes\TaskItem;
// use Tiptap\Nodes\TaskList;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Set;

/**
 * Utility class for parsing HTML content into Statamic Bard format using Tiptap.
 *
 * Converts raw HTML, such as WordPress WYSIWYG field content or InnerBlocks, into
 * ProseMirror JSON suitable for Statamic Bard fields. Processes inline formatting
 * (e.g., bold, italic) and block elements (e.g., paragraphs, lists, headings).
 */
class BardParser
{
    /**
     * Inline node types, which a Bard document cannot hold at its root.
     *
     * @var list<string>
     */
    private const array INLINE_NODE_TYPES = ['text', 'hardBreak'];

    /**
     * Parses HTML content into a Statamic Bard-compatible array.
     *
     * Processes HTML input using Tiptap to generate ProseMirror JSON, preserving
     * inline marks (e.g., bold, italic, links) and block nodes (e.g., paragraphs,
     * headings, lists). Cleans the input HTML by removing WordPress block comments
     * and ensuring UTF-8 encoding. Puts text at the root into paragraphs. Returns
     * an empty array for empty or whitespace-only input.
     *
     * @param  string  $html  The raw HTML content to parse
     * @return array<int|string, mixed> The Bard-compatible ProseMirror JSON array
     */
    public function parse(
        string $html
    ): array {
        // A shortcut, not a rule: Tiptap parses an empty or whitespace-only div to no content
        // as well, so this only saves building the editor.
        // @pest-mutate-ignore: UnwrapTrim, RemoveEarlyReturn
        if (\trim($html) === '') {
            return [];
        }

        // Clean HTML by removing WordPress block comments
        $html = \preg_replace(
            '/<!--\s*wp:[^\s]+(?:\s*{[^}]*})?\s*-->|<!--\s*\/wp:[^\s]+\s*-->/',
            '',
            $html
        );

        // ---
        // Note: UTF-8 normalization is already handled by TextProcessor::processForBard().
        // No need to call normalizeUtf8 again here to avoid double-processing.
        // ---

        // Initialize Tiptap Editor with necessary extensions
        $editor = new Editor([
            'extensions' => [
                // Custom
                // Nodes
                new Set,
                new CodeBlock,
                // Vanilla
                // Marks
                new Bold,
                new Code,
                // new Highlight(),
                new Italic,
                new Link,
                new Strike,
                new Subscript,
                new Superscript,
                // new TextStyle(),
                // new Underline(),
                // Nodes
                // new Blockquote(),
                new BulletList,
                // new CodeBlockHighlight(),
                // new CodeBlockShiki(),
                new Document,
                new HardBreak,
                // Tiptap's default levels are 1 to 6, every heading WordPress emits; the
                // parser test pins all six, so a change to that default is caught.
                new Heading,
                new HorizontalRule,
                // new Image(),
                new ListItem,
                // new Mention(),
                new OrderedList,
                new Paragraph,
                // new Table(),
                // new TableCell(),
                // new TableHeader(),
                // new TableRow(),
                // new TaskItem(),
                // new TaskList(),
                new Text,
            ],
        ]);

        // Wrap HTML in a div for proper parsing
        $html = "<div>{$html}</div>";

        $document = $editor->setContent($html)->getDocument();
        $content = \is_array($document) ? ($document['content'] ?? []) : [];

        return \is_array($content) ? self::wrapRootText(self::dropUntrustedSets($content)) : [];
    }

    /**
     * Puts each run of text at the root of a document into a paragraph.
     *
     * Tiptap leaves text that no block holds at the root, and a Bard document
     * cannot hold a text node there, so parse() runs this on all it returns
     * (#335). Whitespace between blocks is dropped.
     *
     * @param  array<int|string, mixed>  $content  Parsed ProseMirror content
     * @return array<int|string, mixed> Content with no text node at its root
     */
    public static function wrapRootText(
        array $content
    ): array {
        $wrapped = [];
        $run = [];

        foreach ($content as $node) {
            // Inline nodes wait for the rest of their run
            if (
                \is_array($node)
                && \in_array($node['type'] ?? null, self::INLINE_NODE_TYPES, true)
            ) {
                $run[] = $node;

                continue;
            }

            // Anything else ends the run
            self::addTextRun($run, $wrapped);
            $run = [];
            $wrapped[] = $node;
        }

        self::addTextRun($run, $wrapped);

        return $wrapped;
    }

    /**
     * Adds a run of inline nodes to content as a paragraph, without the space at
     * its edges, unless it holds nothing but whitespace.
     *
     * @param  list<array<int|string, mixed>>  $run  The run of inline nodes
     * @param  array<int|string, mixed>  $wrapped  The content to add it to
     */
    private static function addTextRun(
        array $run,
        array &$wrapped
    ): void {
        // Read the run's text, to tell words from the whitespace between blocks
        $text = \implode('', \array_map(
            static fn (array $node): string => \is_string($node['text'] ?? null) ? $node['text'] : '',
            $run
        ));

        if (\trim($text) === '') {
            return;
        }

        // The run's first and last nodes hold the space at its edges
        $last = \count($run) - 1;

        if (\is_string($run[0]['text'] ?? null)) {
            $run[0]['text'] = \ltrim($run[0]['text']);
        }

        if (\is_string($run[$last]['text'] ?? null)) {
            $run[$last]['text'] = \rtrim($run[$last]['text']);
        }

        // Trimming can empty an edge node, and an empty text node is not valid
        $wrapped[] = [
            'type' => 'paragraph',
            'content' => \array_values(\array_filter(
                $run,
                static fn (array $node): bool => ($node['text'] ?? null) !== ''
            )),
        ];
    }

    /**
     * Replace `set` nodes carrying no values with whatever they contained.
     *
     * `Set::parseHTML()` declines to build attributes for markup it does not
     * trust — an unrecognized `data-set-type`, or a `data-set-nonce` that is
     * not this run's (wordpress-importer-archive#494). Tiptap mints the node regardless, leaving an
     * attribute-less `set` in the entry that the host app's set partials cannot
     * render, with any author text stranded inside it.
     *
     * Splicing the children back in and dropping the shell means untrusted set
     * markup contributes nothing structural while the words around it survive.
     * The unrecognized-type case predates the nonce and is fixed by the same
     * pass.
     *
     * @param  array<int|string, mixed>  $content  Parsed ProseMirror content
     * @return array<int|string, mixed> Content with attribute-less sets removed
     */
    private static function dropUntrustedSets(
        array $content
    ): array {
        $cleaned = [];

        foreach ($content as $node) {
            // Anything that is not a set node passes through untouched
            if (
                ! \is_array($node)
                || ($node['type'] ?? null) !== 'set'
            ) {
                $cleaned[] = $node;

                continue;
            }

            $attrs = $node['attrs'] ?? null;
            $values = \is_array($attrs) ? ($attrs['values'] ?? null) : null;

            // A set the parser trusted always carries at least its own type
            if (
                \is_array($values)
                && $values !== []
            ) {
                $cleaned[] = $node;

                continue;
            }

            $children = $node['content'] ?? null;

            // Keep only what the author actually wrote inside the shell
            if (\is_array($children)) {
                foreach ($children as $child) {
                    $cleaned[] = $child;
                }
            }
        }

        return $cleaned;
    }
}
