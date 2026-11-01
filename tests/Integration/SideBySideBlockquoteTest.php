<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\SideBySideParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests the Blockquote with Citation layouts of the side-by-side block (wordpress-importer-archive#1281).
 *
 * The theme offers `blockquote-citation` and `blockquote-citation-link` as values of
 * `side_text_layout`, with a quote field for each, a speaker name and an optional
 * speaker title (`UAMS-Web/uams-2020#612`). The Statamic fieldset names the layouts
 * `blockquote_with_citation` and `blockquote_with_citation_and_link`
 * (`UAMS-Web/uams-statamic-archive#2757`), and shows a button only for the second.
 *
 * @command  php vendor/bin/pest tests/Integration/SideBySideBlockquoteTest.php
 */
uses(StatamicTestCase::class);

/** The quote and citation of a quote block, with WordPress field names. */
const SIDE_BY_SIDE_QUOTE_FIELDS = [
    'side_quote_speaker_name' => 'Dr. Jane Doe',
    'side_quote_speaker_title' => '<em>Chief of Cardiology</em>',
];

/** A button, saved on the block whatever its layout. */
const SIDE_BY_SIDE_QUOTE_BUTTON = [
    'side_cta_side_cta_text' => 'Read her story',
    'side_cta_side_cta_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => '_blank'],
    'side_cta_side_cta_description' => 'about her care',
];

/** Every handle a button writes. */
const SIDE_BY_SIDE_BUTTON_HANDLES = ['button_text', 'button_url', 'button_target', 'button_link_description'];

beforeEach(function (): void {
    // No link lookup may leave the process
    Http::fake(['*' => Http::response('', 404)]);

    ImportWarningManager::clearWarnings();

    // Uploads enabled, and every image import answers with one asset path
    $this->assetImporter = $this::createStub(AssetImporter::class);
    $this->assetImporter->method('getUploadImages')->willReturn(true);
    $this->assetImporter->method('importImage')->willReturn('images/side.jpg');

    $this->parser = new SideBySideParser;
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

/**
 * Run `parseModule()` over a modules-template `image_side` payload.
 *
 * @param  SideBySideParser  $parser  The parser under test
 * @param  AssetImporter  $assetImporter  The mocked importer
 * @param  array<string, mixed>  $overrides  Fields replacing or adding to the defaults
 * @return array<string, mixed>|null The replicator set, or null when the module is skipped
 */
function quoteModuleSet(SideBySideParser $parser, AssetImporter $assetImporter, array $overrides = []): ?array
{
    /** @var array<string, mixed>|null $set */
    $set = $parser->parseModule(
        \array_merge(
            [
                'side_heading' => 'In her words',
                'side_image_side_image_image' => '42',
            ],
            $overrides
        ),
        'default',
        $assetImporter,
        new NullOutput
    );

    return $set;
}

/**
 * The incomplete-quote warnings recorded so far.
 *
 * @return list<array<string, mixed>> The warnings
 */
function incompleteQuoteWarnings(): array
{
    /** @var list<array<string, mixed>> $warnings */
    $warnings = \array_values(ImportWarningManager::getWarnings('content', 'side_by_side_incomplete_quote'));

    return $warnings;
}

/**
 * Run `parse()` over an ACF block with the given data.
 *
 * @param  SideBySideParser  $parser  The parser under test
 * @param  AssetImporter  $assetImporter  The mocked importer
 * @param  array<string, mixed>  $data  The block's `data`
 * @return array<string, mixed> The replicator set
 */
function quoteBlockSet(SideBySideParser $parser, AssetImporter $assetImporter, array $data): array
{
    /** @var array<string, mixed> $set */
    $set = $parser->parse(['data' => $data], 'default', $assetImporter, new NullOutput);

    return $set;
}

it('imports each quote layout under its Statamic name, with the quote and citation', function (string $layout, string $source, string $statamicLayout, string $handle): void {
    $set = quoteModuleSet($this->parser, $this->assetImporter, [
        ...SIDE_BY_SIDE_QUOTE_FIELDS,
        'side_text_layout' => $layout,
        $source => '"The care here <strong>changed</strong> my life."',
    ]);

    expect($set)->toBeArray()
        ->and($set['text_layout'] ?? null)->toBe($statamicLayout)
        ->and($set[$handle] ?? null)->toBe('"The care here changed my life."')
        ->and($set['speaker_name'] ?? null)->toBe('Dr. Jane Doe')
        ->and($set['speaker_title'] ?? null)->toBe('Chief of Cardiology')
        ->and(\array_intersect_key($set ?? [], \array_flip(['body_body_only', 'body_link_list', 'link_list'])))->toBe([]);
})->with([
    'without a link' => ['blockquote-citation', 'side_quote_text', 'blockquote_with_citation', 'quote_text'],
    'with a link' => ['blockquote-citation-link', 'side_quote_text_link', 'blockquote_with_citation_and_link', 'quote_text_with_link'],
]);

it('reads the quote from the field of its own layout only', function (): void {
    $set = quoteModuleSet($this->parser, $this->assetImporter, [
        ...SIDE_BY_SIDE_QUOTE_FIELDS,
        'side_text_layout' => 'blockquote-citation',
        'side_quote_text' => 'The quote.',
        'side_quote_text_link' => 'A quote left over from the other layout.',
    ]);

    expect($set['quote_text'] ?? null)->toBe('The quote.')
        ->and($set)->not->toHaveKey('quote_text_with_link');
});

it('imports a quote block with no speaker title, leaving that field out', function (): void {
    $set = quoteModuleSet($this->parser, $this->assetImporter, [
        'side_text_layout' => 'blockquote-citation',
        'side_quote_text' => 'The quote.',
        'side_quote_speaker_name' => 'Dr. Jane Doe',
        'side_quote_speaker_title' => '',
    ]);

    expect($set)->toBeArray()
        ->and($set['speaker_name'] ?? null)->toBe('Dr. Jane Doe')
        ->and($set)->not->toHaveKey('speaker_title');
});

it('keeps the button of the linked layout and drops it from the other', function (string $layout, string $source, bool $hasButton): void {
    $set = quoteModuleSet($this->parser, $this->assetImporter, [
        ...SIDE_BY_SIDE_QUOTE_FIELDS,
        ...SIDE_BY_SIDE_QUOTE_BUTTON,
        'side_text_layout' => $layout,
        $source => 'The quote.',
    ]);

    expect($set)->toBeArray();

    if ($hasButton) {
        expect($set['button_text'] ?? null)->toBe('Read her story')
            ->and($set['button_url'] ?? null)->toBe('https://www.facebook.com/uams/')
            ->and(\array_keys(\array_intersect_key($set ?? [], \array_flip(SIDE_BY_SIDE_BUTTON_HANDLES))))->toEqualCanonicalizing(SIDE_BY_SIDE_BUTTON_HANDLES);

        return;
    }

    expect(\array_intersect_key($set ?? [], \array_flip(SIDE_BY_SIDE_BUTTON_HANDLES)))->toBe([]);
})->with([
    'with a link' => ['blockquote-citation-link', 'side_quote_text_link', true],
    'without a link' => ['blockquote-citation', 'side_quote_text', false],
]);

it('skips a quote module with no quote or no speaker name, and says why', function (array $fields, array $missing): void {
    /** @var array<string, mixed> $fields */
    $logSpy = Log::spy();

    $set = quoteModuleSet($this->parser, $this->assetImporter, ['side_text_layout' => 'blockquote-citation', ...$fields]);

    expect($set)->toBeNull();

    $logSpy->shouldHaveReceived('warning', [
        'SideBySideParser::parseModule: Skipping modules template image_side due to missing required quote or speaker name for a quote layout',
        Mockery::on(
            static fn (mixed $context): bool => \is_array($context)
                && ($context['text_layout'] ?? null) === 'blockquote-citation'
        ),
    ]);

    $warnings = incompleteQuoteWarnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['heading'] ?? null)->toBe('In her words')
        ->and($warnings[0]['text_layout'] ?? null)->toBe('blockquote-citation')
        ->and($warnings[0]['missing'] ?? null)->toBe($missing)
        ->and($warnings[0]['reason'] ?? null)->toBe('The block needs a quote and a speaker name to render, so it was skipped.');
})->with([
    'no quote' => [['side_quote_text' => '', 'side_quote_speaker_name' => 'Dr. Jane Doe'], ['quote_text']],
    'a quote of markup only' => [['side_quote_text' => '<p></p>', 'side_quote_speaker_name' => 'Dr. Jane Doe'], ['quote_text']],
    'no speaker name' => [['side_quote_text' => 'The quote.', 'side_quote_speaker_name' => ''], ['speaker_name']],
    'neither' => [[], ['quote_text', 'speaker_name']],
    'only the other layout\'s quote' => [['side_quote_text_link' => 'The quote.', 'side_quote_speaker_name' => 'Dr. Jane Doe'], ['quote_text']],
]);

it('reports nothing for a complete quote module', function (): void {
    quoteModuleSet($this->parser, $this->assetImporter, [
        'side_text_layout' => 'blockquote-citation',
        'side_quote_text' => 'The quote.',
        'side_quote_speaker_name' => 'Dr. Jane Doe',
    ]);

    expect(incompleteQuoteWarnings())->toBe([]);
});

it('imports body only and link list modules as before, with no quote fields', function (array $fields, string $layout): void {
    /** @var array<string, mixed> $fields */
    $set = quoteModuleSet($this->parser, $this->assetImporter, [...SIDE_BY_SIDE_QUOTE_FIELDS, ...SIDE_BY_SIDE_QUOTE_BUTTON, ...$fields]);

    expect($set)->toBeArray()
        ->and($set['text_layout'] ?? null)->toBe($layout)
        ->and($set['button_text'] ?? null)->toBe('Read her story')
        ->and(\array_intersect_key($set ?? [], \array_flip(['quote_text', 'quote_text_with_link', 'speaker_name', 'speaker_title'])))->toBe([]);
})->with([
    'body only' => [['side_text_layout' => 'body-only', 'side_layout_body_text' => 'Body copy.'], 'body-only'],
    'link list' => [[
        'side_text_layout' => 'link-list',
        'side_layout_link_text' => 'Lead-in copy.',
        'side_link_list' => '1',
        'side_link_list_0_side_link_list_text' => 'Facebook',
        'side_link_list_0_side_link_list_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => ''],
    ], 'link-list'],
]);

it('imports a quote layout from an ACF block, by field name or by field key', function (array $data): void {
    /** @var array<string, mixed> $data */
    $set = quoteBlockSet($this->parser, $this->assetImporter, [
        'side_heading' => 'In her words',
        'side_text_layout' => 'blockquote-citation-link',
        'side_image_side_image_image' => '42',
        ...SIDE_BY_SIDE_QUOTE_BUTTON,
        ...$data,
    ]);

    expect($set['text_layout'] ?? null)->toBe('blockquote_with_citation_and_link')
        ->and($set['quote_text_with_link'] ?? null)->toBe('The quote.')
        ->and($set['speaker_name'] ?? null)->toBe('Dr. Jane Doe')
        ->and($set['speaker_title'] ?? null)->toBe('Chief of Cardiology')
        ->and($set['button_text'] ?? null)->toBe('Read her story');
})->with([
    'field names' => [[
        'side_quote_text_link' => 'The quote.',
        'side_quote_speaker_name' => 'Dr. Jane Doe',
        'side_quote_speaker_title' => 'Chief of Cardiology',
    ]],
    'field keys' => [[
        'field_side_quote_text_link_b' => 'The quote.',
        'field_side_quote_speaker_name_b' => 'Dr. Jane Doe',
        'field_side_quote_speaker_title_b' => 'Chief of Cardiology',
    ]],
]);

it('drops the button from an ACF block whose quote layout hides it', function (): void {
    $set = quoteBlockSet($this->parser, $this->assetImporter, [
        'side_heading' => 'In her words',
        'side_text_layout' => 'blockquote-citation',
        'side_image_side_image_image' => '42',
        'side_quote_text' => 'The quote.',
        ...SIDE_BY_SIDE_QUOTE_FIELDS,
        ...SIDE_BY_SIDE_QUOTE_BUTTON,
    ]);

    expect($set['text_layout'] ?? null)->toBe('blockquote_with_citation')
        ->and($set['quote_text'] ?? null)->toBe('The quote.')
        ->and(\array_intersect_key($set, \array_flip(SIDE_BY_SIDE_BUTTON_HANDLES)))->toBe([])
        ->and(incompleteQuoteWarnings())->toBe([]);
});

it('imports an ACF quote block with no speaker title, leaving that field out', function (): void {
    $set = quoteBlockSet($this->parser, $this->assetImporter, [
        'side_heading' => 'In her words',
        'side_text_layout' => 'blockquote-citation',
        'side_image_side_image_image' => '42',
        'side_quote_text' => 'The quote.',
        'side_quote_speaker_name' => 'Dr. Jane Doe',
        'side_quote_speaker_title' => '',
    ]);

    expect($set['speaker_name'] ?? null)->toBe('Dr. Jane Doe')
        ->and($set)->not->toHaveKey('speaker_title');
});

it('imports an incomplete ACF quote block without empty values, and reports it', function (): void {
    $set = quoteBlockSet($this->parser, $this->assetImporter, [
        'side_heading' => 'In her words',
        'side_text_layout' => 'blockquote-citation-link',
        'side_image_side_image_image' => '42',
        'side_quote_speaker_name' => 'Dr. Jane Doe',
    ]);

    expect($set['text_layout'] ?? null)->toBe('blockquote_with_citation_and_link')
        ->and($set)->not->toHaveKey('quote_text_with_link')
        ->and($set['speaker_name'] ?? null)->toBe('Dr. Jane Doe');

    $warnings = incompleteQuoteWarnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['text_layout'] ?? null)->toBe('blockquote-citation-link')
        ->and($warnings[0]['missing'] ?? null)->toBe(['quote_text_with_link'])
        ->and($warnings[0]['reason'] ?? null)->toBe(
            'The block needs a quote and a speaker name to render, so it imports '
            .'blank until an editor fills them in.'
        );
});

it('reports a quote field over its limit and imports it unchanged', function (string $layout, string $source, string $handle, int $limit): void {
    $value = \str_repeat('q', $limit + 1);

    $set = quoteModuleSet($this->parser, $this->assetImporter, [
        'side_text_layout' => $layout,
        'side_quote_text' => 'The quote.',
        'side_quote_speaker_name' => 'Dr. Jane Doe',
        $source => $value,
    ]);

    expect($set[$handle] ?? null)->toBe($value);

    /** @var list<array<string, mixed>> $warnings */
    $warnings = \array_values(ImportWarningManager::getWarnings('content', 'side_by_side_over_character_limit'));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['handle'] ?? null)->toBe($handle)
        ->and($warnings[0]['limit'] ?? null)->toBe($limit)
        ->and($warnings[0]['length'] ?? null)->toBe($limit + 1);
})->with([
    'quote_text' => ['blockquote-citation', 'side_quote_text', 'quote_text', 306],
    'quote_text_with_link' => ['blockquote-citation-link', 'side_quote_text_link', 'quote_text_with_link', 217],
    'speaker_name' => ['blockquote-citation', 'side_quote_speaker_name', 'speaker_name', 58],
    'speaker_title' => ['blockquote-citation', 'side_quote_speaker_title', 'speaker_title', 121],
]);
