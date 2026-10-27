<?php

declare(strict_types=1);

/**
 * Tests that `TextProcessor::processForBard()` turns a post's HTML into Bard
 * content: the InnerBlocks and empty-content paths, the shortcode-only shortcut,
 * the `data-ccp-props` span strip, Classic Editor paragraphs, early shortcode
 * replacement and the blocks it leaves alone, the block-processor loop and each
 * result shape it handles, the `wp:image` and `<figure>`/`<img>` paths, the empty
 * paragraph filter, and the Texstyle attribute mapping at the end.
 *
 * The link, image and shortcode processors are replaced through the class's
 * processor cache with recording fakes, so each test sees what the coordinator
 * handed them; the Bard parser, `WpAutoP` and the block processors are the real
 * ones. The cache is emptied after each test so no fake outlives it.
 *
 * @command  php vendor/bin/pest tests/Integration/TextProcessorForBardTest.php
 */

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A link processor that records each call and returns the HTML unchanged.
 */
class TextProcessorForBardLinkProcessor extends LinkProcessor
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
 * An image processor that records each call and returns a set naming its source.
 */
class TextProcessorForBardImageProcessor extends ImageProcessor
{
    /**
     * The HTML and block attributes of every call.
     *
     * @var list<array{html: string, attributes: ?array<int|string, mixed>}>
     */
    public array $calls = [];

    /**
     * Record the call and return an image set carrying the image's `src`, or
     * nothing for a source named `none.jpg` or `empty.jpg`.
     *
     * @param  array<int|string, mixed>  $attachments
     * @param  ?array<int|string, mixed>  $blockAttributes
     * @return ?array<int|string, mixed>
     */
    public function processImage(
        string $html,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?string $pageSlug = null,
        ?array $blockAttributes = null
    ): ?array {
        $this->calls[] = ['html' => $html, 'attributes' => $blockAttributes];

        if (str_contains($html, 'none.jpg')) {
            return null;
        }

        if (str_contains($html, 'empty.jpg')) {
            return [];
        }

        return ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'src' => preg_match('/src="([^"]*)"/', $html, $m) === 1 ? $m[1] : '']]];
    }
}

/**
 * A shortcode processor whose conversions name the shortcode they were given.
 */
class TextProcessorForBardShortcodeProcessor extends ShortcodeProcessor
{
    /**
     * The shortcode names toTiptapHtml() was called with, in order.
     *
     * @var list<string>
     */
    public array $converted = [];

    /**
     * The content and page slug processShortcodesInContent() was called with.
     *
     * @var list<array{content: string, slug: ?string}>
     */
    public array $inContent = [];

    /**
     * What toTiptapHtml() returns for a shortcode name, in place of a paragraph.
     *
     * @var array<string, string>
     */
    public array $replacements = [];

    /**
     * What processShortcodesInContent() returns, or null to return a paragraph.
     */
    public ?string $inContentResult = null;

    /**
     * What extract() returns, or null to extract the content's shortcodes.
     *
     * @var ?list<mixed>
     */
    public ?array $extracted = null;

    /**
     * Return the configured shortcodes, or the content's own.
     *
     * @return array<int|string, mixed>
     */
    public function extract(
        string $content,
        OutputInterface $output
    ): array {
        return $this->extracted ?? parent::extract($content, $output);
    }

    /**
     * Return a paragraph naming the shortcode.
     *
     * @param  array<int|string, mixed>  $shortcode
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     */
    public function toTiptapHtml(
        array $shortcode,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): string {
        $name = is_string($shortcode['name'] ?? null) ? $shortcode['name'] : '';
        $this->converted[] = $name;

        return $this->replacements[$name] ?? "<p>Converted {$name}</p>";
    }

    /**
     * Record the call and return the configured result.
     *
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     * @param  array<int|string, mixed>  $unaddressedBlocks
     */
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
        $this->inContent[] = ['content' => $content, 'slug' => $pageSlug];

        return $this->inContentResult ?? "<p>Shortcode {$content}</p>";
    }
}

/**
 * The fakes installed for the current test.
 *
 * @return array{link: TextProcessorForBardLinkProcessor, image: TextProcessorForBardImageProcessor, shortcode: TextProcessorForBardShortcodeProcessor}
 */
function textProcessorForBardFakes(): array
{
    $fakes = [
        'link' => new TextProcessorForBardLinkProcessor,
        'image' => new TextProcessorForBardImageProcessor,
        'shortcode' => new TextProcessorForBardShortcodeProcessor,
    ];

    (new ReflectionProperty(TextProcessor::class, 'cachedProcessors'))->setValue(null, [
        'bardParser' => new BardParser,
        'linkProcessor' => $fakes['link'],
        'wpAutoP' => new WpAutoP,
        'shortcodeProcessor' => $fakes['shortcode'],
        'imageProcessor' => $fakes['image'],
    ]);

    return $fakes;
}

/**
 * Run processForBard() over the HTML with the fakes installed.
 *
 * @return array<int|string, mixed>
 */
function textProcessorForBard(string $html, bool $autoP = false, bool $innerBlocks = false): array
{
    return TextProcessor::processForBard($html, 'default', app(AssetImporter::class), new BufferedOutput, $autoP, $innerBlocks);
}

beforeEach(function (): void {
    config()->set('statamic.bard_texstyle.attributes', ['paragraph' => ['id' => []], 'heading' => ['id' => []]]);
});

afterEach(function (): void {
    (new ReflectionProperty(TextProcessor::class, 'cachedProcessors'))->setValue(null, []);
});

/**
 * The value at the given path of nested arrays, or null where the path ends early.
 *
 * @param  list<int|string>  $path
 */
function textProcessorForBardPath(mixed $value, array $path): mixed
{
    foreach ($path as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return null;
        }

        $value = $value[$key];
    }

    return $value;
}

/**
 * Record each warning this class logs, leaving out the block processors' own.
 *
 * @return ArrayObject<int, array{message: string, context: array<int|string, mixed>}> The warnings, in order
 */
