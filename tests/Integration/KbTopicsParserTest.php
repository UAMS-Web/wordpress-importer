<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\KbTopicsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `KbTopicsParser` maps a `wp:minervakb/topics` block onto the
 * `uamsweb_block_kb_topics` set: the heading, the topic source with its list
 * or dynamic topics, the display, article, color and graphic settings, the
 * defaults, and the knowledge base from the processing context.
 *
 * @command  php vendor/bin/pest tests/Integration/KbTopicsParserTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    KbLinkRegistry::clear();
    ProcessingContext::clearKnowledgeBaseId();
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    KbLinkRegistry::clear();
    ProcessingContext::clearKnowledgeBaseId();
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Parse block attributes.
 *
 * @param  array<int|string, mixed>  $attributes  The block attributes
 * @return array<int|string, mixed> The set without its id
 */
function kbtParse(array $attributes): array
{
    $set = (new KbTopicsParser)->parse($attributes, 'default', new FakeAssetImporter, new NullOutput);

    expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
    unset($set['id']);

    return $set;
}

/**
 * The set that empty attributes map to.
 *
 * @return array<string, mixed> The set without its id
 */
function kbtDefaults(): array
{
    return [
        'type' => 'uamsweb_block_kb_topics',
        'enabled' => true,
        'article_limit' => '4',
        'topic_graphic' => 'icon',
        'hide_block_heading' => false,
        'background_color' => 'bg-auto',
    ];
}

/**
 * The set the given attributes map to, less the keys every set carries.
 *
 * @param  array<int|string, mixed>  $attributes  The block attributes
 * @return array<int|string, mixed> The set without the fields `kbtDefaults()` holds
 */
function kbtExtra(array $attributes): array
{
    return array_diff_key(kbtParse($attributes), ['type' => 1, 'enabled' => 1, 'hide_block_heading' => 1, 'background_color' => 1]);
}

/**
 * Registers the given term id to entry id mappings.
 *
 * @param  array<int, string>  $mappings  Entry ids keyed by term id
 */
function kbtRegister(array $mappings): void
{
    foreach ($mappings as $termId => $entryId) {
        KbLinkRegistry::registerKbtopicMappingByTermId((string) $termId, $entryId);
    }
}

it('maps empty attributes to the defaults', function (): void {
    expect(kbtParse([]))->toBe(kbtDefaults());
});

it('maps every field, in the order the set carries them', function (): void {
    ProcessingContext::setKnowledgeBaseId('kb-1');

    expect(kbtParse([
        'title' => 'Topics',
        'titleSize' => ['size' => '2em'],
        'titleColor' => '#111',
        'topics' => 'recent,top_likes',
        'view' => 'box',
        'columns' => '3col',
        'limit' => '6',
        'hideChildren' => 1,
        'showDescription' => false,
        'showAll' => false,
        'showCount' => false,
        'articlesLimit' => '8',
        'topicColor' => '#222',
        'forceTopicColor' => 1,
        'boxItemBg' => '#333',
        'boxItemHoverBg' => '#444',
        'countBg' => '#555',
        'countColor' => '#666',
        'showTopicIcons' => true,
        'useTopicImage' => true,
        'topicIcon' => 'fa-star',
        'forceTopicIcon' => 'yes',
        'imageSize' => ['size' => '3em'],
        'iconPaddingTop' => ['size' => '1em'],
        'iconPaddingBottom' => ['size' => '2em'],
        'showArticleIcons' => false,
        'articleIcon' => 'fa-file',
    ]))->toBe([
        'type' => 'uamsweb_block_kb_topics',
        'enabled' => true,
        'block_heading' => 'Topics',
        'title_size' => ['size' => '2em'],
        'title_color' => '#111',
        'topic_source' => 'dynamic',
        'topic_dynamic' => ['recently_published', 'top_likes'],
        'topic_display_style' => 'box',
        'topic_columns' => '3',
        'topic_limit' => '6',
        'hide_children_query' => true,
        'topic_description_query' => false,
        'topic_show_all_link_query' => false,
        'article_count_query' => false,
        'article_limit' => '8',
        'topic_color' => '#222',
        'topic_color_force_query' => true,
        'topic_box_item_bg_color' => '#333',
        'topic_box_item_bg_color_hover' => '#444',
        'article_count_bg_color' => '#555',
        'article_count_text_color' => '#666',
        'topic_graphic' => 'featured_image',
        'topic_icon_class' => 'fa-star',
        'topic_icon_force_query' => true,
        'topic_image_size' => ['size' => '3em'],
        'topic_icon_padding_top' => ['size' => '1em'],
        'topic_icon_padding_bottom' => ['size' => '2em'],
        'article_icon_query' => false,
        'article_icon_class' => 'fa-file',
        'hide_block_heading' => false,
        'background_color' => 'bg-auto',
        'knowledge_base' => ['kb-1'],
    ]);
});

