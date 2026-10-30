<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\ActionBarParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\CallOutParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\ContentParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\CounterListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\CtaParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocLocationsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocProvidersParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\GalleryParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LinkListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LiveWhaleParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LogoListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\NewsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\OverlayParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\SectionParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\SideBySideParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\StackedParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\AcfBlockParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `AcfBlockParser` hands each ACF block comment to the parser for its
 * block type with the arguments that parser takes, counts the nodes a paired
 * block spans, routes a Stacked block by its items, reads a LiveWhale widget
 * div, reports undecodable block JSON, and extracts blocks from an HTML string.
 *
 * Every block parser is replaced with a recorder, so each test sees exactly
 * which parser was called, with what, and returns a set naming it.
 *
 * @command  php vendor/bin/pest tests/Integration/AcfBlockParserTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    Http::fake(['*' => Http::response('', 404)]);
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

/**
 * The calls each recording block parser received, and what each returns.
 */
final class AcfBlockCalls
{
    /**
     * Each call as the parser name and its arguments.
     *
     * @var list<array{string, list<mixed>}>
     */
    public array $calls = [];

    /**
     * What a parser returns, by parser name; a parser not listed returns a set naming it.
     *
     * @var array<string, mixed>
     */
    public array $returns = [];

    /**
     * Run on each call, before it returns, so a test can act from inside a parser.
     */
    public ?Closure $onRecord = null;

    /**
     * Records a call and returns what the named parser is set to return.
     *
     * @param  string  $name  The parser name
     * @param  list<mixed>  $arguments  The arguments it received
     * @return mixed What the parser returns
     */
    public function record(string $name, array $arguments): mixed
    {
        $this->calls[] = [$name, $arguments];

        if ($this->onRecord instanceof Closure) {
            ($this->onRecord)();
        }

        return \array_key_exists($name, $this->returns) ? $this->returns[$name] : ['set' => $name];
    }

    /**
     * The parser names called, in order.
     *
     * @return list<string> The parser names
     */
    public function names(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->calls);
    }
}

/**
 * Narrows a recorded return to an array, or an empty one.
 *
 * @param  mixed  $value  The recorded return
 * @return array<int|string, mixed> The array, or an empty one
 */
function acfBlockArray(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * Narrows a recorded return to an array, or null.
 *
 * @param  mixed  $value  The recorded return
 * @return ?array<int|string, mixed> The array, or null
 */
function acfBlockNullableArray(mixed $value): ?array
{
    return is_array($value) ? $value : null;
}

/**
 * An `AcfBlockParser` whose block parsers all record their calls.
 *
 * @param  AcfBlockCalls  $calls  The recorder
 */
function acfBlockParser(AcfBlockCalls $calls): AcfBlockParser
{
    return new AcfBlockParser(
        sectionParser: new class($calls) extends SectionParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, array $innerContent, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = [], array $terms = [], ?string $pageSlug = null): ?array
            {
                return acfBlockNullableArray($this->calls->record('section', [$attributes, $innerContent, $site, $assetImporter, $output, $attachments, $terms, $pageSlug]));
            }
        },
        contentParser: new class($calls) extends ContentParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, array $innerContent, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = [], array $terms = []): ?array
            {
                return acfBlockNullableArray($this->calls->record('content', [$attributes, $innerContent, $site, $assetImporter, $output, $attachments, $terms]));
            }
        },
        actionBarParser: new class($calls) extends ActionBarParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output): array
            {
                return acfBlockArray($this->calls->record('actionBar', [$attributes, $site, $assetImporter, $output]));
            }
        },
        ctaParser: new class($calls) extends CtaParser
        {
            public function __construct(private readonly AcfBlockCalls $calls)
            {
                parent::__construct();
            }

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = [], array $terms = []): array
            {
                return acfBlockArray($this->calls->record('cta', [$attributes, $site, $assetImporter, $output, $attachments, $terms]));
            }
        },
        callOutParser: new class($calls) extends CallOutParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = []): array
            {
                return acfBlockArray($this->calls->record('callOut', [$attributes, $site, $assetImporter, $output, $attachments]));
            }
        },
        sideBySideParser: new class($calls) extends SideBySideParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = []): array
            {
                return acfBlockArray($this->calls->record('sideBySide', [$attributes, $site, $assetImporter, $output, $attachments]));
            }
        },
        stackedParser: new class($calls) extends StackedParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = []): array
            {
                return acfBlockArray($this->calls->record('stacked', [$attributes, $site, $assetImporter, $output, $attachments]));
            }
        },
        overlayParser: new class($calls) extends OverlayParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = []): array
            {
                return acfBlockArray($this->calls->record('overlay', [$attributes, $site, $assetImporter, $output, $attachments]));
            }
        },
        newsParser: new class($calls) extends NewsParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = []): array
            {
                return acfBlockArray($this->calls->record('news', [$attributes, $site, $assetImporter, $output, $attachments]));
            }
        },
        liveWhaleParser: new class($calls) extends LiveWhaleParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = []): array
            {
                return acfBlockArray($this->calls->record('liveWhale', [$attributes, $site, $assetImporter, $output, $attachments]));
            }
        },
        linkListParser: new class($calls) extends LinkListParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = []): array
            {
                return acfBlockArray($this->calls->record('linkList', [$attributes, $site, $assetImporter, $output, $attachments]));
            }
        },
        counterListParser: new class($calls) extends CounterListParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = []): array
            {
                return acfBlockArray($this->calls->record('counterList', [$attributes, $site, $assetImporter, $output, $attachments]));
            }
        },
        galleryParser: new class($calls) extends GalleryParser
        {
            public function __construct(private readonly AcfBlockCalls $calls)
            {
                parent::__construct();
            }

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $attachments = [], array $terms = [], ?string $sourceUrl = null): array
            {
                return acfBlockArray($this->calls->record('gallery', [$attributes, $site, $assetImporter, $output, $attachments, $terms, $sourceUrl]));
            }
        },
        logoListParser: new class($calls) extends LogoListParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $terms = [], ?string $pageSlug = null): array
            {
                return acfBlockArray($this->calls->record('logoList', [$attributes, $site, $assetImporter, $output, $terms, $pageSlug]));
            }
        },
        findADocProvidersParser: new class($calls) extends FindADocProvidersParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $terms = [], array $items = [], ?string $pageSlug = null): array
            {
                return acfBlockArray($this->calls->record('findADocProviders', [$attributes, $site, $assetImporter, $output, $terms, $items, $pageSlug]));
            }
        },
        findADocLocationsParser: new class($calls) extends FindADocLocationsParser
        {
            public function __construct(private readonly AcfBlockCalls $calls) {}

            #[Override]
            public function parse(array $attributes, string $site, AssetImporter $assetImporter, OutputInterface $output, array $terms = [], array $items = [], ?string $pageSlug = null): array
            {
                return acfBlockArray($this->calls->record('findADocLocations', [$attributes, $site, $assetImporter, $output, $terms, $items, $pageSlug]));
            }
        },
    );
}

