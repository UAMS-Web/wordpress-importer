<?php

declare(strict_types=1);

/**
 * Tests how `BlockProcessorHelper` hands a block comment to the first processor that
 * accepts it, and what it returns.
 *
 * The helper is the shared dispatch for `AcfBlockParser` and `NonAcfBlockParser`. It
 * reads the block's JSON attributes out of the comment, offers the node to each
 * processor in order, and normalizes the first non-empty result to `html` plus a
 * `skipCount`. Processors here are recording stubs, so each assertion is about the
 * dispatch rather than about any one block.
 *
 * @command  php vendor/bin/pest tests/Unit/BlockProcessorHelperTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BlockProcessorHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\BlockProcessorInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\EmbedProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\FileBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\WpImageBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\WpPostFeaturedImageBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\QuoteProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TableProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpDetailsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpGroupBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpSpacerBlockProcessor;

/**
 * A processor that records what it is offered and answers as configured.
 */
class BlockProcessorHelperRecordingProcessor implements BlockProcessorInterface
{
    /**
     * Every canProcess() call, as [site, pageSlug].
     *
     * @var list<array{string, ?string}>
     */
    public array $offers = [];

    /**
     * Every process() call, as [attributes, site, attachments, terms, pageSlug].
     *
     * @var list<array{array<int|string, mixed>, string, array<int|string, mixed>, array<int|string, mixed>, ?string}>
     */
    public array $calls = [];

    /**
     * Configure whether the processor accepts a node and what it returns.
     *
     * @param  string|array<int|string, mixed>|null  $result  What process() returns
     */
    public function __construct(
        private readonly bool $accepts,
        private readonly string|array|null $result = null
    ) {}

    /**
     * Record the offer and answer as configured.
     */
    public function canProcess(DOMNode $node, string $site, ?string $pageSlug): bool
    {
        $this->offers[] = [$site, $pageSlug];

        return $this->accepts;
    }

    /**
     * Record the call and return the configured result.
     *
     * @param  array<int|string, mixed>  $attributes
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     * @return string|array<int|string, mixed>|null
     */
    public function process(
        DOMNode $node,
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): string|array|null {
        $this->calls[] = [$attributes, $site, $attachments, $terms, $pageSlug];

        return $this->result;
    }
}

/**
 * A comment node holding the given text, inside a document.
 */
function blockHelperComment(string $text): DOMComment
{
    $document = new DOMDocument;
    $comment = $document->createComment($text);
    $document->appendChild($comment);

    return $comment;
}

/**
 * Run processBlockNode() with the given processors and fixed context.
 *
 * @param  array<int|string, mixed>  $processors
 * @return array<int|string, mixed>|null
 */
function blockHelperRun(DOMNode $node, array $processors, AssetImporter $importer): ?array
{
    return BlockProcessorHelper::processBlockNode(
        $node,
        $processors,
        'default',
        $importer,
        new BufferedOutput,
        ['an attachment'],
        ['a term'],
        'a-page'
    );
}

it('offers nothing a node that is not a comment', function (): void {
    $processor = new BlockProcessorHelperRecordingProcessor(true, 'html');
    $element = (new DOMDocument)->createElement('div');

    expect(blockHelperRun($element, [$processor], $this->createMock(AssetImporter::class)))->toBeNull()
        ->and($processor->offers)->toBe([]);
});

it('passes the block attributes and the context to the processor', function (): void {
    $processor = new BlockProcessorHelperRecordingProcessor(true, '<p>out</p>');

    blockHelperRun(
        blockHelperComment(' wp:image {"id":42,"sizeSlug":"large"} /'),
        [$processor],
        $this->createMock(AssetImporter::class)
    );

    expect($processor->offers)->toBe([['default', 'a-page']])
        ->and($processor->calls)->toBe([[
            ['id' => 42, 'sizeSlug' => 'large'],
            'default',
            ['an attachment'],
            ['a term'],
            'a-page',
        ]]);
});

it('passes no attributes for a comment that carries none, or carries invalid JSON', function (string $text): void {
    $processor = new BlockProcessorHelperRecordingProcessor(true, 'html');

    blockHelperRun(blockHelperComment($text), [$processor], $this->createMock(AssetImporter::class));

    expect($processor->calls[0][0] ?? null)->toBe([]);
})->with([
    'no braces' => ['wp:separator /'],
    'not JSON' => ['wp:x {not json} /'],
    'a JSON scalar' => ['wp:x {1} /'],
]);

it('passes no attributes for nested JSON, which the lazy match cuts short', function (): void {
    // Characterization: `{.+?}` stops at the first closing brace, so nested attributes
    // decode as invalid JSON and the processor gets none of them. Pinned as today's
    // behavior; the fix is #123, which flips this test.
    $processor = new BlockProcessorHelperRecordingProcessor(true, 'html');

    blockHelperRun(
        blockHelperComment('wp:group {"style":{"color":"red"},"layout":"flex"} -->'),
        [$processor],
        $this->createMock(AssetImporter::class)
    );

    expect($processor->calls[0][0] ?? null)->toBe([]);
});

