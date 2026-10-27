<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessorInterface;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A registered handler that records how it was built and called.
 */
class ShortcodeProbeHandler implements ShortcodeProcessorInterface
{
    /**
     * @var array<int|string, mixed>|null What parse() returns
     */
    public static ?array $parseResult = [];

    /**
     * @var mixed What toPageBuilderBlock() returns
     */
    public static mixed $block = [];

    /**
     * @var array<int, array<int, mixed>> Recorded calls, method name first
     */
    public static array $calls = [];

    /**
     * Record the constructor argument, if any.
     *
     * @param  string  $name  The shortcode name passed by the processor
     */
    public function __construct(public string $name = 'unset')
    {
        self::$calls[] = ['construct', $name];
    }

    /**
     * Record the call and return the configured result.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  array<int|string, mixed>  $terms  Term objects
     * @param  ?string  $pageSlug  The page slug
     * @return array<int|string, mixed>|null The configured result
     */
    public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $terms = [], ?string $pageSlug = null): ?array
    {
        self::$calls[] = ['parse', $attributes, $site, $terms, $pageSlug];

        return self::$parseResult;
    }

    /**
     * Record the call and return a marker naming the constructor argument.
     *
     * @param  array<int|string, mixed>  $shortcode  Shortcode data
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  array<int|string, mixed>  $attachments  Attachment objects
     * @param  array<int|string, mixed>  $terms  Term objects
     * @param  ?string  $pageSlug  The page slug
     * @return string The marker
     */
    public function toTiptapHtml(array $shortcode, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = [], array $terms = [], ?string $pageSlug = null): string
    {
        self::$calls[] = ['toTiptapHtml', $shortcode, $site, $attachments, $terms, $pageSlug];

        return '<probe:'.$this->name.'>';
    }

    /**
     * Record the call and return the configured block.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  array<int|string, mixed>  $terms  Term objects
     * @param  ?string  $pageSlug  The page slug
     * @return mixed The configured block
     */
    public function toPageBuilderBlock(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $terms = [], ?string $pageSlug = null): mixed
    {
        self::$calls[] = ['toPageBuilderBlock', $attributes, $site, $terms, $pageSlug];

        return self::$block;
    }
}

/**
 * A registered handler with no page builder block.
 */
class ShortcodeProbeNoBlockHandler implements ShortcodeProcessorInterface
{
    /**
     * Return no values.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  array<int|string, mixed>  $terms  Term objects
     * @param  ?string  $pageSlug  The page slug
     * @return array<int|string, mixed> No values
     */
    public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $terms = [], ?string $pageSlug = null): array
    {
        return [];
    }

    /**
     * Return no markup.
     *
     * @param  array<int|string, mixed>  $shortcode  Shortcode data
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  array<int|string, mixed>  $attachments  Attachment objects
     * @param  array<int|string, mixed>  $terms  Term objects
     * @param  ?string  $pageSlug  The page slug
     * @return string No markup
     */
    public function toTiptapHtml(array $shortcode, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = [], array $terms = [], ?string $pageSlug = null): string
    {
        return '';
    }
}

beforeEach(function (): void {
    // Start every case with no context, warnings or recorded probe calls
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
    ProcessingContext::clearAttachments();
    ProcessingContext::clearShortcodeContent();
    ImportWarningManager::clearWarnings();
    ShortcodeProbeHandler::$parseResult = [];
    ShortcodeProbeHandler::$block = [];
    ShortcodeProbeHandler::$calls = [];
});

afterEach(function (): void {
    // Leave no context or warnings behind for the next test file
    ProcessingContext::clearAttachments();
    ProcessingContext::clearShortcodeContent();
    ImportWarningManager::clearWarnings();
});

/**
 * A processor with the probe handlers registered.
 *
 * @return ShortcodeProcessor The processor
 */
function shortcodeProcessor(): ShortcodeProcessor
{
    $processor = new ShortcodeProcessor;
    $processor->registerHandler('probe', 'probe-set', ShortcodeProbeHandler::class);
    $processor->registerHandler('noblock', 'noblock-set', ShortcodeProbeNoBlockHandler::class);
    $processor->registerHandler('plain', 'plain-set', stdClass::class);

    return $processor;
}

/**
 * Record every warning logged during a test.
 *
 * @return ArrayObject<int, array{message: string, context: array<string, mixed>}> The warnings
 */
function shortcodeWarnings(): ArrayObject
{
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings->append(['message' => $message, 'context' => $context]);
        }
    );

    return $warnings;
}

