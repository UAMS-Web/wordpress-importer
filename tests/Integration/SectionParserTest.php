<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\SectionParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that `SectionParser` maps a `uams-section` block, with its InnerBlocks
 * rendered to Bard, onto the content replicator set; skips a section whose body
 * comes out empty with a warning; and maps a modules-template section to an
 * empty content set.
 *
 * @command  php vendor/bin/pest tests/Integration/SectionParserTest.php
 */
uses(StatamicTestCase::class);

// The skip warning reads the page link, entry data and ids from process-statics
beforeEach(function (): void {
    sectionParserClearContext();
});

afterEach(function (): void {
    sectionParserClearContext();
});

/**
 * Clear every `ProcessingContext` value the parser reads.
 */
function sectionParserClearContext(): void
{
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ProcessingContext::clearWordPressPostId();
    ProcessingContext::clearStatamicEntryId();
    ProcessingContext::clearCollectionHandle();
}

/**
 * An `AssetImporter` that imports nothing, enough for text-only InnerBlocks.
 */
function sectionParserAssetImporter(): AssetImporter
{
    /** @var AssetImporter&MockInterface $assetImporter */
    $assetImporter = Mockery::mock(AssetImporter::class)->shouldIgnoreMissing();

    return $assetImporter;
}

/**
 * Parse a block through `parse()`.
 *
 * @param  array<int|string, mixed>  $data  The block data
 * @param  array<int|string, mixed>  $innerContent  The InnerBlocks fragments
 * @return array<int|string, mixed>|null The replicator set, or null when skipped
 */
function sectionParse(array $data, array $innerContent): ?array
{
    return new SectionParser()->parse(
        ['data' => $data],
        $innerContent,
        'default',
        sectionParserAssetImporter(),
        new NullOutput
    );
}

/**
 * Parse a block and drop the generated row id, after checking its shape.
 *
 * @param  array<int|string, mixed>  $data  The block data
 * @param  array<int|string, mixed>  $innerContent  The InnerBlocks fragments
 * @return array<int|string, mixed> The replicator set without its id
 */
