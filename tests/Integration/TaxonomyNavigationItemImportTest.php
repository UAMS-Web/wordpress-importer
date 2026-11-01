<?php

declare(strict_types=1);

use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Nav as NavFacade;
use Statamic\Facades\Site;
use Statamic\Structures\Nav as NavStructure;
use Statamic\Structures\NavTree;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Navigation\NavigationMenuImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * End-to-end cover for taxonomy navigation items, from WXR to the saved tree.
 *
 * This is the first test in the package to run {@see NavigationMenuImporter}
 * and read back what it wrote — before `wordpress-importer-archive#12` there were no assertions on
 * navigation output at all, in any suite, so the whole path was gated only by
 * whether it threw. That absence is why these tests assert the *tree*, not the
 * importer's return value: an item that resolves but never reaches the tree,
 * and an item silently dropped, are the two failures this path actually has.
 *
 * The band is Integration rather than Feature deliberately. `tests/Feature/` is
 * not one of this package's three CI jobs, so a host-app-only test would leave
 * the fix ungated. `PreventsSavingStacheItemsToDisk` keeps the created nav,
 * collection and entries out of the working tree.
 *
 * @command  php vendor/bin/pest tests/Integration/TaxonomyNavigationItemImportTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    $collection = CollectionFacade::make('post_categories');
    $collection->sites(['default']);
    $collection->save();

    $nav = NavFacade::make('primary_navigation');

    // `NavFacade::make()` is typed to the contract, which declares none of these.
    if (! $nav instanceof NavStructure) {
        throw new RuntimeException('Statamic did not return a concrete navigation.');
    }

    $nav->title('Primary Navigation');

    // A nav's sites come from its trees, so there is nothing to set here — the
    // importer makes the `default` tree itself when it finds none.
    $nav->save();

    TermDestinationRegistry::clear();
    KbLinkRegistry::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    TermDestinationRegistry::clear();
    KbLinkRegistry::clear();
    ImportWarningManager::clearWarnings();
});

/**
 * A WXR export holding one menu with one `category` navigation item.
 *
 * The `category` term is declared as `<wp:category>` because that is how
 * WordPress serializes the built-in taxonomy, and the menu item carries the
 * `_menu_item_*` postmeta the importer reads. The empty `<title>` mirrors the
 * corpus: two of the three real category items in the export set have one,
 * which is WordPress's way of saying "render the term's own name".
 *
 * @param  string  $title  The nav item's own title, empty to inherit the entry's
 * @param  string  $taxonomy  The `_menu_item_object` value
 * @param  string  $termId  The `_menu_item_object_id` value
 * @return string The path of the fixture written to a temporary file
 */
function navMenuFixture(
    string $title = '',
    string $taxonomy = 'category',
    string $termId = '19'
): string {
    $fixture = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <wp:category>
                <wp:term_id>19</wp:term_id>
                <wp:category_nicename><![CDATA[publications]]></wp:category_nicename>
                <wp:category_parent><![CDATA[]]></wp:category_parent>
                <wp:cat_name><![CDATA[Publications]]></wp:cat_name>
            </wp:category>
            <item>
                <title><![CDATA[{$title}]]></title>
                <category domain="nav_menu" nicename="primary-navigation"><![CDATA[Primary Navigation]]></category>
                <wp:post_id>5748</wp:post_id>
                <wp:post_type><![CDATA[nav_menu_item]]></wp:post_type>
                <wp:status><![CDATA[publish]]></wp:status>
                <wp:menu_order>1</wp:menu_order>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_type]]></wp:meta_key>
                    <wp:meta_value><![CDATA[taxonomy]]></wp:meta_value>
                </wp:postmeta>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_object]]></wp:meta_key>
                    <wp:meta_value><![CDATA[{$taxonomy}]]></wp:meta_value>
                </wp:postmeta>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_object_id]]></wp:meta_key>
                    <wp:meta_value><![CDATA[{$termId}]]></wp:meta_value>
                </wp:postmeta>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_menu_item_parent]]></wp:meta_key>
                    <wp:meta_value><![CDATA[0]]></wp:meta_value>
                </wp:postmeta>
            </item>
        </channel>
    </rss>
    XML;

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_navmenu_');
    file_put_contents($path, $fixture);

    return $path;
}