describe('extract', function (): void {
    it('keeps registered and dropped shortcodes and skips everything else', function (): void {
        $result = shortcodeProcessor()->extract('[probe a=1] [unknown a=1] [subpage-list link=""] [gallery ids="1"]', new BufferedOutput);

        expect(array_column($result, 'name'))->toBe(['probe', 'subpage-list', 'gallery']);
    });

    it('swallows a bare shortcode followed later by another closing bracket', function (): void {
        // Current behavior, recorded so a parser fix shows up here: the name group
        // runs past the bare shortcode's "]", so both shortcodes are lost.
        expect(shortcodeProcessor()->extract('[probe] then [gallery ids="1"]', new BufferedOutput))->toBe([])
            ->and(shortcodeProcessor()->extract('[probe]', new BufferedOutput))->toHaveCount(1);
    });

    it('parses flags, quoted, escaped and unquoted attributes', function (): void {
        $result = shortcodeProcessor()->extract('[probe flag a="one two" b=\'single\' c="say \"hi\"" d=bare e=""]', new BufferedOutput);

        expect($result)->toBe([[
            'name' => 'probe',
            'attributes' => [
                'flag' => true,
                'a' => 'one two',
                'b' => 'single',
                'c' => 'say \"hi\"',
                'd' => 'bare',
                'e' => '',
            ],
            'content' => '',
            'original' => '[probe flag a="one two" b=\'single\' c="say \"hi\"" d=bare e=""]',
        ]]);
    });

    it('captures enclosed content and the whole original text', function (): void {
        $result = shortcodeProcessor()->extract("before [probe x=1]inner\n[b]text[/probe] after", new BufferedOutput);

        expect($result)->toBe([[
            'name' => 'probe',
            'attributes' => ['x' => '1'],
            'content' => "inner\n[b]text",
            'original' => "[probe x=1]inner\n[b]text[/probe]",
        ]]);
    });

    it('returns nothing for content without shortcodes', function (): void {
        expect(shortcodeProcessor()->extract('<p>plain</p>', new BufferedOutput))->toBe([]);
    });
});

describe('toTiptapHtml', function (): void {
    it('returns nothing for a dropped shortcode', function (): void {
        expect(shortcodeProcessor()->toTiptapHtml(['name' => 'subpage-list'], 'default', new AssetImporter, new BufferedOutput))->toBe('');
    });

    it('delegates to the registered handler with every argument', function (): void {
        $output = new BufferedOutput;
        $shortcode = ['name' => 'probe', 'attributes' => ['a' => '1'], 'content' => 'c'];

        $result = shortcodeProcessor()->toTiptapHtml($shortcode, 'site-a', new AssetImporter, $output, ['att'], ['term'], 'about');

        expect($result)->toBe('<probe:unset>')
            ->and(ShortcodeProbeHandler::$calls)->toBe([
                ['construct', 'unset'],
                ['toTiptapHtml', $shortcode, 'site-a', ['att'], ['term'], 'about'],
            ]);
    });

    it('returns nothing for a registered class that is not a shortcode processor', function (): void {
        expect(shortcodeProcessor()->toTiptapHtml(['name' => 'plain'], 'default', new AssetImporter, new BufferedOutput))->toBe('');
    });

    it('warns and emits an unknown set for an unregistered shortcode', function (array $shortcode, string $name, string $content): void {
        $warnings = shortcodeWarnings();

        $result = shortcodeProcessor()->toTiptapHtml($shortcode, 'site-a', new AssetImporter, new BufferedOutput, [], [], 'about');

        expect($result)->toBe('<div data-set-type="unknown"'.SetMarkupNonce::attribute().'>'.$content.'</div>')
            ->and($warnings->getArrayCopy())->toBe([[
                'message' => "ShortcodeProcessor::toTiptapHtml: No valid handler for shortcode '{$name}'",
                'context' => ['handler' => null, 'site' => 'site-a', 'pageSlug' => 'about'],
            ]]);
    })->with([
        'named, with content' => [['name' => 'mystery', 'content' => 'a <b> & c'], 'mystery', 'a &lt;b&gt; &amp; c'],
        'numeric name, no content' => [['name' => 7], '7', ''],
        'missing name, empty content' => [['content' => ''], '', ''],
        'non-scalar name and content' => [['name' => ['x'], 'content' => ['y']], '', ''],
    ]);
});

