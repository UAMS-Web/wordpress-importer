<?php

declare(strict_types=1);

/**
 * Tests that `PageBuilderParser` hands each node to the wp:block processor, then
 * the MinervaKB, WordPress core and ACF parsers, and otherwise the non-ACF
 * parser, that it keeps the sets they return in the order of their nodes, folds
 * the non-ACF content between them into one set each, lifts ACF blocks out of
 * accumulated non-ACF HTML, and appends everything at the last node or when
 * finalization is forced.
 *
 * The four parsers are fakes that record each call and return results scripted
 * per node; the wp:block processor is the real one, over the real registry.
 *
 * @command  php vendor/bin/pest tests/Integration/PageBuilderParserTest.php
 */

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\AcfBlockParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\MinervaKBBlockParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\NonAcfBlockParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\PageBuilderParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\WordPressCoreBlockParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpBlockBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpBlockRegistry;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    pbClearContext();
});

afterEach(function (): void {
    pbClearContext();
});

/**
 * Clears the processing context, the wp:block registry and the wp:block caches.
 */
function pbClearContext(): void
{
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ProcessingContext::clearStatamicEntryId();
    ProcessingContext::clearCollectionHandle();
    ProcessingContext::clearWordPressPostId();
    WpBlockRegistry::clear();
    WpBlockBlockProcessor::clearWpBlockPostIds();
    WpBlockBlockProcessor::clearDeferredWpBlockReferences();
}

/**
 * A short label for a node: `c:` and the trimmed data for a comment, else the
 * node name and its text.
 */
function pbLabel(DOMNode $node): string
{
    return $node instanceof DOMComment
        ? 'c:'.trim($node->data)
        : $node->nodeName.':'.trim($node->textContent);
}

/**
 * The parse result of a parser that did not handle the node.
 *
 * @return array{processed: bool, skipCount: int, set: ?array<int|string, mixed>}
 */
function pbUnhandled(): array
{
    return ['processed' => false, 'skipCount' => 0, 'set' => null];
}

/**
 * A MinervaKB parser that records each call and returns results scripted by node label.
 */
class PbFakeMinerva extends MinervaKBBlockParser
{
    /**
     * Each call: the node label, index, attachments, terms and page slug.
     *
     * @var list<list<mixed>>
     */
    public array $calls = [];

    /**
     * The result for each node label.
     *
     * @var array<string, array{processed: bool, skipCount: int, set: ?array<int|string, mixed>}>
     */
    public array $results = [];

    /**
     * Record the call and return the scripted result.
     *
     * @param  DOMNodeList<DOMNode>  $nodes
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     * @return array{processed: bool, skipCount: int, set: ?array<int|string, mixed>}
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
        $this->calls[] = [pbLabel($node), $index, $site, $attachments, $terms, $pageSlug];

        return $this->results[pbLabel($node)] ?? pbUnhandled();
    }
}

/**
 * A WordPress core block parser that records each call and returns results
 * scripted by node label.
 */
class PbFakeCore extends WordPressCoreBlockParser
{
    /**
     * Each call: the node label, index, attachments, terms and page slug.
     *
     * @var list<list<mixed>>
     */
    public array $calls = [];

    /**
     * The result for each node label.
     *
     * @var array<string, array{processed: bool, skipCount: int, set: ?array<int|string, mixed>}>
     */
    public array $results = [];

    /**
     * Record the call and return the scripted result.
     *
     * @param  DOMNodeList<DOMNode>  $nodes
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     * @return array{processed: bool, skipCount: int, set: ?array<int|string, mixed>}
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
        $this->calls[] = [pbLabel($node), $index, $site, $attachments, $terms, $pageSlug];

        return $this->results[pbLabel($node)] ?? pbUnhandled();
    }
}

/**
 * An ACF parser that records each call and returns results scripted by node
 * label, and by HTML for ACF blocks found in accumulated HTML.
 */
class PbFakeAcf extends AcfBlockParser
{
    /**
     * Each parseNode() call: the node label, index, attachments, terms, items and page slug.
     *
     * @var list<list<mixed>>
     */
    public array $calls = [];

    /**
     * Each parseAcfBlocksFromHtml() call: the HTML, site, attachments, terms, items and page slug.
     *
     * @var list<list<mixed>>
     */
    public array $htmlCalls = [];

    /**
     * The parseNode() result for each node label.
     *
     * @var array<string, array{processed: bool, skipCount: int, set: ?array<int|string, mixed>}>
     */
    public array $results = [];

    /**
     * The parseAcfBlocksFromHtml() result for each HTML string.
     *
     * @var array<string, array<int|string, mixed>>
     */
    public array $htmlResults = [];