/**
 * The fixed values every parse in these tests passes through.
 */
final class AcfBlockContext
{
    /**
     * The asset importer.
     */
    public readonly FakeAssetImporter $assetImporter;

    /**
     * The console output.
     */
    public readonly NullOutput $output;

    /**
     * The WordPress items, whose first carries the source link.
     *
     * @var list<object>
     */
    public readonly array $items;

    /**
     * Builds the fixed values.
     */
    public function __construct()
    {
        $this->assetImporter = new FakeAssetImporter;
        $this->output = new NullOutput;
        $this->items = [acfBlockObject(['link' => 'https://example.edu/source-page/'])];
    }

    /**
     * Fills an argument template with the fixed values, the attributes, and the inner content.
     *
     * @param  array<mixed>  $template  The argument names, in order
     * @param  array<int|string, mixed>  $attributes  The attributes the parser should receive
     * @param  array<int|string, mixed>  $innerContent  The inner content the parser should receive
     * @return list<mixed> The expected arguments
     */
    public function arguments(array $template, array $attributes, array $innerContent = []): array
    {
        return array_map(fn (mixed $name): mixed => match ($name) {
            'attributes' => $attributes,
            'inner' => $innerContent,
            'site' => 'test_site',
            'importer' => $this->assetImporter,
            'output' => $this->output,
            'attachments' => ['attachment'],
            'terms' => ['term'],
            'items' => $this->items,
            'slug' => 'a-page',
            'source' => 'https://example.edu/source-page/',
            default => throw new InvalidArgumentException('Unknown argument'),
        }, array_values($template));
    }
}

/**
 * An object with the given properties, as a WXR item stands in for one.
 *
 * @param  array<string, mixed>  $properties  The properties
 * @return stdClass The object
 */
function acfBlockObject(array $properties): stdClass
{
    $object = new stdClass;

    foreach ($properties as $name => $value) {
        $object->{$name} = $value;
    }

    return $object;
}

/**
 * The body child nodes of an HTML fragment.
 *
 * @param  string  $html  The fragment
 * @return DOMNodeList<DOMNode> The nodes
 */
function acfBlockNodes(string $html): DOMNodeList
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML(
        '<html><body>'.$html.'</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $body = $document->getElementsByTagName('body')->item(0);

    if (! $body instanceof DOMElement) {
        throw new RuntimeException('No body element');
    }

    return $body->childNodes;
}

/**
 * Parses the node at an index of an HTML fragment.
 *
 * @param  AcfBlockParser  $parser  The parser
 * @param  AcfBlockContext  $context  The fixed values
 * @param  string  $html  The fragment
 * @param  int  $index  The index of the node to parse
 * @return array{processed: bool, skipCount: int, set: ?array<int|string, mixed>} The result
 */
function acfBlockParse(AcfBlockParser $parser, AcfBlockContext $context, string $html, int $index = 0): array
{
    $nodes = acfBlockNodes($html);
    $node = $nodes->item($index);

    if (! $node instanceof DOMNode) {
        throw new RuntimeException("No node at index {$index}");
    }

    return $parser->parseNode(
        $node,
        $index,
        $nodes,
        'test_site',
        $context->assetImporter,
        $context->output,
        ['attachment'],
        ['term'],
        $context->items,
        'a-page'
    );
}

/**
 * Each block type other than Stacked, its parser, the arguments that parser
 * takes, and whether the block may carry no attributes.
 *
 * @return array<string, array{string, string, list<string>, bool}>
 */
function acfBlockTypes(): array
{
    return [
        'action-bar' => ['action-bar', 'actionBar', ['attributes', 'site', 'importer', 'output'], true],
        'uams-section' => ['uams-section', 'section', ['attributes', 'inner', 'site', 'importer', 'output', 'attachments', 'terms', 'slug'], true],
        'uams-content' => ['uams-content', 'content', ['attributes', 'inner', 'site', 'importer', 'output', 'attachments', 'terms'], true],
        'cta' => ['cta', 'cta', ['attributes', 'site', 'importer', 'output', 'attachments', 'terms'], false],
        'call-out' => ['call-out', 'callOut', ['attributes', 'site', 'importer', 'output', 'attachments'], false],
        'image-side' => ['image-side', 'sideBySide', ['attributes', 'site', 'importer', 'output', 'attachments'], false],
        'text-overlay' => ['text-overlay', 'overlay', ['attributes', 'site', 'importer', 'output', 'attachments'], false],
        'uams-news' => ['uams-news', 'news', ['attributes', 'site', 'importer', 'output', 'attachments'], false],
        'livewhale-calendar' => ['livewhale-calendar', 'liveWhale', ['attributes', 'site', 'importer', 'output', 'attachments'], false],
        'link-list' => ['link-list', 'linkList', ['attributes', 'site', 'importer', 'output', 'attachments'], false],
        'counter-list' => ['counter-list', 'counterList', ['attributes', 'site', 'importer', 'output', 'attachments'], false],
        'uams-gallery' => ['uams-gallery', 'gallery', ['attributes', 'site', 'importer', 'output', 'attachments', 'terms', 'source'], false],
        'logo-list' => ['logo-list', 'logoList', ['attributes', 'site', 'importer', 'output', 'terms', 'slug'], false],
        'fad-providers' => ['fad-providers', 'findADocProviders', ['attributes', 'site', 'importer', 'output', 'terms', 'items', 'slug'], false],
        'fad-locations' => ['fad-locations', 'findADocLocations', ['attributes', 'site', 'importer', 'output', 'terms', 'items', 'slug'], false],
    ];
}