describe('registerHandler', function (): void {
    it('records the set type and class under the shortcode name', function (): void {
        expect(shortcodeProcessor()->getHandlers()['probe'] ?? null)
            ->toBe(['set' => 'probe-set', 'class' => ShortcodeProbeHandler::class]);
    });
});

describe('generateTiptapHtml', function (): void {
    it('renders scalars, booleans and arrays, skipping type and null', function (): void {
        $object = new stdClass;

        $html = ShortcodeProcessor::generateTiptapHtml(
            ['type' => 'x', 'gone' => null, 'text' => 'a "b" <c>', 'count' => 3, 'ratio' => 1.5, 'on' => true, 'off' => false, 0 => 'zero', 'list' => ['a' => "it's"], 'obj' => $object, 'k<y' => 'v', 'l<y' => ['x' => 1]],
            'my<set>',
            'body & more'
        );

        $encoded = base64_encode((string) json_encode(['a' => "it's"]));

        expect($html)->toBe(
            '<div data-set-type="my&lt;set&gt;"'.SetMarkupNonce::attribute()
            .' data-set-attr-text="a &quot;b&quot; &lt;c&gt;"'
            .' data-set-attr-count="3"'
            .' data-set-attr-ratio="1.5"'
            .' data-set-attr-on="true"'
            .' data-set-attr-off="false"'
            .' data-set-attr-0="zero"'
            ." data-set-attr-list='".$encoded."'"
            .' data-set-attr-obj=""'
            .' data-set-attr-k&lt;y="v"'
            ." data-set-attr-l&lt;y='".base64_encode('{"x":1}')."'"
            .'>body &amp; more</div>'
        );
    });

    it('drops an array value that cannot be encoded as JSON', function (): void {
        expect(ShortcodeProcessor::generateTiptapHtml(['bad' => ["\xB1\x31"], 7 => ['k' => 'v']], 'set'))
            ->toBe('<div data-set-type="set"'.SetMarkupNonce::attribute()." data-set-attr-7='".base64_encode('{"k":"v"}')."'></div>");
    });
});