    /**
     * Record the call and return the scripted result.
     *
     * @param  DOMNodeList<DOMNameSpaceNode|DOMNode>  $nodes
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     * @param  array<int|string, mixed>  $items
     * @return array{processed: bool, skipCount: int, set: ?array<int|string, mixed>}
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
        array $items = [],
        ?string $pageSlug = null
    ): array {
        $this->calls[] = [pbLabel($node), $index, $site, $attachments, $terms, $items, $pageSlug];

        return $this->results[pbLabel($node)] ?? pbUnhandled();
    }

    /**
     * Record the call and return the scripted result.
     *
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     * @param  array<int|string, mixed>  $items
     * @return array<int|string, mixed>
     */
    public function parseAcfBlocksFromHtml(
        string $html,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        array $items = [],
        ?string $pageSlug = null
    ): array {
        $this->htmlCalls[] = [$html, $site, $attachments, $terms, $items, $pageSlug];

        return $this->htmlResults[$html] ?? ['acfSets' => [], 'remainingHtml' => $html];
    }
}

/**
 * A non-ACF parser that accumulates each element's text as HTML, unless a node
 * is scripted otherwise, and finalizes the accumulated HTML into one set.
 */
class PbFakeNonAcf extends NonAcfBlockParser
{
    /**
     * Each call, in order: the method and its arguments.
     *
     * @var list<list<mixed>>
     */
    public array $calls = [];

    /**
     * What parseNode() adds to the accumulator for each node label.
     *
     * @var array<string, list<mixed>>
     */
    public array $pushes = [];

    /**
     * The parseNode() result for each node label.
     *
     * @var array<string, array{processed: bool, skipCount: int, directSet?: array<int|string, mixed>}>
     */
    public array $results = [];

    /**
     * What finalizeNonAcfContent() returns in place of the joined set.
     *
     * @var ?Closure(list<mixed>): ?array<int|string, mixed>
     */
    public ?Closure $finalizer = null;

    /**
     * Record the reset.
     */
    public function resetStaticVariables(): void
    {
        $this->calls[] = ['reset'];
    }

    /**
     * Record the call, add the node's scripted items to the accumulator (an
     * element's text by default), and return the scripted result.
     *
     * @param  DOMNodeList<DOMNode>  $nodes
     * @param  array<int|string, mixed>  $accumulator
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     * @param  array<int|string, mixed>  $items
     * @param  array<int|string, mixed>  $unaddressedBlocks
     * @return array{processed: bool, skipCount: int, directSet?: array<int|string, mixed>}
     */
    public function parseNode(
        DOMNode $node,
        int $index,
        DOMNodeList $nodes,
        array &$accumulator,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        array $items = [],
        array $unaddressedBlocks = [],
        ?string $pageSlug = null
    ): array {
        $label = pbLabel($node);
        $this->calls[] = ['parse', $label, $index, $site, $attachments, $terms, $items, $unaddressedBlocks, $pageSlug];
        $element = $node instanceof DOMElement;

        foreach ($this->pushes[$label] ?? ($element ? [['html' => trim($node->textContent)]] : []) as $item) {
            $accumulator[] = $item;
        }

        return $this->results[$label] ?? ['processed' => $element, 'skipCount' => $element ? 1 : 0];
    }

