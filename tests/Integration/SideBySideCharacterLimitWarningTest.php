<?php

declare(strict_types=1);

/**
 * Tests that `SideBySideParser` reports each imported text value longer than its
 * field's `character_limit`, and imports the value unchanged (wordpress-importer-archive#1345).
 *
 * The limits come from `uamsweb_block_image_side_by_side.yaml` in
 * `UAMS-Web/uams-statamic`, where they are authoring hints with no `max:` rule:
 * `block_heading` 50, `body_body_only` 449, `body_link_list` 173, `button_text`
 * 42, and 22 for each `link_list` row's `text`. Both of the parser's entry
 * points are covered, since both write those handles.
 *
 * @command  php vendor/bin/pest tests/Integration/SideBySideCharacterLimitWarningTest.php
 */

use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\SideBySideParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    // No link lookup may leave the process
    Http::fake(['*' => Http::response('', 404)]);

    ImportWarningManager::clearWarnings();
    ProcessingContext::setWordPressPageLink('https://example.org/clinic/');

    // Uploads enabled, and every image import answers with one asset path
    $this->assetImporter = $this::createStub(AssetImporter::class);
    $this->assetImporter->method('getUploadImages')->willReturn(true);
    $this->assetImporter->method('importImage')->willReturn('images/side.jpg');

    $this->parser = new SideBySideParser;
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Run `parseModule()` over a modules-template payload with valid defaults.
 *
 * @param  SideBySideParser  $parser  The parser under test
 * @param  AssetImporter  $assetImporter  The mocked importer
 * @param  array<string, mixed>  $overrides  Fields replacing or adding to the defaults
 * @return array<string, mixed> The replicator set
 */
function limitModuleSet(SideBySideParser $parser, AssetImporter $assetImporter, array $overrides = []): array
{
    $set = $parser->parseModule(
        \array_merge(
            [
                'side_heading' => 'About the clinic',
                'side_layout_body_text' => 'Body copy.',
                'side_image_side_image_image' => '42',
            ],
            $overrides
        ),
        'default',
        $assetImporter,
        new NullOutput
    );

    expect($set)->toBeArray();

    /** @var array<string, mixed> $set */
    return $set;
}

/**
 * The over-limit warnings recorded so far.
 *
 * @return list<array<string, mixed>> The warnings
 */
function limitWarnings(): array
{
    /** @var list<array<string, mixed>> $warnings */
    $warnings = \array_values(ImportWarningManager::getWarnings('content', 'side_by_side_over_character_limit'));

    return $warnings;
}

it('reports a heading over 50 characters and imports it unchanged', function (int $length, bool $reported): void {
    $heading = \str_repeat('h', $length);

    $set = limitModuleSet($this->parser, $this->assetImporter, ['side_heading' => $heading]);

    expect($set['block_heading'] ?? null)->toBe($heading);

    $warnings = limitWarnings();

    if (! $reported) {
        expect($warnings)->toBe([]);

        return;
    }

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['page_url'] ?? null)->toBe('https://example.org/clinic/')
        ->and($warnings[0]['handle'] ?? null)->toBe('block_heading')
        ->and($warnings[0]['limit'] ?? null)->toBe(50)
        ->and($warnings[0]['length'] ?? null)->toBe($length)
        ->and($warnings[0]['heading'] ?? null)->toBe($heading)
        ->and($warnings[0]['block_type'] ?? null)->toBe('uamsweb_block_image_side_by_side')
        ->and($warnings[0]['reason'] ?? null)->toBe(
            'The value is longer than the character limit of the box the block renders it in, '
            .'so it overflows; it was imported unchanged for an editor to shorten.'
        );
})->with([
    'under the limit' => [49, false],
    'at the limit' => [50, false],
    'over the limit' => [51, true],
]);