describe('process', function (): void {
    it('tracks an unregistered shortcode as unaddressed', function (): void {
        $processor = shortcodeProcessor();

        $result = $processor->process(
            ['name' => 'mystery', 'original' => '[mystery]', 'id' => 12, 'link' => 'https://example.com/p/'],
            'site-a',
            new AssetImporter,
            new BufferedOutput,
            [],
            'bard',
            [],
            'about'
        );

        expect($result)->toBeNull()
            ->and($processor->getUnaddressedNodes())->toBe([
                'shortcode:mystery' => [['id' => '12', 'slug' => 'about', 'link' => 'https://example.com/p/', 'original' => '[mystery]']],
            ])
            ->and(ImportWarningManager::getWarnings('content', 'unaddressed_shortcodes'))->toBe([[
                'shortcode_name' => 'mystery',
                'original' => '[mystery]',
                'site' => 'site-a',
                'page_slug' => 'about',
                'page_id' => '12',
                'page_link' => 'https://example.com/p/',
            ]]);
    });

    it('tracks a shortcode with no usable name or context under an empty name', function (): void {
        $processor = shortcodeProcessor();

        expect($processor->process([], 'site-a', new AssetImporter, new BufferedOutput))->toBeNull()
            ->and($processor->getUnaddressedNodes())->toBe([
                'shortcode:' => [['id' => '', 'slug' => null, 'link' => '', 'original' => '']],
            ]);
    });

    it('drops a recognized shortcode without tracking it', function (): void {
        $processor = shortcodeProcessor();

        expect($processor->process(['name' => 'subpage-list'], 'site-a', new AssetImporter, new BufferedOutput))->toBeNull()
            ->and($processor->getUnaddressedNodes())->toBe([])
            ->and(ImportWarningManager::getWarnings('content', 'unaddressed_shortcodes'))->toBe([]);
    });

    it('has no set or block for audio and accordion', function (string $name, string $outputType): void {
        expect(shortcodeProcessor()->process(['name' => $name], 'site-a', new AssetImporter, new BufferedOutput, [], $outputType))->toBeNull();
    })->with([
        'audio bard' => ['audio', 'bard'],
        'accordion bard' => ['accordion', 'bard'],
        'audio page builder' => ['audio', 'page_builder'],
        'accordion page builder' => ['accordion', 'page_builder'],
    ]);

    it('builds a Bard set from the handler values and shares the shortcode context', function (?array $parsed, array $values): void {
        ShortcodeProbeHandler::$parseResult = $parsed;

        $set = shortcodeProcessor()->process(
            ['name' => 'probe', 'attributes' => ['a' => '1'], 'content' => 'inner'],
            'site-a',
            new AssetImporter,
            new BufferedOutput,
            ['term'],
            'bard',
            ['att'],
            'about'
        );

        expect($set)->toBeArray()
            ->and(data_get($set, 'type'))->toBe('set')
            ->and(data_get($set, 'attrs.values'))->toBe($values)
            ->and(data_get($set, 'attrs.id'))->toBeString()->not->toBe('')
            ->and(ProcessingContext::getAttachments())->toBe(['att'])
            ->and(ProcessingContext::getShortcodeContent())->toBe('inner')
            ->and(ShortcodeProbeHandler::$calls)->toBe([
                ['construct', 'unset'],
                ['parse', ['a' => '1'], 'site-a', ['term'], 'about'],
            ]);
    })->with([
        'nested values' => [['attrs' => ['values' => ['k' => 'v']]], ['k' => 'v']],
        'flat values' => [['k' => 'v'], ['k' => 'v']],
        'attrs without values' => [['attrs' => ['other' => 1]], ['attrs' => ['other' => 1]]],
        'non-array attrs' => [['attrs' => 'x'], ['attrs' => 'x']],
        'non-array values' => [['attrs' => ['values' => 'x']], ['attrs' => ['values' => 'x']]],
        'no result' => [null, []],
    ]);

    it('leaves the shared shortcode content alone when the shortcode has none', function (): void {
        ProcessingContext::setShortcodeContent('earlier');

        shortcodeProcessor()->process(['name' => 'probe', 'content' => ''], 'site-a', new AssetImporter, new BufferedOutput);

        expect(ProcessingContext::getShortcodeContent())->toBe('earlier');
    });

    it('gives each Bard set its own id', function (): void {
        $processor = shortcodeProcessor();

        $first = $processor->process(['name' => 'probe'], 'site-a', new AssetImporter, new BufferedOutput);
        $second = $processor->process(['name' => 'probe'], 'site-a', new AssetImporter, new BufferedOutput);

        expect(data_get($first, 'attrs.id'))->not->toBe(data_get($second, 'attrs.id'));
    });

    it('treats non-array attributes as none', function (): void {
        shortcodeProcessor()->process(['name' => 'probe', 'attributes' => 'x'], 'site-a', new AssetImporter, new BufferedOutput);

        expect(ShortcodeProbeHandler::$calls[1][1])->toBe([]);
    });

    it('returns null for a registered class that is not a shortcode processor', function (string $outputType): void {
        expect(shortcodeProcessor()->process(['name' => 'plain'], 'site-a', new AssetImporter, new BufferedOutput, [], $outputType))->toBeNull();
    })->with(['bard', 'page_builder']);

    it('returns the handler page builder block', function (): void {
        ShortcodeProbeHandler::$block = ['type' => 'probe_block'];

        $block = shortcodeProcessor()->process(['name' => 'probe', 'attributes' => ['a' => '1']], 'site-a', new AssetImporter, new BufferedOutput, ['term'], 'page_builder', [], 'about');

        expect($block)->toBe(['type' => 'probe_block'])
            ->and(ShortcodeProbeHandler::$calls)->toBe([
                ['construct', 'unset'],
                ['toPageBuilderBlock', ['a' => '1'], 'site-a', ['term'], 'about'],
            ]);
    });

    it('returns null when the handler block is not an array or there is no block method', function (string $name): void {
        ShortcodeProbeHandler::$block = 'not a block';

        expect(shortcodeProcessor()->process(['name' => $name], 'site-a', new AssetImporter, new BufferedOutput, [], 'page_builder'))->toBeNull();
    })->with(['probe', 'noblock']);

    it('warns about an unhandled output type', function (): void {
        $warnings = shortcodeWarnings();

        expect(shortcodeProcessor()->process(['name' => 'probe'], 'site-a', new AssetImporter, new BufferedOutput, [], 'json', [], 'about'))->toBeNull()
            ->and($warnings->getArrayCopy())->toBe([[
                'message' => "ShortcodeProcessor::process: Unhandled outputType 'json' for shortcode 'probe'",
                'context' => ['site' => 'site-a', 'pageSlug' => 'about', 'outputType' => 'json', 'shortcode_name' => 'probe'],
            ]]);
    });

    it('builds each people shortcode for its own WordPress taxonomy', function (string $name, array $expectedOutput): void {
        $warnings = shortcodeWarnings();
        $output = new BufferedOutput;
        $found = (new SimpleXMLElement(
            '<rss xmlns:wp="http://wordpress.org/export/1.2/"><channel><wp:term>'
            .'<wp:term_id>5</wp:term_id><wp:term_taxonomy>uamspeople-categories</wp:term_taxonomy>'
            .'<wp:term_slug>known</wp:term_slug><wp:term_name>Known</wp:term_name>'
            .'</wp:term></channel></rss>'
        ))->xpath('//wp:term');
        $terms = is_array($found) ? $found : [];

        shortcodeProcessor()->process(['name' => $name, 'attributes' => ['category' => 'missing']], 'site-a', new AssetImporter, $output, $terms, 'bard');

        // show-people reads uamspeople-categories, so its non-empty map reports the
        // missing slug; show-team reads tshowcase-categories, whose map is empty
        expect(array_values(array_filter(explode(PHP_EOL, $output->fetch()), static fn (string $line): bool => $line !== '')))->toBe($expectedOutput)
            ->and(count($warnings))->toBe(count($expectedOutput));
    })->with([
        'show-people' => ['show-people', ["Warning: People group term 'missing' not found - skipping"]],
        'show-team' => ['show-team', []],
    ]);

    it('builds a page builder caption block without the shortcode content', function (): void {
        // Current behavior: this path never puts the shortcode content where the
        // caption handler reads it, so the caption is lost
        $warnings = shortcodeWarnings();

        $block = shortcodeProcessor()->process(
            ['name' => 'caption', 'attributes' => ['align' => 'alignright'], 'content' => '<img src="https://example.com/a.jpg"> A caption'],
            'site-a',
            new AssetImporter,
            new BufferedOutput,
            [],
            'page_builder',
            [],
            'about'
        );

        expect($block)->toBeArray()
            ->and(array_column($warnings->getArrayCopy(), 'context'))->toBe([['site' => 'site-a', 'pageSlug' => 'about']])
            ->and($block['id'] ?? null)->toBeString()
            ->and(array_diff_key($block ?? [], ['id' => true]))->toBe(['type' => 'uamsweb_block_caption', 'enabled' => true, 'caption' => null, 'align' => null])
            ->and(array_column($warnings->getArrayCopy(), 'message'))->toBe(['CaptionShortcodeProcessor::parse: No content provided for [caption] shortcode']);
    });
});