function textProcessorForBardWarnings(): ArrayObject
{
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            if (str_starts_with($message, 'TextProcessor::')) {
                $warnings->append(['message' => $message, 'context' => $context]);
            }
        }
    );
    Log::shouldReceive('info', 'debug', 'notice', 'error')->andReturnNull();

    return $warnings;
}

/**
 * A paragraph node as mapped, with the given text and id.
 *
 * @return array<string, mixed>
 */
function textProcessorForBardParagraph(string $text, ?string $id = null): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]], 'attrs' => ['id' => $id]];
}

/**
 * The image set the fake image processor returns for a source.
 *
 * @return array<string, mixed>
 */
function textProcessorForBardImage(string $src): array
{
    return ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'src' => $src]]];
}

it('parses paragraphs and maps their Texstyle attributes', function (): void {
    textProcessorForBardFakes();

    expect(textProcessorForBard('<p id="intro">Hello</p><p>World</p>'))
        ->toBe([textProcessorForBardParagraph('Hello', 'intro'), textProcessorForBardParagraph('World')]);
});

it('applies neither WpAutoP nor InnerBlocks handling by default', function (): void {
    textProcessorForBardFakes();

    $quote = TextProcessor::processForBard(
        '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Q</p></blockquote><!-- /wp:quote -->',
        'default',
        app(AssetImporter::class),
        new BufferedOutput
    );
    $text = TextProcessor::processForBard("One\n\nTwo", 'default', app(AssetImporter::class), new BufferedOutput);

    // The quote comment reached the block loop, and the text was not split into paragraphs
    expect(textProcessorForBardPath($quote, [0, 'attrs', 'values', 'type']))->toBe('pull_quote')
        ->and($text)->toBe([['type' => 'text', 'text' => 'OneTwo', 'attrs' => []]]);
});

it('strips block comments from InnerBlocks content before anything reads them', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard('<!-- wp:spacer --><div class="wp-block-spacer"></div><!-- /wp:spacer --><p>X</p>', innerBlocks: true);

    expect($content)->toBe([textProcessorForBardParagraph('X')])
        ->and(array_column($fakes['link']->calls, 'html'))->toBe(['<div class="wp-block-spacer"></div><p>X</p>']);
});

it('returns nothing for content that is only whitespace, without processing it', function (): void {
    $fakes = textProcessorForBardFakes();

    expect(textProcessorForBard(" \n\t "))->toBe([])
        ->and($fakes['link']->calls)->toBe([]);
});

it('trims the content before processing it', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard("  <p>A</p>\n  ");

    expect(array_column($fakes['link']->calls, 'html'))->toBe(['<p>A</p>']);
});

it('converts content that is only a shortcode block or a bare form shortcode directly', function (string $html, string $inner): void {
    $fakes = textProcessorForBardFakes();

    expect(textProcessorForBard($html))->toBe([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Shortcode {$inner}"]]]])
        ->and($fakes['shortcode']->inContent)->toBe([['content' => $inner, 'slug' => null]])
        ->and($fakes['link']->calls)->toBe([]);
})->with([
    'a shortcode block' => ["\n<!-- wp:shortcode -->\n  [gravityform id=\"3\"]  \n<!-- /wp:shortcode -->\n", '[gravityform id="3"]'],
    'a bare Gravity Forms shortcode' => ['  [gravityform id="3" title="false"]  ', '[gravityform id="3" title="false"]'],
    'a bare Formidable shortcode' => ["[formidable id='12']", "[formidable id='12']"],
    'a bare Ninja Forms shortcode' => ['[ninja_form id=7]', '[ninja_form id=7]'],
]);

it('passes the page slug to a shortcode-only conversion', function (): void {
    $fakes = textProcessorForBardFakes();

    TextProcessor::processForBard('[ninja_form id=7]', 'default', app(AssetImporter::class), new BufferedOutput, pageSlug: 'contact');

    expect($fakes['shortcode']->inContent)->toBe([['content' => '[ninja_form id=7]', 'slug' => 'contact']]);
});

it('falls back to the full parse when a shortcode-only conversion yields nothing new', function (?string $result): void {
    $fakes = textProcessorForBardFakes();
    $fakes['shortcode']->inContentResult = $result;

    $content = textProcessorForBard('[gravityform id="3"]');

    // The full parse converts the shortcode through toTiptapHtml() instead
    expect($fakes['link']->calls)->toHaveCount(1)
        ->and($fakes['shortcode']->converted)->toBe(['gravityform'])
        ->and($content)->toBe([textProcessorForBardParagraph('Converted gravityform')]);

})->with([
    'an empty conversion' => [''],
    'the shortcode unchanged' => ['[gravityform id="3"]'],
    'markup that parses to nothing' => ['<!-- nothing -->'],
]);

it('does not take the shortcut for a shortcode block that is empty, or one form shortcode among other text', function (string $html): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard($html);

    expect($fakes['shortcode']->inContent)->toBe([])
        ->and($fakes['link']->calls)->toHaveCount(1);
})->with([
    'an empty shortcode block' => ['<!-- wp:shortcode --><!-- /wp:shortcode --><p>P</p>'],
    'a form shortcode with text after it' => ['[gravityform id="3"] and more'],
    'a form shortcode without an id' => ['[gravityform title="x"]'],
    'another shortcode alone' => ['[unknown_thing id="3"]'],
]);

it('unwraps data-ccp-props spans and keeps their text', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard('<p><SPAN data-ccp-props="{}" >Hi</SPAN> there <span data-ccp-props="">you</span><span class="keep">!</span></p>');

    expect(array_column($fakes['link']->calls, 'html'))->toBe(['<p>Hi there you<span class="keep">!</span></p>'])
        ->and($content)->toBe([textProcessorForBardParagraph('Hi there you!')]);
});

