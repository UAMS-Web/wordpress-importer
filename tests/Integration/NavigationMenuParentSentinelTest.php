<?php

declare(strict_types=1);

use Statamic\Facades\Nav as NavFacade;
use Statamic\Facades\Site;
use Statamic\Structures\Nav as NavStructure;
use Statamic\Structures\NavTree;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Navigation\NavigationMenuImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests that a top-level WordPress menu item survives the import.
 *
 * WordPress writes `_menu_item_menu_item_parent` as **`0`** for an item with no
 * parent; the importer represents "no parent" as an empty string. Until `wordpress-importer-archive#12`
 * those two never met, so a `'0'` item was neither top-level nor a child of
 * anything in the hierarchy map and fell out of both passes — not imported, not
 * counted as skipped, not logged, with its whole subtree behind it. Across the
 * 259-export corpus that is **3,938** items encoded `0` against **69** with the
 * meta absent, so in practice a menu imported almost nothing while reporting
 * completion.
 *
 * These use `custom` (plain URL) items on purpose: the defect is in the
 * hierarchy walk and has nothing to do with what an item links to, so the test
 * should not need a resolvable entry to demonstrate it.
 *
 * @command  php vendor/bin/pest tests/Integration/NavigationMenuParentSentinelTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    $nav = NavFacade::make('primary_navigation');

    // `NavFacade::make()` is typed to the contract, which declares none of these.
    if (! $nav instanceof NavStructure) {
        throw new RuntimeException('Statamic did not return a concrete navigation.');
    }

    $nav->title('Primary Navigation');

    // A nav's sites come from its trees, so there is nothing to set here — the
    // importer makes the `default` tree itself when it finds none.
    $nav->save();

    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

/**
 * Build a WXR export of `custom` menu items with the given parent encodings.
 *
 * @param  list<array{id: string, title: string, parent: string}>  $items  The menu items to emit
 * @return string The path of the fixture written to a temporary file
 */
function sentinelFixture(
    array $items
): string {
    $entries = '';

    foreach ($items as $item) {
        $entries .= <<<XML

            <item>
                <title><![CDATA[{$item['title']}]]></title>
                <category domain="nav_menu" nicename="primary-navigation"><![CDATA[Primary Navigation]]></category>
                <wp:post_id>{$item['id']}</wp:post_id>
                <wp:post_type><![CDATA[nav_menu_item]]></wp:post_type>
                <wp:status><![CDATA[publish]]></wp:status>
                <wp:menu_order>1</wp:menu_order>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_type]]></wp:meta_key>
                    <wp:meta_value><![CDATA[custom]]></wp:meta_value>
                </wp:postmeta>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_url]]></wp:meta_key>
                    <wp:meta_value><![CDATA[https://example.org/{$item['id']}]]></wp:meta_value>
                </wp:postmeta>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_menu_item_parent]]></wp:meta_key>
                    <wp:meta_value><![CDATA[{$item['parent']}]]></wp:meta_value>
                </wp:postmeta>
            </item>
XML;
    }

    $fixture = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>{$entries}
        </channel>
    </rss>
    XML;

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_sentinel_');
    file_put_contents($path, $fixture);

    return $path;
}

/**
 * Import a fixture into the primary navigation and return the console output.
 *
 * @param  string  $fixturePath  Path to the WXR fixture
 * @return string Everything the importer wrote to the console
 */
function runSentinelImport(
    string $fixturePath
): string {
    $xmlParser = new XmlParser;
    $xmlParser->parse($fixturePath);

    $output = new BufferedOutput;

    $importer = new NavigationMenuImporter;
    $importer->importNavigationMenu(
        $xmlParser,
        'primary-navigation',
        'default',
        $output
    );

    unlink($fixturePath);

    return $output->fetch();
}

/**
 * Read back the branches the import wrote.
 *
 * @return list<array<string, mixed>> The saved tree's top-level branches
 */
function sentinelBranches(): array
{
    $nav = NavFacade::findByHandle('primary_navigation');

    if (! $nav instanceof NavStructure) {
        throw new RuntimeException('The primary navigation was not created.');
    }

    $tree = $nav->in('default');

    if (! $tree instanceof NavTree) {
        throw new RuntimeException('The primary navigation has no tree for the default site.');
    }

    /** @var list<array<string, mixed>> $branches */
    $branches = $tree->tree();

    return $branches;
}

