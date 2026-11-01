<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\NonAcfBlockParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\BardParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HeadingDemoter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\FormidableFormsBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\GravityFormsBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `NonAcfBlockParser` hands each block comment to the first
 * processor that claims it, appends what that processor returns to the
 * accumulator with the node count to skip, passes HTML elements and text
 * through the shortcode processor, and folds the accumulated HTML into one
 * content set.
 *
 * @command  php vendor/bin/pest tests/Integration/NonAcfBlockParserTest.php
 */
uses(StatamicTestCase::class);

// A Formidable Forms block sets a process-static flag
beforeEach(function (): void {
    (new NonAcfBlockParser)->resetStaticVariables();
});

afterEach(function (): void {
    (new NonAcfBlockParser)->resetStaticVariables();
});

/**
 * The body child nodes of an HTML document.
 *
 * @param  string  $html  The body HTML
 * @return DOMNodeList<DOMNode> The body's child nodes
 */
function nonAcfNodes(string $html): DOMNodeList
{
    $doc = new DOMDocument;
    $doc->loadHTML('<html><body>'.$html.'</body></html>', LIBXML_NOERROR);
    $body = $doc->getElementsByTagName('body')->item(0);
    \assert($body instanceof DOMElement);

    return $body->childNodes;
}

/**
 * A block processor of the given class that claims a node or not, and returns
 * a fixed result.
 *
 * @template T of object
 *
 * @param  class-string<T>  $class  The processor class
 * @param  bool  $claims  What `canProcess()` returns
 * @param  string|array<int|string, mixed>|null  $result  What `process()` returns
 * @return T&MockInterface The processor
 */
function nonAcfProcessor(string $class, bool $claims = false, string|array|null $result = null): object
{
    /** @var T&MockInterface $processor */
    $processor = Mockery::mock($class, ['canProcess' => $claims, 'process' => $result]);

    return $processor;
}

/**
 * A shortcode processor that records the content it is given and wraps it.
 *
 * @param  ?ArrayObject<int, string>  $contents  Receives each content string
 */
function nonAcfShortcodeProcessor(?ArrayObject $contents = null): ShortcodeProcessor
{
    return new class($contents ?? new ArrayObject) extends ShortcodeProcessor
    {
        /**
         * Hold the recorder.
         *
         * @param  ArrayObject<int, string>  $contents  Receives each content string
         */
        public function __construct(private readonly ArrayObject $contents) {}

        #[Override]
        public function processShortcodesInContent(
            string $content,
            string $site,
            AssetImporter $assetImporter,
            OutputInterface $output,
            array $attachments = [],
            array $terms = [],
            array $unaddressedBlocks = [],
            ?string $pageSlug = null
        ): string {
            $this->contents->append($content);

            return '['.$content.']';
        }
    };
}

/**
 * A parser built from the given processors, with every block processor that
 * is not given claiming nothing.
 *
 * @param  array<string, object>  $processors  Processors keyed by constructor parameter name
 */
function nonAcfParser(array $processors = []): NonAcfBlockParser
{
    $tablePress = $processors['tablePress'] ?? nonAcfProcessor(TablePressBlockProcessor::class);
    $gravity = $processors['gravity'] ?? nonAcfProcessor(GravityFormsBlockProcessor::class);
    $formidable = $processors['formidable'] ?? nonAcfProcessor(FormidableFormsBlockProcessor::class);
    $ninja = $processors['ninja'] ?? nonAcfProcessor(NinjaFormsBlockProcessor::class);
    $shortcodeBlock = $processors['shortcodeBlock'] ?? nonAcfProcessor(ShortcodeBlockProcessor::class);
    $shortcode = $processors['shortcode'] ?? nonAcfShortcodeProcessor();

    \assert($tablePress instanceof TablePressBlockProcessor);
    \assert($gravity instanceof GravityFormsBlockProcessor);
    \assert($formidable instanceof FormidableFormsBlockProcessor);
    \assert($ninja instanceof NinjaFormsBlockProcessor);
    \assert($shortcodeBlock instanceof ShortcodeBlockProcessor);
    \assert($shortcode instanceof ShortcodeProcessor);

    return new NonAcfBlockParser(
        shortcodeProcessor: $shortcode,
        tablePressBlockProcessor: $tablePress,
        gravityFormsProcessor: $gravity,
        formidableFormsProcessor: $formidable,
        shortcodeBlockProcessor: $shortcodeBlock,
        ninjaFormsProcessor: $ninja
    );
}

