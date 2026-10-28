<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Statamic\Entries\Entry;
use Statamic\Events\NavTreeSaved;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Nav as NavFacade;
use Statamic\Facades\Site;
use Statamic\Structures\Nav as NavStructure;
use Statamic\Structures\NavTree;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\KbPageSubstitutions;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Navigation\NavigationMenuImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests `NavigationMenuImporter` end to end, from a WXR export to the saved navigation tree:
 * which items a menu holds, how they nest and sort, what each kind of item becomes, and what
 * the import says about the items it creates, skips and cannot place.
 *
 * Each case builds its own export, so the menu items it holds are the whole subject. The
 * `primary_navigation` structure and a `pages` collection are real, and are kept off disk.
 *
 * @command  php vendor/bin/pest tests/Integration/NavigationMenuImporterTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    $collection = CollectionFacade::make('pages');
    $collection->sites(['default']);
    $collection->save();

    $nav = NavFacade::make('primary_navigation');

    if (! $nav instanceof NavStructure) {
        throw new RuntimeException('Statamic did not return a concrete navigation.');
    }

    $nav->title('Primary Navigation');
    $nav->save();

    TermDestinationRegistry::clear();
    KbLinkRegistry::clear();
    KbPageSubstitutions::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    TermDestinationRegistry::clear();
    KbLinkRegistry::clear();
    KbPageSubstitutions::clear();
    ImportWarningManager::clearWarnings();
});

/**
 * Write a WXR export holding the given menu items, and answer its path.
 *
 * Each item is an array of the fields a `nav_menu_item` carries. A field left out of an item
 * is left out of the export: `id` (the `wp:post_id`), `title`, `type`, `object`, `object_id`,
 * `url`, `parent`, `order`, and `menus`, the menu slugs it belongs to.
 *
 * @param  list<array<string, mixed>>  $items  The menu items
 * @param  string  $channel  More channel XML, such as the terms the export declares
 * @return string The fixture's path
 */
function navTestFixture(array $items, string $channel = ''): string
{
    $meta = static fn (string $key, mixed $value): string => \is_string($value)
        ? '<wp:postmeta><wp:meta_key><![CDATA['.$key.']]></wp:meta_key><wp:meta_value><![CDATA['.$value.']]></wp:meta_value></wp:postmeta>'
        : '';

    $xml = '';

    foreach ($items as $item) {
        $menus = \is_array($item['menus'] ?? null) ? $item['menus'] : ['primary-navigation'];
        $categories = '';

        foreach ($menus as $menu) {
            $categories .= '<category domain="nav_menu" nicename="'.(\is_string($menu) ? $menu : '').'"><![CDATA[Menu]]></category>';
        }

        $id = $item['id'] ?? null;
        $title = $item['title'] ?? '';
        $order = $item['order'] ?? 0;

        $xml .= '<item><title><![CDATA['.(\is_string($title) ? $title : '').']]></title>'.$categories
            .(\is_string($id) ? '<wp:post_id>'.$id.'</wp:post_id>' : '')
            .'<wp:post_type><![CDATA[nav_menu_item]]></wp:post_type><wp:status><![CDATA[publish]]></wp:status>'
            .'<wp:menu_order>'.(\is_int($order) ? $order : 0).'</wp:menu_order>'
            .$meta('_menu_item_type', $item['type'] ?? 'custom')
            .$meta('_menu_item_object', $item['object'] ?? null)
            .$meta('_menu_item_object_id', $item['object_id'] ?? null)
            .$meta('_menu_item_url', $item['url'] ?? null)
            .$meta('_menu_item_menu_item_parent', $item['parent'] ?? '0')
            .'</item>';
    }

    $path = (string) \tempnam(\sys_get_temp_dir(), 'wxr_navtest_');
    \file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel>'.$channel.$xml.'</channel></rss>');

    return $path;
}

/**
 * Import a fixture's primary menu, and answer the console output with its line endings made
 * plain.
 *
 * @param  list<array<string, mixed>>  $items  The menu items
 * @param  ?NavigationMenuImporter  $importer  The importer, or a new one
 * @param  string  $channel  More channel XML, such as the terms the export declares
 */