it('applies WpAutoP to Classic Editor content, decoding the entities it reads', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard("One &amp;#65; &amp;apos;\n\nTwo", autoP: true);

    expect(array_column($fakes['link']->calls, 'html'))->toBe(["<p>One A '</p>\n<p>Two</p>\n"])
        ->and($content)->toBe([textProcessorForBardParagraph("One A '"), textProcessorForBardParagraph('Two')]);
});

it('leaves double-encoded entities to the parser when WpAutoP is off', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard('<p>A &amp;#65; &amp;apos; B</p>');

    expect(array_column($fakes['link']->calls, 'html'))->toBe(['<p>A &#65; &apos; B</p>']);
});

it('converts a registered shortcode outside a shortcode block, and leaves one inside it to the block', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard("<!-- wp:shortcode -->[formidable id=\"2\"]<!-- /wp:shortcode -->[ninja_form id=3]\n<p>[gravityform id=\"1\"]</p>");

    // The Ninja Forms shortcode starts where the block ends, so it is outside it
    expect($fakes['shortcode']->converted)->toBe(['ninja_form', 'gravityform']);
});

it('measures blocks against offsets an earlier, longer replacement has shifted', function (): void {
    $fakes = textProcessorForBardFakes();
    $fakes['shortcode']->replacements = ['gravityform' => '<p>'.str_repeat('x', 200).'</p>'];

    // Characterization of #155: replacing the form shortcode with much longer HTML
    // moves the block past the offsets recorded for it, so the shortcode inside it
    // is converted here too, where it was meant to be left to the block
    textProcessorForBard('<p>[gravityform id="1"]</p><!-- wp:shortcode -->[formidable id="2"]<!-- /wp:shortcode -->');

    expect($fakes['shortcode']->converted)->toBe(['gravityform', 'formidable']);
});

it('skips extracted shortcodes that are malformed, and keeps converting the rest', function (): void {
    $fakes = textProcessorForBardFakes();
    $fakes['shortcode']->extracted = ['loose', ['original' => 5], ['name' => 'foo', 'original' => '[foo]']];

    $content = textProcessorForBard('<p>[foo]</p>');

    expect($fakes['shortcode']->converted)->toBe(['foo'])
        ->and($content)->toBe([textProcessorForBardParagraph('Converted foo')]);
});

it('converts an extracted shortcode the content no longer holds, even when a block starts the content', function (): void {
    $fakes = textProcessorForBardFakes();
    $fakes['shortcode']->extracted = [['name' => 'gone', 'original' => '[gone]']];

    textProcessorForBard('<!-- wp:shortcode -->[unknown_thing]<!-- /wp:shortcode --><p>Text</p>');

    expect($fakes['shortcode']->converted)->toBe(['gone']);
});

it('converts a caption with an image, and skips the same image later', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard('<p>[caption id="x"]<img src="c.jpg"> Cap[/caption]</p><img src="c.jpg">');

    expect($fakes['shortcode']->converted)->toBe(['caption'])
        ->and($fakes['image']->calls)->toBe([])
        ->and($content)->toBe([textProcessorForBardParagraph('Converted caption')]);
});

it('records only the first image of a caption', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard('<p>[caption id="x"]<img src="c.jpg"><img src="d.jpg"> Cap[/caption]</p><img src="c.jpg"><img src="d.jpg">');

    expect(array_column($fakes['image']->calls, 'html'))->toBe(['<img src="d.jpg">']);
});

it('leaves a caption without an image in the text', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard('<p>[caption id="x"]No image here[/caption]</p>');

    expect($fakes['shortcode']->converted)->toBe([])
        ->and($content)->toBe([textProcessorForBardParagraph('[caption id="x"]No image here[/caption]')]);
});

it('reads a caption whose content is not a string as having no image', function (): void {
    $fakes = textProcessorForBardFakes();
    $fakes['shortcode']->extracted = [['name' => 'caption', 'original' => '[caption]', 'content' => ['<img src="x.jpg">']]];

    textProcessorForBard('<p>[caption]</p>');

    expect($fakes['shortcode']->converted)->toBe([]);
});

it('strips link targets inside gallery and shortcode blocks before the link processor reads them', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard(
        '<!-- wp:gallery {"ids":[1]} --><figure class="wp-block-gallery"><a href="/g.jpg"><img src="g.jpg"></a></figure><!-- /wp:gallery -->'
        .'<!-- wp:shortcode -->[unknown_thing]<a class="c" href=\'/s\' title="t">S</a><!-- /wp:shortcode -->'
        .'<p><a href="/k">K</a></p>'
    );

    expect($fakes['link']->calls[0]['html'] ?? null)->toBe(
        '<!-- wp:gallery {"ids":[1]} --><figure class="wp-block-gallery"><a ><img src="g.jpg"></a></figure><!-- /wp:gallery -->'
        .'<!-- wp:shortcode -->[unknown_thing]<a class="c"  title="t">S</a><!-- /wp:shortcode -->'
        .'<p><a href="/k">K</a></p>'
    );
});

it('hands the link processor the entry, the collection and the XML path', function (): void {
    $fakes = textProcessorForBardFakes();
    ProcessingContext::setXmlPath('/context.xml');

    try {
        TextProcessor::processForBard('<p>A</p>', 'other', app(AssetImporter::class), new BufferedOutput, statamicEntryId: 'e1', collectionHandle: 'pages', xmlPath: '/given.xml');
        TextProcessor::processForBard('<p>B</p>', 'other', app(AssetImporter::class), new BufferedOutput);
    } finally {
        ProcessingContext::clearXmlPath();
    }

    expect($fakes['link']->calls)->toBe([
        ['html' => '<p>A</p>', 'site' => 'other', 'entry' => 'e1', 'collection' => 'pages', 'xml' => '/given.xml'],
        ['html' => '<p>B</p>', 'site' => 'other', 'entry' => null, 'collection' => null, 'xml' => '/context.xml'],
    ]);
});