/**
 * Parse one node of a node list.
 *
 * @param  NonAcfBlockParser  $parser  The parser
 * @param  DOMNodeList<DOMNode>  $nodes  The node list
 * @param  int  $index  The node to parse
 * @param  array<int|string, mixed>  $accumulator  The accumulator, appended to
 * @return array<int|string, mixed> The parse result
 */
function nonAcfParseAt(NonAcfBlockParser $parser, DOMNodeList $nodes, int $index, array &$accumulator): array
{
    $node = $nodes->item($index);
    \assert($node instanceof DOMNode);

    return $parser->parseNode($node, $index, $nodes, $accumulator, 'default', new FakeAssetImporter, new NullOutput, [], [], [], [], 'page');
}

dataset('nonAcfFormProcessors', ['tablePress', 'gravity', 'formidable', 'ninja']);

it('appends a claiming processor\'s HTML with its skip count', function (string $key): void {
    $parser = nonAcfParser([$key => nonAcfProcessor(
        [
            'tablePress' => TablePressBlockProcessor::class,
            'gravity' => GravityFormsBlockProcessor::class,
            'formidable' => FormidableFormsBlockProcessor::class,
            'ninja' => NinjaFormsBlockProcessor::class,
        ][$key],
        true,
        ['html' => '<p>Form</p>', 'skipCount' => 3]
    )]);
    $accumulator = [];

    expect(nonAcfParseAt($parser, nonAcfNodes('<!-- wp:block {"id":5} /-->'), 0, $accumulator))
        ->toBe(['processed' => true, 'skipCount' => 3])
        ->and($accumulator)->toBe([['html' => '<p>Form</p>']]);
})->with('nonAcfFormProcessors');

it('skips one node when a claiming processor gives no usable skip count', function (string $key, array $result): void {
    $parser = nonAcfParser([$key => nonAcfProcessor(
        [
            'tablePress' => TablePressBlockProcessor::class,
            'gravity' => GravityFormsBlockProcessor::class,
            'formidable' => FormidableFormsBlockProcessor::class,
            'ninja' => NinjaFormsBlockProcessor::class,
        ][$key],
        true,
        $result
    )]);
    $accumulator = [];

    expect(nonAcfParseAt($parser, nonAcfNodes('<!-- wp:block /-->'), 0, $accumulator)['skipCount'] ?? null)->toBe(1);
})->with('nonAcfFormProcessors')->with([
    'missing' => [['html' => '<p>Form</p>']],
    'not an integer' => [['html' => '<p>Form</p>', 'skipCount' => '3']],
]);

it('leaves a block unprocessed when a claiming processor returns no HTML', function (string $key, string|array|null $result): void {
    $parser = nonAcfParser([$key => nonAcfProcessor(
        [
            'tablePress' => TablePressBlockProcessor::class,
            'gravity' => GravityFormsBlockProcessor::class,
            'formidable' => FormidableFormsBlockProcessor::class,
            'ninja' => NinjaFormsBlockProcessor::class,
        ][$key],
        true,
        $result
    )]);
    $accumulator = [];

    expect(nonAcfParseAt($parser, nonAcfNodes('<!-- wp:block /-->'), 0, $accumulator))
        ->toBe(['processed' => false, 'skipCount' => 0])
        ->and($accumulator)->toBe([]);
})->with('nonAcfFormProcessors')->with([
    'null' => [null],
    'no html key' => [['skipCount' => 3]],
    'html that is not text' => [['html' => ['<p>Form</p>'], 'skipCount' => 3]],
]);

