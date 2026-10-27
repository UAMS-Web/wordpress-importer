<?php

declare(strict_types=1);

/**
 * Tests that `ContentProcessor::process()` records the page's context, routes
 * content with ACF, MinervaKB, RSS, LiveWhale or blogroll markup on pages and
 * reusable content through the page builder parser node by node, and converts
 * everything else to Bard through `TextProcessor::processForBard()`, with WpAutoP
 * only for Classic Editor content; and that it reports the blocks nothing
 * handled, and sanitizes what it returns.
 *
 * The page builder parser is a fake that records each call and returns results
 * scripted per node, the sanitizer is a fake that marks what it was given, and
 * the link processor is faked through TextProcessor's processor cache so the
 * Bard path's arguments can be read.
 *
 * @command  php vendor/bin/pest tests/Integration/ContentProcessorProcessTest.php
 */

use App\Services\BardContentSanitizer;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\PageBuilderParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

// The constructor makes the host app's sanitizer; outside the host app, load the
// pass-through stand-in the package keeps for analysis
if (! class_exists(BardContentSanitizer::class)) {
    require_once __DIR__.'/../Support/IdeStubs/App/Services/BardContentSanitizer.php';
}

/**
 * A short label for a node: its kind and its text.
 */
function contentProcessLabel(DOMNode $node): string
{
    return match (true) {
        $node instanceof DOMComment => 'comment:'.trim($node->data),
        $node instanceof DOMText => 'text:'.trim($node->textContent),
        default => $node->nodeName.':'.trim($node->textContent),
    };
}

/**
 * A page builder parser that records each call and returns scripted results.
 */
class ContentProcessFakePageBuilder extends PageBuilderParser
{
    /**
     * Each call, in order: the method, and for parseNode() the index and label.
     *
     * @var list<array<int|string, mixed>>
     */
    public array $calls = [];

    /**
     * The result parseNode() returns, by node label.
     *
     * @var array<string, array<int|string, mixed>>
     */
    public array $results = [];

    /**
     * libxml's internal-errors mode, buffered error count and last error at each
     * parseNode() call.
     *
     * @var list<array{internal: bool, errors: int, last: ?string}>
     */
    public array $libxml = [];

    /**
     * What forceFinalization() appends to the content.
     *
     * @var list<mixed>
     */
    public array $finalized = [['type' => 'finalized']];

    /**
     * Build the fake; its parse methods are overridden, so the real parsers the
     * parent builds are never used.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Record the reset.
     */
    public function resetStaticVariables(): void
    {
        $this->calls[] = ['reset'];
    }

    /**
     * Record the call, append the node's label to the content, and return the
     * scripted result.
     *
     * @param  DOMNodeList<DOMNode>  $nodes
     * @param  array<int|string, mixed>  $content
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     * @param  array<int|string, mixed>  $items
     * @param  array<int|string, mixed>  $unaddressedBlocks
     * @return array<int|string, mixed>
     */
    public function parseNode(
        DOMNode $node,
        int $index,
        DOMNodeList $nodes,
        array &$content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        array $items = [],
        array &$unaddressedBlocks = [],
        ?string $pageSlug = null
    ): array {
        $label = contentProcessLabel($node);
        $this->calls[] = ['parse', $index, $label];
        $last = libxml_get_last_error();
        $this->libxml[] = ['internal' => libxml_use_internal_errors(), 'errors' => count(libxml_get_errors()), 'last' => $last instanceof LibXMLError ? trim($last->message) : null];
        $content[] = ['type' => 'parsed', 'node' => $label];

        return $this->results[$label] ?? [];
    }

    /**
     * Record the call and append the finalized sets.
     *
     * @param  array<int|string, mixed>  $content
     */
    public function forceFinalization(
        array &$content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): void {
        $this->calls[] = ['finalize'];

        foreach ($this->finalized as $set) {
            $content[] = $set;
        }
    }
}