/**
 * Run the navigation import over a fixture and return the console output.
 *
 * @param  string  $fixturePath  Path to the WXR fixture
 * @return string Everything the importer wrote to the console
 */
function runNavImport(
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
 * Read back the branches the import wrote to the primary navigation.
 *
 * @return list<array<string, mixed>> The saved tree's top-level branches
 */
function savedNavBranches(): array
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

/**
 * Create the `post_categories` entry a resolved item should point at.
 *
 * @param  string  $slug  The entry slug
 * @return string The created entry's id
 */
function navImportCategoryEntry(
    string $slug = 'publications'
): string {
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException("Could not make an entry for '{$slug}'.");
    }

    $entry->collection('post_categories');
    $entry->locale('default');
    $entry->slug($slug);
    $entry->data(['title' => 'Publications']);
    $entry->save();

    $id = $entry->id();

    if (! \is_string($id) || $id === '') {
        throw new RuntimeException("Entry '{$slug}' saved without an id.");
    }

    return $id;
}

it('writes a category navigation item into the tree as an entry reference', function (): void {
    $entryId = navImportCategoryEntry();

    TermDestinationRegistry::registerCollectionEntry(
        'category',
        19,
        'post_categories',
        $entryId
    );

    runNavImport(navMenuFixture());

    $branches = savedNavBranches();

    expect($branches)->toHaveCount(1)
        ->and($branches[0]['entry'] ?? null)->toBe($entryId)
        // An empty WordPress title must not become an empty Statamic one, or the
        // branch renders blank instead of inheriting the entry's title.
        ->and($branches[0])->not->toHaveKey('title');
});

it('keeps a title the menu item set for itself', function (): void {
    $entryId = navImportCategoryEntry();

    TermDestinationRegistry::registerCollectionEntry(
        'category',
        19,
        'post_categories',
        $entryId
    );

    runNavImport(navMenuFixture('Good Catch Award'));

    $branches = savedNavBranches();

    // The corpus has one of these: a nav title that deliberately differs from
    // the term's own name, which the import must not overwrite.
    expect($branches[0]['title'] ?? null)->toBe('Good Catch Award');
});

it('resolves the item with no registry at all, from the saved entry', function (): void {
    $entryId = navImportCategoryEntry();

    // The nav-only re-run: nothing populated the registry in this process.
    runNavImport(navMenuFixture());

    expect(savedNavBranches()[0]['entry'] ?? null)->toBe($entryId);
});

it('drops an item whose term never imported, and says why', function (): void {
    // No entry, no registry — the term resolves to nothing at all.
    $console = runNavImport(navMenuFixture());

    expect(savedNavBranches())->toBe([])
        ->and($console)->toContain('Skipping taxonomy navigation item')
        ->and($console)->toContain('post_categories');
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
function navImportWarningField(
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

it('records a dropped item in the import summary, not only the log', function (): void {
    runNavImport(navMenuFixture('Publications'));

    // A dropped item leaves no trace in the tree, so the summary is the only
    // place an operator finds out the menu came out short.
    expect(navImportWarningField('dropped_taxonomy_item', 'title'))->toBe('Publications')
        ->and(navImportWarningField('dropped_taxonomy_item', 'reason_code'))->toBe('not_imported_into_site');
});

it('drops a flat-taxonomy item rather than inventing a URL for it', function (): void {
    TermDestinationRegistry::registerTaxonomyTerm(
        'post_tag',
        42,
        'post_tags',
        'default_cancer'
    );

    $console = runNavImport(navMenuFixture('Cancer', 'post_tag', '42'));

    // The rejected alternative was synthesizing `/tags/cancer` here. If a branch
    // ever appears for a flat term, that decision has been quietly reversed.
    expect(savedNavBranches())->toBe([])
        ->and($console)->toContain('post_tags');
});