it('maps a topic list, with the article settings in their order', function (): void {
    kbtRegister(['3' => 'entry-3']);

    expect(kbtParse(['topics' => '3', 'showAll' => true, 'showAllLabel' => 'All', 'articlesLimit' => '-1']))->toBe([
        'type' => 'uamsweb_block_kb_topics',
        'enabled' => true,
        'topic_source' => 'list',
        'topic_list' => ['entry-3'],
        'topic_show_all_link_text' => 'All',
        'article_all_query' => true,
        'article_limit' => '4',
        'topic_graphic' => 'icon',
        'hide_block_heading' => false,
        'background_color' => 'bg-auto',
    ]);
});

it('keeps the article query before the default article limit', function (): void {
    expect(kbtParse(['articlesLimit' => '0']))->toBe([
        'type' => 'uamsweb_block_kb_topics',
        'enabled' => true,
        'article_query' => false,
        'topic_graphic' => 'icon',
        'hide_block_heading' => false,
        'background_color' => 'bg-auto',
    ]);
});

it('maps the title to the heading, normalizing the default title', function (mixed $title, mixed $heading): void {
    expect(kbtExtra(['title' => $title])['block_heading'] ?? 'missing')->toBe($heading);
})->with([
    'empty' => ['', 'Popular Topics'],
    'the default' => ['Popular Topics', 'Popular Topics'],
    'the default in sentence case' => ['Popular topics', 'Popular Topics'],
    'the default in lower case' => ['popular topics', 'popular topics'],
    'another title' => ['Our topics', 'Our topics'],
    'an integer, as it is' => [5, 5],
    'missing' => [null, 'missing'],
]);

it('maps the array settings only when they are non-empty arrays', function (string $attribute, string $field): void {
    expect(kbtExtra([$attribute => ['size' => '1em']]))->toHaveKey($field, ['size' => '1em'])
        ->and(kbtExtra([$attribute => []]))->not->toHaveKey($field)
        ->and(kbtExtra([$attribute => '1em']))->not->toHaveKey($field)
        ->and(kbtExtra([$attribute => null]))->not->toHaveKey($field);
})->with([
    'titleSize' => ['titleSize', 'title_size'],
    'imageSize' => ['imageSize', 'topic_image_size'],
    'iconPaddingTop' => ['iconPaddingTop', 'topic_icon_padding_top'],
    'iconPaddingBottom' => ['iconPaddingBottom', 'topic_icon_padding_bottom'],
]);

it('maps the color settings as they are, unless empty', function (string $attribute, string $field): void {
    expect(kbtExtra([$attribute => '#abc']))->toHaveKey($field, '#abc')
        ->and(kbtExtra([$attribute => 0]))->toHaveKey($field, 0)
        ->and(kbtExtra([$attribute => '']))->not->toHaveKey($field)
        ->and(kbtExtra([$attribute => null]))->not->toHaveKey($field);
})->with([
    'titleColor' => ['titleColor', 'title_color'],
    'topicColor' => ['topicColor', 'topic_color'],
    'boxItemBg' => ['boxItemBg', 'topic_box_item_bg_color'],
    'boxItemHoverBg' => ['boxItemHoverBg', 'topic_box_item_bg_color_hover'],
    'countBg' => ['countBg', 'article_count_bg_color'],
    'countColor' => ['countColor', 'article_count_text_color'],
]);

it('maps a list of topic ids to their entries, in order', function (): void {
    kbtRegister(['3' => 'entry-3', '12' => 'entry-12']);

    expect(kbtExtra(['topics' => '12,3']))->toBe([
        'topic_source' => 'list',
        'topic_list' => ['entry-12', 'entry-3'],
        'article_limit' => '4',
        'topic_graphic' => 'icon',
    ]);
});

it('warns about each topic id with no entry, and keeps the ones it finds', function (): void {
    kbtRegister(['3' => 'entry-3']);
    ProcessingContext::setWordPressPageLink('https://example.org/kb/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(kbtExtra(['topics' => '9,3,8'])['topic_list'] ?? null)->toBe(['entry-3']);

    foreach (['9', '8'] as $id) {
        $logSpy->shouldHaveReceived('warning', [
            "KbTopicsParser::parse: Could not find Statamic entry ID for WordPress term ID '{$id}' (from entry: https://example.org/kb/)",
            ['site' => 'default', 'term_id' => $id, 'topics_string' => '9,3,8', 'entry_url' => 'https://example.org/kb/'],
        ]);
    }
});

it('sets no topic source when no topic id has an entry', function (): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(kbtExtra(['topics' => '9', 'limit' => '6', 'hideChildren' => true]))->toBe([
        'article_limit' => '4',
        'topic_graphic' => 'icon',
    ]);

    $logSpy->shouldHaveReceived('warning', [
        "KbTopicsParser::parse: Could not find Statamic entry ID for WordPress term ID '9'",
        ['site' => 'default', 'term_id' => '9', 'topics_string' => '9'],
    ]);
});