it('leaves a block unprocessed when TablePress returns a string', function (): void {
    $accumulator = [];

    expect(nonAcfParseAt(nonAcfParser([
        'tablePress' => nonAcfProcessor(TablePressBlockProcessor::class, true, '<p>Table</p>'),
    ]), nonAcfNodes('<!-- wp:tablepress/table /-->'), 0, $accumulator))
        ->toBe(['processed' => false, 'skipCount' => 0])
        ->and($accumulator)->toBe([]);
});

it('asks the processors in order and stops at the first that claims the block', function (): void {
    $tablePress = nonAcfProcessor(TablePressBlockProcessor::class, true, ['html' => '<p>Table</p>']);
    $gravity = nonAcfProcessor(GravityFormsBlockProcessor::class, true, ['html' => '<p>Gravity</p>']);
    $accumulator = [];

    nonAcfParseAt(nonAcfParser(['tablePress' => $tablePress, 'gravity' => $gravity]), nonAcfNodes('<!-- wp:block /-->'), 0, $accumulator);

    expect($accumulator)->toBe([['html' => '<p>Table</p>']]);
    $gravity->shouldNotHaveReceived('canProcess');
});

it('passes the block comment\'s first JSON object to the processor as its attributes', function (string $comment, array $expected): void {
    $tablePress = nonAcfProcessor(TablePressBlockProcessor::class, true, ['html' => '<p>Table</p>']);
    $accumulator = [];
    $nodes = nonAcfNodes($comment);

    nonAcfParseAt(nonAcfParser(['tablePress' => $tablePress]), $nodes, 0, $accumulator);

    $tablePress->shouldHaveReceived('process', [$nodes->item(0), $expected, 'default', Mockery::type(AssetImporter::class), Mockery::type(OutputInterface::class), [], [], 'page']);
})->with([
    'an object' => ['<!-- wp:tablepress/table {"id":"3","x":true} /-->', ['id' => '3', 'x' => true]],
    'a nested object, cut at the first closing brace' => ['<!-- wp:block {"a":{"b":1}} /-->', []],
    'an object over two lines' => ["<!-- wp:block {\"id\":\n\"3\"} /-->", ['id' => '3']],
    'invalid JSON' => ['<!-- wp:block {nope} /-->', []],
    'empty braces' => ['<!-- wp:block {} /-->', []],
    'no object' => ['<!-- wp:block /-->', []],
]);

it('returns a shortcode block\'s page builder set directly', function (): void {
    $set = ['type' => 'uamsweb_block_blogroll'];
    $accumulator = [];

    $result = nonAcfParseAt(nonAcfParser([
        'shortcodeBlock' => nonAcfProcessor(ShortcodeBlockProcessor::class, true, ['page_builder_set' => $set, 'skipCount' => 4]),
    ]), nonAcfNodes('<!-- wp:shortcode -->[blogroll]<!-- /wp:shortcode -->'), 0, $accumulator);

    expect($result)->toBe(['processed' => true, 'skipCount' => 4, 'directSet' => $set])
        ->and($accumulator)->toBe([]);
});

it('treats a page builder set that is not an array as shortcode HTML', function (): void {
    $accumulator = [];

    $result = nonAcfParseAt(nonAcfParser([
        'shortcodeBlock' => nonAcfProcessor(ShortcodeBlockProcessor::class, true, ['page_builder_set' => 'x', 'html' => '<p>S</p>']),
    ]), nonAcfNodes('<!-- wp:shortcode -->[x]<!-- /wp:shortcode -->'), 0, $accumulator);

    expect($result)->toBe(['processed' => true, 'skipCount' => 3])
        ->and($accumulator)->toBe([['html' => '<p>S</p>']]);
});