/**
 * Every supported block type, Stacked included.
 *
 * @return array<string, array{string}>
 */
function acfAllBlockTypes(): array
{
    $types = array_map(static fn (array $type): array => [$type[0]], acfBlockTypes());
    $types['text-stacked'] = ['text-stacked'];

    return $types;
}

describe('a self-closing block', function (): void {
    it('is handed to its parser with the decoded attributes', function (string $type, string $parser, array $template): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;

        $result = acfBlockParse(acfBlockParser($calls), $context, '<!-- wp:acf/'.$type.' {"data":{"field":"value"}} /-->');

        expect($result)->toBe(['processed' => true, 'skipCount' => 1, 'set' => ['set' => $parser]])
            ->and($calls->calls)->toBe([[$parser, $context->arguments($template, ['data' => ['field' => 'value']])]]);
    })->with(acfBlockTypes());

    it('reads attributes written across lines', function (string $type, string $parser, array $template): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;

        $result = acfBlockParse(acfBlockParser($calls), $context, "<!-- wp:acf/{$type}\n{\"data\":\n{\"field\":\"value\"}}\n/-->");

        expect($result['skipCount'])->toBe(1)
            ->and($calls->calls)->toBe([[$parser, $context->arguments($template, ['data' => ['field' => 'value']])]]);
    })->with(acfBlockTypes());

    it('passes empty attributes when its JSON cannot be decoded', function (string $type, string $parser, array $template): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;

        $result = acfBlockParse(acfBlockParser($calls), $context, '<!-- wp:acf/'.$type.' {not json} /-->');

        expect($result)->toBe(['processed' => true, 'skipCount' => 1, 'set' => ['set' => $parser]])
            ->and($calls->calls)->toBe([[$parser, $context->arguments($template, [])]]);
    })->with(acfBlockTypes());

    it('is parsed with empty attributes, or left alone, when it has none', function (string $type, string $parser, array $template, bool $optional): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;

        $result = acfBlockParse(acfBlockParser($calls), $context, '<!-- wp:acf/'.$type.' /-->');

        if ($optional) {
            expect($result)->toBe(['processed' => true, 'skipCount' => 1, 'set' => ['set' => $parser]])
                ->and($calls->calls)->toBe([[$parser, $context->arguments($template, [])]]);
        } else {
            expect($result)->toBe(['processed' => false, 'skipCount' => 0, 'set' => null])
                ->and($calls->calls)->toBe([]);
        }
    })->with(acfBlockTypes());

    it('needs whitespace before its attributes only when they are optional', function (string $type, string $parser, array $template, bool $optional): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;

        $result = acfBlockParse(acfBlockParser($calls), $context, '<!-- wp:acf/'.$type.'{"data":{"field":"value"}} /-->');

        if ($optional) {
            expect($result)->toBe(['processed' => false, 'skipCount' => 0, 'set' => null])
                ->and($calls->calls)->toBe([]);
        } else {
            expect($result)->toBe(['processed' => true, 'skipCount' => 1, 'set' => ['set' => $parser]])
                ->and($calls->calls)->toBe([[$parser, $context->arguments($template, ['data' => ['field' => 'value']])]]);
        }
    })->with(acfBlockTypes());

    it('gives a section or content block no inner content, whatever follows it', function (string $type, string $parser): void {
        $calls = new AcfBlockCalls;

        $result = acfBlockParse(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/'.$type.' {"data":{}} /--><p>after</p><!-- /wp:acf/'.$type.' -->'
        );

        expect($result['skipCount'])->toBe(1)
            ->and($calls->names())->toBe([$parser])
            ->and($calls->calls[0][1][1])->toBe([]);
    })->with([
        'section' => ['uams-section', 'section'],
        'content' => ['uams-content', 'content'],
    ]);

    it('is matched against the block types in order', function (): void {
        $calls = new AcfBlockCalls;

        acfBlockParse(acfBlockParser($calls), new AcfBlockContext, '<!-- wp:acf/cta {"a":"wp:acf/uams-section {}"} /-->');

        expect($calls->names())->toBe(['section']);
    });

    it('returns what its parser returns', function (): void {
        $calls = new AcfBlockCalls;
        $calls->returns['section'] = null;

        $result = acfBlockParse(acfBlockParser($calls), new AcfBlockContext, '<!-- wp:acf/uams-section {"data":{}} /-->');

        expect($result)->toBe(['processed' => true, 'skipCount' => 1, 'set' => null]);
    });

    it('passes no source URL to the gallery when the first item has no string link', function (array $items): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;
        $nodes = acfBlockNodes('<!-- wp:acf/uams-gallery {"data":{}} /-->');
        $node = $nodes->item(0);

        if (! $node instanceof DOMNode) {
            throw new RuntimeException('No node');
        }

        acfBlockParser($calls)->parseNode($node, 0, $nodes, 'test_site', $context->assetImporter, $context->output, [], [], $items);

        expect($calls->calls)->toHaveCount(1)
            ->and($calls->calls[0][1][6])->toBeNull();
    })->with([
        'no items' => [[]],
        'an array item' => [[['link' => 'https://example.edu/']]],
        'no link' => [[acfBlockObject(['title' => 'Page'])]],
        'a link that is not a string' => [[acfBlockObject(['link' => 42])]],
        'the link on the second item' => [[acfBlockObject([]), acfBlockObject(['link' => 'https://example.edu/'])]],
    ]);
});