it('skips nodes already processed elsewhere, matched by their HTML', function (): void {
    textProcessorForBardFakes();
    // Built the way the coordinator builds its own document, so the HTML matches
    $done = TextProcessor::loadHtml('<p>Done</p><p>Other</p>')->getElementsByTagName('p')->item(0);

    $content = TextProcessor::processForBard(
        '<p>Done</p><p>New</p>',
        'default',
        app(AssetImporter::class),
        new BufferedOutput,
        preProcessedNodes: ['loose', new DOMElement('p', 'New'), $done]
    );

    // A node outside any document has no HTML to match, so it marks nothing
    expect($content)->toBe([textProcessorForBardParagraph('New')]);
});

it('parses the content before a block ahead of the block, and the content after it after', function (): void {
    textProcessorForBardFakes();

    $content = textProcessorForBard("<p>Before</p>\n\n<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>Q</p></blockquote>\n<!-- /wp:quote -->\n\n<p>After</p>");

    expect(array_map(static fn (mixed $node): mixed => textProcessorForBardPath($node, ['type']), $content))->toBe(['paragraph', 'set', 'paragraph'])
        ->and(textProcessorForBardPath($content, [1, 'attrs', 'values', 'type']))->toBe('pull_quote')
        ->and(textProcessorForBardPath($content, [2, 'content', 0, 'text']))->toBe('After');
});

it('merges a block that returns Bard content, and adds nothing for one that returns empty HTML', function (): void {
    textProcessorForBardFakes();

    $content = textProcessorForBard(
        "<!-- wp:group -->\n<div class=\"wp-block-group\"><p>In group</p></div>\n<!-- /wp:group -->\n\n"
        ."<!-- wp:spacer -->\n<div class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->\n\n\n<p>After</p>"
    );

    expect($content)->toBe([textProcessorForBardParagraph('In group'), textProcessorForBardParagraph('After')]);
});

it('adds the set a reusable block returns', function (): void {
    textProcessorForBardFakes();

    $content = textProcessorForBard('<!-- wp:block {"ref":12} /-->');

    expect(textProcessorForBardPath($content, [0, 'attrs', 'values']))
        ->toBe(['type' => 'reusable_content', 'import_wordpress_notes' => 'wp_block post ID 12']);
});

it('keeps the node right after a block whose processor skips nodes', function (): void {
    textProcessorForBardFakes();

    // The spacer counts its opening comment among the nodes to skip, so the loop skips
    // one fewer after it, and the paragraph that directly follows is kept (#152)
    $content = textProcessorForBard('<!-- wp:spacer --><div class="wp-block-spacer"></div><!-- /wp:spacer --><p>After</p><p>Next</p>');

    expect($content)->toBe([textProcessorForBardParagraph('After'), textProcessorForBardParagraph('Next')]);
});

it('skips a block to its closing comment, however its processor counts whitespace', function (string $html, array $summary): void {
    textProcessorForBardFakes();

    // Whitespace text between a block's nodes does not count toward its skip, and the
    // skip never passes the block's closing comment (#152)
    expect(array_map(textProcessorForBardSummary(...), textProcessorForBard($html)))->toBe($summary);
})->with([
    'a spacer with whitespace, then tight content' => ["<!-- wp:spacer -->\n<div class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer --><p>After</p>", ['paragraph:After']],
    'a quote whose processor counts the paragraph inside it' => ["<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"></blockquote><p>Para</p><!-- /wp:quote --><p>After</p>", ['set:pull_quote', 'paragraph:After']],
    'a group nested as a sibling inside a group' => ['<!-- wp:group --><!-- wp:group --><p>In</p><!-- /wp:group --><p>Outer</p><!-- /wp:group --><p>After</p>', ['paragraph:In', 'paragraph:Outer', 'paragraph:After']],
    'a group nested in a group' => ["<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:group -->\n<div class=\"wp-block-group\"><p>In</p></div>\n<!-- /wp:group --></div>\n<!-- /wp:group --><p>After</p>", ['paragraph:In', 'paragraph:After']],
]);

it('keeps a later node whose markup matches a node a block skipped', function (): void {
    textProcessorForBardFakes();

    // The spacer's div is skipped by identity, so the same div outside the block is
    // read as content (#152)
    $content = textProcessorForBard('<!-- wp:spacer --><div class="wp-block-spacer">X</div><!-- /wp:spacer --><div class="wp-block-spacer">X</div>');

    expect($content)->toBe([['type' => 'text', 'text' => 'X', 'attrs' => []]]);
});

it('keeps the text on either side of a spacer apart', function (): void {
    textProcessorForBardFakes();

    // The spacer processor ends the content before it, so the text after it starts anew
    $content = textProcessorForBard('lead<!-- wp:spacer --><div class="wp-block-spacer"></div><!-- /wp:spacer -->tail');

    expect($content)->toBe([
        ['type' => 'text', 'text' => 'lead', 'attrs' => []],
        ['type' => 'text', 'text' => 'tail', 'attrs' => []],
    ]);
});

it('keeps a later node whose markup matches one a block skipped', function (): void {
    textProcessorForBardFakes();

    // Skipped nodes are held by identity, so a paragraph sharing their markup elsewhere
    // is not skipped with them (#152)
    $content = textProcessorForBard('<p>Same</p><!-- wp:spacer --><div class="wp-block-spacer"></div><!-- /wp:spacer --><p>Same</p><p>Other</p><div class="wp-block-spacer"></div><p>Same</p>');

    expect(array_map(textProcessorForBardSummary(...), $content))->toBe(['paragraph:Same', 'paragraph:Same', 'paragraph:Other', 'paragraph:Same']);
});

it('warns when a block comment carries JSON that does not decode', function (): void {
    $fakes = textProcessorForBardFakes();
    $warnings = textProcessorForBardWarnings();
    ProcessingContext::setWordPressPageLink('https://example.com/page');

    try {
        TextProcessor::processForBard('<!-- wp:heading {"level":3,} --><h3>H</h3><figure><img src="h.jpg"></figure>', 'default', app(AssetImporter::class), new BufferedOutput, pageSlug: 'page');
    } finally {
        ProcessingContext::clearWordPressPageLink();
    }

    // The heading's attributes were reset, so the figure takes the plain branch
    expect($fakes['image']->calls[0]['attributes'] ?? null)->toBe([])
        ->and($warnings->getArrayCopy())->toBe([[
            'message' => 'TextProcessor::processForBard: Failed to parse block attributes JSON (from entry: https://example.com/page)',
            'context' => ['site' => 'default', 'pageSlug' => 'page', 'block_type' => 'heading', 'json_string' => '{"level":3,}', 'json_error' => 'Syntax error', 'entry_url' => 'https://example.com/page'],
        ]]);
});

it('names no page slug in the warning when it has none', function (): void {
    textProcessorForBardFakes();
    $warnings = textProcessorForBardWarnings();

    textProcessorForBard('<!-- wp:heading {"level":3,} --><h3>H</h3>');

    expect($warnings->getArrayCopy())->toBe([[
        'message' => 'TextProcessor::processForBard: Failed to parse block attributes JSON',
        'context' => ['site' => 'default', 'pageSlug' => 'none', 'block_type' => 'heading', 'json_string' => '{"level":3,}', 'json_error' => 'Syntax error'],
    ]]);
});

it('reads a block comment without JSON as carrying no attributes', function (): void {
    $fakes = textProcessorForBardFakes();
    $warnings = textProcessorForBardWarnings();

    $content = textProcessorForBard('<!-- wp:heading --><h3>H</h3><!-- /wp:heading --><figure><img src="h.jpg"></figure>');

    expect($fakes['image']->calls)->toBe([['html' => '<figure><img src="h.jpg"></figure>', 'attributes' => []]])
        ->and(array_column($content, 'type'))->toBe(['heading', 'set'])
        ->and($warnings->getArrayCopy())->toBe([]);
});

it('gives the next figure the attributes of an unclaimed block, and places it ahead of the content before it', function (): void {
    $fakes = textProcessorForBardFakes();

    // Characterization of #153
    $content = textProcessorForBard('<!-- wp:heading {"level":3} --><h3>H</h3><!-- /wp:heading --><figure><img src="h.jpg"></figure><figure><img src="i.jpg"></figure>');

    expect($fakes['image']->calls)->toBe([
        ['html' => '<figure><img src="h.jpg"></figure>', 'attributes' => ['level' => 3]],
        ['html' => '<figure><img src="i.jpg"></figure>', 'attributes' => []],
    ])
        ->and(array_column($content, 'type'))->toBe(['set', 'heading', 'set']);
});

it('drops the attributes an unclaimed block left at a closing image comment', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard('<!-- wp:heading {"level":3} --><h3>H</h3><!--   /wp:image   --><figure><img src="h.jpg"></figure>');

    expect($fakes['image']->calls[0]['attributes'] ?? null)->toBe([]);
});

