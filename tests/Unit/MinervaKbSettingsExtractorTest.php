<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Extractor;

/**
 * The MinervaKB settings `Extractor` maps a settings export onto the knowledge base
 * blueprint's fields, field by field and in the blueprint's order (#100).
 *
 * The string and boolean forms the two plugin versions store are covered by
 * `MinervaKbSettingsExtractorNormalizationTest`; this file covers the mapping itself.
 */

/**
 * Extract the knowledge base settings for a site named Research.
 *
 * @param  array<int|string, mixed>  $settings
 * @return array<int|string, mixed>
 */
function minervaKbExtract(array $settings): array
{
    return (new Extractor)->extractKnowledgeBaseSettings($settings, 'Research');
}

/**
 * The fields an export with no settings maps to, in order.
 *
 * @return array<string, mixed>
 */
function minervaKbExtractedDefaults(): array
{
    return [
        'title' => 'Research Knowledge Base',
        'nav_alt_name' => 'Knowledge Base',
        'knowledge_base_search_query' => true,
        'knowledge_base_search_title' => '',
        'knowledge_base_search_placeholder' => '',
        'topic_label' => '',
        'topic_limit' => -1,
        'show_all_topics_label' => '',
        'topic_description_query' => false,
        'topic_search_query' => false,
        'subtopic_description_query' => false,
        'topic_article_count' => null,
        'topic_article_description_query' => false,
        'article_last_modified_query' => false,
        'article_creator_query' => false,
        'article_estimated_reading_time_query' => false,
        'toc_query' => false,
        'toc_heading_exclude' => [],
        'toc_label' => '',
        'attachments_label' => '',
        'related_articles_query' => false,
    ];
}

it('maps an export with no settings to every field default, in order', function (): void {
    expect(minervaKbExtract([]))->toBe(minervaKbExtractedDefaults());
});

it('maps every setting an export carries to its field, in order', function (): void {
    expect(minervaKbExtract([
        'breadcrumbs_home_label' => 'Help Center',
        'home_sections_switch' => true,
        'home_sections' => ['search', 'topics'],
        'search_title' => 'Find it',
        'search_placeholder' => 'Type here',
        'cpt_topic_label_name' => 'Topic',
        'home_topics_limit' => '6',
        'show_all_label' => 'All topics',
        'home_topics_show_description' => true,
        'restrict_on' => true,
        'restrict_article_role' => '["subscriber"]',
        'restrict_article_message' => 'Sign in',
        'add_topic_search' => true,
        'show_topic_description' => true,
        'topic_articles_per_page' => '12',
        'show_search_page_excerpt' => true,
        'show_search_page_last_edit' => true,
        'last_modified_date_text' => 'Updated',
        'show_article_author' => true,
        'show_reading_estimate' => true,
        'toc_dynamic_enable' => true,
        'toc_headings_exclude' => 'h4, h5',
        'toc_label' => 'Contents',
        'article_attach_label' => 'Files',
        'show_related_articles' => true,
        'related_articles_label' => 'See also',
    ]))->toBe([
        'title' => 'Research Help Center',
        'nav_alt_name' => 'Help Center',
        'knowledge_base_search_query' => true,
        'knowledge_base_search_title' => 'Find it',
        'knowledge_base_search_placeholder' => 'Type here',
        'topic_label' => 'Topic',
        'topic_limit' => 6,
        'show_all_topics_label' => 'All topics',
        'topic_description_query' => true,
        'restriction_enabled' => true,
        'restriction_global_query' => true,
        'restriction_message' => 'Sign in',
        'restriction_category' => ['user_roles'],
        'restriction_user_roles' => null,
        'import_wordpress_notes' => ['restriction_user_roles: "subscriber"'],
        'topic_search_query' => true,
        'subtopic_description_query' => true,
        'topic_article_count' => 12,
        'topic_article_description_query' => true,
        'article_last_modified_query' => true,
        'article_last_modified_label' => 'Updated',
        'article_creator_query' => true,
        'article_estimated_reading_time_query' => true,
        'toc_query' => true,
        'toc_heading_exclude' => ['h4', 'h5'],
        'toc_label' => 'Contents',
        'attachments_label' => 'Files',
        'related_articles_query' => true,
        'related_articles_label' => 'See also',
    ]);
});

it('queries the search section unless home sections are on and leave it out', function (array $settings, bool $query): void {
    expect(minervaKbExtract($settings)['knowledge_base_search_query'])->toBe($query);
})->with([
    'home sections off' => [['home_sections_switch' => false, 'home_sections' => ['topics']], true],
    'home sections not exactly on' => [['home_sections_switch' => 1, 'home_sections' => ['topics']], true],
    'on, with search' => [['home_sections_switch' => true, 'home_sections' => ['topics', 'search']], true],
    'on, without search' => [['home_sections_switch' => true, 'home_sections' => ['topics']], false],
    'on, as a list in a string' => [['home_sections_switch' => true, 'home_sections' => 'topics, search'], true],
    'on, a string without search' => [['home_sections_switch' => true, 'home_sections' => 'topics,searching'], false],
    'on, with no sections' => [['home_sections_switch' => true], false],
    'on, with empty sections' => [['home_sections_switch' => true, 'home_sections' => []], false],
    'on, with an empty string' => [['home_sections_switch' => true, 'home_sections' => ''], false],
    'on, with a number' => [['home_sections_switch' => true, 'home_sections' => 5], false],
    'on, with a section that only loosely equals search' => [['home_sections_switch' => true, 'home_sections' => [true]], false],
]);