it('appends shortcode output and skips through the closing shortcode comment', function (string|array $output, string $html, string $body, int $index, int $skipCount): void {
    $accumulator = [];

    $result = nonAcfParseAt(nonAcfParser([
        'shortcodeBlock' => nonAcfProcessor(ShortcodeBlockProcessor::class, true, $output),
    ]), nonAcfNodes($body), $index, $accumulator);

    expect($result)->toBe(['processed' => true, 'skipCount' => $skipCount])
        ->and($accumulator)->toBe([['html' => $html]]);
})->with([
    'a string' => ['<p>S</p>', '<p>S</p>', '<!-- wp:shortcode -->[x]<!-- /wp:shortcode --><p>After</p>', 0, 3],
    'an array with HTML' => [['html' => '<p>S</p>'], '<p>S</p>', '<!-- wp:shortcode -->[x]<!-- /wp:shortcode --><p>After</p>', 0, 3],
    'an array without HTML' => [['skipCount' => 9], '', '<!-- wp:shortcode -->[x]<!-- /wp:shortcode --><p>After</p>', 0, 3],
    'an array with non-text HTML' => [['html' => 5], '', '<!-- wp:shortcode -->[x]<!-- /wp:shortcode --><p>After</p>', 0, 3],
    'a later block' => ['<p>S</p>', '<p>S</p>', '<p>Before</p><!-- wp:shortcode -->[x]<!-- /wp:shortcode --><p>After</p>', 1, 3],
    'a closing comment with trailing space' => ['<p>S</p>', '<p>S</p>', '<!-- wp:shortcode -->[x]<!-- /wp:shortcode  --><p>After</p>', 0, 3],
    'a closing marker as a bare text node' => ['<p>S</p>', '<p>S</p>', '<!-- wp:shortcode -->/wp:shortcode<!-- /wp:shortcode --><p>After</p>', 0, 3],
    'a closing marker in text, then the comment' => ['<p>S</p>', '<p>S</p>', '<!-- wp:shortcode --><p>/wp:shortcode</p><!-- /wp:shortcode-other --><!-- /wp:shortcode --><p>After</p>', 0, 4],
    'no closing comment' => ['<p>S</p>', '<p>S</p>', '<!-- wp:shortcode -->[x]<p>After</p>', 0, 3],
]);

it('leaves a shortcode block unprocessed when it produces nothing', function (?string $output): void {
    $accumulator = [];

    expect(nonAcfParseAt(nonAcfParser([
        'shortcodeBlock' => nonAcfProcessor(ShortcodeBlockProcessor::class, true, $output),
    ]), nonAcfNodes('<!-- wp:shortcode -->[x]<!-- /wp:shortcode -->'), 0, $accumulator))
        ->toBe(['processed' => false, 'skipCount' => 0])
        ->and($accumulator)->toBe([]);
})->with([
    'null' => [null],
    'an empty string' => [''],
]);

it('hands an unclaimed block to the common processors', function (): void {
    $accumulator = [];

    $result = nonAcfParseAt(nonAcfParser(), nonAcfNodes('<!-- wp:quote --><blockquote class="wp-block-quote"><p>Said.</p></blockquote><!-- /wp:quote --><p>After</p>'), 0, $accumulator);

    expect($result['processed'] ?? null)->toBeTrue()
        ->and($result['skipCount'] ?? null)->toBe(2)
        ->and($accumulator)->toHaveCount(1)
        ->and((string) json_encode($accumulator))->toContain('Said.');
});

it('leaves a block nothing handles unprocessed', function (): void {
    $accumulator = [];

    expect(nonAcfParseAt(nonAcfParser(), nonAcfNodes('<!-- wp:unknown/block --><!-- /wp:unknown/block -->'), 0, $accumulator))
        ->toBe(['processed' => false, 'skipCount' => 0])
        ->and($accumulator)->toBe([]);
});

it('skips the formidable shortcode div inside a Formidable Forms block, and only there', function (): void {
    $parser = nonAcfParser([
        'formidable' => nonAcfProcessor(FormidableFormsBlockProcessor::class, true, ['html' => '<p>Form</p>']),
    ]);
    $contents = new ArrayObject;
    $after = nonAcfParser(['shortcode' => nonAcfShortcodeProcessor($contents)]);
    $nodes = nonAcfNodes('<!-- wp:formidable/simple-form {"formId":"5"} --><div>[formidable id="5" title=true]</div><!-- /wp:formidable/simple-form --><div>[formidable id="5"]</div>');
    $accumulator = [];

    expect(nonAcfParseAt($parser, $nodes, 0, $accumulator))->toBe(['processed' => true, 'skipCount' => 1])
        ->and(nonAcfParseAt($after, $nodes, 1, $accumulator))->toBe(['processed' => true, 'skipCount' => 1])
        ->and(nonAcfParseAt($after, $nodes, 2, $accumulator))->toBe(['processed' => true, 'skipCount' => 1])
        ->and(nonAcfParseAt($after, $nodes, 3, $accumulator))->toBe(['processed' => true, 'skipCount' => 1])
        ->and($accumulator)->toBe([['html' => '<p>Form</p>'], ['html' => '[<div>[formidable id="5"]</div>]']])
        ->and($contents->getArrayCopy())->toBe(['<div>[formidable id="5"]</div>']);
});