    /**
     * Record the call and return the accumulated HTML joined into one set.
     *
     * @param  array<int|string, mixed>  $nonAcfContent
     * @param  array<int|string, mixed>  $content
     * @return ?array<int|string, mixed>
     */
    public function finalizeNonAcfContent(
        array $nonAcfContent,
        array &$content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): ?array {
        $nonAcfContent = array_values($nonAcfContent);
        $this->calls[] = ['finalize', $nonAcfContent];

        if ($this->finalizer instanceof Closure) {
            return ($this->finalizer)($nonAcfContent);
        }

        return ['type' => 'text', 'html' => implode('|', array_map(
            static fn (mixed $item): string => is_array($item) && is_string($item['html'] ?? null) ? $item['html'] : '?',
            $nonAcfContent
        ))];
    }
}

/**
 * A page builder parser over fake parsers, with the fakes.
 *
 * @return array{parser: PageBuilderParser, minerva: PbFakeMinerva, core: PbFakeCore, acf: PbFakeAcf, nonAcf: PbFakeNonAcf}
 */
function pbParser(): array
{
    $fakes = [
        'minerva' => new PbFakeMinerva,
        'core' => new PbFakeCore,
        'acf' => new PbFakeAcf,
        'nonAcf' => new PbFakeNonAcf,
    ];

    $parser = new PageBuilderParser($fakes['acf'], $fakes['nonAcf'], $fakes['minerva'], $fakes['core']);
    $parser->resetStaticVariables();
    $fakes['nonAcf']->calls = [];

    return ['parser' => $parser] + $fakes;
}

/**
 * The fake of a block parser that runs before the ACF parser.
 *
 * @param  array{parser: PageBuilderParser, minerva: PbFakeMinerva, core: PbFakeCore, acf: PbFakeAcf, nonAcf: PbFakeNonAcf}  $fakes  The fakes
 * @param  string  $which  `minerva` or `core`
 */
function pbEarlyFake(array $fakes, string $which): PbFakeMinerva|PbFakeCore
{
    return $which === 'minerva' ? $fakes['minerva'] : $fakes['core'];
}

/**
 * The body nodes of an HTML fragment.
 *
 * @return DOMNodeList<DOMNode>
 */
function pbNodes(string $html): DOMNodeList
{
    $doc = new DOMDocument;
    $doc->loadHTML('<html><body>'.$html.'</body></html>', LIBXML_NOERROR);
    $body = $doc->getElementsByTagName('body')->item(0);
    assert($body instanceof DOMElement);

    return $body->childNodes;
}

/**
 * Parses one node of the list.
 *
 * @param  DOMNodeList<DOMNode>  $nodes  The node list
 * @param  array<int|string, mixed>  $content  The page builder content
 * @param  array<string, mixed>  $extra  Attachments, terms, items, unaddressed blocks and page slug
 * @return array<int|string, mixed> The parse result
 */
function pbParse(PageBuilderParser $parser, DOMNodeList $nodes, int $index, array &$content, array $extra = []): array
{
    $node = $nodes->item($index);
    assert($node instanceof DOMNode);
    $unaddressed = is_array($extra['unaddressed'] ?? null) ? $extra['unaddressed'] : [];
    $slug = $extra['slug'] ?? null;

    return $parser->parseNode(
        $node,
        $index,
        $nodes,
        $content,
        'default',
        app(AssetImporter::class),
        new BufferedOutput,
        is_array($extra['attachments'] ?? null) ? $extra['attachments'] : [],
        is_array($extra['terms'] ?? null) ? $extra['terms'] : [],
        is_array($extra['items'] ?? null) ? $extra['items'] : [],
        $unaddressed,
        is_string($slug) ? $slug : null
    );
}

/**
 * Parses every node of the list in turn, and returns the content and each result.
 *
 * @param  DOMNodeList<DOMNode>  $nodes  The node list
 * @return array{content: array<int|string, mixed>, results: list<array<int|string, mixed>>}
 */
function pbParseAll(PageBuilderParser $parser, DOMNodeList $nodes): array
{
    $content = [];
    $results = [];

    for ($index = 0; $index < $nodes->length; $index++) {
        $results[] = pbParse($parser, $nodes, $index, $content);
    }

    return ['content' => $content, 'results' => $results];
}

/**
 * Forces finalization into the given content, and returns it.
 *
 * @param  array<int|string, mixed>  $content  The page builder content
 * @return array<int|string, mixed> The content after finalization
 */
function pbForce(PageBuilderParser $parser, array $content = []): array
{
    $parser->forceFinalization($content, 'default', app(AssetImporter::class), new BufferedOutput);

    return $content;
}

/**
 * A set from one of the fake parsers.
 *
 * @return array<string, string>
 */
function pbSet(string $name): array
{
    return ['type' => $name];
}

/**
 * The parse result of a parser that handled the node.
 *
 * @param  ?array<int|string, mixed>  $set  The set it made
 * @return array{processed: bool, skipCount: int, set: ?array<int|string, mixed>}
 */
function pbHandled(?array $set, int $skipCount = 1): array
{
    return ['processed' => true, 'skipCount' => $skipCount, 'set' => $set];
}

/**
 * The warnings logged while running a callback that the page builder parser wrote.
 *
 * @param  callable(): mixed  $callback  The code to run
 * @return list<array{string, array<int|string, mixed>}> Each warning's message and context, in order
 */
function pbWarnings(callable $callback): array
{
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning' && str_starts_with($event->message, 'PageBuilderParser')) {
            $warnings[] = [$event->message, $event->context];
        }
    });

    $callback();

    return $warnings;
}

/**
 * A set with its row id checked and removed.
 *
 * @return array<int|string, mixed>
 */
function pbWithoutId(mixed $set): array
{
    expect($set)->toBeArray();
    assert(is_array($set));
    expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
    unset($set['id']);

    return $set;
}

it('folds consecutive non-ACF nodes into one set at the last node', function (): void {
    $fakes = pbParser();
    $nodes = pbNodes('<p>A</p><p>B</p>');
    $content = [];

    expect(pbParse($fakes['parser'], $nodes, 0, $content))->toBe(['processed' => true, 'skipCount' => 1])
        ->and($content)->toBe([])
        ->and(pbParse($fakes['parser'], $nodes, 1, $content))->toBe(['processed' => true, 'skipCount' => 1])
        ->and($content)->toBe([['type' => 'text', 'html' => 'A|B']])
        ->and(pbForce($fakes['parser']))->toBe([]);
});

it('keeps the sets of the nodes in their order, with the non-ACF content around an ACF block in a set each', function (): void {
    $fakes = pbParser();
    $fakes['acf']->results['c:wp:acf/x'] = pbHandled(pbSet('acf'));

    $run = pbParseAll($fakes['parser'], pbNodes('<p>A</p><!-- wp:acf/x --><p>B</p>'));

    expect($run['content'])->toBe([
        ['type' => 'text', 'html' => 'A'],
        pbSet('acf'),
        ['type' => 'text', 'html' => 'B'],
    ])
        ->and($run['results'][1])->toBe(['processed' => true, 'skipCount' => 1])
        ->and(array_column($fakes['nonAcf']->calls, 1))->toBe(['p:A', [['html' => 'A']], 'p:B', [['html' => 'B']]]);
});

