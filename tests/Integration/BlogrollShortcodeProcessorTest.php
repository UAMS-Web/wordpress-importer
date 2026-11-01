<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\BlogrollShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for how BlogrollShortcodeProcessor reads each `[blogroll]` attribute.
 *
 * `style` selects the block format, `image`, `date` and `author` each set a `hide_*` flag,
 * and `category` or `category_name` sets `include_link`. Resolving a category to entries is
 * `NewsBlockCategoryWiringTest`, and the fieldset shape is `NewsBlockFieldsetShapeTest`.
 *
 * @command  php vendor/bin/pest tests/Integration/BlogrollShortcodeProcessorTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();
});

/**
 * Build a page builder block from shortcode attributes.
 *
 * @param  array<int|string, mixed>  $attributes  The shortcode attributes
 * @return array<int|string, mixed> The block
 */
function blogrollBlock(array $attributes): array
{
    return new BlogrollShortcodeProcessor()->toPageBuilderBlock($attributes, 'default', new AssetImporter, new NullOutput);
}

it('maps the style attribute to a format in the Bard set and the block', function (array $attributes, string $format): void {
    $set = new BlogrollShortcodeProcessor()->parse($attributes, 'default', new AssetImporter, new NullOutput);

    expect($set)->toBe(['type' => 'news', 'format' => $format]);
    expect(blogrollBlock($attributes)['format'])->toBe($format);
})->with([
    'no style' => [[], 'grid'],
    'an empty style' => [['style' => ''], 'grid'],
    'the default style' => [['style' => 'default'], 'grid'],
    'the card style' => [['style' => 'card'], 'cards'],
    'the card style, in capitals and padded' => [['style' => ' CARD '], 'cards'],
    'the headlines style' => [['style' => 'headlines'], 'headlines'],
    'the excerpts style' => [['style' => 'excerpts'], 'excerpts'],
    'the full style' => [['style' => 'full'], 'full'],
    'the side style, in capitals and padded' => [['style' => ' SIDE '], 'side'],
    'an unknown style' => [['style' => 'carousel'], 'grid'],
    'a number' => [['style' => 5], 'grid'],
    'a style that is not scalar' => [['style' => ['card']], 'grid'],
]);

it('reads a scalar style as text', function (): void {
    // Nothing maps a boolean, so `true` reaches the format lookup as `1` and falls to grid
    expect(blogrollBlock(['style' => true])['format'])->toBe('grid');
});

it('sets each hide flag from its own attribute', function (string $attribute, string $flag): void {
    $block = blogrollBlock([$attribute => 'hide']);

    $flags = ['hide_img' => false, 'hide_date' => false, 'hide_creator_query' => false];
    $flags[$flag] = true;

    expect(array_intersect_key($block, $flags))->toEqual($flags);
})->with([
    'image' => ['image', 'hide_img'],
    'date' => ['date', 'hide_date'],
    'author' => ['author', 'hide_creator_query'],
]);

it('reads the value of a hide attribute', function (mixed $value, bool $hidden): void {
    expect(blogrollBlock(['image' => $value])['hide_img'])->toBe($hidden);
})->with([
    'empty' => ['', false],
    'true' => [true, false],
    'false' => [false, true],
    'the word hide' => ['hide', true],
    'the word false' => ['false', true],
    'the word no' => ['no', true],
    'the word off' => ['off', true],
    'the digit zero' => ['0', true],
    'two zeroes, which are numerically zero' => ['00', false],
    'the integer zero' => [0, true],
    'a word in capitals and padded' => [' HIDE ', true],
    'the word show' => ['show', false],
    'a value that is not scalar' => [['hide'], false],
]);

it('shows every field when no hide attribute is present', function (): void {
    expect(blogrollBlock([]))->toMatchArray([
        'hide_img' => false,
        'hide_date' => false,
        'hide_creator_query' => false,
    ]);
});

it('includes the link when a category is selected', function (array $attributes, bool $included): void {
    expect(blogrollBlock($attributes)['include_link'])->toBe($included);
})->with([
    'no category' => [[], false],
    'an empty category' => [['category' => ''], false],
    'an empty category name' => [['category_name' => ''], false],
    'false' => [['category' => false], false],
    'true' => [['category' => true], true],
    'blank text' => [['category' => '   '], false],
    'the digit zero' => [['category' => '0'], false],
    'the word false, in capitals' => [['category' => 'FALSE'], false],
    'a value that is not scalar' => [['category' => ['news']], false],
    'a term id' => [['category' => 37], true],
    'a slug' => [['category' => 'news'], true],
    'a category name' => [['category_name' => 'news'], true],
    'a padded slug' => [['category' => ' news '], true],
]);

it('reads the category attribute before the name for the link', function (): void {
    expect(blogrollBlock(['category' => '0', 'category_name' => 'news'])['include_link'])->toBeFalse();
});

it('reports a term id with the site, the id, the page and the reason', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.org/a-page');

    blogrollBlock(['category' => 37]);

    ProcessingContext::clearWordPressPageLink();

    /** @var list<array<string, mixed>> $warnings */
    $warnings = ImportWarningManager::getWarnings('content', 'unresolvable_blogroll_category_id');

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['site'] ?? null)->toBe('default');
    expect($warnings[0]['wordpress_term_id'] ?? null)->toBe('37');
    expect($warnings[0]['page_url'] ?? null)->toBe('https://example.org/a-page');
    expect($warnings[0]['reason'] ?? null)->toBe(
        'The [blogroll] `category` attribute is a WordPress term id, which the reshaped uamsweb_block_news fieldset '
        ."has no field for \u{2014} it used to be stored in the retired `advanced_cat`. The block will render every "
        .'news post; set its category in the Control Panel.'
    );

    // A term id cannot be looked up, so the resolver reports no miss of its own
    expect(ImportWarningManager::getWarningCount())->toBe(1);
});

it('falls back to the category when the category name is empty', function (): void {
    blogrollBlock(['category' => '37', 'category_name' => '']);

    expect(ImportWarningManager::getWarnings('content', 'unresolvable_blogroll_category_id'))->toHaveCount(1);
});

it('reads a scalar category name as text', function (): void {
    expect(blogrollBlock(['category_name' => 5]))->not->toHaveKey('category');
});

it('reports no term id unless the category is numeric', function (): void {

    blogrollBlock(['category' => '37', 'category_name' => 'news']);
    blogrollBlock(['category' => ['37']]);
    blogrollBlock(['category' => '']);
    blogrollBlock([]);

    expect(ImportWarningManager::getWarnings('content', 'unresolvable_blogroll_category_id'))->toBe([]);
});

it('writes the fixed block fields', function (): void {
    $block = blogrollBlock([]);

    expect($block['id'])->toBeString()->not->toBe('');

    unset($block['id']);

    expect($block)->toBe([
        'type' => 'uamsweb_block_news',
        'format' => 'grid',
        'block_heading' => "Placeholder Block Heading \u{2014} Please Replace",
        'hide_block_heading' => false,
        'block_description' => null,
        'source_sites' => ['news', 'current'],
        'offset' => 0,
        'hide_img' => false,
        'hide_date' => false,
        'hide_creator_query' => false,
        'include_link' => false,
        'background_color' => 'bg-auto',
        'enabled' => true,
    ]);
});