function navTestRun(array $items, ?NavigationMenuImporter $importer = null, string $channel = ''): string
{
    $path = navTestFixture($items, $channel);
    $parser = new XmlParser;
    $parser->parse($path);
    $output = new BufferedOutput;

    ($importer ?? new NavigationMenuImporter)->importNavigationMenu($parser, 'primary-navigation', 'default', $output);
    \unlink($path);

    return \str_replace(\PHP_EOL, "\n", $output->fetch());
}

/**
 * The saved navigation tree for the default site.
 *
 * @return array<array-key, mixed>
 */
function navTestTree(): array
{
    $nav = NavFacade::findByHandle('primary_navigation');
    $tree = $nav instanceof NavStructure ? $nav->in('default') : null;

    if (! $tree instanceof NavTree) {
        throw new RuntimeException('The primary navigation has no tree for the default site.');
    }

    $branches = $tree->tree();

    return \is_array($branches) ? $branches : [];
}

/**
 * The saved tree with each branch's generated id left out, so it can be compared whole.
 *
 * @param  array<array-key, mixed>  $branches  The branches
 * @return list<mixed>
 */
function navTestShape(array $branches): array
{
    $shape = [];

    foreach ($branches as $branch) {
        if (! \is_array($branch)) {
            continue;
        }

        unset($branch['id']);
        $branch['children'] = navTestShape(\is_array($branch['children'] ?? null) ? $branch['children'] : []);
        $shape[] = $branch;
    }

    return $shape;
}

/**
 * Save a page entry whose id carries the given WordPress id, as the importer writes ids.
 *
 * @param  string  $slug  The slug
 * @param  string  $wordPressId  The WordPress post id
 * @param  string  $suffix  The random suffix
 */
function navTestPage(string $slug, string $wordPressId, string $suffix = 'abc123'): string
{
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException('Could not make an entry.');
    }

    $id = "{$slug}-{$wordPressId}-{$suffix}";
    $entry->id($id);
    $entry->collection('pages');
    $entry->locale('default');
    $entry->slug($slug);
    $entry->data(['title' => \ucfirst($slug)]);
    $entry->save();

    return $id;
}

/**
 * Put an entry in the importer's lookup cache under a post type and id.
 *
 * @param  string  $key  The cache key, `{post type}_{WordPress id}_{site}`
 * @param  Entry  $entry  The entry
 */
function navTestCache(string $key, Entry $entry): void
{
    $property = new ReflectionProperty(NavigationMenuImporter::class, 'entryCache');
    $cache = $property->getValue();
    $cache = \is_array($cache) ? $cache : [];
    $cache[$key] = $entry;
    $property->setValue(null, $cache);
}

/**
 * One field of one row in a list of rows.
 *
 * @param  array<array-key, mixed>  $rows  The rows
 * @param  int  $index  The row
 * @param  string  $field  The field
 */
function navTestField(array $rows, int $index, string $field): mixed
{
    $row = $rows[$index] ?? null;

    return \is_array($row) ? ($row[$field] ?? null) : null;
}

/**
 * Collect the warnings the log receives.
 *
 * @return ArrayObject<int, array{string, mixed}>
 */
function navTestWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{string, mixed}> $warnings */
    $warnings = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $logged) use ($warnings): void {
        if ($logged->level === 'warning') {
            $warnings->append([$logged->message, $logged->context]);
        }
    });

    return $warnings;
}

it('says so when the export holds no menu items', function (): void {
    $output = navTestRun([]);

    expect($output)->toBe("Starting navigation menu import for the 'primary-navigation' menu...\n  - No 'nav_menu_item' items found\n");
});

it('says so when no item belongs to the menu', function (): void {
    $output = navTestRun([['id' => '1', 'url' => 'https://a.test/', 'menus' => ['footer']]]);

    expect($output)->toBe(
        "Starting navigation menu import for the 'primary-navigation' menu...\n"
        ."  - Found 1 total 'nav_menu_item' item.\n"
        ."  - No 'nav_menu_item' items found for the 'primary-navigation' menu\n"
    );
});

