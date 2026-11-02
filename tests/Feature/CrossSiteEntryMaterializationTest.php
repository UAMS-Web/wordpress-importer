<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Stache\Query\EntryQueryBuilder;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestCase;

/**
 * An import must not create entry files under a site it was not asked to touch (wordpress-importer-archive#1160).
 *
 * A run whose `--site` was `33` wrote 37 dataless `knowledge_bases` entries into
 * `health_employee_assistance_program` inside 70 milliseconds — a site with no content on
 * disk at the time. The ids were freshly minted rather than cached, and the host app rejects
 * every one of them.
 *
 * **The precondition is the reproducible half and the trigger is not.** The Stache keeps
 * serving entries after their files are removed out of band — a `git restore`, a branch
 * switch or a submodule pointer move all do that and none of them touch `storage/`. What
 * distinguishes a run that then materializes them from one that does not is unidentified: it
 * fired once in three attempts, and the three run logs are byte-identical apart from the
 * directory name.
 *
 * **So this test asserts the invariant, and carries a control for the precondition.** Without
 * the control it would pass whenever the phantom state failed to build, which is the same
 * shape as a probe that cannot see: a negative assertion and a broken setup are
 * indistinguishable from the result alone.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/CrossSiteEntryMaterializationTest.php
 */
uses(WordPressImportTestCase::class);

beforeEach(function (): void {
    $this->captureSubmoduleStates();
});

afterEach(function (): void {
    $this->assertSubmodulesUnchanged();
});

it('creates no entry files under a site the run was not asked to touch', function (): void {
    $sites = \array_keys($this->getAvailableSites());

    if (\count($sites) < 2) {
        TestCase::markTestSkipped('Needs two sites to distinguish the targeted one from the bystander.');
    }

    $target = $sites[0];
    $bystander = $sites[1];
    $collection = 'pages';

    $bystanderDir = ContentRoot::collections().'/'.$collection.'/'.$bystander;
    $movedAside = $bystanderDir.'.1160-moved-aside';

    // ---
    // Build the precondition: entries the Stache serves whose files are absent
    // ---

    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException('Could not make the bystander entry.');
    }

    $entry->collection($collection);
    $entry->locale($bystander);
    $entry->slug('uams-1160-bystander');
    $entry->set('title', 'Bystander');
    $entry->published(true);
    $entry->save();

    $restore = static function () use ($bystanderDir, $movedAside): void {
        if (\is_dir($movedAside)) {
            if (\is_dir($bystanderDir)) {
                $strays = \glob($bystanderDir.'/*');

                foreach (\is_array($strays) ? $strays : [] as $stray) {
                    if (\is_file($stray)) {
                        \unlink($stray);
                    }
                }

                \rmdir($bystanderDir);
            }

            \rename($movedAside, $bystanderDir);
        }
    };

    try {
        expect(\is_dir($bystanderDir))->toBeTrue();

        // Remove the files out of band, exactly as a `git restore` or a branch switch does.
        \rename($bystanderDir, $movedAside);

        // ---
        // CONTROL: the phantom state is real, or everything below is vacuous
        // ---

        expect(\is_dir($bystanderDir))->toBeFalse();

        // Narrowed the way `RedirectRuleImportTest` does: the facade's `query()` is typed to a
        // contract that carries neither `where()` nor `get()`.
        $query = EntryFacade::query();

        if (! $query instanceof EntryQueryBuilder) {
            throw new RuntimeException('Entry facade query() did not return EntryQueryBuilder.');
        }

        // Filtered in PHP rather than chained: `where()` is typed `mixed`, so the chain after
        // it cannot be narrowed at level max.
        $servedWhileAbsent = 0;
        $served = $query->get();

        if (! \is_iterable($served)) {
            throw new RuntimeException('Entry query get() did not return an iterable.');
        }

        foreach ($served as $found) {
            if (
                \is_object($found)
                && \method_exists($found, 'locale')
                && \method_exists($found, 'collectionHandle')
                && $found->locale() === $bystander
                && $found->collectionHandle() === $collection
            ) {
                $servedWhileAbsent++;
            }
        }

        expect($servedWhileAbsent)->toBeGreaterThan(
            0,
            'The Stache no longer serves the bystander site, so the precondition this test '
            .'exists to exercise was never built and a clean result below would mean nothing.'
        );

        // ---
        // The assertion: a run into one site leaves the other alone
        // ---

        // `createTempFile()` reserves a path; it does not write content.
        $xml = $this->createTempFile('uams-1160-cross-site');

        \file_put_contents(
            $xml,
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"
                 xmlns:content="http://purl.org/rss/1.0/modules/content/"
                 xmlns:dc="http://purl.org/dc/elements/1.1/"
                 xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/">
            <channel>
            <item>
              <title>UAMS 1160 target page</title>
              <wp:post_id>116001</wp:post_id>
              <wp:post_type>page</wp:post_type>
              <wp:status>publish</wp:status>
              <wp:post_name>uams-1160-target</wp:post_name>
              <content:encoded><![CDATA[<p>Body.</p>]]></content:encoded>
            </item>
            </channel>
            </rss>
            XML
        );

        // Inlined rather than reusing `importCommandRun()`: that helper is defined inside
        // `ImportWordPressCommandTest.php` and Pest scopes file-level functions per file.
        //
        // `Artisan::call()` and not `$this->artisan()` for the reason that file records —
        // the latter swaps the output buffer and its expectations are verified only after
        // the kernel returns.
        Artisan::call(
            'statamic:import:wordpress',
            [
                'file' => $xml,
                '--no-interaction' => true,
                '--site' => $target,
                '--content-type' => 'page',
                '--unsupported-content' => 'skip',
            ],
            new BufferedOutput
        );

        // The invariant. Any file here is an entry the run was never asked to write.
        $found = \is_dir($bystanderDir) ? \glob($bystanderDir.'/*.md') : [];
        $leaked = \is_array($found) ? $found : [];

        expect($leaked)->toBe(
            [],
            'The run targeted "'.$target.'" and wrote '.\count($leaked).' entry file(s) under '
            .'"'.$bystander.'", which it was not asked to touch.'
        );
    } finally {
        $restore();

        $this::cleanupTestFilesWithPaths(['uams-1160-bystander', 'uams-1160-target']);
    }
})->group('cross-site', 'dataless-entries');