it('reads attributes that span lines', function (): void {
    $processor = new BlockProcessorHelperRecordingProcessor(true, 'html');

    blockHelperRun(
        blockHelperComment("wp:table {\n\"hasFixedLayout\":true\n} /"),
        [$processor],
        $this->createMock(AssetImporter::class)
    );

    expect($processor->calls[0][0] ?? null)->toBe(['hasFixedLayout' => true]);
});

it('returns the first non-empty result, skipping refusals, empty results and non-processors', function (): void {
    $refuses = new BlockProcessorHelperRecordingProcessor(false, 'never');
    $returnsNull = new BlockProcessorHelperRecordingProcessor(true, null);
    $returnsEmpty = new BlockProcessorHelperRecordingProcessor(true, '');
    $wins = new BlockProcessorHelperRecordingProcessor(true, '<p>won</p>');
    $after = new BlockProcessorHelperRecordingProcessor(true, '<p>too late</p>');

    $result = blockHelperRun(
        blockHelperComment('wp:paragraph'),
        [$refuses, 'not a processor', $returnsNull, $returnsEmpty, $wins, $after],
        $this->createMock(AssetImporter::class)
    );

    expect($result)->toBe(['html' => '<p>won</p>', 'skipCount' => 1])
        ->and($refuses->calls)->toBe([])
        ->and($returnsNull->calls)->toHaveCount(1)
        ->and($returnsEmpty->calls)->toHaveCount(1)
        ->and($after->offers)->toBe([]);
});

it('returns null when no processor produces anything', function (): void {
    $result = blockHelperRun(
        blockHelperComment('wp:paragraph'),
        [new BlockProcessorHelperRecordingProcessor(false), new BlockProcessorHelperRecordingProcessor(true, null)],
        $this->createMock(AssetImporter::class)
    );

    expect($result)->toBeNull();
});

it('defaults an array result to a skip count of one and keeps one it already has', function (): void {
    $importer = $this->createMock(AssetImporter::class);

    $defaulted = blockHelperRun(
        blockHelperComment('wp:paragraph'),
        [new BlockProcessorHelperRecordingProcessor(true, ['html' => '<p>a</p>'])],
        $importer
    );

    $kept = blockHelperRun(
        blockHelperComment('wp:paragraph'),
        [new BlockProcessorHelperRecordingProcessor(true, ['html' => '<p>b</p>', 'skipCount' => 3])],
        $importer
    );

    expect($defaulted)->toBe(['html' => '<p>a</p>', 'skipCount' => 1])
        ->and($kept)->toBe(['html' => '<p>b</p>', 'skipCount' => 3]);
});

it('builds the common processors once, in order', function (): void {
    $processors = BlockProcessorHelper::getCommonProcessors();

    expect(\array_map(static fn (object $processor): string => $processor::class, $processors))->toBe([
        WpGroupBlockProcessor::class,
        WpDetailsBlockProcessor::class,
        WpSpacerBlockProcessor::class,
        EmbedProcessor::class,
        WpImageBlockProcessor::class,
        WpPostFeaturedImageBlockProcessor::class,
        TableProcessor::class,
        QuoteProcessor::class,
        FileBlockProcessor::class,
    ])->and(BlockProcessorHelper::getCommonProcessors())->toBe($processors);
});

it('dispatches through the common processors', function (): void {
    // A spacer block is stripped: the spacer processor returns empty html with a count
    $document = new DOMDocument;
    $document->loadHTML(
        '<div><!-- wp:spacer {"height":"40px"} --><div class="wp-block-spacer"></div><!-- /wp:spacer --></div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    $comments = (new DOMXPath($document))->query('//comment()');
    $comment = $comments === false ? null : $comments->item(0);

    if (! $comment instanceof DOMComment) {
        throw new RuntimeException('The fixture has no comment.');
    }

    $result = BlockProcessorHelper::processBlockNodeWithCommonProcessors(
        $comment,
        'default',
        $this->createMock(AssetImporter::class),
        new BufferedOutput
    );

    expect($result['html'] ?? null)->toBe('')
        ->and($result['skipCount'] ?? null)->toBe(3);
});

it('returns a node as an HTML fragment of its own markup', function (): void {
    $document = new DOMDocument;
    $paragraph = $document->createElement('p', 'Hello');
    $document->appendChild($paragraph);

    expect(BlockProcessorHelper::convertNodeToHtmlFragment($paragraph))->toBe(['html' => '<p>Hello</p>']);
});

it('returns no fragment for a node that has no owner document', function (): void {
    // A document is its own root and has no ownerDocument
    expect(BlockProcessorHelper::convertNodeToHtmlFragment(new DOMDocument))->toBeNull();
});