it('keeps the attributes at a comment that only resembles a closing image comment', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard('<!-- wp:heading {"level":3} --><h3>H</h3><!-- /wp:image x --><figure><img src="h.jpg"></figure>');

    expect($fakes['image']->calls[0]['attributes'] ?? null)->toBe(['level' => 3]);
});

it('warns when a block processor returns nothing, and keeps the content after it', function (): void {
    textProcessorForBardFakes();
    $warnings = textProcessorForBardWarnings();

    $content = textProcessorForBard('<!-- wp:image {"id":1} --><p>No figure</p><!-- /wp:image -->');

    expect($content)->toBe([textProcessorForBardParagraph('No figure')])
        ->and($warnings->getArrayCopy())->toBe([[
            'message' => "TextProcessor::processForBard: Block processor returned null for block type 'image'",
            'context' => ['site' => 'default', 'pageSlug' => 'none', 'block_type' => 'image'],
        ]]);
});

it('reads a comment that is not a block as content', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard('<!-- note {"level":3} --><p>Note</p><figure><img src="n.jpg"></figure>');

    expect($content)->toBe([textProcessorForBardParagraph('Note'), textProcessorForBardImage('n.jpg')])
        ->and($fakes['image']->calls[0]['attributes'] ?? null)->toBe([]);
});

it('imports each figure and image once, and puts the content before each ahead of it', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard('<p>A</p><figure><img src="f.jpg"></figure><p>B</p><img src="s.jpg"><p>C</p><figure class="again"><img src="f.jpg"></figure><img src="s.jpg">');

    expect($content)->toBe([
        textProcessorForBardParagraph('A'),
        textProcessorForBardImage('f.jpg'),
        textProcessorForBardParagraph('B'),
        textProcessorForBardImage('s.jpg'),
        textProcessorForBardParagraph('C'),
    ])
        ->and(array_column($fakes['image']->calls, 'html'))->toBe(['<figure><img src="f.jpg"></figure>', '<img src="s.jpg">']);
});

it('retries an image that imported nothing, but not the same figure', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard('<figure><img src="none.jpg"></figure><figure><img src="none.jpg"></figure><figure class="x"><img src="none.jpg"></figure><img src="empty.jpg"><p>x</p><img src="empty.jpg">');

    // The repeated figure's HTML was already processed, so only the other figure retries
    expect(array_column($fakes['image']->calls, 'html'))->toBe([
        '<figure><img src="none.jpg"></figure>',
        '<figure class="x"><img src="none.jpg"></figure>',
        '<img src="empty.jpg">',
    ])
        ->and($content)->toBe([textProcessorForBardParagraph('x')]);
});

it('parses a figure without an image as content', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard('<figure><p>No image</p></figure>');

    expect($fakes['image']->calls)->toBe([])
        ->and($content)->toBe([textProcessorForBardParagraph('No image')]);
});

it('filters out empty paragraphs', function (): void {
    textProcessorForBardFakes();

    expect(textProcessorForBard('<p></p><p>Kept</p><p>  </p>'))->toBe([textProcessorForBardParagraph('Kept')]);
});