/**
 * A shortcode processor that reports scripted unaddressed nodes.
 */
class ContentProcessFakeShortcodes extends ShortcodeProcessor
{
    /**
     * The unaddressed nodes to report.
     *
     * @var array<string, array<array{id: string, slug: ?string, link: string, original: string}>>
     */
    public array $unaddressed = [];

    /**
     * Return the scripted nodes.
     *
     * @return array<string, array<array{id: string, slug: ?string, link: string, original: string}>>
     */
    public function getUnaddressedNodes(): array
    {
        return $this->unaddressed;
    }
}

/**
 * A link processor that records each call and returns the HTML unchanged.
 */
class ContentProcessFakeLinks extends LinkProcessor
{
    /**
     * The HTML and context of every call.
     *
     * @var list<array{html: string, site: string, entry: ?string, collection: ?string, xml: ?string}>
     */
    public array $calls = [];

    /**
     * Record the call and return the HTML unchanged.
     */
    public function process(
        string $html,
        string $statamicSiteHandle,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $statamicEntryId = null,
        ?string $statamicEntryCollectionHandle = null,
        ?string $xmlPath = null
    ): string {
        $this->calls[] = ['html' => $html, 'site' => $statamicSiteHandle, 'entry' => $statamicEntryId, 'collection' => $statamicEntryCollectionHandle, 'xml' => $xmlPath];

        return $html;
    }
}

/**
 * A sanitizer that appends a marker to every Bard list it is given, or returns
 * null when told to.
 */
class ContentProcessFakeSanitizer
{
    /**
     * Whether sanitize() returns null.
     */
    public bool $returnNull = false;

    /**
     * Return the list with a marker appended, or null.
     *
     * @param  array<int|string, mixed>  $field
     * @return ?array<int|string, mixed>
     */
    public function sanitize(array $field): ?array
    {
        return $this->returnNull ? null : [...$field, 'sanitized'];
    }
}

/**
 * The fakes for one test.
 *
 * @return array{processor: ContentProcessor, builder: ContentProcessFakePageBuilder, shortcodes: ContentProcessFakeShortcodes, links: ContentProcessFakeLinks, sanitizer: ContentProcessFakeSanitizer}
 */
function contentProcessFakes(): array
{
    $builder = new ContentProcessFakePageBuilder;
    $shortcodes = new ContentProcessFakeShortcodes;
    $links = new ContentProcessFakeLinks;
    $sanitizer = new ContentProcessFakeSanitizer;

    $processor = new ContentProcessor($builder, $shortcodes);
    (new ReflectionProperty(ContentProcessor::class, 'bardContentSanitizer'))->setValue($processor, $sanitizer);

    (new ReflectionProperty(TextProcessor::class, 'cachedProcessors'))->setValue(null, [
        'bardParser' => new BardParser,
        'linkProcessor' => $links,
        'wpAutoP' => new WpAutoP,
        'shortcodeProcessor' => new ShortcodeProcessor,
        'imageProcessor' => new ImageProcessor,
    ]);

    return ['processor' => $processor, 'builder' => $builder, 'shortcodes' => $shortcodes, 'links' => $links, 'sanitizer' => $sanitizer];
}

/**
 * An XML parser whose file path is the given one.
 */
function contentProcessXml(string $path = '/exports/site.xml'): XmlParser
{
    $parser = new XmlParser;
    (new ReflectionProperty(XmlParser::class, 'filePaths'))->setValue($parser, [$path]);

    return $parser;
}

/**
 * Run process() over the content.
 *
 * @param  array<int|string, mixed>  $data
 * @return array{content: mixed, page_builder: array<int|string, mixed>}
 */
function contentProcess(ContentProcessor $processor, string $content, string $collection = 'posts', array $data = [], ?string $slug = 'the-page'): array
{
    return $processor->process($content, 'default', app(AssetImporter::class), new BufferedOutput, contentProcessXml(), 'Title', '42', 'https://example.com/the-page', [], [], [], $data, $collection, $slug, 'entry-1');
}