describe('a paired block', function (): void {
    it('counts the nodes through its closing comment, ignoring text and other closings', function (string $type, string $parser, array $template): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;

        $result = acfBlockParse(
            acfBlockParser($calls),
            $context,
            '<p>before</p><!-- wp:acf/'.$type.' {"data":{"field":"value"}} --><p>inside</p>/wp:acf/'.$type.'<!-- /wp:acf/other-block --><!-- /wp:acf/'.$type.' --><p>after</p>',
            1
        );

        $inner = [
            ['html' => '<p>inside</p>'],
            ['html' => '/wp:acf/'.$type],
            ['html' => '<!-- /wp:acf/other-block -->'],
        ];

        expect($result)->toBe(['processed' => true, 'skipCount' => 5, 'set' => ['set' => $parser]])
            ->and($calls->calls)->toBe([[$parser, $context->arguments($template, ['data' => ['field' => 'value']], $inner)]]);
    })->with(acfBlockTypes());

    it('counts to the last node when its closing comment is missing', function (string $type, string $lead, int $index): void {
        $result = acfBlockParse(
            acfBlockParser(new AcfBlockCalls),
            new AcfBlockContext,
            $lead.'<!-- wp:acf/'.$type.' {"data":{"field":"value"}} --><p>one</p><p>two</p>',
            $index
        );

        expect($result['processed'])->toBeTrue()
            ->and($result['skipCount'])->toBe(3);
    })->with(acfAllBlockTypes())->with([
        'as the first node' => ['', 0],
        'after two other nodes' => ['<p>a</p><p>b</p>', 2],
    ]);

    it('ends at a closing comment that follows it directly', function (string $type): void {
        $result = acfBlockParse(
            acfBlockParser(new AcfBlockCalls),
            new AcfBlockContext,
            '<!-- wp:acf/'.$type.' {"data":{}} --><!-- /wp:acf/'.$type.' --><p>after</p>'
        );

        expect($result['skipCount'])->toBe(2);
    })->with(acfAllBlockTypes());

    it('does not take its own opening comment for its closing one', function (string $type): void {
        $result = acfBlockParse(
            acfBlockParser(new AcfBlockCalls),
            new AcfBlockContext,
            '<!-- wp:acf/'.$type.' {"data":{"a":"/wp:acf/'.$type.'"}} --><p>inside</p><!-- /wp:acf/'.$type.' -->'
        );

        expect($result['skipCount'])->toBe(3);
    })->with(acfAllBlockTypes());

    it('ignores a closing comment before it', function (string $type): void {
        $result = acfBlockParse(
            acfBlockParser(new AcfBlockCalls),
            new AcfBlockContext,
            '<p>a</p><!-- /wp:acf/'.$type.' --><!-- wp:acf/'.$type.' {"data":{}} --><p>inside</p><!-- /wp:acf/'.$type.' -->',
            2
        );

        expect($result['skipCount'])->toBe(3);
    })->with(acfAllBlockTypes());

    it('is paired when its JSON ends the comment without a slash', function (string $type): void {
        $result = acfBlockParse(
            acfBlockParser(new AcfBlockCalls),
            new AcfBlockContext,
            '<!-- wp:acf/'.$type.' {"data":{"field":"a/"}} --><p>one</p><!-- /wp:acf/'.$type.' -->'
        );

        expect($result['skipCount'])->toBe(3);
    })->with(acfAllBlockTypes());
});

describe('the inner content of a section or content block', function (): void {
    it('stops at the closing comment of any of the four blocks that hold content', function (string $closing): void {
        $calls = new AcfBlockCalls;

        acfBlockParse(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/uams-content {"data":{}} --><p>kept</p><!-- /wp:acf/'.$closing.' --><p>left out</p><!-- /wp:acf/uams-content -->'
        );

        expect($calls->calls[0][1][1])->toBe([['html' => '<p>kept</p>']]);
    })->with(['uams-section', 'action-bar', 'uams-content', 'cta']);

    it('keeps the nodes after the closing comment of any other block', function (): void {
        $calls = new AcfBlockCalls;

        acfBlockParse(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/uams-section {"data":{}} --><p>one</p><!-- /wp:acf/call-out --><p>two</p><!-- /wp:acf/uams-section -->'
        );

        expect($calls->calls[0][1][1])->toBe([
            ['html' => '<p>one</p>'],
            ['html' => '<!-- /wp:acf/call-out -->'],
            ['html' => '<p>two</p>'],
        ]);
    });

    it('stops at a closing comment written with surrounding whitespace', function (): void {
        $calls = new AcfBlockCalls;

        acfBlockParse(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/uams-section {"data":{}} --><p>one</p><!--   /wp:acf/uams-section   --><p>two</p>'
        );

        expect($calls->calls[0][1][1])->toBe([['html' => '<p>one</p>']]);
    });

    it('collects to the last node when no closing comment follows', function (): void {
        $calls = new AcfBlockCalls;

        acfBlockParse(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/uams-section {"data":{}} --><p>one</p><p>two</p>'
        );

        expect($calls->calls[0][1][1])->toBe([['html' => '<p>one</p>'], ['html' => '<p>two</p>']]);
    });

    it('takes the HTML a block processor makes of a block comment, and skips the nodes it consumed', function (): void {
        $calls = new AcfBlockCalls;

        acfBlockParse(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/uams-section {"data":{}} --><!-- wp:spacer {"height":"20px"} --><div class="wp-block-spacer"></div><!-- /wp:spacer --><p>after</p><!-- /wp:acf/uams-section -->'
        );

        $inner = acfBlockArray($calls->calls[0][1][1]);
        $first = acfBlockArray($inner[0] ?? null);

        expect($inner)->toHaveCount(2)
            ->and($first)->toHaveKeys(['html', 'processedNode'])
            ->and($first['html'] ?? null)->toBe('')
            ->and($first['processedNode'] ?? null)->toBeInstanceOf(DOMComment::class)
            ->and($inner[1] ?? null)->toBe(['html' => '<p>after</p>']);
    });
});

