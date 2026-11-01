<?php

declare(strict_types=1);

/**
 * Tests that `WordPressCoreBlockParser` recognizes a `wp:rss` block comment,
 * reads the JSON attributes after its name, and hands them to the RSS block
 * parser, leaving every other node unprocessed (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/WordPressCoreBlockParserTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\RssBlockParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\WordPressCoreBlockParser;

/**
 * An RSS block parser that records what it is given.
 */
class CoreBlockRecordingRssParser extends RssBlockParser
{
    /**
     * @var list<array{attributes: array<mixed>, site: string, assetImporter: AssetImporter, output: OutputInterface, attachments: array<mixed>}> The calls, in order
     */
    public array $calls = [];

    /**
     * Record the call and return a marker set.
     *
     * @param  array<mixed>  $attributes  The block attributes
     * @param  string  $site  The site
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  The output
     * @param  array<mixed>  $attachments  The attachments
     * @return array<string, mixed> The marker set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = []
    ): array {
        $this->calls[] = [
            'attributes' => $attributes,
            'site' => $site,
            'assetImporter' => $assetImporter,
            'output' => $output,
            'attachments' => $attachments,
        ];

        return ['type' => 'recorded'];
    }
}

/**
 * Parse one node with the given parser.
 *
 * @param  WordPressCoreBlockParser  $parser  The parser
 * @param  DOMNode  $node  The node, already attached to a parent
 * @return array{processed: bool, skipCount: int, set: array<mixed>|null} The parse result
 */
function coreBlockParseNode(WordPressCoreBlockParser $parser, DOMNode $node): array
{
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();
    $parent = $node->parentNode;
    assert($parent instanceof DOMNode);

    return $parser->parseNode($node, 0, $parent->childNodes, 'default', $assetImporter, new BufferedOutput, ['a' => 1], [], 'about');
}

/**
 * A comment node holding the given text, inside an element in a fresh document.
 *
 * The parent sits in the document so it outlives this function: a detached
 * element is freed with its last variable, and the comment loses its parent.
 *
 * @param  string  $text  The comment text
 * @return DOMComment The comment
 */
function coreBlockComment(string $text): DOMComment
{
    $document = new DOMDocument;
    $parent = $document->appendChild($document->createElement('div'));
    $comment = $document->createComment($text);
    $parent->appendChild($comment);

    return $comment;
}

it('hands an RSS block and its attributes to the RSS block parser', function (): void {
    $rss = new CoreBlockRecordingRssParser;
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();
    $output = new BufferedOutput;
    $comment = coreBlockComment(' wp:rss {"feedURL":"https://example.org/feed","itemsToShow":3} /');
    $parent = $comment->parentNode;
    assert($parent instanceof DOMNode);

    $result = new WordPressCoreBlockParser($rss)->parseNode($comment, 0, $parent->childNodes, 'news', $assetImporter, $output, ['a' => 1]);

    expect($result)->toBe(['processed' => true, 'skipCount' => 1, 'set' => ['type' => 'recorded']])
        ->and($rss->calls)->toHaveCount(1)
        ->and($rss->calls[0]['attributes'])->toBe(['feedURL' => 'https://example.org/feed', 'itemsToShow' => 3])
        ->and($rss->calls[0]['site'])->toBe('news')
        ->and($rss->calls[0]['assetImporter'])->toBe($assetImporter)
        ->and($rss->calls[0]['output'])->toBe($output)
        ->and($rss->calls[0]['attachments'])->toBe(['a' => 1]);
});

it('reads the attributes the block comment carries', function (string $text, array $expected): void {
    $rss = new CoreBlockRecordingRssParser;

    coreBlockParseNode(new WordPressCoreBlockParser($rss), coreBlockComment($text));

    expect($rss->calls[0]['attributes'] ?? null)->toBe($expected);
})->with([
    'no attributes' => ['wp:rss /', []],
    'nested attributes, with text after them' => ['wp:rss {"a":{"b":"}"},"feedURL":"x"} /', ['a' => ['b' => '}'], 'feedURL' => 'x']],
    'attributes that are not valid JSON' => ['wp:rss {"a":} /', []],
    'an unclosed brace' => ['wp:rss {"a":1', []],
    'attributes split across lines' => ["wp:rss\n  {\"feedURL\":\n\"x\"}\n/", ['feedURL' => 'x']],
]);

it('recognizes an RSS block whose name is split from the comment start by whitespace', function (): void {
    $rss = new CoreBlockRecordingRssParser;

    $result = coreBlockParseNode(new WordPressCoreBlockParser($rss), coreBlockComment("\n\t wp:rss\n /"));

    expect($result['processed'])->toBeTrue()
        ->and($rss->calls)->toHaveCount(1);
});

it('treats any block name starting with wp:rss as an RSS block', function (): void {
    // Characterization: the name is matched as a prefix, so a longer name that
    // begins with it is parsed as an RSS block too
    $rss = new CoreBlockRecordingRssParser;

    $result = coreBlockParseNode(new WordPressCoreBlockParser($rss), coreBlockComment('wp:rssfeed /'));

    expect($result['processed'])->toBeTrue();
});

it('leaves a node that is not an RSS block comment unprocessed', function (DOMNode $node): void {
    $rss = new CoreBlockRecordingRssParser;

    $result = coreBlockParseNode(new WordPressCoreBlockParser($rss), $node);

    expect($result)->toBe(['processed' => false, 'skipCount' => 0, 'set' => null])
        ->and($rss->calls)->toBe([]);
})->with([
    'another block' => [fn (): DOMNode => coreBlockComment('wp:paragraph {"feedURL":"x"}')],
    'an RSS block named after other text' => [fn (): DOMNode => coreBlockComment('x wp:rss /')],
    'the closing comment of an RSS block' => [fn (): DOMNode => coreBlockComment('/wp:rss')],
    'an element' => [function (): DOMNode {
        $document = new DOMDocument;
        $parent = $document->appendChild($document->createElement('div'));
        $element = $document->createElement('p', 'wp:rss');
        $parent->appendChild($element);

        return $element;
    }],
    'a text node' => [function (): DOMNode {
        $document = new DOMDocument;
        $parent = $document->appendChild($document->createElement('div'));
        $text = $document->createTextNode('wp:rss');
        $parent->appendChild($text);

        return $text;
    }],
]);

it('parses with a real RSS block parser by default', function (): void {
    $result = coreBlockParseNode(new WordPressCoreBlockParser, coreBlockComment('wp:rss {"feedURL":"https://example.org/feed"} /'));
    $set = $result['set'];

    expect($set)->toBeArray()
        ->and(\is_array($set) ? $set['type'] ?? null : null)->toBe('uamsweb_block_rss')
        ->and(\is_array($set) ? $set['feed_url'] ?? null : null)->toBe('https://example.org/feed');
});