it('leaves a Formidable Forms block on reset', function (): void {
    $parser = nonAcfParser([
        'formidable' => nonAcfProcessor(FormidableFormsBlockProcessor::class, true, ['html' => '<p>Form</p>']),
    ]);
    $contents = new ArrayObject;
    $after = nonAcfParser(['shortcode' => nonAcfShortcodeProcessor($contents)]);
    $nodes = nonAcfNodes('<!-- wp:formidable/simple-form --><div>[formidable id="5"]</div>');
    $accumulator = [];

    nonAcfParseAt($parser, $nodes, 0, $accumulator);
    $after->resetStaticVariables();

    expect(nonAcfParseAt($after, $nodes, 1, $accumulator))->toBe(['processed' => true, 'skipCount' => 1])
        ->and($contents->getArrayCopy())->toBe(['<div>[formidable id="5"]</div>']);
});

it('passes other divs inside a Formidable Forms block through', function (string $div): void {
    $parser = nonAcfParser([
        'formidable' => nonAcfProcessor(FormidableFormsBlockProcessor::class, true, ['html' => '<p>Form</p>']),
    ]);
    $nodes = nonAcfNodes('<!-- wp:formidable/simple-form --><p>x</p>'.$div);
    $accumulator = [];

    nonAcfParseAt($parser, $nodes, 0, $accumulator);

    expect(nonAcfParseAt($parser, $nodes, 2, $accumulator))->toBe(['processed' => true, 'skipCount' => 1])
        ->and($accumulator[1] ?? null)->toBe(['html' => '['.$div.']']);
})->with([
    'another shortcode' => ['<div>[gravityform id="5"]</div>'],
    'a formidable id that is not a number' => ['<div>[formidable id="x"]</div>'],
    'text around the shortcode' => ['<div>Fill in [formidable id="5"]</div>'],
]);

it('does not skip a formidable shortcode outside a div', function (): void {
    $parser = nonAcfParser([
        'formidable' => nonAcfProcessor(FormidableFormsBlockProcessor::class, true, ['html' => '<p>Form</p>']),
    ]);
    $nodes = nonAcfNodes('<!-- wp:formidable/simple-form --><p>[formidable id="5"]</p>');
    $accumulator = [];

    nonAcfParseAt($parser, $nodes, 0, $accumulator);

    expect(nonAcfParseAt($parser, $nodes, 1, $accumulator))->toBe(['processed' => true, 'skipCount' => 1])
        ->and($accumulator[1] ?? null)->toBe(['html' => '[<p>[formidable id="5"]</p>]']);
});

it('only closes a Formidable Forms block on its own closing comment', function (string $comment, bool $processed): void {
    $accumulator = [];

    expect(nonAcfParseAt(nonAcfParser(), nonAcfNodes($comment), 0, $accumulator))
        ->toBe(['processed' => $processed, 'skipCount' => $processed ? 1 : 0]);
})->with([
    'the closing comment' => ['<!-- /wp:formidable/simple-form -->', true],
    'with trailing space' => ['<!-- /wp:formidable/simple-form  -->', true],
    'another closing comment' => ['<!-- /wp:formidable/simple-form-x -->', false],
    'text before it' => ['<!-- x /wp:formidable/simple-form -->', false],
]);

it('passes HTML elements and non-blank text through the shortcode processor', function (string $body, string $html): void {
    $contents = new ArrayObject;
    $accumulator = [];

    expect(nonAcfParseAt(nonAcfParser(['shortcode' => nonAcfShortcodeProcessor($contents)]), nonAcfNodes($body), 0, $accumulator))
        ->toBe(['processed' => true, 'skipCount' => 1])
        ->and($accumulator)->toBe([['html' => '['.$html.']']])
        ->and($contents->getArrayCopy())->toBe([$html]);
})->with([
    'an element' => ['<p>Hello [x]</p>', '<p>Hello [x]</p>'],
    'a text node' => ['Loose text', 'Loose text'],
]);