describe('a Stacked block', function (): void {
    it('is parsed as a Stacked block when an item has an image', function (string $suffix, int $skip): void {
        $calls = new AcfBlockCalls;
        $calls->returns['stacked'] = ['items' => ['item']];
        $context = new AcfBlockContext;
        $attributes = ['data' => ['stacked_section' => '1', 'stacked_section_0_stacked_section_image' => '12']];

        $result = acfBlockParse(acfBlockParser($calls), $context, '<!-- wp:acf/text-stacked '.json_encode($attributes).$suffix);

        expect($result)->toBe(['processed' => true, 'skipCount' => $skip, 'set' => ['items' => ['item']]])
            ->and($calls->calls)->toBe([['stacked', $context->arguments(['attributes', 'site', 'importer', 'output', 'attachments'], $attributes)]]);
    })->with([
        'self-closing' => [' /-->', 1],
        'paired' => [' --><p>x</p><!-- /wp:acf/text-stacked --><p>y</p>', 3],
    ]);

    it('is parsed as a Stacked block when it has no items', function (): void {
        $calls = new AcfBlockCalls;
        $calls->returns['stacked'] = ['items' => ['item']];

        acfBlockParse(acfBlockParser($calls), new AcfBlockContext, '<!-- wp:acf/text-stacked {"data":{"stacked_heading":"Title"}} /-->');

        expect($calls->names())->toBe(['stacked']);
    });

    it('falls back to a section when the Stacked parse has no items', function (array $returned, string $suffix, int $skip): void {
        $calls = new AcfBlockCalls;
        $calls->returns['stacked'] = $returned;
        $context = new AcfBlockContext;
        $attributes = ['data' => [
            'stacked_heading' => 'Title',
            'stacked_section' => '1',
            'stacked_section_0_stacked_section_image' => '12',
            'stacked_section_0_stacked_section_heading' => 'One',
        ]];
        $section = StackedParser::convertToSectionFormat($attributes);

        $result = acfBlockParse(acfBlockParser($calls), $context, '<!-- wp:acf/text-stacked '.json_encode($attributes).$suffix);

        expect($section['attributes'])->toBe(['data' => ['section_heading' => 'Title']])
            ->and($section['innerContent'])->toHaveCount(1)
            ->and($result)->toBe(['processed' => true, 'skipCount' => $skip, 'set' => ['set' => 'section']])
            ->and($calls->names())->toBe(['stacked', 'section'])
            ->and($calls->calls[1][1])->toBe($context->arguments(
                ['attributes', 'inner', 'site', 'importer', 'output', 'attachments', 'terms', 'slug'],
                $section['attributes'],
                $section['innerContent']
            ));
    })->with([
        'an empty set' => [[]],
        'an empty item list' => [['items' => []]],
    ])->with([
        'self-closing' => [' /-->', 1],
        'paired' => [' --><p>x</p><!-- /wp:acf/text-stacked --><p>y</p>', 3],
    ]);

    it('keeps a Stacked set that has items or no item list', function (array $returned): void {
        $calls = new AcfBlockCalls;
        $calls->returns['stacked'] = $returned;

        $result = acfBlockParse(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/text-stacked {"data":{"stacked_section":"1","stacked_section_0_stacked_section_image":"12"}} /-->'
        );

        expect($result['set'])->toBe($returned)
            ->and($calls->names())->toBe(['stacked']);
    })->with([
        'items' => [['items' => ['item']]],
        'no item list' => [['heading' => 'Title']],
    ]);

    it('is parsed as a link list when no item has an image and every item has a URL', function (string $suffix, int $skip): void {
        $calls = new AcfBlockCalls;
        $calls->returns['linkList'] = ['items' => ['link']];
        $context = new AcfBlockContext;
        $attributes = ['data' => [
            'stacked_section' => '2',
            'stacked_section_0_stacked_section_button_url' => 'https://example.edu/one',
            'stacked_section_1_stacked_section_button_url' => 'https://example.edu/two',
        ]];

        $result = acfBlockParse(acfBlockParser($calls), $context, '<!-- wp:acf/text-stacked '.json_encode($attributes).$suffix);

        expect($result)->toBe(['processed' => true, 'skipCount' => $skip, 'set' => ['items' => ['link']]])
            ->and($calls->calls)->toBe([[
                'linkList',
                $context->arguments(['attributes', 'site', 'importer', 'output', 'attachments'], StackedParser::convertToLinkListAttributes($attributes)),
            ]]);
    })->with([
        'self-closing' => [' /-->', 1],
        'paired' => [' --><p>x</p><!-- /wp:acf/text-stacked --><p>y</p>', 3],
    ]);

    it('falls back to a section when the link list has no items', function (array $returned): void {
        $calls = new AcfBlockCalls;
        $calls->returns['linkList'] = $returned;
        $attributes = ['data' => ['stacked_section' => '1', 'stacked_section_0_stacked_section_button_url' => 'https://example.edu/one']];

        $result = acfBlockParse(acfBlockParser($calls), new AcfBlockContext, '<!-- wp:acf/text-stacked '.json_encode($attributes).' /-->');

        expect($result['set'])->toBe(['set' => 'section'])
            ->and($calls->names())->toBe(['linkList', 'section']);
    })->with([
        'an empty set' => [[]],
        'an empty item list' => [['items' => []]],
    ]);

    it('keeps a link list that has items or no item list', function (array $returned): void {
        $calls = new AcfBlockCalls;
        $calls->returns['linkList'] = $returned;

        $result = acfBlockParse(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/text-stacked {"data":{"stacked_section":"1","stacked_section_0_stacked_section_button_url":"https://example.edu/"}} /-->'
        );

        expect($result['set'])->toBe($returned)
            ->and($calls->names())->toBe(['linkList']);
    })->with([
        'items' => [['items' => ['link']]],
        'no item list' => [['heading' => 'Links']],
    ]);

    it('is parsed as a section when no item has an image and one has no URL', function (string $suffix, int $skip): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;
        $attributes = ['data' => [
            'stacked_heading' => 'Title',
            'stacked_section' => '2',
            'stacked_section_0_stacked_section_button_url' => 'https://example.edu/one',
            'stacked_section_1_stacked_section_body' => 'Body',
        ]];
        $section = StackedParser::convertToSectionFormat($attributes);

        $result = acfBlockParse(acfBlockParser($calls), $context, '<!-- wp:acf/text-stacked '.json_encode($attributes).$suffix);

        expect($result)->toBe(['processed' => true, 'skipCount' => $skip, 'set' => ['set' => 'section']])
            ->and($calls->calls)->toBe([[
                'section',
                $context->arguments(['attributes', 'inner', 'site', 'importer', 'output', 'attachments', 'terms', 'slug'], $section['attributes'], $section['innerContent']),
            ]]);
    })->with([
        'self-closing' => [' /-->', 1],
        'paired' => [' --><p>x</p><!-- /wp:acf/text-stacked --><p>y</p>', 3],
    ]);

    it('reads its items from the attributes themselves when there is no data array', function (string $json): void {
        $calls = new AcfBlockCalls;

        acfBlockParse(acfBlockParser($calls), new AcfBlockContext, '<!-- wp:acf/text-stacked '.$json.' /-->');

        expect($calls->names())->toBe(['section']);
    })->with([
        'no data key' => ['{"stacked_section":"1","stacked_section_0_stacked_section_body":"Body"}'],
        'data that is not an array' => ['{"data":"text","stacked_section":"1","stacked_section_0_stacked_section_body":"Body"}'],
    ]);

    it('normalizes field keys before reading its items', function (): void {
        $calls = new AcfBlockCalls;

        acfBlockParse(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/text-stacked {"data":{"stacked_section":"1","_stacked_section":"field_x","stacked_section_0_stacked_section_body":"Body"}} /-->'
        );

        expect($calls->names())->toBe(['section']);
    });
});

