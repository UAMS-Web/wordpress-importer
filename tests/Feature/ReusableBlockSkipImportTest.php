<?php

declare(strict_types=1);

use Illuminate\Testing\PendingCommand;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpBlockRegistry;
use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

/**
 * Proves a run's entry overwrite behavior reaches the reusable blocks its pages refer
 * to, through a real import (#245, #358).
 *
 * A page refers to a block before the block is imported, so the reference is
 * deferred and resolved through `DeferredProcessingCoordinator`, against
 * `WpBlockRegistry`. Only the command runs that whole chain: `wp_block` is not a
 * content type it offers, so a block is only ever imported for a page's sake.
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

/**
 * The entries a block left in `reusable_content`.
 *
 * @return list<Entry> The block and its copies
 */
function skipImportBlocks(): array
{
    $blocks = [];

    foreach (EntryFacade::whereCollection('reusable_content') as $entry) {
        if ($entry instanceof Entry && $entry->locale() === 'default' && str_starts_with((string) $entry->slug(), 'zz-skip-block')) {
            $blocks[] = $entry;
        }
    }

    return $blocks;
}

/**
 * Import a page and its block, then a new page and a changed block.
 *
 * @param  WordPressImportTestCase  $test  The test
 * @param  Closure(list<string>): string  $export  Writes an export of the items, returning its path
 * @param  string  $behavior  The answer to the entry overwrite question on the second run
 * @param  Closure(Entry, array<array-key, mixed>, Entry): void  $assert  Given the first block, its data, and the new page
 */
function skipImportTwice(WordPressImportTestCase $test, Closure $export, string $behavior, Closure $assert): void
{
    $block = skipImportItem('wp_block', 94501, 'zz-skip-block', '<!-- wp:paragraph --><p>Reused.</p><!-- /wp:paragraph -->');
    $changed = skipImportItem('wp_block', 94501, 'zz-skip-block', '<!-- wp:paragraph --><p>Changed.</p><!-- /wp:paragraph -->');
    $reference = '<!-- wp:paragraph --><p>Page.</p><!-- /wp:paragraph --><!-- wp:block {"ref":94501} /-->';
    $first = $export([$block, skipImportItem('page', 94502, 'zz-skip-first', $reference)]);
    $second = $export([$changed, skipImportItem('page', 94503, 'zz-skip-second', $reference)]);

    try {
        skipImportRun($test, $first, 'Overwrite matching files');
        $existing = skipImportEntry('reusable_content', 'zz-skip-block');

        expect($existing)->toBeInstanceOf(Entry::class);

        // Taken now, since the second run may delete this entry
        $data = $existing?->data()->all() ?? [];

        // A new run starts with an empty registry, and this one shares the process
        WpBlockRegistry::clear();

        skipImportRun($test, $second, $behavior);
        $page = skipImportEntry('pages', 'zz-skip-second');

        expect($page)->toBeInstanceOf(Entry::class);

        if ($page instanceof Entry && $existing instanceof Entry) {
            $assert($existing, $data, $page);
        }
    } finally {
        skipImportEntry('pages', 'zz-skip-first')?->delete();
        skipImportEntry('pages', 'zz-skip-second')?->delete();

        foreach (skipImportBlocks() as $entry) {
            $entry->delete();
        }
    }
}

it('leaves a block alone and points a new page at it on a run that skips matching files', function (): void {
    skipImportTwice($this, fn (array $items): string => $this->createTemporaryXmlFile($this->buildXmlContent($items)), 'Skip matching files', function (Entry $existing, array $data, Entry $page): void {
        $blocks = skipImportBlocks();

        expect($blocks)->toHaveCount(1)
            ->and($blocks[0]->id())->toBe($existing->id())
            ->and($blocks[0]->data()->all())->toBe($data)
            ->and(skipImportReusableSets($page))->toHaveCount(1)
            ->and(skipImportReusableSets($page)[0] ?? null)->toMatchArray(['content' => $existing->id()]);
    });
});

it('replaces a block and points a new page at the replacement on a run that overwrites', function (): void {
    skipImportTwice($this, fn (array $items): string => $this->createTemporaryXmlFile($this->buildXmlContent($items)), 'Overwrite matching files', function (Entry $existing, array $data, Entry $page): void {
        $blocks = skipImportBlocks();

        expect($blocks)->toHaveCount(1)
            ->and(json_encode($blocks[0]->data()->all()))->toContain('Changed.')
            ->and(skipImportReusableSets($page)[0] ?? null)->toMatchArray(['content' => $blocks[0]->id()]);
    });
});

it('keeps a block and points a new page at a copy on a run that creates an additional file', function (): void {
    skipImportTwice($this, fn (array $items): string => $this->createTemporaryXmlFile($this->buildXmlContent($items)), 'Create an additional file', function (Entry $existing, array $data, Entry $page): void {
        $blocks = skipImportBlocks();
        $copies = array_values(array_filter($blocks, fn (Entry $entry): bool => $entry->id() !== $existing->id()));

        expect($blocks)->toHaveCount(2)
            ->and($copies)->toHaveCount(1)
            ->and(json_encode($copies[0]->data()->all()))->toContain('Changed.')
            ->and(skipImportReusableSets($page)[0] ?? null)->toMatchArray(['content' => $copies[0]->id()]);
    });
});
