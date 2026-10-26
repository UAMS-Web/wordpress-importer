<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\SideBySideParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that `SideBySideParser::parseModule()` writes the side-by-side fieldset's handles (wordpress-importer-archive#1346).
 *
 * The modules-template path wrote `body_text`, `link_text`, `link_include_more`, `crop`,
 * `anchor` and `cta_*`, none of which the fieldset has, and never wrote `image_crop` or
 * `image_anchor`, which the template's validity guard requires. So every block it produced
 * rendered nothing. The ACF path, `parse()`, already wrote the right keys.
 *
 * The handle list below is copied from `resources/fieldsets/uamsweb_block_image_side_by_side.yaml`
 * in `UAMS-Web/uams-statamic` at `831a57cbb`, plus `id`, `type` and `enabled`, which every
 * replicator set carries. Update it with the fieldset.
 *
 * @command  php vendor/bin/pest tests/Integration/SideBySideModuleFieldsetShapeTest.php
 */
uses(StatamicTestCase::class);

/** The fieldset's top-level handles, and the three every replicator set carries. */
const SIDE_BY_SIDE_FIELDSET_HANDLES = [
    'id', 'type', 'enabled',
    'text_layout', 'block_heading', 'hide_block_heading',
    'body_body_only', 'body_with_button', 'body_link_list', 'body_simple_list',
    'link_list', 'include_more', 'simple_list',
    'quote_text', 'quote_text_with_link', 'speaker_name', 'speaker_title',
    'cta', 'button_text', 'button_url', 'button_target', 'button_link_description',
    'image_options', 'image', 'alt_text', 'image_crop', 'image_anchor',
    'layout_options', 'image_position', 'background_color', 'display_media_credit',
];

/** A link-list layout with the lead-in and one link it requires. */
const SIDE_BY_SIDE_LINK_LIST_FIELDS = [
    'side_text_layout' => 'link-list',
    'side_layout_link_text' => 'Lead-in copy.',
    'side_link_list' => '1',
    'side_link_list_0_side_link_list_text' => 'Facebook',
    'side_link_list_0_side_link_list_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => ''],
];

beforeEach(function (): void {
    // No link lookup may leave the process
    Http::fake(['*' => Http::response('', 404)]);

    ImportWarningManager::clearWarnings();

    // Uploads enabled, and every image import answers with one asset path
    $this->assetImporter = $this->createMock(AssetImporter::class);
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
 * @return array<string, mixed> The replicator set
 */
function sideBySideModuleSet(SideBySideParser $parser, AssetImporter $assetImporter, array $overrides = []): array
{
    $set = $parser->parseModule(
        \array_merge(
            [
                'side_heading' => 'About the clinic',
                'side_image_side_image_image' => '42',
                'side_image_side_image_alt_text' => 'The clinic entrance',
                'side_layout_body_text' => 'Body copy.',
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

it('writes only keys the fieldset has, for every layout', function (array $fields): void {
    /** @var array<string, mixed> $fields */
    $set = sideBySideModuleSet($this->parser, $this->assetImporter, $fields);

    expect(\array_values(\array_diff(\array_keys($set), SIDE_BY_SIDE_FIELDSET_HANDLES)))->toBe([]);
})->with([
    'body-only with a button' => [[
        'side_text_layout' => 'body-only',
        'side_layout_body_text' => 'Body copy.',
        'side_cta_side_cta_text' => 'Book a visit',
        'side_cta_side_cta_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => '_blank'],
        'side_image_side_image_crop' => 'top',
        'side_image_side_image_anchor' => 'right',
    ]],
    'link-list with links and include more' => [[...SIDE_BY_SIDE_LINK_LIST_FIELDS, 'side_link_include_more' => '1']],
    'blockquote with citation, a title and a stray button' => [[
        'side_text_layout' => 'blockquote-citation',
        'side_quote_text' => 'The quote.',
        'side_quote_speaker_name' => 'Dr. Jane Doe',
        'side_quote_speaker_title' => 'Chief of Cardiology',
        'side_cta_side_cta_text' => 'Book a visit',
        'side_cta_side_cta_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => '_blank'],
    ]],
    'blockquote with citation and link' => [[
        'side_text_layout' => 'blockquote-citation-link',
        'side_quote_text_link' => 'The quote.',
        'side_quote_speaker_name' => 'Dr. Jane Doe',
        'side_cta_side_cta_text' => 'Book a visit',
        'side_cta_side_cta_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => '_blank'],
    ]],
]);

it('writes the body under the layout it belongs to', function (): void {
    $bodyOnly = sideBySideModuleSet($this->parser, $this->assetImporter, [
        'side_text_layout' => 'body-only',
        'side_layout_body_text' => 'Body copy.',
    ]);
    $linkList = sideBySideModuleSet($this->parser, $this->assetImporter, SIDE_BY_SIDE_LINK_LIST_FIELDS);

    expect($bodyOnly['body_body_only'] ?? null)->toBe('Body copy.')
        ->and($bodyOnly)->not->toHaveKey('body_link_list')
        ->and($linkList['body_link_list'] ?? null)->toBe('Lead-in copy.')
        ->and($linkList)->not->toHaveKey('body_body_only');
});

it('writes the button under the fieldset handles', function (): void {
    $set = sideBySideModuleSet($this->parser, $this->assetImporter, [
        'side_cta_side_cta_text' => 'Book a visit',
        'side_cta_side_cta_url' => ['url' => 'https://www.facebook.com/uams/', 'target' => '_blank'],
    ]);

    expect($set['button_text'] ?? null)->toBe('Book a visit')
        ->and($set['button_url'] ?? null)->toBe('https://www.facebook.com/uams/')
        ->and($set)->toHaveKey('button_target');
});

it('writes include_more beside a link list', function (): void {
    $set = sideBySideModuleSet($this->parser, $this->assetImporter, [...SIDE_BY_SIDE_LINK_LIST_FIELDS, 'side_link_include_more' => '1']);

    expect($set['link_list'] ?? null)->toBeArray()->toHaveCount(1)
        ->and($set['include_more'] ?? null)->toBeTrue();
});

it('writes the truthy image fields the template guard requires', function (array $fields, string $crop, string $anchor): void {
    /** @var array<string, mixed> $fields */
    $set = sideBySideModuleSet($this->parser, $this->assetImporter, $fields);

    expect($set['image'] ?? null)->toBe('images/side.jpg')
        ->and($set['image_crop'] ?? null)->toBe($crop)
        ->and($set['image_anchor'] ?? null)->toBe($anchor)
        ->and($set['image_position'] ?? null)->toBe('left')
        ->and($set['text_layout'] ?? null)->toBeTruthy()
        ->and($set['background_color'] ?? null)->toBeTruthy();
})->with([
    'stored options kept' => [['side_image_side_image_crop' => 'bottom', 'side_image_side_image_anchor' => 'left'], 'bottom', 'left'],
    'absent values default to center' => [[], 'center', 'center'],
    "the old '0' for no crop becomes center" => [['side_image_side_image_crop' => '0', 'side_image_side_image_anchor' => '0'], 'center', 'center'],
]);

it('defaults an absent layout to body-only, a fieldset option', function (): void {
    $set = sideBySideModuleSet($this->parser, $this->assetImporter, [
        'side_layout_body_text' => 'Body copy.',
    ]);

    expect($set['text_layout'] ?? null)->toBe('body-only')
        ->and($set['body_body_only'] ?? null)->toBe('Body copy.');
});