it('counts the items in the export and in the menu, in the singular and the plural', function (): void {
    $output = navTestRun([
        ['id' => '1', 'url' => 'https://a.test/'],
        ['id' => '2', 'url' => 'https://b.test/', 'menus' => ['footer']],
    ]);

    expect($output)->toContain("  - Found 2 total 'nav_menu_item' items.\n  - Found 1 'nav_menu_item' item for the 'primary-navigation' menu.\n");

    $both = navTestRun([['id' => '1', 'url' => 'https://a.test/'], ['id' => '2', 'url' => 'https://b.test/']]);

    expect($both)->toContain("  - Found 2 'nav_menu_item' items for the 'primary-navigation' menu.\n");
});

it('counts an item once when it names the menu twice', function (): void {
    $output = navTestRun([['id' => '1', 'url' => 'https://a.test/', 'menus' => ['primary-navigation', 'primary-navigation']]]);

    expect($output)->toContain("  - Found 1 'nav_menu_item' item for the 'primary-navigation' menu.\n");
});

it('says so when there is no primary navigation, and writes nothing', function (): void {
    $nav = NavFacade::findByHandle('primary_navigation');

    if ($nav instanceof NavStructure) {
        $nav->delete();
    }

    $output = navTestRun([['id' => '1', 'url' => 'https://a.test/']]);

    expect($output)->toEndWith("  - Primary navigation not found in Statamic\n");
});

it('creates a tree for a site that has none, and reports what it imported', function (): void {
    $output = navTestRun([['id' => '1', 'title' => 'A', 'url' => 'https://a.test/']]);

    expect($output)->toContain("  - Primary navigation not available for site: default. Creating tree.\n")
        ->and($output)->toContain("Processing navigation item (Title: A, ID: 1, Type: custom)...\n  - Created nav item: 'https://a.test/'\n")
        ->and($output)->toEndWith("Navigation import completed:\n  - Successfully imported: 1 navigation items\nNavigation menu import completed\n")
        ->and(navTestShape(navTestTree()))->toBe([['title' => 'A', 'url' => 'https://a.test/', 'children' => []]]);
});

it('clears the items a tree already holds before importing', function (): void {
    navTestRun([['id' => '1', 'url' => 'https://a.test/'], ['id' => '2', 'url' => 'https://b.test/']]);

    $output = navTestRun([['id' => '3', 'url' => 'https://c.test/']]);

    expect($output)->toContain("Clearing 2 existing navigation items\n")
        ->and(navTestShape(navTestTree()))->toBe([['url' => 'https://c.test/', 'children' => []]]);
});

it('skips an item with no id', function (): void {
    navTestRun([['url' => 'https://a.test/'], ['id' => '2', 'url' => 'https://b.test/']]);

    expect(\array_column(navTestShape(navTestTree()), 'url'))->toBe(['https://b.test/']);
});

it('nests three levels, sorting each level by menu order and then title', function (): void {
    navTestRun([
        ['id' => '10', 'title' => 'Zeta', 'url' => 'https://z.test/', 'order' => 1],
        ['id' => '11', 'title' => 'Alpha', 'url' => 'https://a.test/', 'order' => 2],
        ['id' => '12', 'url' => 'https://untitled.test/', 'order' => 2],
        ['id' => '20', 'title' => 'Second', 'url' => 'https://second.test/', 'parent' => '10', 'order' => 5],
        ['id' => '21', 'title' => 'First', 'url' => 'https://first.test/', 'parent' => '10', 'order' => 4],
        ['id' => '30', 'title' => 'Deep', 'url' => 'https://deep.test/', 'parent' => '21'],
    ]);

    expect(navTestShape(navTestTree()))->toBe([
        ['title' => 'Zeta', 'url' => 'https://z.test/', 'children' => [
            ['title' => 'First', 'url' => 'https://first.test/', 'children' => [
                ['title' => 'Deep', 'url' => 'https://deep.test/', 'children' => []],
            ]],
            ['title' => 'Second', 'url' => 'https://second.test/', 'children' => []],
        ]],
        ['url' => 'https://untitled.test/', 'children' => []],
        ['title' => 'Alpha', 'url' => 'https://a.test/', 'children' => []],
    ]);
});

it('imports a chain as deep as the menu is long', function (): void {
    navTestRun([
        ['id' => '1', 'url' => 'https://1.test/'],
        ['id' => '2', 'url' => 'https://2.test/', 'parent' => '1'],
        ['id' => '3', 'url' => 'https://3.test/', 'parent' => '2'],
    ]);

    expect(navTestShape(navTestTree()))->toBe([
        ['url' => 'https://1.test/', 'children' => [
            ['url' => 'https://2.test/', 'children' => [
                ['url' => 'https://3.test/', 'children' => []],
            ]],
        ]],
    ]);
});

