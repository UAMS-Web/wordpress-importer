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
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing\KbPageSubstitutions;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Navigation\NavigationMenuImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * A navigation item pointing at a kb_page follows the knowledge base that replaced it (wordpress-importer-archive#1171).
 *
 * A MinervaKB home page is deliberately never imported as a `pages` entry — the knowledge base
 * mounts at its URL instead. `findStatamicEntryByWordPressId()` resolves a post type to exactly
 * one collection, `page` maps to `pages`, and the replacement lives in `knowledge_bases`, so the
 * lookup could not succeed by construction and the item was dropped. A site's primary navigation
 * silently lost its knowledge-base link, which is the ordinary case rather than an edge one.
 *
 * Saves reach disk in this process's own content directory: `PreventsSavingStacheItemsToDisk`
 * moves every store, and `StatamicTestCase` makes that directory per-process
 * (`tests/__fixtures__/dev-null-<pid>/content/`). This file used to write into the tracked
 * `tests/__fixtures__/content/` tree, which every process in a checkout shares, so the next file
 * to write `pages`, `knowledge_bases` or `primary_navigation` there without the trait could
 * delete what this one was reading (wordpress-importer-archive#1399, the move wordpress-importer-archive#1375 made for the advert tests).
 *
 * @command  php vendor/bin/pest tests/Integration/KbPageNavigationSubstitutionTest.php
 */
uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * A WXR fixture of `post_type` menu items pointing at the given WordPress post ids.
 *
 * @param  list<array{id: string, target: string, title: string}>  $items
 */
function kbNavFixture(array $items): string
{
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
                    <wp:meta_value><![CDATA[post_type]]></wp:meta_value>
                </wp:postmeta>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_object]]></wp:meta_key>
                    <wp:meta_value><![CDATA[page]]></wp:meta_value>
                </wp:postmeta>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_object_id]]></wp:meta_key>
                    <wp:meta_value><![CDATA[{$item['target']}]]></wp:meta_value>
                </wp:postmeta>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_menu_item_parent]]></wp:meta_key>
                    <wp:meta_value><![CDATA[0]]></wp:meta_value>
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

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_kbnav_');
    file_put_contents($path, $fixture);

    return $path;
}

/**
 * A fixture with one resolvable parent and one unresolvable child beneath it.
 *
 * The child is the subject: `importChildItems()` only reaches an item whose parent is already
 * imported, so a droppable child needs a parent that resolves.
 *
 * @param  list<array{id: string, target: string, title: string, parent: string}>  $items
 */
function kbNavChildFixture(array $items): string
{
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
                    <wp:meta_value><![CDATA[post_type]]></wp:meta_value>
                </wp:postmeta>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_object]]></wp:meta_key>
                    <wp:meta_value><![CDATA[page]]></wp:meta_value>
                </wp:postmeta>
                <wp:postmeta>
                    <wp:meta_key><![CDATA[_menu_item_object_id]]></wp:meta_key>
                    <wp:meta_value><![CDATA[{$item['target']}]]></wp:meta_value>
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

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_kbnavchild_');
    file_put_contents($path, $fixture);

    return $path;
}

/**
 * Import a fixture into the primary navigation and return the console output.
 */
function runKbNavImport(string $fixturePath): string
{
    $xmlParser = new XmlParser;
    $xmlParser->parse($fixturePath);

    $output = new BufferedOutput;

    (new NavigationMenuImporter)->importNavigationMenu(
        $xmlParser,
        'primary-navigation',
        'default',
        $output
    );

    unlink($fixturePath);

    return $output->fetch();
}

/**
 * Every entry id the saved navigation tree references.
 *
 * @return list<string>
 */
function kbNavEntryIds(): array
{
    // Narrowed the way `NavigationMenuParentSentinelTest` does: the facade is typed to the
    // contract, which declares none of these.
    $nav = NavFacade::findByHandle('primary_navigation');

    if (! $nav instanceof NavStructure) {
        return [];
    }

    $tree = $nav->in('default');

    if (! $tree instanceof NavTree) {
        return [];
    }

    /** @var list<array<string, mixed>> $branches */
    $branches = $tree->tree();
    $ids = [];

    foreach ($branches as $branch) {
        if (isset($branch['entry']) && \is_string($branch['entry'])) {
            $ids[] = $branch['entry'];
        }
    }

    return $ids;
}

