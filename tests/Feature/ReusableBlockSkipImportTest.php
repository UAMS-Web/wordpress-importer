<?php

declare(strict_types=1);

use Illuminate\Testing\PendingCommand;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpBlockRegistry;
use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

/**
 * Proves a page's reference to a reusable block resolves on a "Skip matching files"
 * run through a real import (#245).
 *
 * A page refers to a block before the block is imported, so the reference is
 * deferred and resolved through `DeferredProcessingCoordinator`, against
 * `WpBlockRegistry`. The block itself is saved, not skipped: `wp_block` is not a
 * content type the command offers, and `ReusableBlockCoordinator`'s creator keeps
 * its default of overwriting, so this passes with or without the skip branch's
 * registration, which `ReusableBlockEntryCreatorTest` pins.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/ReusableBlockSkipImportTest.php
 */

/**
 * One item of a WordPress export.
 *
 * @param  string  $postType  The post type
 * @param  int  $postId  The post ID
 * @param  string  $slug  The slug
 * @param  string  $content  The block content
 * @return string The item XML
 */
function skipImportItem(string $postType, int $postId, string $slug, string $content): string
{
    return '<item>
        <title><![CDATA['.$slug.']]></title>
        <link>https://supplychain.uams.edu/'.$slug.'/</link>
        <dc:creator><![CDATA[uamsonline]]></dc:creator>
        <guid isPermaLink="false">https://supplychain.uams.edu/?p='.$postId.'</guid>
        <content:encoded><![CDATA['.$content.']]></content:encoded>
        <excerpt:encoded><![CDATA[]]></excerpt:encoded>
        <wp:post_id>'.$postId.'</wp:post_id>
        <wp:post_date><![CDATA[2025-01-01 10:00:00]]></wp:post_date>
        <wp:post_date_gmt><![CDATA[2025-01-01 10:00:00]]></wp:post_date_gmt>
        <wp:post_modified><![CDATA[2025-01-01 10:00:00]]></wp:post_modified>
        <wp:post_modified_gmt><![CDATA[2025-01-01 10:00:00]]></wp:post_modified_gmt>
        <wp:post_name><![CDATA['.$slug.']]></wp:post_name>
        <wp:status><![CDATA[publish]]></wp:status>
        <wp:post_parent>0</wp:post_parent>
        <wp:menu_order>0</wp:menu_order>
        <wp:post_type><![CDATA['.$postType.']]></wp:post_type>
    </item>';
}

/**
 * Run the import command over an export.
 *
 * @param  WordPressImportTestCase  $test  The test
 * @param  string  $file  The export path
 * @param  string  $behavior  The answer to the entry overwrite question
 */
function skipImportRun(WordPressImportTestCase $test, string $file, string $behavior): void
{
    $command = $test->artisan('statamic:import:wordpress', ['file' => $file]);

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('Expected a PendingCommand instance.');
    }

    $command
        ->expectsQuestion('Which site would you like to import the content into?', 'default')
        ->expectsQuestion('Which content type would you like to import?', 'page (1)')
        ->expectsQuestion('How should existing entries in the \'pages\' collection with matching IDs be handled?', $behavior)
        ->expectsQuestion('How should existing assets in the relevant container be handled?', 'Overwrite matching files')
        ->expectsQuestion('Would you like to import more content?', false)
        ->assertExitCode(0);
    $command->run();
}

/**
 * The entry with a slug in a collection.
 *
 * @param  string  $collection  The collection handle
 * @param  string  $slug  The slug
 * @return ?Entry The entry, if it exists
 */
function skipImportEntry(string $collection, string $slug): ?Entry
{
    foreach (EntryFacade::whereCollection($collection) as $entry) {
        if ($entry instanceof Entry && $entry->locale() === 'default' && $entry->slug() === $slug) {
            return $entry;
        }
    }

    return null;
}

/**
 * The values of every `reusable_content` set in a page's Bard content.
 *
 * @param  Entry  $page  The page
 * @return list<mixed> Each set's values
 */
function skipImportReusableSets(Entry $page): array
{
    $content = $page->get('content');
    $sets = [];

    foreach (is_array($content) ? $content : [] as $node) {
        $values = is_array($node) && is_array($node['attrs'] ?? null) ? ($node['attrs']['values'] ?? null) : null;

        if (is_array($values) && ($values['type'] ?? null) === 'reusable_content') {
            $sets[] = $values;
        }
    }

    return $sets;
}

it('points a new page at a block on a run that skips matching files', function (): void {
    $block = skipImportItem('wp_block', 94501, 'zz-skip-block', '<!-- wp:paragraph --><p>Reused.</p><!-- /wp:paragraph -->');
    $reference = '<!-- wp:paragraph --><p>Page.</p><!-- /wp:paragraph --><!-- wp:block {"ref":94501} /-->';
    $first = $this->createTemporaryXmlFile($this->buildXmlContent([$block, skipImportItem('page', 94502, 'zz-skip-first', $reference)]));
    $second = $this->createTemporaryXmlFile($this->buildXmlContent([$block, skipImportItem('page', 94503, 'zz-skip-second', $reference)]));

    try {
        skipImportRun($this, $first, 'Overwrite matching files');
        $existing = skipImportEntry('reusable_content', 'zz-skip-block');

        expect($existing)->toBeInstanceOf(Entry::class);

        // A new run starts with an empty registry, and this one shares the process
        WpBlockRegistry::clear();

        skipImportRun($this, $second, 'Skip matching files');
        $page = skipImportEntry('pages', 'zz-skip-second');

        expect($page)->toBeInstanceOf(Entry::class);

        if ($page instanceof Entry && $existing instanceof Entry) {
            expect(skipImportReusableSets($page))->toHaveCount(1)
                ->and(skipImportReusableSets($page)[0] ?? null)->toMatchArray(['content' => $existing->id()]);
        }
    } finally {
        foreach ([['pages', 'zz-skip-first'], ['pages', 'zz-skip-second'], ['reusable_content', 'zz-skip-block']] as [$collection, $slug]) {
            skipImportEntry($collection, $slug)?->delete();
        }
    }
});