/**
 * The parse calls a fake page builder recorded, without the reset and finalize calls.
 *
 * @return list<array<int|string, mixed>>
 */
function contentProcessParses(ContentProcessFakePageBuilder $builder): array
{
    return array_values(array_filter($builder->calls, static fn (array $call): bool => ($call[0] ?? null) === 'parse'));
}

/**
 * Record each warning logged during a test.
 *
 * @return ArrayObject<int, array{message: string, context: array<int|string, mixed>}> The warnings, in order
 */
function contentProcessWarnings(): ArrayObject
{
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings->append(['message' => $message, 'context' => $context]);
        }
    );
    Log::shouldReceive('info', 'debug', 'notice', 'error')->andReturnNull();

    return $warnings;
}

beforeEach(function (): void {
    config()->set('statamic.bard_texstyle.attributes', ['paragraph' => ['id' => []]]);
});

afterEach(function (): void {
    (new ReflectionProperty(TextProcessor::class, 'cachedProcessors'))->setValue(null, []);
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ProcessingContext::clearCollectionHandle();
    ProcessingContext::clearStatamicEntryId();
    ProcessingContext::clearWordPressPostId();
});

it('records the page\'s context and returns nothing for empty content', function (): void {
    $fakes = contentProcessFakes();

    $result = contentProcess($fakes['processor'], " \n\t ", 'posts', ['blueprint' => 'post']);

    expect($result)->toBe(['content' => [], 'page_builder' => []])
        ->and($fakes['builder']->calls)->toBe([['reset']])
        ->and($fakes['links']->calls)->toBe([])
        ->and(ProcessingContext::getXmlPath())->toBe('/exports/site.xml')
        ->and(ProcessingContext::getWordPressPageLink())->toBe('https://example.com/the-page')
        ->and(ProcessingContext::getEntryData())->toBe(['blueprint' => 'post', 'post_name' => 'the-page'])
        ->and(ProcessingContext::getCollectionHandle())->toBe('posts')
        ->and(ProcessingContext::getStatamicEntryId())->toBe('entry-1')
        ->and(ProcessingContext::getWordPressPostId())->toBe('42');
});

it('names the post after its slug, its title, its id, or nothing, unless it already has a name', function (array $data, ?string $slug, string $title, string $id, string $name): void {
    $fakes = contentProcessFakes();

    $fakes['processor']->process('', 'default', app(AssetImporter::class), new BufferedOutput, contentProcessXml(), $title, $id, '', [], [], [], $data, 'posts', $slug);

    expect(ProcessingContext::getEntryData()['post_name'] ?? null)->toBe($name);
})->with([
    'a slug' => [[], 'slug', 'Title', '7', 'slug'],
    'a title' => [[], null, 'Title', '7', 'Title'],
    'an id' => [[], null, '', '7', '7'],
    'nothing' => [[], null, '', '', 'unknown'],
    'a name already' => [['post_name' => 'kept'], 'slug', 'Title', '7', 'kept'],
]);

it('records empty context when called with only the required arguments', function (): void {
    $fakes = contentProcessFakes();

    $fakes['processor']->process('<p>A</p>', 'default', app(AssetImporter::class), new BufferedOutput, contentProcessXml());

    expect(ProcessingContext::getEntryData())->toBe(['post_name' => 'unknown'])
        ->and(ProcessingContext::getWordPressPageLink())->toBe('')
        ->and(ProcessingContext::getWordPressPostId())->toBe('')
        ->and(ProcessingContext::getCollectionHandle())->toBe('')
        ->and($fakes['links']->calls)->toBe([['html' => '<p>A</p>', 'site' => 'default', 'entry' => null, 'collection' => '', 'xml' => '/exports/site.xml']]);
});