it('marks the raw shortcode text inside a shortcode block as processed, and only that text', function (): void {
    textProcessorForBardFakes();

    // The block's own text is consumed; the identical text after its closing comment is
    // a separate node and is kept (#152)
    $content = textProcessorForBard("<!-- wp:shortcode -->\n[unknown_thing]\n<!-- /wp:shortcode -->\n[unknown_thing]\n<p>Q</p>");

    expect($content)->toBe([
        ['type' => 'text', 'text' => '[unknown_thing]', 'attrs' => []],
        ['type' => 'text', 'text' => '[unknown_thing]', 'attrs' => []],
        textProcessorForBardParagraph('Q'),
    ]);
});

/**
 * A summary of a node: its type, a set's value type, or a paragraph's text.
 */
function textProcessorForBardSummary(mixed $node): string
{
    $type = textProcessorForBardPath($node, ['type']);
    $setType = textProcessorForBardPath($node, ['attrs', 'values', 'type']);
    $text = textProcessorForBardPath($node, ['content', 0, 'text']);

    return match (true) {
        $type === 'set' && is_string($setType) => "set:{$setType}",
        is_string($type) && is_string($text) => "{$type}:{$text}",
        is_string($type) => $type,
        default => '?',
    };
}

it('hands each block type to its processor', function (string $html, array $summary, array $warnings): void {
    textProcessorForBardFakes();
    $logged = textProcessorForBardWarnings();

    $content = textProcessorForBard($html);

    expect(array_map(textProcessorForBardSummary(...), $content))->toBe($summary)
        ->and(array_column($logged->getArrayCopy(), 'message'))->toBe($warnings);
})->with([
    'details' => ["<!-- wp:details -->\n<details class=\"wp-block-details\"><summary>S</summary><p>Body</p></details>\n<!-- /wp:details -->", ['heading:S', 'paragraph:Body'], []],
    'file' => ["<!-- wp:file {\"id\":9} -->\n<div class=\"wp-block-file\"></div>\n<!-- /wp:file -->", [], ["TextProcessor::processForBard: Block processor returned null for block type 'file'"]],
    'embed' => ["<!-- wp:embed {\"url\":\"https://www.youtube.com/watch?v=abc\",\"providerNameSlug\":\"youtube\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">https://www.youtube.com/watch?v=abc</div></figure>\n<!-- /wp:embed -->", ['set:youtube'], []],
    'table' => ["<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>T</td></tr></tbody></table></figure>\n<!-- /wp:table -->", ['set:table'], []],
    'post featured image' => ['<!-- wp:post-featured-image /-->', [], ["TextProcessor::processForBard: Block processor returned null for block type 'post-featured-image'"]],
    'gallery' => ['<!-- wp:gallery {"ids":[1]} --><p>x</p><!-- /wp:gallery -->', ['paragraph:x'], ["TextProcessor::processForBard: Block processor returned null for block type 'gallery'"]],
    'ACF gallery' => ['<!-- wp:acf/uams-gallery {"name":"acf/uams-gallery","data":{}} /-->', ['set:gallery'], []],
    'TablePress' => ["<!-- wp:tablepress/table {\"id\":\"1\"} -->\n[table id=1 /]\n<!-- /wp:tablepress/table -->", ['set:table'], []],
    'Gravity Forms' => ['<!-- wp:gravityforms/form {"formId":"3"} /-->', ['set:form'], []],
    'Formidable' => ['<!-- wp:formidable/simple-form {"formId":"4"} /-->', ['set:form'], []],
    'Formidable with its inner div' => ["<!-- wp:formidable/simple-form {\"formId\":\"2\"} -->\n<div>[formidable id=\"2\"]</div>\n<!-- /wp:formidable/simple-form --><p>After</p>", ['set:form', 'paragraph:After'], []],
    'Ninja Forms' => ['<!-- wp:ninja-forms/form {"formID":5} /-->', ['set:form'], []],
]);

it('merges every node of a block that returns Bard content', function (): void {
    textProcessorForBardFakes();

    $content = textProcessorForBard("<!-- wp:group -->\n<div class=\"wp-block-group\"><p>One</p><p>Two</p></div>\n<!-- /wp:group -->");

    expect($content)->toBe([textProcessorForBardParagraph('One'), textProcessorForBardParagraph('Two')]);
});

it('matches already-processed nodes by exact HTML, so text that only compares equal as a number is kept', function (): void {
    textProcessorForBardFakes();
    $one = TextProcessor::loadHtml('1')->getElementsByTagName('div')->item(0)?->firstChild;

    $content = TextProcessor::processForBard('01', 'default', app(AssetImporter::class), new BufferedOutput, preProcessedNodes: [$one]);

    expect($content)->toBe([['type' => 'text', 'text' => '01', 'attrs' => []]]);
});

it('reads a bare form shortcode without the whitespace a stripped control character left', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard("\x01 [gravityform id=\"3\"]");

    expect($fakes['shortcode']->inContent)->toBe([['content' => '[gravityform id="3"]', 'slug' => null]]);
});

it('does not take the shortcut for content that is only an empty shortcode block', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard('<!-- wp:shortcode -->  <!-- /wp:shortcode -->');

    expect($fakes['shortcode']->inContent)->toBe([]);
});

it('strips link targets in every excluded block, wherever the earlier ones change the length', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard(
        '<!-- wp:gallery {"ids":[1]} --><figure class="wp-block-gallery"><a href="/one-long-target.jpg"><img src="a.jpg"></a></figure><!-- /wp:gallery -->'
        .'<p>between</p>'
        .'<!-- wp:gallery {"ids":[2]} --><figure class="wp-block-gallery"><a href="/two.jpg"><img src="b.jpg"></a></figure><!-- /wp:gallery -->'
    );

    expect($fakes['link']->calls[0]['html'] ?? null)->toBe(
        '<!-- wp:gallery {"ids":[1]} --><figure class="wp-block-gallery"><a ><img src="a.jpg"></a></figure><!-- /wp:gallery -->'
        .'<p>between</p>'
        .'<!-- wp:gallery {"ids":[2]} --><figure class="wp-block-gallery"><a ><img src="b.jpg"></a></figure><!-- /wp:gallery -->'
    );
});