it('puts a direct set after the non-ACF content ahead of it', function (): void {
    $fakes = pbParser();
    $fakes['nonAcf']->results['c:wp:shortcode'] = ['processed' => true, 'skipCount' => 1, 'directSet' => pbSet('direct')];

    $run = pbParseAll($fakes['parser'], pbNodes('<p>A</p><!-- wp:shortcode --><p>B</p>'));

    expect($run['content'])->toBe([
        ['type' => 'text', 'html' => 'A'],
        pbSet('direct'),
        ['type' => 'text', 'html' => 'B'],
    ]);
});

it('finalizes at an ACF block whose skip count reaches the end of the nodes', function (): void {
    $fakes = pbParser();
    $fakes['acf']->results['c:wp:acf/x'] = pbHandled(pbSet('acf'), 2);
    $nodes = pbNodes('<p>A</p><!-- wp:acf/x --><p>B</p>');
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content);

    expect(pbParse($fakes['parser'], $nodes, 1, $content))->toBe(['processed' => true, 'skipCount' => 2])
        ->and($content)->toBe([['type' => 'text', 'html' => 'A'], pbSet('acf')]);
});

it('does not finalize at an ACF block whose skip count stops short of the end', function (): void {
    $fakes = pbParser();
    $fakes['acf']->results['c:wp:acf/x'] = pbHandled(pbSet('acf'), 1);
    $nodes = pbNodes('<p>A</p><!-- wp:acf/x --><p>B</p>');
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content);
    pbParse($fakes['parser'], $nodes, 1, $content);

    expect($content)->toBe([]);
});

it('finalizes at a node whose skip count passes the end of the nodes', function (): void {
    $fakes = pbParser();
    $fakes['nonAcf']->results['p:A'] = ['processed' => true, 'skipCount' => 3];
    $nodes = pbNodes('<p>A</p><p>B</p>');
    $content = [];

    expect(pbParse($fakes['parser'], $nodes, 0, $content))->toBe(['processed' => true, 'skipCount' => 3])
        ->and($content)->toBe([['type' => 'text', 'html' => 'A']]);
});

it('logs an ACF block that made no set, adds nothing for it, and still finalizes before it', function (): void {
    $fakes = pbParser();
    $value = ' wp:acf/empty '.str_repeat('a', 300).' ';
    $fakes['acf']->results['c:'.trim($value)] = pbHandled(null, 1);
    ProcessingContext::setWordPressPageLink('https://example.com/the-page');
    ProcessingContext::setWordPressPostId('7');
    ProcessingContext::setStatamicEntryId('entry-1');
    ProcessingContext::setEntryData(['post_name' => 'the-page']);
    $nodes = pbNodes('<p>A</p><!--'.$value.'-->');
    $run = [];

    $warnings = pbWarnings(function () use ($fakes, $nodes, &$run): void {
        $run = pbParseAll($fakes['parser'], $nodes);
    });

    expect($run['content'] ?? null)->toBe([['type' => 'text', 'html' => 'A']])
        ->and($run['results'][1] ?? null)->toBe(['processed' => true, 'skipCount' => 1])
        ->and($warnings)->toBe([[
            'PageBuilderParser::parseNode: ACF block processed but no set created (from entry: https://example.com/the-page)',
            [
                'node_value' => substr($value, 0, 200),
                'index' => 1,
                'nodes_length' => 2,
                'acf_result_keys' => ['processed', 'skipCount', 'set'],
                'acf_result_processed' => true,
                'acf_result_skip_count' => 1,
                'wordpress_post_id' => '7',
                'wordpress_page_link' => 'https://example.com/the-page',
                'statamic_entry_id' => 'entry-1',
                'post_name' => 'the-page',
                'entry_url' => 'https://example.com/the-page',
            ],
        ]]);
});

it('logs an ACF block that made no set with no post name when the entry has none', function (): void {
    $fakes = pbParser();
    $fakes['acf']->results['c:wp:acf/empty'] = pbHandled(null, 2);
    $nodes = pbNodes('<!-- wp:acf/empty -->');
    $content = [];

    $warnings = pbWarnings(function () use ($fakes, $nodes, &$content): void {
        pbParse($fakes['parser'], $nodes, 0, $content);
    });

    expect($warnings)->toBe([[
        'PageBuilderParser::parseNode: ACF block processed but no set created',
        [
            'node_value' => ' wp:acf/empty ',
            'index' => 0,
            'nodes_length' => 1,
            'acf_result_keys' => ['processed', 'skipCount', 'set'],
            'acf_result_processed' => true,
            'acf_result_skip_count' => 2,
            'wordpress_post_id' => null,
            'wordpress_page_link' => null,
            'statamic_entry_id' => null,
            'post_name' => null,
        ],
    ]]);
});