describe('a comment that is not an opening block', function (): void {
    it('is left alone when it is a closing comment', function (string $comment): void {
        $calls = new AcfBlockCalls;

        $result = acfBlockParse(acfBlockParser($calls), new AcfBlockContext, $comment);

        expect($result)->toBe(['processed' => false, 'skipCount' => 0, 'set' => null])
            ->and($calls->calls)->toBe([]);
    })->with([
        'a supported block' => ['<!-- /wp:acf/cta -->'],
        'with extra whitespace' => ["<!--  \n /wp:acf/uams-section  -->"],
        'an unsupported block' => ['<!-- /wp:acf/unknown -->'],
    ]);

    it('is marked processed when it carries a supported closing tag after other text', function (string $type): void {
        $calls = new AcfBlockCalls;

        $result = acfBlockParse(acfBlockParser($calls), new AcfBlockContext, '<!-- note /wp:acf/'.$type.' closed here -->');

        expect($result)->toBe(['processed' => true, 'skipCount' => 0, 'set' => null])
            ->and($calls->calls)->toBe([]);
    })->with(acfAllBlockTypes());

    it('is left alone when it names no supported block', function (string $comment): void {
        $calls = new AcfBlockCalls;

        $result = acfBlockParse(acfBlockParser($calls), new AcfBlockContext, $comment);

        expect($result)->toBe(['processed' => false, 'skipCount' => 0, 'set' => null])
            ->and($calls->calls)->toBe([]);
    })->with([
        'a core block' => ['<!-- wp:paragraph -->'],
        'an unsupported ACF block' => ['<!-- wp:acf/unknown {"data":{}} /-->'],
        'an unsupported closing tag after text' => ['<!-- note /wp:acf/unknown -->'],
        'a longer block name' => ['<!-- wp:acf/cta-banner {"data":{}} /-->'],
    ]);
});

describe('a LiveWhale widget div', function (): void {
    it('is parsed as a LiveWhale block with the id from its options', function (string $class, string $options, string $id): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;

        $result = acfBlockParse(
            acfBlockParser($calls),
            $context,
            '<div class="'.$class.'" data-options="'.$options.'"></div><script src="widget.js"></script>'
        );

        expect($result)->toBe(['processed' => true, 'skipCount' => 2, 'set' => ['set' => 'liveWhale']])
            ->and($calls->calls)->toBe([[
                'liveWhale',
                $context->arguments(['attributes', 'site', 'importer', 'output', 'attachments'], ['data' => [
                    'livewhale_heading' => "Placeholder Block Heading \u{2014} Please Replace",
                    'livewhale_id' => $id,
                    'livewhale_background_color' => 'bg-auto',
                ]]),
            ]]);
    })->with([
        'the id first' => ['lwcw', 'id=34&amp;format=html', '34'],
        'among other classes' => ['widget lwcw calendar', 'id=7', '7'],
        'a hyphenated class' => ['lwcw-widget', 'id=7', '7'],
        'an id of one' => ['lwcw', 'id=1', '1'],
        'an id after an escaped ampersand' => ['lwcw', 'format=html&amp;amp;id=34', '34'],
    ]);

    it('is left alone when it is not a usable widget', function (string $html): void {
        $calls = new AcfBlockCalls;

        $result = acfBlockParse(acfBlockParser($calls), new AcfBlockContext, $html);

        expect($result)->toBe(['processed' => false, 'skipCount' => 0, 'set' => null])
            ->and($calls->calls)->toBe([]);
    })->with([
        'another class' => ['<div class="lwcwx" data-options="id=34"></div>'],
        'no class' => ['<div data-options="id=34"></div>'],
        'no options' => ['<div class="lwcw"></div>'],
        'empty options' => ['<div class="lwcw" data-options=""></div>'],
        'no id' => ['<div class="lwcw" data-options="format=html"></div>'],
        'an id of zero' => ['<div class="lwcw" data-options="id=0"></div>'],
        'a negative id' => ['<div class="lwcw" data-options="id=-3"></div>'],
        'an id that is not a number' => ['<div class="lwcw" data-options="id=abc"></div>'],
        'another element' => ['<span class="lwcw" data-options="id=34"></span>'],
        'a text node' => ['lwcw id=34'],
    ]);

    it('is left alone when it is a node named div that is not an element', function (): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;
        $attribute = (new DOMDocument)->createAttribute('div');
        $attribute->value = 'lwcw';

        $result = (acfBlockParser($calls))->parseNode(
            $attribute,
            0,
            acfBlockNodes('<p>a</p>'),
            'test_site',
            $context->assetImporter,
            $context->output
        );

        expect($result)->toBe(['processed' => false, 'skipCount' => 0, 'set' => null])
            ->and($calls->calls)->toBe([]);
    });

    it('returns the empty set the LiveWhale parser makes without marking it processed', function (): void {
        $calls = new AcfBlockCalls;
        $calls->returns['liveWhale'] = [];

        $result = acfBlockParse(acfBlockParser($calls), new AcfBlockContext, '<div class="lwcw" data-options="id=34"></div>');

        expect($result)->toBe(['processed' => false, 'skipCount' => 0, 'set' => []])
            ->and($calls->names())->toBe(['liveWhale']);
    });
});