it('counts what it imported and skipped at every level, and reports items it cannot place', function (): void {
    $output = navTestRun([
        ['id' => '1', 'url' => 'https://1.test/'],
        ['id' => '2', 'type' => 'mystery'],
        ['id' => '3', 'url' => 'https://3.test/', 'parent' => '1'],
        ['id' => '4', 'type' => 'custom', 'parent' => '1'],
        ['id' => '5', 'url' => 'https://5.test/', 'parent' => '99'],
        ['id' => '6', 'url' => 'https://6.test/', 'parent' => '7'],
        ['id' => '7', 'url' => 'https://7.test/', 'parent' => '6'],
    ]);

    expect($output)->toContain("  - Unknown menu item type 'mystery' for item\n")
        ->and($output)->toContain("  - Custom navigation item has no URL (skipping navigation item)\n")
        ->and($output)->toContain("  - Not imported: 3 navigation item(s) name a parent outside this menu (5, 6, 7)\n")
        ->and($output)->toContain("  - Successfully imported: 2 navigation items\n  - Skipped: 2 navigation items (missing entries or invalid data)\n")
        ->and(ImportWarningManager::getWarnings('navigation', 'unreachable_menu_item'))->toBe([[
            'menu' => 'primary-navigation',
            'site' => 'default',
            'wordpress_item_ids' => '5, 6, 7',
            'reason' => 'Each item names a `_menu_item_menu_item_parent` that is not among '
                ."this menu's own items, so it has no place in the tree.",
        ]]);
});

it('reports no skipped items when none were skipped, and none it cannot place', function (): void {
    $output = navTestRun([['id' => '1', 'url' => 'https://1.test/']]);

    expect($output)->not->toContain('Skipped:')
        ->and($output)->not->toContain('Not imported:')
        ->and(ImportWarningManager::getWarnings('navigation', 'unreachable_menu_item'))->toBe([]);
});

it('decodes the entities in a title', function (): void {
    navTestRun([['id' => '1', 'title' => 'Ben &amp; Jerry&#039;s', 'url' => 'https://a.test/']]);

    expect(navTestField(navTestTree(), 0, 'title'))->toBe("Ben & Jerry's");
});

it('links a page item to the entry its post imported into, with its title', function (): void {
    $entryId = navTestPage('about', '40');

    $output = navTestRun([['id' => '1', 'title' => 'About us', 'type' => 'post_type', 'object' => 'page', 'object_id' => '40']]);

    expect(navTestShape(navTestTree()))->toBe([['entry' => $entryId, 'title' => 'About us', 'children' => []]])
        ->and($output)->toContain("  - Created link to entry: '{$entryId}'\n");
});

it('drops a page item whose post never imported, and records and logs why', function (): void {
    $warnings = navTestWarnings();

    $output = navTestRun([['id' => '1', 'type' => 'post_type', 'object' => 'page', 'object_id' => '41']]);

    expect($output)->toContain("  - Could not find Statamic entry for WordPress page ID 41 (skipping navigation item)\n")
        ->and(ImportWarningManager::getWarnings('content', 'unresolved_navigation_items'))->toBe([[
            'wordpress_post_id' => '41',
            'post_type' => 'page',
            'title' => '(untitled)',
            'site' => 'default',
            'effect' => 'The navigation item was dropped, so this link is absent from the '
                .'imported menu. The menu renders without it rather than failing.',
        ]])
        ->and($warnings->getArrayCopy())->toBe([[
            'NavigationMenuImporter::createPostTypeNavigationItem: Could not find Statamic entry',
            ['wordpress_post_id' => '41', 'post_type' => 'page', 'title' => '', 'site' => 'default'],
        ]]);
});

it('names a dropped page item by its title when it has one', function (): void {
    navTestRun([['id' => '1', 'title' => 'Gone', 'type' => 'post_type', 'object' => 'page', 'object_id' => '41']]);

    expect(navTestField(ImportWarningManager::getWarnings('content', 'unresolved_navigation_items'), 0, 'title'))->toBe('Gone');
});