it('keeps the set of a block parser that runs before the ACF parser for finalization, after the non-ACF content before it', function (string $which): void {
    $fakes = pbParser();
    pbEarlyFake($fakes, $which)->results['c:wp:x'] = pbHandled(pbSet($which), 1);
    $nodes = pbNodes('<p>A</p><!-- wp:x -->');
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content);

    expect(pbParse($fakes['parser'], $nodes, 1, $content))->toBe(['processed' => true, 'skipCount' => 1])
        ->and($content)->toBe([])
        ->and($fakes['acf']->calls)->toBe([['p:A', 0, 'default', [], [], [], null]])
        ->and(array_column($fakes['nonAcf']->calls, 0))->toBe(['parse', 'finalize'])
        ->and(pbForce($fakes['parser']))->toBe([['type' => 'text', 'html' => 'A'], pbSet($which)]);
})->with(['minerva', 'core']);

it('returns the skip count of a block parser that runs before the ACF parser', function (string $which): void {
    $fakes = pbParser();
    pbEarlyFake($fakes, $which)->results['c:wp:x'] = pbHandled(pbSet($which), 3);
    $content = [];

    expect(pbParse($fakes['parser'], pbNodes('<!-- wp:x --><p>A</p>'), 0, $content))->toBe(['processed' => true, 'skipCount' => 3]);
})->with(['minerva', 'core']);

it('adds nothing for a block parser that runs before the ACF parser and made no set', function (string $which): void {
    $fakes = pbParser();
    pbEarlyFake($fakes, $which)->results['c:wp:x'] = pbHandled(null, 1);
    $nodes = pbNodes('<p>A</p><!-- wp:x -->');
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content);
    pbParse($fakes['parser'], $nodes, 1, $content);

    expect(pbForce($fakes['parser']))->toBe([['type' => 'text', 'html' => 'A']]);
})->with(['minerva', 'core']);

it('asks the MinervaKB parser before the core block parser, and the core block parser before the ACF parser', function (): void {
    $fakes = pbParser();
    $fakes['minerva']->results['c:wp:m'] = pbHandled(pbSet('minerva'));
    $fakes['core']->results['c:wp:m'] = pbHandled(pbSet('core'));
    $fakes['core']->results['c:wp:c'] = pbHandled(pbSet('core'));
    $fakes['acf']->results['c:wp:c'] = pbHandled(pbSet('acf'));

    pbParseAll($fakes['parser'], pbNodes('<!-- wp:m --><!-- wp:c -->'));

    expect(array_column($fakes['core']->calls, 0))->toBe(['c:wp:c'])
        ->and($fakes['acf']->calls)->toBe([])
        ->and(pbForce($fakes['parser']))->toBe([pbSet('minerva'), pbSet('core')]);
});

it('passes the node, its index, the site, the attachments, terms, items, unaddressed blocks and page slug to each parser', function (): void {
    $fakes = pbParser();
    $nodes = pbNodes('<p>A</p>');
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content, [
        'attachments' => ['att'],
        'terms' => ['term'],
        'items' => ['item'],
        'unaddressed' => ['block'],
        'slug' => 'the-page',
    ]);

    expect($fakes['minerva']->calls)->toBe([['p:A', 0, 'default', ['att'], ['term'], 'the-page']])
        ->and($fakes['core']->calls)->toBe([['p:A', 0, 'default', ['att'], ['term'], 'the-page']])
        ->and($fakes['acf']->calls)->toBe([['p:A', 0, 'default', ['att'], ['term'], ['item'], 'the-page']])
        ->and($fakes['nonAcf']->calls[0])->toBe(['parse', 'p:A', 0, 'default', ['att'], ['term'], ['item'], ['block'], 'the-page']);
});

it('makes a reusable content set for a wp:block whose entry is imported, after the non-ACF content before it', function (): void {
    $fakes = pbParser();
    WpBlockRegistry::registerMapping('12', 'entry-9');
    $nodes = pbNodes('<p>A</p><!-- wp:block {"ref":12} /-->');
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content);

    expect(pbParse($fakes['parser'], $nodes, 1, $content))->toBe(['processed' => true, 'skipCount' => 1])
        ->and($content)->toBe([])
        ->and($fakes['minerva']->calls)->toHaveCount(1)
        ->and($fakes['acf']->calls)->toHaveCount(1);

    $content = pbForce($fakes['parser']);

    expect($content[0] ?? null)->toBe(['type' => 'text', 'html' => 'A'])
        ->and(pbWithoutId($content[1] ?? null))->toBe([
            'type' => 'uamsweb_block_reusable_content_landing_page',
            'content' => ['entry-9'],
            'enabled' => true,
        ])
        ->and($content)->toHaveCount(2);
});

it('makes a placeholder reusable content set for a wp:block whose entry is not imported yet', function (): void {
    $fakes = pbParser();
    $content = [];

    pbParse($fakes['parser'], pbNodes('<!-- wp:block {"ref":"34"} /-->'), 0, $content);

    expect(array_map(pbWithoutId(...), pbForce($fakes['parser'])))->toBe([[
        'type' => 'uamsweb_block_reusable_content_landing_page',
        'import_wordpress_notes' => 'wp_block post ID 34',
        'enabled' => true,
    ]]);
});