describe('an undecodable block payload', function (): void {
    it('logs a warning naming the block, the page and the decode error', function (): void {
        /** @var MockInterface $logSpy */
        $logSpy = Log::spy();

        acfBlockParse(acfBlockParser(new AcfBlockCalls), new AcfBlockContext, '<!-- wp:acf/cta {not json} /-->');

        $logSpy->shouldHaveReceived('warning', [
            'AcfBlockParser: failed to json_decode wp:acf/cta block comment (Syntax error); block attributes are empty',
            [
                'block_type' => 'wp:acf/cta',
                'page_slug' => 'a-page',
                'json_error' => 'Syntax error',
                'json_snippet' => '{not json}',
            ],
        ]);
    });

    it('names the page it came from', function (): void {
        /** @var MockInterface $logSpy */
        $logSpy = Log::spy();
        ProcessingContext::setWordPressPageLink('https://example.edu/page/');

        acfBlockParse(acfBlockParser(new AcfBlockCalls), new AcfBlockContext, '<!-- wp:acf/uams-news {not json} -->');

        $logSpy->shouldHaveReceived('warning', [
            'AcfBlockParser: failed to json_decode wp:acf/uams-news block comment (Syntax error); block attributes are empty (from entry: https://example.edu/page/)',
            [
                'block_type' => 'wp:acf/uams-news',
                'page_slug' => 'a-page',
                'json_error' => 'Syntax error',
                'json_snippet' => '{not json}',
                'entry_url' => 'https://example.edu/page/',
            ],
        ]);
    });

    it('quotes at most 200 characters of the payload', function (int $length, string $suffix): void {
        /** @var MockInterface $logSpy */
        $logSpy = Log::spy();
        $json = '{'.str_repeat('x', $length - 2).'}';

        acfBlockParse(acfBlockParser(new AcfBlockCalls), new AcfBlockContext, '<!-- wp:acf/cta '.$json.' /-->');

        $logSpy->shouldHaveReceived('warning', [
            Mockery::any(),
            Mockery::on(static fn (mixed $context): bool => is_array($context) && ($context['json_snippet'] ?? null) === substr($json, 0, 200).$suffix),
        ]);
    })->with([
        '200 characters' => [200, ''],
        '201 characters' => [201, '...'],
    ]);

    it('logs nothing when the payload decodes, or there is none to decode', function (string $comment): void {
        /** @var MockInterface $logSpy */
        $logSpy = Log::spy();

        acfBlockParse(acfBlockParser(new AcfBlockCalls), new AcfBlockContext, $comment);

        $logSpy->shouldNotHaveReceived('warning');
    })->with([
        'valid JSON' => ['<!-- wp:acf/cta {"data":{"a":1}} /-->'],
        'no payload' => ['<!-- wp:acf/action-bar /-->'],
        'no whitespace before the payload' => ['<!-- wp:acf/cta{not json} /-->'],
        'text before the block' => ['<!-- note wp:acf/cta {not json} /-->'],
        'a closing tag' => ['<!-- /wp:acf/cta -->'],
    ]);
});

/**
 * Extracts the ACF blocks from an HTML string.
 *
 * @param  AcfBlockParser  $parser  The parser
 * @param  AcfBlockContext  $context  The fixed values
 * @param  string  $html  The HTML string
 * @return array<int|string, mixed> The sets and the remaining HTML
 */
function acfBlocksFromHtml(AcfBlockParser $parser, AcfBlockContext $context, string $html): array
{
    return $parser->parseAcfBlocksFromHtml(
        $html,
        'test_site',
        $context->assetImporter,
        $context->output,
        ['attachment'],
        ['term'],
        $context->items,
        'a-page'
    );
}