it('logs an item whose post type maps to no collection', function (): void {
    $warnings = navTestWarnings();

    navTestRun([['id' => '1', 'type' => 'post_type', 'object' => 'widget', 'object_id' => '7']]);

    expect($warnings->getArrayCopy()[0] ?? null)->toBe([
        'NavigationMenuImporter::findStatamicEntryByWordPressId: Unknown post type',
        ['post_type' => 'widget', 'wordpress_id' => '7', 'site' => 'default'],
    ]);
});

it('answers a repeated lookup from its cache, and empties the cache when the import ends', function (): void {
    $cached = new Entry;
    $cached->id('cached-entry');
    navTestCache('page_40_default', $cached);
    $realId = navTestPage('about', '40');

    navTestRun([['id' => '1', 'type' => 'post_type', 'object' => 'page', 'object_id' => '40']]);

    expect(navTestField(navTestTree(), 0, 'entry'))->toBe('cached-entry');

    navTestRun([['id' => '1', 'type' => 'post_type', 'object' => 'page', 'object_id' => '40']]);

    expect(navTestField(navTestTree(), 0, 'entry'))->toBe($realId);
});

it('links an entry whose id is an integer, and skips one with an empty id', function (): void {
    $numbered = new Entry;
    $numbered->id(77);
    navTestCache('page_40_default', $numbered);

    $blank = new Entry;
    $blank->id('');
    navTestCache('page_41_default', $blank);

    $output = navTestRun([
        ['id' => '1', 'type' => 'post_type', 'object' => 'page', 'object_id' => '40'],
        ['id' => '2', 'type' => 'post_type', 'object' => 'page', 'object_id' => '41'],
    ]);

    expect(\array_column(navTestTree(), 'entry'))->toBe(['77'])
        ->and($output)->toContain("  - Entry for WordPress page ID 41 has no usable ID (skipping navigation item)\n");
});

it('prefers the entry a page imported into over a knowledge base that replaced another', function (): void {
    $entryId = navTestPage('about', '40');
    KbPageSubstitutions::register('40', navTestPage('kb', '99'), 'default');

    navTestRun([['id' => '1', 'type' => 'post_type', 'object' => 'page', 'object_id' => '40']]);

    expect(navTestField(navTestTree(), 0, 'entry'))->toBe($entryId);
});

it('records, logs and reports a taxonomy item it cannot link', function (): void {
    $warnings = navTestWarnings();

    $output = navTestRun([['id' => '1', 'type' => 'taxonomy', 'object' => 'category', 'object_id' => '19']]);

    expect($output)->toMatch("/  - Skipping taxonomy navigation item \\(untitled, category term 19\\): .+\n/");

    $dropped = ImportWarningManager::getWarnings('navigation', 'dropped_taxonomy_item')[0] ?? null;
    $logged = $warnings->getArrayCopy()[0] ?? null;

    expect(\is_array($dropped) ? \array_keys($dropped) : null)->toBe(['title', 'taxonomy', 'term_id', 'site', 'reason_code', 'reason'])
        ->and(\is_array($dropped) ? [$dropped['title'], $dropped['taxonomy'], $dropped['term_id'], $dropped['site']] : null)->toBe(['(untitled)', 'category', '19', 'default'])
        ->and($logged[0] ?? null)->toBe('NavigationMenuImporter::createTaxonomyNavigationItem: dropped a taxonomy navigation item')
        ->and(\is_array($logged[1] ?? null) ? \array_keys($logged[1]) : null)->toBe(['wordpress_term_id', 'taxonomy', 'title', 'site', 'reason_code', 'reason'])
        ->and(\is_array($dropped) && \is_array($logged[1] ?? null) ? [$logged[1]['reason_code'], $logged[1]['reason']] : null)
        ->toBe(\is_array($dropped) ? [$dropped['reason_code'], $dropped['reason']] : null);
});

it('names a dropped taxonomy item by its title when it has one', function (): void {
    $output = navTestRun([['id' => '1', 'title' => 'Topics', 'type' => 'taxonomy', 'object' => 'category', 'object_id' => '19']]);

    expect($output)->toContain("Skipping taxonomy navigation item 'Topics': ")
        ->and(navTestField(ImportWarningManager::getWarnings('navigation', 'dropped_taxonomy_item'), 0, 'title'))->toBe('Topics');
});