it('logs a wp:block the processor could not read, and adds nothing for it', function (string $comment, ?string $slug, string $pageSlug): void {
    $fakes = pbParser();
    ProcessingContext::setWordPressPageLink('https://example.com/the-page');
    $nodes = pbNodes('<p>A</p><!--'.$comment.'-->');
    $content = [];
    $result = [];

    $warnings = pbWarnings(function () use ($fakes, $nodes, $slug, &$content, &$result): void {
        pbParse($fakes['parser'], $nodes, 0, $content);
        $result = pbParse($fakes['parser'], $nodes, 1, $content, ['slug' => $slug]);
    });

    expect($result)->toBe(['processed' => true, 'skipCount' => 1])
        ->and($warnings)->toBe([[
            'PageBuilderParser::parseNode: wp:block processing returned null/false (from entry: https://example.com/the-page)',
            [
                'page_slug' => $pageSlug,
                'node_value' => substr($comment, 0, 200),
                'entry_url' => 'https://example.com/the-page',
            ],
        ]])
        ->and(pbForce($fakes['parser']))->toBe([['type' => 'text', 'html' => 'A']]);
})->with([
    'no ref, with a page slug' => [' wp:block {"other":1} /', 'the-page', 'the-page'],
    'no ref, without a page slug' => [' wp:block {"other":1} /', null, 'none'],
    'attributes that are not JSON' => [' wp:block {not json} /', null, 'none'],
    'a long comment' => [' wp:block {"other":"'.str_repeat('b', 300).'"} /', null, 'none'],
]);

it('reads wp:block attributes that span lines', function (): void {
    $fakes = pbParser();
    $content = [];

    pbParse($fakes['parser'], pbNodes("<!-- wp:block {\n\"ref\":\"56\"\n} /-->"), 0, $content);

    expect(array_map(pbWithoutId(...), pbForce($fakes['parser'])))->toBe([[
        'type' => 'uamsweb_block_reusable_content_landing_page',
        'import_wordpress_notes' => 'wp_block post ID 56',
        'enabled' => true,
    ]]);
});

it('passes a comment that is not a wp:block on to the other parsers', function (string $html): void {
    $fakes = pbParser();
    $nodes = pbNodes($html);
    $label = pbLabel($nodes->item(0) ?? new DOMText);
    $fakes['minerva']->results[$label] = pbHandled(pbSet('minerva'));
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content);

    expect(pbForce($fakes['parser']))->toBe([pbSet('minerva')]);
})->with([
    'another block' => ['<!-- wp:paragraph {"ref":1} -->'],
    'a wp:block followed by more text' => ['<!-- wp:block {"ref":1} more -->'],
    'an element' => ['<p>wp:block {"ref":1}</p>'],
]);

it('lifts the ACF blocks out of the HTML a non-ACF node added, after the content accumulated before them', function (string $tail): void {
    $fakes = pbParser();
    $fakes['nonAcf']->pushes['p:T'] = [['html' => $tail]];
    $fakes['acf']->htmlResults[$tail] = ['acfSets' => [pbSet('acf 1'), pbSet('acf 2')], 'remainingHtml' => "  rest \n"];

    $run = pbParseAll($fakes['parser'], pbNodes('<p>A</p><p>T</p>'));

    expect($run['content'])->toBe([
        ['type' => 'text', 'html' => 'A|'.$tail],
        pbSet('acf 1'),
        pbSet('acf 2'),
        ['type' => 'text', 'html' => 'rest'],
    ])
        ->and($fakes['acf']->htmlCalls)->toBe([[$tail, 'default', [], [], [], null]]);
})->with([
    'with a space' => ['<!-- wp:acf/x /-->'],
    'without a space' => ['<!--wp:acf/x /-->'],
    'after other HTML, with a line break' => ["<p>x</p><!--\n  wp:acf/x /-->"],
]);

it('passes the attachments, terms, items and page slug on when lifting ACF blocks out of HTML', function (): void {
    $fakes = pbParser();
    $fakes['nonAcf']->pushes['p:T'] = [['html' => '<!-- wp:acf/x /-->']];
    $content = [];

    pbParse($fakes['parser'], pbNodes('<p>T</p><p>B</p>'), 0, $content, [
        'attachments' => ['att'],
        'terms' => ['term'],
        'items' => ['item'],
        'slug' => 'the-page',
    ]);

    expect($fakes['acf']->htmlCalls)->toBe([['<!-- wp:acf/x /-->', 'default', ['att'], ['term'], ['item'], 'the-page']]);
});

it('looks for ACF blocks only in the last HTML a non-ACF node added', function (): void {
    $fakes = pbParser();
    $fakes['nonAcf']->pushes['p:T'] = [['html' => '<!-- wp:acf/x /-->'], ['html' => 'plain']];

    pbParseAll($fakes['parser'], pbNodes('<p>T</p><p>B</p>'));

    expect($fakes['acf']->htmlCalls)->toBe([]);
});