it('imports a top-level item whose parent is the WordPress `0` sentinel', function (): void {
    runSentinelImport(sentinelFixture([
        ['id' => '10', 'title' => 'About', 'parent' => '0'],
    ]));

    // Drop the `'0'` normalization and this is an empty tree — which is exactly
    // how the defect looked in production: a completed import with no menu.
    expect(sentinelBranches())->toHaveCount(1)
        ->and(sentinelBranches()[0]['title'] ?? null)->toBe('About');
});

it('treats an absent parent meta as top-level too', function (): void {
    // The 69 corpus items that omit the meta entirely are the only ones that
    // imported before the fix; they must keep working.
    $path = sentinelFixture([['id' => '10', 'title' => 'About', 'parent' => '0']]);
    $xml = (string) file_get_contents($path);
    $xml = (string) preg_replace(
        '/\s*<wp:postmeta>\s*<wp:meta_key><!\[CDATA\[_menu_item_menu_item_parent\]\]><\/wp:meta_key>.*?<\/wp:postmeta>/s',
        '',
        $xml
    );
    file_put_contents($path, $xml);

    runSentinelImport($path);

    expect(sentinelBranches())->toHaveCount(1);
});

/**
 * Read the titles of a branch's children, in tree order.
 *
 * @param  array<string, mixed>  $branch  A saved navigation branch
 * @return list<string> The child titles
 */
function sentinelChildTitles(
    array $branch
): array {
    $children = $branch['children'] ?? null;

    if (! \is_array($children)) {
        return [];
    }

    $titles = [];

    foreach ($children as $child) {
        if (! \is_array($child)) {
            continue;
        }

        $title = $child['title'] ?? null;

        if (\is_string($title)) {
            $titles[] = $title;
        }
    }

    return $titles;
}

it('nests a child under the parent it names', function (): void {
    runSentinelImport(sentinelFixture([
        ['id' => '10', 'title' => 'About', 'parent' => '0'],
        ['id' => '11', 'title' => 'Leadership', 'parent' => '10'],
    ]));

    $branches = sentinelBranches();

    // The subtree is the part that vanished with its root, so assert the nesting
    // rather than only the count.
    expect($branches)->toHaveCount(1)
        ->and($branches[0]['title'] ?? null)->toBe('About')
        ->and(sentinelChildTitles($branches[0]))->toBe(['Leadership']);
});

/**
 * Read one string field from the first warning of a `navigation` type.
 *
 * `ImportWarningManager` stores warnings as `mixed`, so a test reaching into one
 * has to narrow before it can assert on it.
 *
 * @param  string  $type  The warning type
 * @param  string  $field  The field to read
 * @return ?string The field value, or null when it is absent or not a string
 */
function sentinelWarningField(
    string $type,
    string $field
): ?string {
    $first = ImportWarningManager::getWarnings('navigation', $type)[0] ?? null;

    if (! \is_array($first)) {
        return null;
    }

    $value = $first[$field] ?? null;

    return \is_string($value)
        ? $value
        : null;
}

it('reports an item naming a parent outside this menu instead of dropping it in silence', function (): void {
    $console = runSentinelImport(sentinelFixture([
        ['id' => '10', 'title' => 'About', 'parent' => '0'],
        ['id' => '11', 'title' => 'Orphan', 'parent' => '9999'],
    ]));

    expect(sentinelBranches())->toHaveCount(1)
        ->and($console)->toContain('name a parent outside this menu')
        ->and(sentinelWarningField('unreachable_menu_item', 'wordpress_item_ids'))->toBe('11');
});

it('does not spin on a menu item cycle', function (): void {
    // Malformed export data: two items naming each other. Neither is placeable,
    // and the walk must terminate rather than hang the import.
    $console = runSentinelImport(sentinelFixture([
        ['id' => '10', 'title' => 'Loop A', 'parent' => '11'],
        ['id' => '11', 'title' => 'Loop B', 'parent' => '10'],
    ]));

    expect(sentinelBranches())->toBe([])
        ->and($console)->toContain('name a parent outside this menu');
});