function sectionSet(array $data, array $innerContent): array
{
    $set = sectionParse($data, $innerContent);

    expect($set)->toBeArray()
        ->and($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');

    \assert(\is_array($set));
    unset($set['id']);

    return $set;
}

/**
 * The Bard text of every paragraph in a body, in order.
 *
 * @param  mixed  $body  The set's `body` value
 * @return list<string> One string per paragraph
 */
function sectionParagraphs(mixed $body): array
{
    $paragraphs = [];

    foreach (\is_array($body) ? $body : [] as $node) {
        if (! \is_array($node) || ($node['type'] ?? null) !== 'paragraph') {
            continue;
        }

        $text = '';

        foreach (\is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $text .= \is_array($child) && \is_string($child['text'] ?? null) ? $child['text'] : '';
        }

        $paragraphs[] = $text;
    }

    return $paragraphs;
}

it('maps a section with InnerBlocks onto the content set', function (): void {
    $set = sectionSet(
        [
            'section_heading' => 'Our <em>Mission</em>',
            'section_hide_heading' => '1',
            'section_background_color' => 'bg-gray',
        ],
        [['html' => '<p>First</p>'], ['html' => '<p>Second</p>']]
    );

    expect(sectionParagraphs($set['body']))->toBe(['First', 'Second']);

    unset($set['body']);

    expect($set)->toBe([
        'type' => 'uamsweb_block_content',
        'block_heading' => 'Our Mission',
        'hide_block_heading' => true,
        'background_color' => 'bg-gray',
        'enabled' => true,
    ]);
});

it('reads the field-keyed ACF names', function (): void {
    $set = sectionSet(
        [
            'field_section_heading_b' => 'Keyed',
            'field_section_hide_heading_b' => '1',
            'field_section_background_color_b' => 'bg-white',
        ],
        [['html' => '<p>Body</p>']]
    );

    expect($set['block_heading'])->toBe('Keyed')
        ->and($set['hide_block_heading'])->toBeTrue()
        ->and($set['background_color'])->toBe('bg-white');
});

it('uses the placeholder heading when the heading is missing, blank or not a string', function (array $data): void {
    $set = sectionSet($data, [['html' => '<p>Body</p>']]);

    expect($set['block_heading'])->toBe('Section Title - Please Replace')
        ->and($set['background_color'])->toBe('bg-auto');
})->with([
    'missing' => [[]],
    'an empty string' => [['section_heading' => '']],
    'only markup' => [['section_heading' => '<span></span>']],
    'an array' => [['section_heading' => ['Mission']]],
]);

it('shows the heading unless the hide flag is exactly the string one', function (mixed $hide, bool $expected): void {
    expect(sectionSet(['section_hide_heading' => $hide], [['html' => '<p>Body</p>']])['hide_block_heading'])
        ->toBe($expected);
})->with([
    'the string one' => ['1', true],
    'the integer one' => [1, false],
    'true' => [true, false],
    'the string zero' => ['0', false],
    'missing' => [null, false],
]);

it('joins fragments, ignoring ones that are not arrays or carry no string html', function (): void {
    $set = sectionSet([], [
        ['html' => '<p>Kept</p>'],
        'not an array',
        ['html' => ['<p>Not a string</p>']],
        ['no_html' => true],
        ['html' => '<p>Also kept</p>'],
    ]);

    // Compare whole bodies: a fragment that contributed any text would add a node
    $clean = sectionSet([], [['html' => '<p>Kept</p>'], ['html' => '<p>Also kept</p>']]);

    expect(sectionParagraphs($set['body']))->toBe(['Kept', 'Also kept'])
        ->and($set['body'])->toBe($clean['body']);
});

it('renders InnerBlocks without wpautop, so a blank line does not split a paragraph', function (): void {
    expect(sectionSet([], [['html' => "First line\n\nSecond line"]])['body'])->toBe([
        ['type' => 'text', 'text' => 'First lineSecond line', 'attrs' => []],
    ]);
});

it('renders InnerBlocks as InnerBlocks, stripping their block comments first', function (): void {
    $body = sectionSet([], [[
        'html' => '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://example.org/a.jpg" alt="Clinic" class="wp-image-5"/></figure><!-- /wp:image -->',
    ]])['body'];

    expect($body)->toBeArray()->toHaveCount(1);

    \assert(\is_array($body) && \is_array($body[0]) && \is_array($body[0]['attrs'] ?? null));

    // The InnerBlocks path writes the alt override under the blueprint's handle
    expect($body[0]['type'])->toBe('set')
        ->and($body[0]['attrs']['values'] ?? null)->toBe([
            'type' => 'image',
            'image' => 'https://example.org/a.jpg',
            'align' => 'aligncenter',
            'alt_override' => 'Clinic',
        ]);
});

it('leaves out of the body the nodes an InnerBlock already processed', function (): void {
    $doc = new DOMDocument;
    $doc->loadHTML('<p>Handled</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $handled = $doc->getElementsByTagName('p')->item(0);

    $set = sectionSet([], [
        ['html' => '<p>Handled</p>', 'processedNode' => $handled],
        ['html' => '<p>Plain</p>'],
    ]);

    expect(sectionParagraphs($set['body']))->toBe(['Plain']);
});

it('skips a section whose body is empty, with a warning naming the entry', function (array $innerContent, bool $hasInnerContent): void {
    ProcessingContext::setWordPressPageLink('https://example.org/about/');
    ProcessingContext::setEntryData(['post_name' => 'about']);
    ProcessingContext::setWordPressPostId('321');
    ProcessingContext::setStatamicEntryId('entry-9');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(sectionParse(['section_heading' => 'Lonely', 'section_background_color' => 'bg-red'], $innerContent))->toBeNull();

    $logSpy->shouldHaveReceived('warning', [
        'SectionParser::parse: Returning null - empty body (from entry: https://example.org/about/)',
        [
            'block_heading' => 'Lonely',
            'heading_is_default' => false,
            'body_content_empty' => true,
            'has_inner_content' => $hasInnerContent,
            'normalized_data_keys' => ['section_heading', 'section_background_color'],
            'section_heading_in_data' => true,
            'wordpress_post_id' => '321',
            'statamic_entry_id' => 'entry-9',
            'post_name' => 'about',
            'entry_url' => 'https://example.org/about/',
        ],
    ]);
})->with([
    'no InnerBlocks' => [[], false],
    'InnerBlocks with no html' => [[['html' => '']], true],
]);

it('records a default heading and a missing entry in the skip warning', function (): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(sectionParse([], []))->toBeNull();

    $logSpy->shouldHaveReceived('warning', [
        'SectionParser::parse: Returning null - empty body',
        [
            'block_heading' => 'Section Title - Please Replace',
            'heading_is_default' => true,
            'body_content_empty' => true,
            'has_inner_content' => false,
            'normalized_data_keys' => [],
            'section_heading_in_data' => false,
            'wordpress_post_id' => null,
            'statamic_entry_id' => null,
            'post_name' => null,
        ],
    ]);
});

it('reads block attributes without a data key, and ignores a data key that is not an array', function (): void {
    $parser = new SectionParser;
    $inner = [['html' => '<p>Body</p>']];

    $bare = $parser->parse(['section_heading' => 'Bare'], $inner, 'default', sectionParserAssetImporter(), new NullOutput);
    $scalar = $parser->parse(['data' => 'x', 'section_heading' => 'Ignored'], $inner, 'default', sectionParserAssetImporter(), new NullOutput);

    expect($bare['block_heading'] ?? null)->toBe('Bare')
        ->and($scalar['block_heading'] ?? null)->toBe('Section Title - Please Replace');
});

it('maps a modules-template section to a content set with an empty body', function (array $data, array $expected): void {
    $set = new SectionParser()->parseModule($data, 'default', sectionParserAssetImporter(), new NullOutput);

    expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');

    unset($set['id']);

    expect($set)->toBe($expected);
})->with([
    'complete' => [
        ['field_section_heading_b' => 'Team <b>News</b>', 'section_hide_heading' => '1', 'section_background_color' => 'bg-gray'],
        [
            'type' => 'uamsweb_block_content',
            'block_heading' => 'Team News',
            'hide_block_heading' => true,
            'background_color' => 'bg-gray',
            'body' => [],
            'enabled' => true,
        ],
    ],
    'empty' => [
        [],
        [
            'type' => 'uamsweb_block_content',
            'block_heading' => 'Section Title - Please Replace',
            'hide_block_heading' => false,
            'background_color' => 'bg-auto',
            'body' => [],
            'enabled' => true,
        ],
    ],
    'a heading that is not a string, and a hide flag that is not the string one' => [
        ['section_heading' => 5, 'section_hide_heading' => 1],
        [
            'type' => 'uamsweb_block_content',
            'block_heading' => 'Section Title - Please Replace',
            'hide_block_heading' => false,
            'background_color' => 'bg-auto',
            'body' => [],
            'enabled' => true,
        ],
    ],
]);

it('gives each set its own row id', function (): void {
    $inner = [['html' => '<p>Body</p>']];

    expect(sectionParse([], $inner)['id'] ?? null)->not->toBe(sectionParse([], $inner)['id'] ?? null);
});