describe('blocks in an HTML string', function (): void {
    it('returns the HTML unchanged when it holds no supported block', function (string $html): void {
        $calls = new AcfBlockCalls;

        expect(acfBlocksFromHtml(acfBlockParser($calls), new AcfBlockContext, $html))
            ->toBe(['acfSets' => [], 'remainingHtml' => $html])
            ->and($calls->calls)->toBe([]);
    })->with([
        'plain HTML' => ['<p>Text</p>'],
        'an unsupported block' => ['<p>a</p><!-- wp:acf/unknown {"a":1} /--><p>b</p>'],
    ]);

    it('replaces each self-closing block with its set, keeping the HTML around it', function (): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;

        $result = acfBlocksFromHtml(
            acfBlockParser($calls),
            $context,
            '<p>a</p><!-- wp:acf/cta {"data":{"x":1}} /--><p>b</p><!--wp:acf/call-out {"data":{"y":2}}/--><p>c</p>'
        );

        expect($result)->toBe([
            'acfSets' => [['set' => 'cta'], ['set' => 'callOut']],
            'remainingHtml' => '<p>a</p><p>b</p><p>c</p>',
        ])->and($calls->calls)->toBe([
            ['cta', $context->arguments(['attributes', 'site', 'importer', 'output', 'attachments', 'terms'], ['data' => ['x' => 1]])],
            ['callOut', $context->arguments(['attributes', 'site', 'importer', 'output', 'attachments'], ['data' => ['y' => 2]])],
        ]);
    });

    it('keeps an empty set and drops a block whose parser returns none', function (): void {
        $calls = new AcfBlockCalls;
        $calls->returns['cta'] = [];
        $calls->returns['section'] = null;

        $result = acfBlocksFromHtml(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/cta {"data":{}} /--><!-- wp:acf/uams-section {"data":{}} /--><!-- wp:acf/link-list {"data":{}} /-->'
        );

        expect($result)->toBe(['acfSets' => [[], ['set' => 'linkList']], 'remainingHtml' => ''])
            ->and($calls->names())->toBe(['cta', 'section', 'linkList']);
    });

    it('drops a block the node parser does not process, and the comment with it', function (): void {
        $calls = new AcfBlockCalls;

        $result = acfBlocksFromHtml(acfBlockParser($calls), new AcfBlockContext, '<p>a</p><!-- wp:acf/cta /--><p>b</p>');

        expect($result)->toBe(['acfSets' => [], 'remainingHtml' => '<p>a</p><p>b</p>'])
            ->and($calls->calls)->toBe([]);
    });

    /**
     * A paired block's content between its comments is its parser's inner content, as it
     * is for the same block in a whole post, and neither the content nor the closing comment
     * stays in the HTML (#201).
     *
     * @param  string  $type  The block type
     * @param  string  $parser  The parser it reaches
     * @param  list<string>  $arguments  The arguments that parser takes
     */
    it('hands a paired section or content block its inner content, and keeps only the HTML outside it', function (string $type, string $parser, array $arguments): void {
        $block = '<!-- wp:acf/'.$type.' {"data":{"x":1}} --><p>inside</p><h2>Heading</h2><!-- /wp:acf/'.$type.' -->';
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;

        $result = acfBlocksFromHtml(acfBlockParser($calls), $context, '<p>a</p>'.$block.'<p>b</p>');

        // The inner content the whole-post path hands the same block
        $postCalls = new AcfBlockCalls;
        acfBlockParse(acfBlockParser($postCalls), $context, $block);
        $inner = acfBlockArray($postCalls->calls[0][1][1] ?? null);

        expect($inner)->not->toBe([])
            ->and($result)->toBe([
                'acfSets' => [['set' => $parser]],
                'remainingHtml' => '<p>a</p><p>b</p>',
            ])
            ->and($calls->calls)->toBe([[$parser, $context->arguments($arguments, ['data' => ['x' => 1]], innerContent: $inner)]]);
    })->with([
        'uams-section' => ['uams-section', 'section', ['attributes', 'inner', 'site', 'importer', 'output', 'attachments', 'terms', 'slug']],
        'uams-content' => ['uams-content', 'content', ['attributes', 'inner', 'site', 'importer', 'output', 'attachments', 'terms']],
    ]);

    it('removes a paired block of another type with its content and closing comment', function (): void {
        $calls = new AcfBlockCalls;

        $result = acfBlocksFromHtml(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<p>a</p><!-- wp:acf/cta {"data":{"x":1}} --><p>inside</p><!-- /wp:acf/cta --><p>b</p>'
        );

        expect($result)->toBe(['acfSets' => [['set' => 'cta']], 'remainingHtml' => '<p>a</p><p>b</p>'])
            ->and($calls->names())->toBe(['cta']);
    });

    it('parses a paired block with no closing comment from its opening comment, and keeps the rest', function (): void {
        $calls = new AcfBlockCalls;
        $context = new AcfBlockContext;

        $result = acfBlocksFromHtml(
            acfBlockParser($calls),
            $context,
            '<p>a</p><!-- wp:acf/uams-section {"data":{"x":1}} --><p>inside</p><!-- /wp:acf/cta --><p>b</p>'
        );

        expect($result)->toBe([
            'acfSets' => [['set' => 'section']],
            'remainingHtml' => '<p>a</p><p>inside</p><!-- /wp:acf/cta --><p>b</p>',
        ])->and($calls->calls)->toBe([[
            'section',
            $context->arguments(['attributes', 'inner', 'site', 'importer', 'output', 'attachments', 'terms', 'slug'], ['data' => ['x' => 1]]),
        ]]);
    });

    it('parses no block a paired block contains a second time, and parses the blocks after it', function (): void {
        $calls = new AcfBlockCalls;

        $result = acfBlocksFromHtml(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<p>a</p><!-- wp:acf/uams-section {"data":{}} --><p>in</p><!-- wp:acf/call-out {"data":{}} /--><!-- /wp:acf/uams-section -->'
                .'<p>b</p><!-- wp:acf/cta {"data":{}} --><p>c</p><!-- /wp:acf/cta --><!-- wp:acf/link-list {"data":{}} /--><p>d</p>'
        );

        expect($result['remainingHtml'] ?? null)->toBe('<p>a</p><p>b</p><p>d</p>')
            ->and($calls->names())->toBe(['section', 'cta', 'linkList']);
    });

    it('restores the libxml error mode the caller had', function (bool $mode): void {
        $previous = libxml_use_internal_errors($mode);

        try {
            acfBlocksFromHtml(acfBlockParser(new AcfBlockCalls), new AcfBlockContext, '<!-- wp:acf/cta {"data":{}} /--><p>a</p>');

            expect(libxml_use_internal_errors())->toBe($mode);
        } finally {
            libxml_use_internal_errors($previous);
        }
    })->with([true, false]);

    it('restores the libxml error mode the caller had when a block parser changes it', function (): void {
        $previous = libxml_use_internal_errors(false);
        $calls = new AcfBlockCalls;
        $calls->onRecord = static function (): void {
            libxml_use_internal_errors(true);
        };

        try {
            acfBlocksFromHtml(acfBlockParser($calls), new AcfBlockContext, '<!-- wp:acf/cta {"data":{}} /-->');

            expect($calls->names())->toBe(['cta'])
                ->and(libxml_use_internal_errors())->toBeFalse();
        } finally {
            libxml_use_internal_errors($previous);
        }
    });

    it('raises no warning for a block comment libxml cannot read cleanly', function (): void {
        $previous = libxml_use_internal_errors(false);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            acfBlocksFromHtml(acfBlockParser(new AcfBlockCalls), new AcfBlockContext, "<!-- wp:acf/cta {\"data\":{\"a\":\"\x01\"}} /-->");
        } finally {
            restore_error_handler();
            libxml_use_internal_errors($previous);
        }

        expect($warnings)->toBe([]);
    });

    it('leaves no libxml error behind for a block comment libxml cannot read cleanly', function (): void {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            acfBlocksFromHtml(acfBlockParser(new AcfBlockCalls), new AcfBlockContext, "<!-- wp:acf/cta {\"data\":{\"a\":\"\x01\"}} /-->");

            expect(libxml_get_errors())->toBe([]);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    });

    it('clears the libxml errors already collected when it loads a block comment', function (): void {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            simplexml_load_string('<unclosed>');
            $before = count(libxml_get_errors());

            acfBlocksFromHtml(acfBlockParser(new AcfBlockCalls), new AcfBlockContext, '<!-- wp:acf/cta {"data":{}} /-->');

            expect($before)->toBeGreaterThan(0)
                ->and(libxml_get_errors())->toBe([]);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    });

    it('gives a block whose match runs across comments the nodes after its own', function (): void {
        $calls = new AcfBlockCalls;

        $result = acfBlocksFromHtml(
            acfBlockParser($calls),
            new AcfBlockContext,
            '<!-- wp:acf/cta {x wp:acf/uams-section --><p>inside</p><!-- } -->'
        );

        expect($result)->toBe(['acfSets' => [['set' => 'section']], 'remainingHtml' => ''])
            ->and($calls->names())->toBe(['section'])
            ->and($calls->calls[0][1][1])->toBe([['html' => '<p>inside</p>'], ['html' => '<!-- } -->']]);
    });
});