it('maps dynamic topic keywords in order, skipping the ones it does not know', function (): void {
    expect(kbtExtra(['topics' => ' updated, ,top_views,unknown,recent,top_likes,recent']))->toBe([
        'topic_source' => 'dynamic',
        'topic_dynamic' => ['recently_updated', 'top_views', 'recently_published', 'top_likes', 'recently_published'],
        'article_limit' => '4',
        'topic_graphic' => 'icon',
    ]);
});

it('reads a topics string that mixes ids and keywords as keywords', function (string $topics): void {
    kbtRegister(['3' => 'entry-3']);

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(kbtExtra(['topics' => $topics]))->toBe([
        'topic_source' => 'dynamic',
        'topic_dynamic' => ['recently_published'],
        'article_limit' => '4',
        'topic_graphic' => 'icon',
    ]);

    $logSpy->shouldNotHaveReceived('warning');
})->with([
    'an id before a keyword' => ['3,recent'],
    'an id with a space' => ['3, recent'],
    'a keyword before an id' => ['recent,3'],
]);

it('maps nothing from topics that name no known keyword, or are not a string', function (mixed $topics): void {
    expect(kbtExtra(['topics' => $topics, 'limit' => '6']))->toBe([
        'article_limit' => '4',
        'topic_graphic' => 'icon',
    ]);
})->with([
    'unknown keywords' => ['popular,new'],
    'ids with a space' => ['3, 4'],
    'ids with a trailing comma' => ['3,'],
    'an array' => [['3']],
    'an integer' => [3],
]);

it('maps the view only when it is list or box', function (mixed $view, ?string $style): void {
    expect(kbtExtra(['view' => $view])['topic_display_style'] ?? null)->toBe($style);
})->with([
    'list' => ['list', 'list'],
    'box' => ['box', 'box'],
    'another view' => ['grid', null],
    'missing' => [null, null],
]);

it('maps the columns to their number', function (mixed $columns, ?string $number): void {
    expect(kbtExtra(['columns' => $columns])['topic_columns'] ?? null)->toBe($number);
})->with([
    'one' => ['1col', '1'],
    'two' => ['2col', '2'],
    'three' => ['3col', '3'],
    'four' => ['4col', '4'],
    'five' => ['5col', null],
    'an integer' => [2, null],
    'missing' => [null, null],
]);

it('maps the limit of dynamic topics, defaulting one that is not a positive number', function (mixed $limit, ?string $topicLimit): void {
    expect(kbtExtra(['topics' => 'recent', 'limit' => $limit])['topic_limit'] ?? null)->toBe($topicLimit);
})->with([
    'a positive number' => ['10', '10'],
    'one' => ['1', '1'],
    'zero' => ['0', '4'],
    'zeros' => ['00', '4'],
    'a number with leading zeros' => ['007', '007'],
    'a number ending in zero' => ['10', '10'],
    'a negative number' => ['-2', '4'],
    'a number with text' => ['10px', '4'],
    'text before a number' => ['x10', '4'],
    'an integer' => [10, '4'],
    'missing' => [null, null],
]);

it('maps no limit for a topic list', function (): void {
    kbtRegister(['3' => 'entry-3']);

    expect(kbtExtra(['topics' => '3', 'limit' => '6']))->not->toHaveKey('topic_limit');
});

it('maps hide children for dynamic topics and for no topics, but not for a topic list', function (array $attributes, ?bool $hide): void {
    kbtRegister(['3' => 'entry-3']);

    expect(kbtExtra($attributes)['hide_children_query'] ?? null)->toBe($hide);
})->with([
    'dynamic, true' => [['topics' => 'recent', 'hideChildren' => true], true],
    'dynamic, zero' => [['topics' => 'recent', 'hideChildren' => 0], false],
    'dynamic, missing' => [['topics' => 'recent'], null],
    'no topics' => [['hideChildren' => 1], true],
    'empty topics' => [['topics' => '', 'hideChildren' => 'yes'], true],
    'empty topics, false' => [['topics' => '', 'hideChildren' => false], false],
    'a topic list' => [['topics' => '3', 'hideChildren' => true], null],
    'topics that are not a string' => [['topics' => ['3'], 'hideChildren' => true], null],
    'unknown keywords' => [['topics' => 'popular', 'hideChildren' => true], null],
]);