it('leaves blank text unprocessed', function (): void {
    $doc = new DOMDocument;
    $text = $doc->createTextNode("  \n ");
    $doc->appendChild($doc->createElement('body'))->appendChild($text);
    $accumulator = [];

    expect(nonAcfParser()->parseNode($text, 0, $doc->childNodes, $accumulator, 'default', new FakeAssetImporter, new NullOutput))
        ->toBe(['processed' => false, 'skipCount' => 0])
        ->and($accumulator)->toBe([]);
});

it('folds the accumulated HTML into one content set', function (): void {
    $content = [];

    $set = (new NonAcfBlockParser)->finalizeNonAcfContent([
        ['html' => '<h2>Title</h2>'],
        'not a fragment',
        ['html' => ['not text']],
        ['other' => '<p>x</p>'],
        ['html' => '<p>Body</p>'],
    ], $content, 'default', new FakeAssetImporter, new NullOutput);

    expect($set)->toBeArray();
    \assert(\is_array($set));
    expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
    unset($set['id']);

    expect($set)->toBe([
        'type' => 'uamsweb_block_content',
        'block_heading' => 'Placeholder',
        'hide_block_heading' => false,
        'background_color' => 'bg-auto',
        'body' => [
            ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body']]],
        ],
        'enabled' => true,
    ]);
});

it('runs the HTML through the link processor, the Bard parser and the heading demoter in turn', function (): void {
    /** @var LinkProcessor&MockInterface $linkProcessor */
    $linkProcessor = Mockery::mock(LinkProcessor::class, ['process' => '<p>Linked</p>']);
    /** @var BardParser&MockInterface $bardParser */
    $bardParser = Mockery::mock(BardParser::class, ['parse' => [['type' => 'parsed']]]);
    /** @var HeadingDemoter&MockInterface $headingDemoter */
    $headingDemoter = Mockery::mock(HeadingDemoter::class, ['demote' => [['type' => 'demoted']]]);
    $content = [];

    $set = (new NonAcfBlockParser($bardParser, $linkProcessor, $headingDemoter))
        ->finalizeNonAcfContent([['html' => '<p>A</p>'], ['html' => '<p>B</p>']], $content, 'default', new FakeAssetImporter, new NullOutput);

    expect($set['body'] ?? null)->toBe([['type' => 'demoted']]);
    $linkProcessor->shouldHaveReceived('process', ['<p>A</p><p>B</p>', 'default', Mockery::type(AssetImporter::class), Mockery::type(OutputInterface::class), null, null, null]);
    $bardParser->shouldHaveReceived('parse', ['<p>Linked</p>']);
    $headingDemoter->shouldHaveReceived('demote', [[['type' => 'parsed']]]);
});

it('produces no set when there is nothing to fold', function (array $fragments, array $parsed, array $demoted): void {
    /** @var BardParser&MockInterface $bardParser */
    $bardParser = Mockery::mock(BardParser::class, ['parse' => $parsed]);
    /** @var HeadingDemoter&MockInterface $headingDemoter */
    $headingDemoter = Mockery::mock(HeadingDemoter::class, ['demote' => $demoted]);
    $content = [];

    expect((new NonAcfBlockParser($bardParser, null, $headingDemoter))
        ->finalizeNonAcfContent($fragments, $content, 'default', new FakeAssetImporter, new NullOutput))->toBeNull();
})->with([
    'no fragments' => [[], [['type' => 'p']], [['type' => 'p']]],
    'blank HTML' => [[['html' => " \n "], 'x'], [['type' => 'p']], [['type' => 'p']]],
    'nothing parsed' => [[['html' => '<p>A</p>']], [], [['type' => 'p']]],
    'nothing left after demotion' => [[['html' => '<p>A</p>']], [['type' => 'p']], []],
]);