it('converts Classic Editor content to Bard with WpAutoP, passing the page\'s context along', function (): void {
    $fakes = contentProcessFakes();

    $result = contentProcess($fakes['processor'], "One\n\nTwo");

    expect($fakes['links']->calls)->toBe([['html' => "<p>One</p>\n<p>Two</p>\n", 'site' => 'default', 'entry' => 'entry-1', 'collection' => 'posts', 'xml' => '/exports/site.xml']])
        ->and($result)->toBe([
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'One']], 'attrs' => ['id' => null]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Two']], 'attrs' => ['id' => null]],
                'sanitized',
            ],
            'page_builder' => [],
        ])
        ->and($fakes['builder']->calls)->toBe([['reset']]);
});

it('applies no WpAutoP to block-editor content or to ACF content outside the page builder', function (string $content): void {
    $fakes = contentProcessFakes();

    contentProcess($fakes['processor'], $content);

    expect($fakes['links']->calls[0]['html'] ?? null)->toBe($content);
})->with([
    'a block comment' => ["<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->\n\nTwo"],
    'a block comment after text' => ["One\n\nTwo<!-- wp:separator /-->"],
    'an ACF block in a post' => ["<!-- wp:acf/thing {\"name\":\"acf/thing\"} /-->One\n\nTwo"],
]);

it('reads an ordinary comment as Classic Editor content', function (): void {
    $fakes = contentProcessFakes();

    contentProcess($fakes['processor'], "<!-- note -->One\n\nTwo");

    expect($fakes['links']->calls[0]['html'] ?? null)->toBe("<p><!-- note -->One</p>\n<p>Two</p>\n");
});

it('leaves block comments for the block processors in Bard content', function (): void {
    $fakes = contentProcessFakes();

    $result = contentProcess($fakes['processor'], '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Q</p></blockquote><!-- /wp:quote -->');

    $first = is_array($result['content']) ? ($result['content'][0] ?? null) : null;
    $attrs = is_array($first) ? ($first['attrs'] ?? null) : null;
    $values = is_array($attrs) ? ($attrs['values'] ?? null) : null;

    expect(is_array($values) ? ($values['type'] ?? null) : null)->toBe('pull_quote');
});

it('converts nothing for an ACF page in a collection named page', function (): void {
    $fakes = contentProcessFakes();

    $result = contentProcess($fakes['processor'], '<!-- wp:acf/thing {"name":"acf/thing"} /--><p>A</p>', 'page');

    expect($result)->toBe(['content' => ['sanitized'], 'page_builder' => []])
        ->and($fakes['links']->calls)->toBe([]);
});

it('returns empty Bard content when the sanitizer returns nothing', function (): void {
    $fakes = contentProcessFakes();
    $fakes['sanitizer']->returnNull = true;

    expect(contentProcess($fakes['processor'], '<p>A</p>')['content'])->toBe([]);
});

it('parses page builder content for a page or reusable content that carries a page builder block', function (string $content, string $collection, array $data): void {
    $fakes = contentProcessFakes();

    $result = contentProcess($fakes['processor'], $content, $collection, $data);

    expect($fakes['links']->calls)->toBe([])
        ->and($result['content'])->toBe([])
        ->and(contentProcessParses($fakes['builder']))->not->toBe([]);
})->with([
    'an ACF block' => ['<!-- wp:acf/thing {"name":"acf/thing"} /-->', 'pages', []],
    'an ACF block with spaces' => ['<!--   wp:acf/thing /-->', 'reusable_content', []],
    'a MinervaKB block' => ['<!-- wp:minervakb/search /-->', 'pages', []],
    'an RSS block' => ['<!-- wp:rss {"feedURL":"x"} /-->', 'pages', []],
    'a LiveWhale widget' => ['<div class="lwcw" data-options="id=12|x">W</div>', 'pages', []],
    'a LiveWhale widget, single-quoted' => ["<div class='lwcw' data-options='a=1 id=3'>W</div>", 'pages', []],
    'a blogroll shortcode' => ['[blogroll category="news"]', 'pages', []],
    'a landing page' => ['<p>A</p>', 'pages', ['blueprint' => 'landing_page']],
    'reusable content with the landing blueprint' => ['<p>A</p>', 'reusable_content', ['blueprint' => 'reusable_content_landing_page']],
]);