it('leaves the accumulated HTML alone when it holds no ACF block, or lifting found none', function (string $tail, array $result): void {
    $fakes = pbParser();
    $fakes['nonAcf']->pushes['p:T'] = [['html' => $tail]];
    $fakes['acf']->htmlResults[$tail] = $result;

    $run = pbParseAll($fakes['parser'], pbNodes('<p>A</p><p>T</p>'));

    expect($run['content'])->toBe([['type' => 'text', 'html' => 'A|'.$tail]]);
})->with([
    'another block' => ['<!-- wp:acfx /-->', ['acfSets' => [pbSet('acf')]]],
    'a block name that only mentions acf' => ['<!-- wp:block/acf/x /-->', ['acfSets' => [pbSet('acf')]]],
    'no sets found' => ['<!-- wp:acf/x /-->', ['acfSets' => [], 'remainingHtml' => 'rest']],
    'sets that are not a list' => ['<!-- wp:acf/x /-->', ['acfSets' => 'acf', 'remainingHtml' => 'rest']],
    'no sets key' => ['<!-- wp:acf/x /-->', ['remainingHtml' => 'rest']],
]);

it('puts back no remaining HTML when there is none to put back', function (array $result): void {
    $fakes = pbParser();
    $fakes['nonAcf']->pushes['p:T'] = [['html' => '<!-- wp:acf/x /-->']];
    $fakes['acf']->htmlResults['<!-- wp:acf/x /-->'] = ['acfSets' => [pbSet('acf')]] + $result;

    $run = pbParseAll($fakes['parser'], pbNodes('<p>A</p><p>T</p>'));

    expect($run['content'])->toBe([['type' => 'text', 'html' => 'A|<!-- wp:acf/x /-->'], pbSet('acf')]);
})->with([
    'blank' => [['remainingHtml' => " \n "]],
    'not a string' => [['remainingHtml' => 5]],
    'missing' => [[]],
]);

it('adds the lifted ACF sets even when the content before them finalizes to nothing', function (?array $finalized): void {
    $fakes = pbParser();
    $fakes['nonAcf']->pushes['p:T'] = [['html' => '<!-- wp:acf/x /-->']];
    $fakes['nonAcf']->finalizer = static fn (array $content): ?array => ($content[0] ?? null) === ['html' => 'rest'] ? ['type' => 'rest'] : $finalized;
    $fakes['acf']->htmlResults['<!-- wp:acf/x /-->'] = ['acfSets' => [pbSet('acf')], 'remainingHtml' => 'rest'];

    $run = pbParseAll($fakes['parser'], pbNodes('<p>T</p><p>B</p>'));

    expect($run['content'])->toBe([pbSet('acf'), ['type' => 'rest']]);
})->with([
    'null' => [null],
    'an empty set' => [[]],
]);

it('looks for no ACF blocks when the last thing accumulated is not HTML', function (mixed $item): void {
    $fakes = pbParser();
    $fakes['nonAcf']->pushes['p:T'] = [$item];

    $run = pbParseAll($fakes['parser'], pbNodes('<p>T</p><p>B</p>'));

    expect($fakes['acf']->htmlCalls)->toBe([])
        ->and($run['content'])->toBe([['type' => 'text', 'html' => '?|B']]);
})->with([
    'a string' => ['<!-- wp:acf/x /-->'],
    'an item with non-string HTML' => [['html' => 5]],
    'an item without HTML' => [['text' => '<!-- wp:acf/x /-->']],
]);

it('looks for no ACF blocks when the non-ACF parser did not handle the node', function (): void {
    $fakes = pbParser();
    $fakes['nonAcf']->pushes['p:T'] = [['html' => '<!-- wp:acf/x /-->']];
    $fakes['nonAcf']->results['p:T'] = ['processed' => false, 'skipCount' => 0];

    pbParseAll($fakes['parser'], pbNodes('<p>T</p><p>B</p>'));

    expect($fakes['acf']->htmlCalls)->toBe([]);
});

it('marks an unhandled last node as skipped, and finalizes there', function (): void {
    $fakes = pbParser();

    $run = pbParseAll($fakes['parser'], pbNodes('<p>A</p><!-- other -->'));

    expect($run['results'][1])->toBe(['processed' => false, 'skipCount' => 1])
        ->and($run['content'])->toBe([['type' => 'text', 'html' => 'A']]);
});

it('marks a handled last node with no skip count as skipped, and finalizes there', function (): void {
    $fakes = pbParser();
    $fakes['nonAcf']->results['p:B'] = ['processed' => true, 'skipCount' => 0];

    $run = pbParseAll($fakes['parser'], pbNodes('<p>A</p><p>B</p>'));

    expect($run['results'][1])->toBe(['processed' => true, 'skipCount' => 1])
        ->and($run['content'])->toBe([['type' => 'text', 'html' => 'A|B']]);
});

it('leaves an unhandled node before the last unskipped and unfinalized', function (): void {
    $fakes = pbParser();
    $nodes = pbNodes('<p>A</p><!-- other --><p>B</p>');
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content);

    expect(pbParse($fakes['parser'], $nodes, 1, $content))->toBe(['processed' => false, 'skipCount' => 0])
        ->and($content)->toBe([]);
});