it('reports a link list row over 22 characters and imports it unchanged', function (int $length, bool $reported): void {
    $text = \str_repeat('t', $length);

    $set = limitModuleSet($this->parser, $this->assetImporter, [
        'side_text_layout' => 'link-list',
        'side_layout_link_text' => 'Lead-in copy.',
        'side_link_list' => '2',
        'side_link_list_0_side_link_list_text' => 'Short',
        'side_link_list_0_side_link_list_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => ''],
        'side_link_list_1_side_link_list_text' => $text,
        'side_link_list_1_side_link_list_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => ''],
    ]);

    $rows = $set['link_list'] ?? null;

    expect($rows)->toBeArray()->toHaveCount(2);

    /** @var list<array<string, mixed>> $rows */
    expect($rows[1]['text'] ?? null)->toBe($text);

    $warnings = limitWarnings();

    if (! $reported) {
        expect($warnings)->toBe([]);

        return;
    }

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['handle'] ?? null)->toBe('link_list.1.text')
        ->and($warnings[0]['limit'] ?? null)->toBe(22)
        ->and($warnings[0]['length'] ?? null)->toBe($length);
})->with([
    'under the limit' => [21, false],
    'at the limit' => [22, false],
    'over the limit' => [23, true],
]);

it('reports each other limited handle one character over its limit', function (string $handle, int $limit, array $fields): void {
    /** @var array<string, mixed> $fields */
    $value = \str_repeat('v', $limit + 1);

    // Put the over-limit value wherever the dataset marks it
    $overrides = [];

    foreach ($fields as $key => $field) {
        $overrides[$key] = $field === 'VALUE' ? $value : $field;
    }

    $set = limitModuleSet($this->parser, $this->assetImporter, $overrides);

    expect($set[$handle] ?? null)->toBe($value);

    $warnings = limitWarnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['handle'] ?? null)->toBe($handle)
        ->and($warnings[0]['limit'] ?? null)->toBe($limit)
        ->and($warnings[0]['length'] ?? null)->toBe($limit + 1);
})->with([
    'body_body_only' => ['body_body_only', 449, ['side_text_layout' => 'body-only', 'side_layout_body_text' => 'VALUE']],
    'body_link_list' => ['body_link_list', 173, [
        'side_text_layout' => 'link-list',
        'side_layout_link_text' => 'VALUE',
        'side_link_list' => '1',
        'side_link_list_0_side_link_list_text' => 'Short',
        'side_link_list_0_side_link_list_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => ''],
    ]],
    'button_text' => ['button_text', 42, [
        'side_cta_side_cta_text' => 'VALUE',
        'side_cta_side_cta_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => ''],
    ]],
]);

it('reports each over-limit value in one block separately', function (): void {
    limitModuleSet($this->parser, $this->assetImporter, [
        'side_heading' => \str_repeat('h', 60),
        'side_cta_side_cta_text' => \str_repeat('b', 50),
        'side_cta_side_cta_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => ''],
    ]);

    expect(\array_column(limitWarnings(), 'handle'))->toBe(['block_heading', 'button_text']);
});

it('counts characters, not bytes', function (): void {
    // Fifty two-byte characters are within a 50-character limit
    limitModuleSet($this->parser, $this->assetImporter, ['side_heading' => \str_repeat('é', 50)]);

    expect(limitWarnings())->toBe([]);
});

it('reports an empty page URL when the page is not known', function (): void {
    ProcessingContext::clearWordPressPageLink();

    limitModuleSet($this->parser, $this->assetImporter, ['side_heading' => \str_repeat('h', 51)]);

    expect(limitWarnings()[0]['page_url'] ?? null)->toBe('');
});

it('reports an over-limit value from an ACF block as well', function (): void {
    $heading = \str_repeat('h', 51);

    $set = $this->parser->parse(
        ['data' => [
            'side_heading' => $heading,
            'side_text_layout' => 'body-only',
            'side_layout_body_text' => 'Body copy.',
            'side_image_side_image_image' => '42',
        ]],
        'default',
        $this->assetImporter,
        new NullOutput
    );

    expect($set['block_heading'] ?? null)->toBe($heading);

    $warnings = limitWarnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['handle'] ?? null)->toBe('block_heading')
        ->and($warnings[0]['length'] ?? null)->toBe(51);
});