it('turns double-encoded entities above 127 into the wrong bytes when it applies WpAutoP', function (): void {
    textProcessorForBardFakes();

    // Characterization of #154: chr() wraps 8212 to the control character U+0014,
    // which libxml keeps on macOS and drops on the CI runner, and turns 233 into a
    // lone byte that comes out as `?`; without WpAutoP both decode correctly
    $withAutoP = textProcessorForBard('<p>A &amp;#8212; B &amp;#233; C</p>', autoP: true);
    $without = textProcessorForBard('<p>A &amp;#8212; B &amp;#233; C</p>');

    expect(textProcessorForBardPath($withAutoP, [0, 'content', 0, 'text']))->toBeIn(["A \x14 B ? C", 'A  B ? C'])
        ->and(textProcessorForBardPath($without, [0, 'content', 0, 'text']))->toBe("A \u{2014} B \u{00E9} C");
});

it('processes the blocks inside a group block', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard('<!-- wp:group --><div class="wp-block-group"><!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="g.jpg"></figure><!-- /wp:image --></div><!-- /wp:group -->');

    expect($content)->toBe([textProcessorForBardImage('g.jpg')])
        ->and($fakes['image']->calls[0]['attributes'] ?? null)->toBe(['id' => 5]);
});

it('keeps a figure right after a block whose processor skips nodes', function (): void {
    $fakes = textProcessorForBardFakes();

    // As for the paragraph above (#152)
    $content = textProcessorForBard('<!-- wp:spacer --><div class="wp-block-spacer"></div><!-- /wp:spacer --><figure><img src="x.jpg"></figure>');

    expect(array_map(textProcessorForBardSummary(...), $content))->toBe(['set:image'])
        ->and($fakes['image']->calls)->toHaveCount(1);
});

it('drops a figure without an image when an unclaimed block left attributes', function (): void {
    $fakes = textProcessorForBardFakes();

    // Characterization of #153: the branch for a figure with attributes reads no
    // content, so the figure's paragraph is lost
    $content = textProcessorForBard('<!-- wp:heading {"level":3} --><h3>H</h3><!-- /wp:heading --><figure><p>No image</p></figure><figure><img src="i.jpg"></figure>');

    expect($fakes['image']->calls)->toBe([['html' => '<figure><img src="i.jpg"></figure>', 'attributes' => []]])
        ->and(array_map(textProcessorForBardSummary(...), $content))->toBe(['heading:H', 'set:image']);
});

it('adds nothing for a figure with attributes whose image imports nothing, and retries the image', function (string $src): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard("<!-- wp:heading {\"level\":3} --><h3>H</h3><!-- /wp:heading --><figure><img src=\"{$src}\"></figure><figure class=\"again\"><img src=\"{$src}\"></figure>");

    expect(array_column($fakes['image']->calls, 'attributes'))->toBe([['level' => 3], []])
        ->and(array_map(textProcessorForBardSummary(...), $content))->toBe(['heading:H']);
})->with(['an image processor that returns nothing' => ['none.jpg'], 'one that returns an empty set' => ['empty.jpg']]);

it('imports each different image in a figure', function (): void {
    $fakes = textProcessorForBardFakes();

    $content = textProcessorForBard('<!-- wp:heading {"level":3} --><h3>H</h3><!-- /wp:heading --><figure><img src="a.jpg"></figure><figure><img src="b.jpg"></figure><figure><img src="c.jpg"></figure>');

    expect(array_column($fakes['image']->calls, 'html'))->toBe(['<figure><img src="a.jpg"></figure>', '<figure><img src="b.jpg"></figure>', '<figure><img src="c.jpg"></figure>'])
        ->and(array_map(textProcessorForBardSummary(...), $content))->toBe(['set:image', 'heading:H', 'set:image', 'set:image']);
});

it('keeps whitespace between images to join the text after it', function (): void {
    textProcessorForBardFakes();

    $content = textProcessorForBard("<img src=\"a.jpg\">\n<img src=\"b.jpg\"> c");

    // Parsed with the newline before it, the leading space of " c" is dropped
    expect($content)->toBe([textProcessorForBardImage('a.jpg'), textProcessorForBardImage('b.jpg'), ['type' => 'text', 'text' => 'c', 'attrs' => []]]);
});

it('strips the link targets of a later block after an earlier block shortened the content', function (): void {
    $fakes = textProcessorForBardFakes();
    $long = '/'.str_repeat('a', 120).'.jpg';

    textProcessorForBard(
        '<!-- wp:gallery {"ids":[1]} --><figure class="wp-block-gallery"><a href="'.$long.'"><img src="a.jpg"></a></figure><!-- /wp:gallery -->'
        .'<!-- wp:gallery {"ids":[2]} --><figure class="wp-block-gallery"><a href="/b.jpg"><img src="b.jpg"></a></figure><!-- /wp:gallery -->'
    );

    expect($fakes['link']->calls[0]['html'] ?? null)->not->toContain('href=');
});

it('warns when a block processor returns HTML that parses to nothing', function (?string $slug, string $pageSlug): void {
    textProcessorForBardFakes();
    $warnings = textProcessorForBardWarnings();

    $content = TextProcessor::processForBard('<p>Lead</p><!-- wp:shortcode --><div class="x"></div><!-- /wp:shortcode -->', 'default', app(AssetImporter::class), new BufferedOutput, pageSlug: $slug);

    expect($content)->toBe([textProcessorForBardParagraph('Lead')])
        ->and($warnings->getArrayCopy())->toBe([[
            'message' => "TextProcessor::processForBard: Block processor returned HTML but BardParser returned empty result for block type 'shortcode'",
            'context' => ['site' => 'default', 'pageSlug' => $pageSlug, 'block_type' => 'shortcode', 'result_length' => 21, 'result_preview' => '<div class="x"></div>'],
        ]]);
})->with([
    'with a page slug' => ['about', 'about'],
    'without one' => [null, 'none'],
]);