beforeEach(function (): void {
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    foreach (['pages', 'knowledge_bases'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default']);
        $collection->save();
    }

    $nav = NavFacade::make('primary_navigation');

    // `NavFacade::make()` is typed to the contract, which declares none of these.
    if (! $nav instanceof NavStructure) {
        throw new RuntimeException('Could not make the primary navigation.');
    }

    $nav->title('Primary Navigation');
    $nav->collections(['pages', 'knowledge_bases']);
    $nav->save();

    KbPageSubstitutions::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    KbPageSubstitutions::clear();
    ImportWarningManager::clearWarnings();

    // ---
    // Remove what this file wrote into the per-process content tree
    // ---

    // Teardown deletes that directory too, but the next test in this file would otherwise find
    // this test's entries and write suffixed copies beside them. An earlier version of this
    // file, writing into the tracked `tests/__fixtures__/content/` tree, left 26 files there.
    //
    // Removed as trees, because the suffixed copies are what a fixed list of slugs misses.
    $root = ContentRoot::collections();

    foreach (['pages', 'knowledge_bases'] as $handle) {
        $directory = $root.'/'.$handle;

        if (\is_dir($directory)) {
            $files = \glob($directory.'/*');

            foreach (\is_array($files) ? $files : [] as $file) {
                if (\is_file($file)) {
                    \unlink($file);
                }
            }

            \rmdir($directory);
        }

        if (\file_exists($root.'/'.$handle.'.yaml')) {
            \unlink($root.'/'.$handle.'.yaml');
        }
    }

    // Derived from the collections root rather than resolved directly: `ContentRoot` owns only
    // the subdirectories a Statamic store claims, and refuses `structures/navigation` outright
    // rather than falling back to `base_path()` — which is the behaviour that keeps a delete off
    // the real submodule, so it is worked with rather than around.
    $contentRoot = \dirname($root);

    foreach ([
        $contentRoot.'/navigation/primary_navigation.yaml',
        $contentRoot.'/structures/navigation/primary_navigation.yaml',
    ] as $navFile) {
        if (\file_exists($navFile)) {
            \unlink($navFile);
        }
    }
});

it('re-points a kb_page navigation item at the knowledge base that replaced it', function (): void {
    // The knowledge base root that mounted at the kb_page's URL. Its id embeds the WordPress
    // post id in production; the substitution is registered explicitly rather than parsed from
    // it, so this fixture does not have to imitate that scheme.
    $root = EntryFacade::make();

    if (! $root instanceof Entry) {
        throw new RuntimeException('Could not make the knowledge base root.');
    }

    $root->collection('knowledge_bases');
    $root->locale('default');
    $root->slug('pillars-of-wellness-at-uams');
    $root->set('title', 'Pillars of Wellness');
    $root->published(true);
    $root->save();

    $rootId = $root->id();
    expect($rootId)->toBeString();

    KbPageSubstitutions::register('4478', \is_string($rootId) ? $rootId : '', 'default');

    $output = runKbNavImport(kbNavFixture([
        ['id' => '6379', 'target' => '4478', 'title' => 'Knowledge Base'],
    ]));

    // The item resolves and is written as an entry link, rather than dropped.
    expect(kbNavEntryIds())->toContain($rootId);
    expect($output)->not->toContain('skipping navigation item');

    // Nothing is reported as unresolved, because nothing was.
    expect(ImportWarningManager::getWarnings('content', 'unresolved_navigation_items'))->toBe([]);
});

it('still drops an item whose target genuinely was not imported', function (): void {
    // The control. Without it the assertion above passes just as well against a resolver that
    // accepts anything, and the two cases must stay distinguishable in the output.
    $output = runKbNavImport(kbNavFixture([
        ['id' => '6380', 'target' => '9999', 'title' => 'Missing Page'],
    ]));

    expect(kbNavEntryIds())->toBe([]);
    expect($output)->toContain('skipping navigation item');

    // And the drop reaches the end-of-run summary, naming what an editor needs.
    $warnings = ImportWarningManager::getWarnings('content', 'unresolved_navigation_items');

    expect($warnings)->toHaveCount(1);

    // Narrowed before indexing: the manager returns `mixed` rows by contract.
    $warning = $warnings[0];
    expect($warning)->toBeArray();

    if (! \is_array($warning)) {
        throw new RuntimeException('Expected the warning to be an array.');
    }

    expect($warning['wordpress_post_id'])->toBe('9999');
    expect($warning['title'])->toBe('Missing Page');
    expect($warning['site'])->toBe('default');
});

it('counts one dropped child item once, not once per retry pass', function (): void {
    // `importChildItems()` loops `while ($changed)`, and an item that fails to create is never
    // added to `$importedItems` — so its guard stays true and it is retried, and re-counted, on
    // every pass. One dropped item reported as three. The count is what an operator uses to
    // judge whether a navigation import is worth re-running, so overstating it by a factor of
    // three is not cosmetic.
    $parent = EntryFacade::make();

    if (! $parent instanceof Entry) {
        throw new RuntimeException('Could not make the parent page.');
    }

    $parent->collection('pages');
    $parent->locale('default');
    $parent->slug('a-real-page');
    $parent->set('title', 'A real page');
    // `EntryFinder` matches the WordPress id out of the ENTRY ID, which it expects shaped
    // `{slug}-{wordPressId}-{random}` — not out of a field. An entry without that shape is
    // unfindable, which is how an earlier version of this test ended up asserting a skipped
    // count of 1 that counted the parent rather than the child it was written for.
    $parent->id('a-real-page-100-aaaaaaaa');
    $parent->published(true);
    $parent->save();

    $output = runKbNavImport(kbNavChildFixture([
        ['id' => '6379', 'target' => '100', 'title' => 'Parent', 'parent' => '0'],
        ['id' => '6380', 'target' => '9999', 'title' => 'Dropped child', 'parent' => '6379'],
    ]));

    // Exactly one item was dropped, so the report must say one.
    expect($output)->toContain('Skipped: 1 navigation items');
    expect($output)->not->toContain('Skipped: 2 navigation items');
    expect($output)->not->toContain('Skipped: 3 navigation items');

    // Control: the drop really happened, so the count above is of something rather than of
    // nothing — a suppressed skip would also satisfy "not 3".
    expect(ImportWarningManager::getWarnings('content', 'unresolved_navigation_items'))
        ->toHaveCount(1);
});