it('converts to Bard what carries no page builder block, or sits outside pages and reusable content', function (string $content, string $collection, array $data): void {
    $fakes = contentProcessFakes();

    contentProcess($fakes['processor'], $content, $collection, $data);

    expect($fakes['links']->calls)->toHaveCount(1)
        ->and(contentProcessParses($fakes['builder']))->toBe([]);
})->with([
    'a LiveWhale class without an id' => ['<div class="lwcw" data-options="x=1">W</div>', 'pages', []],
    'an id without the LiveWhale class' => ['<div class="other" data-options="id=12">W</div>', 'pages', []],
    'a blogroll shortcode without attributes' => ['[blogroll]', 'pages', []],
    'an ACF block in a post' => ['<!-- wp:acf/thing /-->', 'posts', []],
    'a landing page outside pages' => ['<p>A</p>', 'posts', ['blueprint' => 'landing_page']],
    'reusable content with another blueprint' => ['<p>A</p>', 'reusable_content', ['blueprint' => 'reusable_content']],
    'the reusable landing blueprint outside reusable content' => ['<p>A</p>', 'pages', ['blueprint' => 'reusable_content_landing_page']],
]);

it('parses each page builder node, moving past as many as each result says', function (): void {
    $fakes = contentProcessFakes();
    $fakes['builder']->results = [
        'comment:wp:acf/a /' => ['processed' => true, 'skipCount' => 2],
        'p:B' => ['processed' => true, 'skipCount' => 'two'],
        'p:C' => ['processed' => true, 'skipCount' => 0],
    ];

    contentProcess($fakes['processor'], '<!-- wp:acf/a /--><p>Skipped</p><p>B</p><p>C</p><p>D</p>', 'pages');

    expect(contentProcessParses($fakes['builder']))->toBe([
        ['parse', 0, 'comment:wp:acf/a /'],
        ['parse', 2, 'p:B'],
        ['parse', 3, 'p:C'],
        ['parse', 4, 'p:D'],
        ['parse', 4, 'p:D'],
    ]);
});

it('warns about and records a block nothing processed, and leaves the blocks Tiptap handles alone', function (): void {
    $fakes = contentProcessFakes();
    $warnings = contentProcessWarnings();
    $fakes['builder']->results = ['comment:wp:acf/a /' => ['processed' => true], 'comment:wp:paragraph' => ['skipCount' => 2]];

    contentProcess($fakes['processor'], '<!-- wp:acf/a /--><!-- wp:custom/thing {"a":1} --><!-- wp:paragraph --><p>Q</p><!-- wp:heading --><!-- wp:list --><!-- wp:separator /--><!-- /wp:custom/thing --><p>End</p>', 'pages');

    expect($warnings->getArrayCopy())->toBe([[
        'message' => "ContentProcessor::process: Unaddressed block 'custom/thing' found in page 'the-page' for site 'default' (from entry: https://example.com/the-page)",
        'context' => ['site' => 'default', 'page_slug' => 'the-page', 'block_type' => 'custom/thing', 'node_value' => ' wp:custom/thing {"a":1} ', 'entry_url' => 'https://example.com/the-page'],
    ]])
        ->and($fakes['processor']->getUnaddressedNodes())->toBe(['custom/thing' => [['id' => '42', 'slug' => 'the-page', 'link' => 'https://example.com/the-page']]])
        ->and(array_column(contentProcessParses($fakes['builder']), 1))->toBe([0, 1, 2, 4, 5, 6, 7, 8, 8]);
});