describe('processShortcodesInContent', function (): void {
    it('returns the handler markup alone for a single shortcode wrapped in a paragraph', function (string $content): void {
        expect(shortcodeProcessor()->processShortcodesInContent($content, 'site-a', new AssetImporter, new BufferedOutput))
            ->toBe('<probe:unset>');
    })->with([
        'tight' => ['<p>[probe a=1]</p>'],
        'padded' => ["  <p>\n [probe a=1] \n</p>  "],
        'regex characters in the shortcode' => ['<p>[probe a="(x)*"]</p>'],
    ]);

    it('replaces each shortcode in place and strips dropped ones', function (): void {
        $content = '<p>A [probe a=2] B [subpage-list link=""] C [mystery a=1] D [probe x=1]</p>';

        expect(shortcodeProcessor()->processShortcodesInContent($content, 'site-a', new AssetImporter, new BufferedOutput))
            ->toBe('<p>A <probe:unset> B  C [mystery a=1] D <probe:unset></p>');
    });

    it('replaces a single shortcode that is not alone in its paragraph', function (): void {
        expect(shortcodeProcessor()->processShortcodesInContent('<p>Intro [probe]</p>', 'site-a', new AssetImporter, new BufferedOutput))
            ->toBe('<p>Intro <probe:unset></p>');
    });

    it('strips a lone dropped shortcode from its paragraph', function (): void {
        expect(shortcodeProcessor()->processShortcodesInContent('<p>[subpage-list link=""]</p>', 'site-a', new AssetImporter, new BufferedOutput))
            ->toBe('<p></p>');
    });

    it('leaves unknown shortcodes in place and does not track them', function (): void {
        $processor = shortcodeProcessor();

        expect($processor->processShortcodesInContent('<p>Text [mystery a=1]</p>', 'site-a', new AssetImporter, new BufferedOutput, [], [], ['tablepress/table']))
            ->toBe('<p>Text [mystery a=1]</p>')
            ->and($processor->getUnaddressedNodes())->toBe([]);
    });
});