it('adds no non-ACF set at the last node when the content finalizes to nothing, but still appends the other sets', function (?array $finalized): void {
    $fakes = pbParser();
    $fakes['nonAcf']->finalizer = static fn (array $content): ?array => $finalized;
    $fakes['acf']->results['c:wp:acf/x'] = pbHandled(pbSet('acf'));

    $run = pbParseAll($fakes['parser'], pbNodes('<!-- wp:acf/x --><p>B</p>'));

    expect($run['content'])->toBe([pbSet('acf')])
        ->and(pbForce($fakes['parser']))->toBe([]);
})->with([
    'null' => [null],
    'an empty set' => [[]],
]);

it('adds no non-ACF set before a block when the content finalizes to nothing', function (?array $finalized): void {
    $fakes = pbParser();
    $fakes['nonAcf']->finalizer = static fn (array $content): ?array => $finalized;
    $fakes['minerva']->results['c:wp:x'] = pbHandled(pbSet('minerva'));
    $nodes = pbNodes('<p>A</p><!-- wp:x -->');
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content);
    pbParse($fakes['parser'], $nodes, 1, $content);

    expect(pbForce($fakes['parser']))->toBe([pbSet('minerva')])
        ->and(array_column($fakes['nonAcf']->calls, 0))->toBe(['parse', 'finalize']);
})->with([
    'null' => [null],
    'an empty set' => [[]],
]);

it('finalizes nothing before a block when nothing has accumulated', function (): void {
    $fakes = pbParser();
    $fakes['minerva']->results['c:wp:x'] = pbHandled(pbSet('minerva'));
    $content = [];

    pbParse($fakes['parser'], pbNodes('<!-- wp:x --><p>B</p>'), 0, $content);

    expect($fakes['nonAcf']->calls)->toBe([]);
});

it('clears what it accumulated, and resets the non-ACF parser, when reset', function (): void {
    $fakes = pbParser();
    $fakes['minerva']->results['c:wp:x'] = pbHandled(pbSet('minerva'));
    $nodes = pbNodes('<p>A</p><!-- wp:x --><p>B</p><p>C</p>');
    $content = [];

    pbParse($fakes['parser'], $nodes, 1, $content);
    pbParse($fakes['parser'], $nodes, 2, $content);
    $fakes['parser']->resetStaticVariables();
    pbParse($fakes['parser'], $nodes, 3, $content);

    expect($content)->toBe([['type' => 'text', 'html' => 'C']])
        ->and(array_column($fakes['nonAcf']->calls, 0))->toBe(['parse', 'reset', 'parse', 'finalize']);
});

it('appends what accumulated when finalization is forced, then holds nothing', function (): void {
    $fakes = pbParser();
    $fakes['minerva']->results['c:wp:x'] = pbHandled(pbSet('minerva'));
    $nodes = pbNodes('<p>A</p><!-- wp:x --><p>B</p><p>C</p>');
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content);
    pbParse($fakes['parser'], $nodes, 1, $content);
    pbParse($fakes['parser'], $nodes, 2, $content);

    expect(pbForce($fakes['parser'], [pbSet('earlier')]))->toBe([
        pbSet('earlier'),
        ['type' => 'text', 'html' => 'A'],
        pbSet('minerva'),
        ['type' => 'text', 'html' => 'B'],
    ])
        ->and(pbForce($fakes['parser']))->toBe([])
        ->and(array_column($fakes['nonAcf']->calls, 0))->toBe(['parse', 'finalize', 'parse', 'finalize']);
});

it('adds no non-ACF set when forced content finalizes to nothing, but still appends the other sets', function (?array $finalized): void {
    $fakes = pbParser();
    $fakes['nonAcf']->finalizer = static fn (array $content): ?array => $content === [['html' => 'B']] ? $finalized : ['type' => 'A'];
    $fakes['minerva']->results['c:wp:x'] = pbHandled(pbSet('minerva'));
    $nodes = pbNodes('<p>A</p><!-- wp:x --><p>B</p><p>C</p>');
    $content = [];

    pbParse($fakes['parser'], $nodes, 0, $content);
    pbParse($fakes['parser'], $nodes, 1, $content);
    pbParse($fakes['parser'], $nodes, 2, $content);

    expect(pbForce($fakes['parser']))->toBe([['type' => 'A'], pbSet('minerva')])
        ->and(pbForce($fakes['parser']))->toBe([]);
})->with([
    'null' => [null],
    'an empty set' => [[]],
]);

it('finalizes nothing when forced with nothing accumulated', function (): void {
    $fakes = pbParser();

    expect(pbForce($fakes['parser'], [pbSet('earlier')]))->toBe([pbSet('earlier')])
        ->and($fakes['nonAcf']->calls)->toBe([]);
});

it('builds its own parsers when given none', function (): void {
    $content = [];
    $parser = new PageBuilderParser;
    $parser->resetStaticVariables();

    pbParse($parser, pbNodes('<!-- wp:block {"ref":"78"} /-->'), 0, $content);

    expect(array_map(pbWithoutId(...), pbForce($parser)))->toBe([[
        'type' => 'uamsweb_block_reusable_content_landing_page',
        'import_wordpress_notes' => 'wp_block post ID 78',
        'enabled' => true,
    ]]);
});