it('does not warn about a block the page builder parser processed', function (): void {
    $fakes = contentProcessFakes();
    $warnings = contentProcessWarnings();
    $fakes['builder']->results = ['comment:wp:acf/a /' => ['processed' => true], 'comment:wp:custom/thing' => ['processed' => true]];

    contentProcess($fakes['processor'], '<!-- wp:acf/a /--><!-- wp:custom/thing --><p>End</p>', 'pages');

    expect($warnings->getArrayCopy())->toBe([])
        ->and($fakes['processor']->getUnaddressedNodes())->toBe([]);
});

it('cuts the block in an unaddressed-block warning to its first 100 characters', function (int $length, string $suffix): void {
    $fakes = contentProcessFakes();
    $warnings = contentProcessWarnings();
    $comment = ' wp:custom/x '.str_repeat('y', $length - 13);
    $fakes['builder']->results = ['comment:wp:acf/a /' => ['processed' => true]];

    contentProcess($fakes['processor'], "<!-- wp:acf/a /--><!--{$comment}--><p>End</p>", 'pages');

    expect($warnings[0]['context']['node_value'] ?? null)->toBe(substr($comment, 0, 100).$suffix);
})->with([
    'exactly 100' => [100, ''],
    'over 100' => [101, '...'],
]);

it('finishes the page builder content once, without parsing the last node again when it was a block', function (string $last, array $finalParse, int $finalizations): void {
    $fakes = contentProcessFakes();

    $result = contentProcess($fakes['processor'], "<p>First</p>{$last}", 'pages', ['blueprint' => 'landing_page']);

    $parses = contentProcessParses($fakes['builder']);
    $finalize = array_values(array_filter($fakes['builder']->calls, static fn (array $call): bool => ($call[0] ?? null) === 'finalize'));

    expect(array_slice($parses, 2))->toBe($finalParse)
        ->and(count($finalize))->toBe($finalizations)
        ->and($result['page_builder'][count($result['page_builder']) - 1] ?? null)->toBe(['type' => 'finalized']);
})->with([
    'an ACF block' => ['<!-- wp:acf/a /-->', [], 1],
    'a TablePress block' => ['<!-- wp:tablepress/table {"id":"1"} /-->', [], 1],
    'a Gravity Forms block' => ['<!-- wp:gravityforms/form /-->', [], 1],
    'a Formidable block' => ['<!-- wp:formidable/simple-form /-->', [], 1],
    'a shortcode block' => ['<!-- wp:shortcode -->', [], 1],
    'a reusable block' => ['<!-- wp:block {"ref":1} /-->', [], 1],
    'a MinervaKB block' => ['<!-- wp:minervakb/search /-->', [], 1],
    'an RSS block' => ['<!-- wp:rss /-->', [], 1],
    'a Gravity Forms shortcode' => ['[gravityform id="3" title="false"]', [], 1],
    'a Formidable shortcode' => ["[formidable id='4']", [], 1],
    'an element' => ['<p>Last</p>', [['parse', 1, 'p:Last']], 1],
    'another block' => ['<!-- wp:custom/x /-->', [['parse', 1, 'comment:wp:custom/x /']], 1],
    'other text' => ['plain [gallery]', [['parse', 1, 'text:plain [gallery]']], 1],
    'an element holding a form shortcode' => ['<p>[gravityform id="3"]</p>', [['parse', 1, 'p:[gravityform id="3"]']], 1],
]);

it('only finishes page builder content with no nodes', function (): void {
    $fakes = contentProcessFakes();

    // A stray closing tag leaves the body empty
    $result = contentProcess($fakes['processor'], '</p>', 'pages', ['blueprint' => 'landing_page']);

    expect($fakes['builder']->calls)->toBe([['reset'], ['finalize']])
        ->and($result['page_builder'])->toBe([['type' => 'finalized']]);
});