it('maps the switches that only switch something off', function (string $attribute, string $field): void {
    expect(kbtExtra([$attribute => false]))->toHaveKey($field, false)
        ->and(kbtExtra([$attribute => true]))->not->toHaveKey($field)
        ->and(kbtExtra([$attribute => 0]))->not->toHaveKey($field);
})->with([
    'showDescription' => ['showDescription', 'topic_description_query'],
    'showAll' => ['showAll', 'topic_show_all_link_query'],
    'showCount' => ['showCount', 'article_count_query'],
    'showArticleIcons' => ['showArticleIcons', 'article_icon_query'],
]);

it('maps the show-all label only when show all is true', function (mixed $showAll, mixed $label, mixed $text): void {
    expect(kbtExtra(['showAll' => $showAll, 'showAllLabel' => $label])['topic_show_all_link_text'] ?? 'missing')->toBe($text);
})->with([
    'true' => [true, 'See all', 'See all'],
    'true, with an empty label' => [true, '', ''],
    'true, with no label' => [true, null, 'missing'],
    'one' => [1, 'See all', 'missing'],
    'missing' => [null, 'See all', 'missing'],
]);

it('maps the articles limit to the article settings', function (mixed $limit, array $fields): void {
    $set = kbtExtra(['articlesLimit' => $limit]);

    expect(array_intersect_key($set, ['article_query' => 1, 'article_all_query' => 1, 'article_limit' => 1]))->toBe($fields);
})->with([
    'none' => ['0', ['article_query' => false]],
    'all' => ['-1', ['article_all_query' => true, 'article_limit' => '4']],
    'another negative number' => ['-5', ['article_limit' => '4']],
    'a negative number with text' => ['-5x', ['article_limit' => '-5x']],
    'a positive number' => ['10', ['article_limit' => '10']],
    'an integer, as it is' => [0, ['article_limit' => 0]],
    'false, as it is' => [false, ['article_limit' => false]],
    'missing' => [null, ['article_limit' => '4']],
]);

it('maps force topic color to a boolean', function (mixed $force, ?bool $query): void {
    expect(kbtExtra(['forceTopicColor' => $force])['topic_color_force_query'] ?? null)->toBe($query);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'zero' => [0, false],
    'missing' => [null, null],
]);

it('chooses the topic graphic from the icon and image switches', function (array $attributes, string $graphic): void {
    expect(kbtExtra($attributes)['topic_graphic'] ?? null)->toBe($graphic);
})->with([
    'neither' => [[], 'icon'],
    'both null' => [['showTopicIcons' => null, 'useTopicImage' => null], 'icon'],
    'icons on' => [['showTopicIcons' => true], 'icon'],
    'icons on, image off' => [['showTopicIcons' => true, 'useTopicImage' => false], 'icon'],
    'icons on, image on' => [['showTopicIcons' => true, 'useTopicImage' => true], 'featured_image'],
    'icons on, image one' => [['showTopicIcons' => true, 'useTopicImage' => 1], 'icon'],
    'icons off' => [['showTopicIcons' => false], 'none'],
    'icons off, image on' => [['showTopicIcons' => false, 'useTopicImage' => true], 'none'],
    'icons missing, image on' => [['useTopicImage' => true], 'none'],
    'icons one' => [['showTopicIcons' => 1, 'useTopicImage' => true], 'icon'],
]);

it('maps the icon classes unless they are the defaults', function (string $attribute, string $field, string $default): void {
    expect(kbtExtra([$attribute => 'fa-star']))->toHaveKey($field, 'fa-star')
        ->and(kbtExtra([$attribute => '']))->toHaveKey($field, '')
        ->and(kbtExtra([$attribute => $default]))->not->toHaveKey($field)
        ->and(kbtExtra([$attribute => null]))->not->toHaveKey($field);
})->with([
    'topicIcon' => ['topicIcon', 'topic_icon_class', 'fa-list-alt'],
    'articleIcon' => ['articleIcon', 'article_icon_class', 'fa-book'],
]);

it('maps force topic icon to a boolean, unless it is false', function (mixed $force, ?bool $query): void {
    expect(kbtExtra(['forceTopicIcon' => $force])['topic_icon_force_query'] ?? null)->toBe($query);
})->with([
    'true' => [true, true],
    'one' => [1, true],
    'zero' => [0, false],
    'false' => [false, null],
    'missing' => [null, null],
]);

it('sets the knowledge base from the processing context', function (): void {
    ProcessingContext::setKnowledgeBaseId('kb-7');

    expect(kbtParse([])['knowledge_base'] ?? null)->toBe(['kb-7']);
});