it('previews only the first 100 characters of HTML that parses to nothing', function (): void {
    textProcessorForBardFakes();
    $warnings = textProcessorForBardWarnings();
    $html = '<div class="'.str_repeat('y', 120).'"></div>';

    textProcessorForBard("<p>Lead</p><!-- wp:shortcode -->{$html}<!-- /wp:shortcode -->");

    expect($warnings[0]['context']['result_length'] ?? null)->toBe(strlen($html))
        ->and($warnings[0]['context']['result_preview'] ?? null)->toBe(substr($html, 0, 100));
});

it('adds nothing and warns of nothing for a block whose shortcode is dropped', function (): void {
    textProcessorForBardFakes();
    $warnings = textProcessorForBardWarnings();

    expect(textProcessorForBard('<p>Lead</p><!-- wp:shortcode -->[subpage-list]<!-- /wp:shortcode -->'))->toBe([textProcessorForBardParagraph('Lead')])
        ->and($warnings->getArrayCopy())->toBe([]);
});

it('names the page slug in the warning for a block processor that returns nothing', function (): void {
    textProcessorForBardFakes();
    $warnings = textProcessorForBardWarnings();

    TextProcessor::processForBard('<!-- wp:image {"id":1} --><p>No figure</p><!-- /wp:image -->', 'default', app(AssetImporter::class), new BufferedOutput, pageSlug: 'about');

    expect($warnings[0]['context'] ?? null)->toBe(['site' => 'default', 'pageSlug' => 'about', 'block_type' => 'image']);
});

it('keeps a later node identical to one a block processor processed', function (): void {
    textProcessorForBardFakes();

    // The quote outside any block shares the block's markup but is a node of its own (#152)
    $content = textProcessorForBard("<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>Q</p></blockquote>\n<!-- /wp:quote -->\n\n<p>Between</p>\n<blockquote class=\"wp-block-quote\"><p>Q</p></blockquote>\n<p>After</p>");

    expect(array_map(textProcessorForBardSummary(...), $content))->toBe(['set:pull_quote', 'paragraph:Between', 'paragraph:Q', 'paragraph:After']);
});

it('does not parse a block\'s figure again when the figure lies past the nodes it skips', function (): void {
    textProcessorForBardFakes();

    // Characterization of #197: the table processor finds its figure past three
    // paragraphs, and its fixed skip count consumes the first two of them; the figure
    // itself is tracked as processed, so it is not parsed again
    $content = textProcessorForBard('<!-- wp:table --><p>A</p><p>B</p><p>C</p><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure>');

    expect(array_map(textProcessorForBardSummary(...), $content))->toBe(['set:table', 'paragraph:C']);
});

it('reads nothing past a shortcode block that ends the content', function (): void {
    textProcessorForBardFakes();

    // The host app turns a warning into an exception, so one counts as a failure
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $content = textProcessorForBard('<p>Lead</p><!-- wp:shortcode -->');
    } finally {
        restore_error_handler();
    }

    expect(array_map(textProcessorForBardSummary(...), $content))->toBe(['paragraph:Lead']);
});

it('keeps an element after a shortcode block even when its text looks like a shortcode', function (): void {
    textProcessorForBardFakes();

    // Characterization of #156: the block's processor returns its content as a
    // string and skips nothing, so the paragraph is read again after it
    $content = textProcessorForBard('<p>Lead</p><!-- wp:shortcode --><p>[unknown_thing]</p><!-- /wp:shortcode -->');

    expect(array_map(textProcessorForBardSummary(...), $content))->toBe(['paragraph:Lead', 'paragraph:[unknown_thing]', 'paragraph:[unknown_thing]']);
});

it('parses an image inside a paragraph as content, not as a figure', function (): void {
    $fakes = textProcessorForBardFakes();

    textProcessorForBard('<p>Text <img src="p.jpg"></p>');

    expect($fakes['image']->calls)->toBe([]);
});

it('measures a shortcode that lands on a block\'s recorded start as inside it', function (): void {
    $fakes = textProcessorForBardFakes();
    $fakes['shortcode']->extracted = [
        ['name' => 'gone', 'original' => '[gone aaaaaaaaaaaaaa]'],
        ['name' => 'b', 'original' => '[b]'],
    ];
    $fakes['shortcode']->replacements = ['gone' => ''];

    // Removing the 21-character shortcode moves [b] onto the offset recorded for
    // the block's start (#155), and a position at the start counts as inside
    textProcessorForBard('[gone aaaaaaaaaaaaaa]<!-- wp:shortcode -->[b]<!-- /wp:shortcode -->');

    expect($fakes['shortcode']->converted)->toBe(['gone']);
});

it('imports an attribute value only up to its first escaped quote', function (): void {
    textProcessorForBardFakes();

    // Characterization of #157
    expect(textProcessorForBard('<p id="say &quot;hi&quot; now">x</p>'))->toBe([textProcessorForBardParagraph('x', 'say ')]);
});

it('trims a NUL from the edges of a shortcode block in content that is not valid UTF-8', function (): void {
    $fakes = textProcessorForBardFakes();
    $fakes['shortcode']->inContentResult = '<p>Converted</p>';

    // normalizeUtf8() leaves invalid UTF-8 unchanged, so the NUL reaches the block
    textProcessorForBard("<!-- wp:shortcode --> \0 [gravityform id=\"1\" title=\"\xff\"] \0 <!-- /wp:shortcode -->");

    expect(array_column($fakes['shortcode']->inContent, 'content'))->toBe(["[gravityform id=\"1\" title=\"\xff\"]"]);
});