it('parses page builder content with libxml warnings visible and the load\'s errors cleared', function (): void {
    $fakes = contentProcessFakes();
    $internal = libxml_use_internal_errors(true);

    try {
        // A misplaced <html> is an error on every libxml build measured
        contentProcess($fakes['processor'], 'x<html>y', 'pages', ['blueprint' => 'landing_page']);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($internal);
    }

    expect($fakes['builder']->libxml[0] ?? null)->toBe(['internal' => false, 'errors' => 0, 'last' => null]);
});

it('keeps text nested past libxml\'s default depth in page builder content', function (): void {
    $fakes = contentProcessFakes();

    contentProcess($fakes['processor'], '<p>'.str_repeat('<b>', 300).'deep'.str_repeat('</b>', 300).'</p>', 'pages', ['blueprint' => 'landing_page']);

    expect(contentProcessParses($fakes['builder'])[0][2] ?? null)->toBe('p:deep');
});

it('keeps text nested past libxml\'s default depth in content with block comments', function (): void {
    $fakes = contentProcessFakes();

    contentProcess($fakes['processor'], '<!-- wp:acf/a /-->'.str_repeat('<b>', 300).'deep'.str_repeat('</b>', 300), 'pages');

    expect(contentProcessParses($fakes['builder'])[1][2] ?? null)->toBe('b:deep');
});

it('sanitizes the Bard fields of each page builder set, and of the sets nested in them', function (): void {
    $fakes = contentProcessFakes();
    $fakes['builder']->finalized = [
        'loose',
        [
            'type' => 'block',
            'body' => [['type' => 'paragraph']],
            'text' => [['type' => 'text']],
            'description' => [['type' => 'd']],
            'other' => [['type' => 'o']],
            'content' => [['type' => 'inner', 'text' => [['type' => 't']]]],
        ],
        ['type' => 'block', 'body' => 'not a field', 'content' => 'not content'],
    ];

    $result = contentProcess($fakes['processor'], '</p>', 'pages', ['blueprint' => 'landing_page']);

    expect($result['page_builder'])->toBe([
        'loose',
        [
            'type' => 'block',
            'body' => [['type' => 'paragraph'], 'sanitized'],
            'text' => [['type' => 'text'], 'sanitized'],
            'description' => [['type' => 'd'], 'sanitized'],
            'other' => [['type' => 'o']],
            'content' => [['type' => 'inner', 'text' => [['type' => 't'], 'sanitized']], 'sanitized'],
        ],
        ['type' => 'block', 'body' => 'not a field', 'content' => 'not content'],
    ]);
});

it('reports its unaddressed blocks together with the shortcode processor\'s', function (): void {
    $fakes = contentProcessFakes();
    $fakes['builder']->results = ['comment:wp:acf/a /' => ['processed' => true]];
    $fakes['shortcodes']->unaddressed = [
        'custom/thing' => [['id' => '9', 'slug' => 'other', 'link' => 'l', 'original' => '[x]']],
        'shortcode' => [['id' => '9', 'slug' => 'other', 'link' => 'l', 'original' => '[y]']],
    ];

    contentProcess($fakes['processor'], '<!-- wp:acf/a /--><!-- wp:custom/thing --><p>End</p>', 'pages');

    expect($fakes['processor']->getUnaddressedNodes())->toBe([
        'custom/thing' => [
            ['id' => '42', 'slug' => 'the-page', 'link' => 'https://example.com/the-page'],
            ['id' => '9', 'slug' => 'other', 'link' => 'l', 'original' => '[x]'],
        ],
        'shortcode' => [['id' => '9', 'slug' => 'other', 'link' => 'l', 'original' => '[y]']],
    ]);
});

it('reports none of the load\'s warnings to an error handler', function (): void {
    $fakes = contentProcessFakes();
    $calls = 0;
    set_error_handler(static function () use (&$calls): bool {
        $calls++;

        return true;
    });

    try {
        contentProcess($fakes['processor'], 'x<html>y', 'pages', ['blueprint' => 'landing_page']);
    } finally {
        restore_error_handler();
    }

    expect($calls)->toBe(0);
});