it('saves a new tree when it creates one, and the imported tree after', function (): void {
    /** @var ArrayObject<int, mixed> $saved */
    $saved = new ArrayObject;

    Event::listen(NavTreeSaved::class, static function (NavTreeSaved $event) use ($saved): void {
        $tree = $event->tree instanceof NavTree ? $event->tree->tree() : null;
        $saved->append(navTestShape(\is_array($tree) ? $tree : []));
    });

    navTestRun([['id' => '1', 'url' => 'https://a.test/']]);

    expect($saved->getArrayCopy())->toBe([[], [['url' => 'https://a.test/', 'children' => []]]]);
});

it('saves the cleared tree before the imported one', function (): void {
    navTestRun([['id' => '1', 'url' => 'https://a.test/']]);

    /** @var ArrayObject<int, mixed> $saved */
    $saved = new ArrayObject;

    Event::listen(NavTreeSaved::class, static function (NavTreeSaved $event) use ($saved): void {
        $tree = $event->tree instanceof NavTree ? $event->tree->tree() : null;
        $saved->append(navTestShape(\is_array($tree) ? $tree : []));
    });

    navTestRun([['id' => '2', 'url' => 'https://b.test/']]);

    expect($saved->getArrayCopy())->toBe([[], [['url' => 'https://b.test/', 'children' => []]]]);
});

it('gives each branch an id of its own', function (): void {
    navTestRun([['id' => '1', 'url' => 'https://a.test/'], ['id' => '2', 'url' => 'https://b.test/']]);

    $ids = \array_column(navTestTree(), 'id');

    expect($ids)->toHaveCount(2)
        ->and(\count(\array_unique(\array_map(static fn (mixed $id): string => \is_string($id) ? $id : '', $ids))))->toBe(2)
        ->and(\array_filter($ids, static fn (mixed $id): bool => \is_string($id) && \preg_match('/^[0-9a-f-]{36}$/', $id) === 1))->toHaveCount(2);
});

it('decodes a title encoded twice', function (): void {
    navTestRun([['id' => '1', 'title' => 'Ben &amp;amp; Jerry', 'url' => 'https://a.test/']]);

    expect(navTestField(navTestTree(), 0, 'title'))->toBe('Ben & Jerry');
});

it('leaves the title off a page link the menu item did not title', function (): void {
    $entryId = navTestPage('about', '40');

    navTestRun([['id' => '1', 'type' => 'post_type', 'object' => 'page', 'object_id' => '40']]);

    expect(navTestShape(navTestTree()))->toBe([['entry' => $entryId, 'children' => []]]);
});

it('links a taxonomy item to the entry its term imported into, and says so', function (): void {
    TermDestinationRegistry::registerCollectionEntry('category', '19', 'post_categories', 'category-entry');

    $output = navTestRun([['id' => '1', 'type' => 'taxonomy', 'object' => 'category', 'object_id' => '19']]);

    expect($output)->toContain("  - Created link to entry: 'category-entry'\n")
        ->and(navTestShape(navTestTree()))->toBe([['entry' => 'category-entry', 'children' => []]]);
});

it('reports a taxonomy item whose lookup failed, naming the failure', function (): void {
    EntryFacade::shouldReceive('query')->andThrow(new RuntimeException('store down'));

    $output = navTestRun(
        [['id' => '1', 'type' => 'taxonomy', 'object' => 'category', 'object_id' => '19']],
        channel: '<wp:category><wp:term_id>19</wp:term_id><wp:category_nicename><![CDATA[news]]></wp:category_nicename></wp:category>'
    );

    expect($output)->toContain("  - Skipping taxonomy navigation item (untitled, category term 19): resolving the term failed: store down\n");
});

it('imports a chain listed child first, which takes a pass per level', function (): void {
    navTestRun([
        ['id' => '3', 'url' => 'https://3.test/', 'parent' => '2'],
        ['id' => '2', 'url' => 'https://2.test/', 'parent' => '1'],
        ['id' => '1', 'url' => 'https://1.test/'],
    ]);

    expect(navTestShape(navTestTree()))->toBe([
        ['url' => 'https://1.test/', 'children' => [
            ['url' => 'https://2.test/', 'children' => [
                ['url' => 'https://3.test/', 'children' => []],
            ]],
        ]],
    ]);
});
