<?php

declare(strict_types=1);

/**
 * Tests that `MinervaKBBlockParser` recognizes a `wp:minervakb/topics` block
 * comment, reads the JSON attributes after its name, and hands them to the
 * knowledge base topics parser, leaving every other node unprocessed (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/MinervaKBBlockParserTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\KbTopicsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\MinervaKBBlockParser;

/**
 * A topics parser that records what it is given.
 */
class MinervaRecordingTopicsParser extends KbTopicsParser
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
 * @param  MinervaKBBlockParser  $parser  The parser
 * @param  DOMNode  $node  The node, already attached to a parent
 * @return array{processed: bool, skipCount: int, set: array<mixed>|null} The parse result
 */
function minervaBlockParseNode(MinervaKBBlockParser $parser, DOMNode $node): array
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
function minervaBlockComment(string $text): DOMComment
{
    $document = new DOMDocument;
    $parent = $document->appendChild($document->createElement('div'));
    $comment = $document->createComment($text);
    $parent->appendChild($comment);

    return $comment;
}

it('hands a topics block and its attributes to the topics parser', function (): void {
    $topics = new MinervaRecordingTopicsParser;
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();
    $output = new BufferedOutput;
    $comment = minervaBlockComment(' wp:minervakb/topics {"title":"Help","topics":"3"} /');
    $parent = $comment->parentNode;
    assert($parent instanceof DOMNode);

    $result = new MinervaKBBlockParser($topics)->parseNode($comment, 0, $parent->childNodes, 'news', $assetImporter, $output, ['a' => 1]);

    expect($result)->toBe(['processed' => true, 'skipCount' => 1, 'set' => ['type' => 'recorded']])
        ->and($topics->calls)->toHaveCount(1)
        ->and($topics->calls[0]['attributes'])->toBe(['title' => 'Help', 'topics' => '3'])
        ->and($topics->calls[0]['site'])->toBe('news')
        ->and($topics->calls[0]['assetImporter'])->toBe($assetImporter)
        ->and($topics->calls[0]['output'])->toBe($output)
        ->and($topics->calls[0]['attachments'])->toBe(['a' => 1]);
});

it('reads the attributes the block comment carries', function (string $text, array $expected): void {
    $topics = new MinervaRecordingTopicsParser;

    minervaBlockParseNode(new MinervaKBBlockParser($topics), minervaBlockComment($text));

    expect($topics->calls[0]['attributes'] ?? null)->toBe($expected);
})->with([
    'no attributes' => ['wp:minervakb/topics /', []],
    'nested attributes, with text after them' => ['wp:minervakb/topics {"a":{"b":"}"},"title":"x"} /', ['a' => ['b' => '}'], 'title' => 'x']],
    'attributes that are not valid JSON' => ['wp:minervakb/topics {"a":} /', []],
    'an unclosed brace' => ['wp:minervakb/topics {"a":1', []],
    'attributes split across lines' => ["wp:minervakb/topics\n  {\"title\":\n\"x\"}\n/", ['title' => 'x']],
]);

it('recognizes a topics block whose name is split from the comment start by whitespace', function (): void {
    $topics = new MinervaRecordingTopicsParser;

    $result = minervaBlockParseNode(new MinervaKBBlockParser($topics), minervaBlockComment("\n\t wp:minervakb/topics\n /"));

    expect($result['processed'])->toBeTrue()
        ->and($topics->calls)->toHaveCount(1);
});

it('treats any block name starting with wp:minervakb/topics as a topics block', function (): void {
    // Characterization: the name is matched as a prefix, so a longer name that
    // begins with it is parsed as a topics block too
    $topics = new MinervaRecordingTopicsParser;

    $result = minervaBlockParseNode(new MinervaKBBlockParser($topics), minervaBlockComment('wp:minervakb/topicsx /'));

    expect($result['processed'])->toBeTrue();
});

it('leaves a node that is not a topics block comment unprocessed', function (DOMNode $node): void {
    $topics = new MinervaRecordingTopicsParser;

    $result = minervaBlockParseNode(new MinervaKBBlockParser($topics), $node);

    expect($result)->toBe(['processed' => false, 'skipCount' => 0, 'set' => null])
        ->and($topics->calls)->toBe([]);
})->with([
    'another block' => [fn (): DOMNode => minervaBlockComment('wp:paragraph {"title":"x"}')],
    'a topics block named after other text' => [fn (): DOMNode => minervaBlockComment('x wp:minervakb/topics /')],
    'the closing comment of a topics block' => [fn (): DOMNode => minervaBlockComment('/wp:minervakb/topics')],
    'an element' => [function (): DOMNode {
        $document = new DOMDocument;
        $parent = $document->appendChild($document->createElement('div'));
        $element = $document->createElement('p', 'wp:minervakb/topics');
        $parent->appendChild($element);

        return $element;
    }],
    'a text node' => [function (): DOMNode {
        $document = new DOMDocument;
        $parent = $document->appendChild($document->createElement('div'));
        $text = $document->createTextNode('wp:minervakb/topics');
        $parent->appendChild($text);

        return $text;
    }],
]);

it('parses with a real topics parser by default', function (): void {
    $result = minervaBlockParseNode(new MinervaKBBlockParser, minervaBlockComment('wp:minervakb/topics {"title":"Help"} /'));
    $set = $result['set'];

    expect($set)->toBeArray()
        ->and(\is_array($set) ? $set['type'] ?? null : null)->toBe('uamsweb_block_kb_topics')
        ->and(\is_array($set) ? $set['block_heading'] ?? null : null)->toBe('Help');
});
