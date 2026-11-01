<?php

declare(strict_types=1);

use Illuminate\Testing\PendingCommand;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

/**
 * Proves "Create an additional file" gives each copy its own slug through a real
 * import (#340).
 *
 * The slug itself is pinned in the standalone suite by `BaseEntryCreatorTest`. Only
 * the import command saves a copy into the host app's `pages` collection, whose
 * structure and routes a copy has to survive.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/AdditionalEntryImportTest.php
 */

/**
 * Run the import command over an export.
 *
 * @param  WordPressImportTestCase  $test  The test
 * @param  string  $file  The export path
 * @param  string  $behavior  The answer to the entry overwrite question
 */
function additionalEntryImportRun(WordPressImportTestCase $test, string $file, string $behavior): void
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
 * The slugs of the `pages` entries a test imported.
 *
 * @param  string  $slug  The slug the entries start with
 * @return list<string> The slugs, sorted
 */
function additionalEntryImportSlugs(string $slug): array
{
    $slugs = [];

    foreach (EntryFacade::whereCollection('pages') as $entry) {
        if ($entry instanceof Entry && \is_string($entry->slug()) && \str_starts_with($entry->slug(), $slug)) {
            $slugs[] = $entry->slug();
        }
    }

    \sort($slugs);

    return $slugs;
}

it('saves each additional copy of a page under a slug of its own', function (): void {
    $slug = 'zz-additional-entry';
    $item = '<item>
        <title><![CDATA['.$slug.']]></title>
        <link>https://supplychain.uams.edu/'.$slug.'/</link>
        <dc:creator><![CDATA[uamsonline]]></dc:creator>
        <guid isPermaLink="false">https://supplychain.uams.edu/?p=94601</guid>
        <content:encoded><![CDATA[<!-- wp:paragraph --><p>Page.</p><!-- /wp:paragraph -->]]></content:encoded>
        <excerpt:encoded><![CDATA[]]></excerpt:encoded>
        <wp:post_id>94601</wp:post_id>
        <wp:post_date><![CDATA[2025-01-01 10:00:00]]></wp:post_date>
        <wp:post_date_gmt><![CDATA[2025-01-01 10:00:00]]></wp:post_date_gmt>
        <wp:post_modified><![CDATA[2025-01-01 10:00:00]]></wp:post_modified>
        <wp:post_modified_gmt><![CDATA[2025-01-01 10:00:00]]></wp:post_modified_gmt>
        <wp:post_name><![CDATA['.$slug.']]></wp:post_name>
        <wp:status><![CDATA[publish]]></wp:status>
        <wp:post_parent>0</wp:post_parent>
        <wp:menu_order>0</wp:menu_order>
        <wp:post_type><![CDATA[page]]></wp:post_type>
    </item>';
    $file = $this->createTemporaryXmlFile($this->buildXmlContent([$item]));

    try {
        additionalEntryImportRun($this, $file, 'Overwrite matching files');
        additionalEntryImportRun($this, $file, 'Create an additional file');
        additionalEntryImportRun($this, $file, 'Create an additional file');

        expect(additionalEntryImportSlugs($slug))->toBe([$slug, "{$slug}_copy-1", "{$slug}_copy-2"]);
    } finally {
        foreach (EntryFacade::whereCollection('pages') as $entry) {
            if ($entry instanceof Entry && \is_string($entry->slug()) && \str_starts_with($entry->slug(), $slug)) {
                $entry->delete();
            }
        }
    }
});