it('sets the search title and placeholder only when the search section is queried', function (): void {
    $result = minervaKbExtract([
        'home_sections_switch' => true,
        'home_sections' => ['topics'],
        'search_title' => 'Find it',
        'search_placeholder' => 'Type here',
    ]);

    expect($result)->not->toHaveKey('knowledge_base_search_title')
        ->and($result)->not->toHaveKey('knowledge_base_search_placeholder');
});

it('sets the last modified and related articles labels only when each is shown', function (): void {
    $result = minervaKbExtract([
        'show_search_page_last_edit' => false,
        'last_modified_date_text' => 'Updated',
        'show_related_articles' => false,
        'related_articles_label' => 'See also',
    ]);

    expect($result)->not->toHaveKey('article_last_modified_label')
        ->and($result)->not->toHaveKey('related_articles_label');
});

it('reads a label as text, and an empty one as its default', function (mixed $label, string $title): void {
    expect(minervaKbExtract(['breadcrumbs_home_label' => $label])['title'])->toBe($title);
})->with([
    'text' => ['Help Center', 'Research Help Center'],
    'false' => [false, 'Research Knowledge Base'],
    'an empty string' => ['', 'Research Knowledge Base'],
    'zero' => [0, 'Research Knowledge Base'],
    'the string zero' => ['0', 'Research Knowledge Base'],
    'a string only loosely zero' => ['0.0', 'Research 0.0'],
    'a number' => [1.5, 'Research 1.5'],
    'true' => [true, 'Research 1'],
    'null' => [null, 'Research Knowledge Base'],
    'a list' => [['Help'], 'Research Knowledge Base'],
]);

it('reads the topic limit as a number, and anything else as no limit', function (mixed $limit, int $expected): void {
    expect(minervaKbExtract(['home_topics_limit' => $limit])['topic_limit'])->toBe($expected);
})->with([
    'a numeric string' => ['6', 6],
    'a number' => [6.7, 6],
    'zero' => [0, 0],
    'text' => ['many', -1],
    'null' => [null, -1],
]);

it('reads the articles per topic as a number where the string is one, and as it is otherwise', function (mixed $count, mixed $expected): void {
    expect(minervaKbExtract(['topic_articles_per_page' => $count])['topic_article_count'])->toBe($expected);
})->with([
    'a numeric string' => ['12', 12],
    'a number' => [12, 12],
    'text' => ['all', 'all'],
    'null' => [null, null],
]);

it('reads the headings to leave out of the contents as a list', function (mixed $headings, array $expected): void {
    expect(minervaKbExtract(['toc_headings_exclude' => $headings])['toc_heading_exclude'])->toBe($expected);
})->with([
    'a list in a string' => [' h4 ,h5', ['h4', 'h5']],
    'a list' => [['h2'], ['h2']],
    'an empty string' => ['', []],
    'the string zero' => ['0', []],
    'a string only loosely zero' => ['0.0', ['0.0']],
    'a number' => [5, []],
    'false' => [false, []],
]);

it('reads a toggle as on when its value is truthy', function (mixed $value, bool $on): void {
    expect(minervaKbExtract(['toc_dynamic_enable' => $value])['toc_query'])->toBe($on);
})->with([
    'one' => [1, true],
    'zero' => [0, false],
    'text' => ['yes', true],
    'null' => [null, false],
]);

it('only marks restriction off when it is off', function (): void {
    expect(minervaKbExtract(['restrict_on' => false, 'restrict_article_message' => 'Sign in']))
        ->toBe(array_slice(minervaKbExtractedDefaults(), 0, 9) + ['restriction_enabled' => false] + array_slice(minervaKbExtractedDefaults(), 9));
});

it('restricts a guest-only knowledge base without a global query or message', function (): void {
    expect(minervaKbExtract(['restrict_on' => true, 'restrict_article_role' => '["guest"]', 'restrict_article_message' => 'Sign in']))
        ->toBe(array_slice(minervaKbExtractedDefaults(), 0, 9) + ['restriction_enabled' => true, 'restriction_global_query' => false] + array_slice(minervaKbExtractedDefaults(), 9));
});

it('restricts by role without a message when none is set', function (): void {
    $result = minervaKbExtract(['restrict_on' => true, 'restrict_article_role' => '["subscriber"]']);

    expect($result['restriction_enabled'])->toBeTrue()
        ->and($result['restriction_global_query'])->toBeTrue()
        ->and($result)->not->toHaveKey('restriction_message')
        ->and($result['restriction_category'])->toBe(['user_roles']);
});

it('sets nothing about restriction when it is neither on nor off', function (mixed $restrictOn): void {
    expect(minervaKbExtract(['restrict_on' => $restrictOn, 'restrict_article_message' => 'Sign in']))->toBe(minervaKbExtractedDefaults());
})->with(['missing' => [null], 'a number' => [1]]);
