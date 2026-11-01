<?php

declare(strict_types=1);

use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormidableFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\GravityFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\NinjaFormsCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * An unresolved Bard form reference is reported against the entry that carries it (wordpress-importer-archive#1162).
 *
 * The guard that stops the crash is covered by `UnresolvedBardFormReferenceTest`, which drives
 * the private recursive updater. That frame knows the note and nothing else — so it cannot
 * assert the half that makes the warning useful: **which entry an editor has to open.**
 *
 * Without a case here, `warnUnresolvedFormReferences()` can be made a complete no-op and the
 * suite stays green. That is a surviving mutant, and this file is what kills it.
 *
 * Saves reach disk in this process's own content directory: `PreventsSavingStacheItemsToDisk`
 * moves every store, and `StatamicTestCase` makes that directory per-process
 * (`tests/__fixtures__/dev-null-<pid>/content/`). This file used to write its `posts` collection
 * into the tracked `tests/__fixtures__/content/` tree, which every process in a checkout shares
 * (wordpress-importer-archive#1399).
 *
 * @command  php vendor/bin/pest tests/Integration/UnresolvedFormReferenceWarningTest.php
 */
uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Seed the statics `updateAllFormHandlesInEntries()` builds its map from.
 *
 * The method returns early unless some form was created, so a map must exist for the
 * unresolved path to be reachable at all — and the seeded form is deliberately **not** the
 * one the entry references.
 *
 * @param  array<int|string, string>  $handles
 */
function seedCreatedGravityForms(array $handles): void
{
    $property = new ReflectionProperty(FormPluginCoordinator::class, 'createdGravityFormsHandles');
    $property->setAccessible(true);
    $property->setValue(null, $handles);
}

beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    $collection = CollectionFacade::make('posts');
    $collection->sites(['default']);
    $collection->save();

    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    seedCreatedGravityForms([]);
    ImportWarningManager::clearWarnings();

    // Remove what this file created, as a tree rather than by name.
    //
    // **Named slugs are not enough and that was measured.** Statamic omits the site segment
    // when a collection has one site, so entries land flat at `collections/posts/<slug>.md`;
    // an earlier version unlinked `collections/posts/default/<slug>.md`, `file_exists` was
    // always false, and the cleanup removed nothing while reporting nothing. Worse, a second
    // run writes `may-30-noon.1.md` beside the first, which no fixed list of slugs reaches.
    //
    // The tree is the unit because the collection is created by this file too. Teardown
    // deletes the per-process directory as well, but the next test here would otherwise find
    // this test's entries and write suffixed copies beside them.
    $collectionDir = ContentRoot::collections().'/posts';

    if (\is_dir($collectionDir)) {
        $files = \glob($collectionDir.'/*');

        foreach (\is_array($files) ? $files : [] as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }

        \rmdir($collectionDir);
    }

    $collectionYaml = ContentRoot::collections().'/posts.yaml';

    if (\file_exists($collectionYaml)) {
        \unlink($collectionYaml);
    }
});

it('names the entry carrying a form reference the run could not resolve', function (): void {
    // A form WAS created, so the map is built and the method proceeds — but not form 14,
    // which is the one the entry embeds. That is the reported shape: 29 definitions served,
    // numbered with gaps, one post still pointing at a deleted form.
    seedCreatedGravityForms([15 => 'a_served_form']);

    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException('Could not make the test entry.');
    }

    $entry->collection('posts');
    $entry->locale('default');
    $entry->slug('may-30-noon');
    $entry->set('title', 'May 30 @ Noon');
    $entry->set('original_wordpress_url', 'https://gethealthy.uams.edu/?p=8705');
    $entry->set('content', [
        [
            'type' => 'set',
            'attrs' => [
                'id' => 'set-1',
                'values' => [
                    'type' => 'form',
                    'import_wordpress_notes' => 'Gravity Forms form ID 14',
                ],
            ],
        ],
    ]);
    $entry->published(true);
    $entry->save();

    (new FormPluginCoordinator(
        app(GravityFormsCoordinator::class),
        app(FormidableFormsCoordinator::class),
        app(NinjaFormsCoordinator::class),
    ))->updateAllFormHandlesInEntries('default', new BufferedOutput);

    // Scoped to the entry this test created rather than to a total. The suite shares a
    // content directory, so a count assertion would pass or fail on what other tests left
    // behind rather than on this behavior.
    $mine = \array_values(\array_filter(
        ImportWarningManager::getWarnings('content', 'unresolved_form_references'),
        static fn (mixed $warning): bool => \is_array($warning)
            && ($warning['entry_id'] ?? null) === $entry->id()
    ));

    expect($mine)->toHaveCount(1);

    // The entry, which is what an editor opens. The form id is a dangling pointer.
    expect($mine[0]['entry_title'])->toBe('May 30 @ Noon');
    expect($mine[0]['source_url'])->toBe('https://gethealthy.uams.edu/?p=8705');
    expect($mine[0]['form_notes'])->toBe('Gravity Forms form ID 14');
});

it('reports nothing when every referenced form resolves', function (): void {
    // Control: the warning must not fire on the path that works, or the assertion above
    // would pass just as well against a coordinator that warns unconditionally.
    seedCreatedGravityForms([14 => 'the_right_form']);

    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException('Could not make the test entry.');
    }

    $entry->collection('posts');
    $entry->locale('default');
    $entry->slug('resolves-fine');
    $entry->set('title', 'Resolves fine');
    $entry->set('content', [
        [
            'type' => 'set',
            'attrs' => [
                'id' => 'set-1',
                'values' => [
                    'type' => 'form',
                    'import_wordpress_notes' => 'Gravity Forms form ID 14',
                ],
            ],
        ],
    ]);
    $entry->published(true);
    $entry->save();

    (new FormPluginCoordinator(
        app(GravityFormsCoordinator::class),
        app(FormidableFormsCoordinator::class),
        app(NinjaFormsCoordinator::class),
    ))->updateAllFormHandlesInEntries('default', new BufferedOutput);

    // Same scoping: nothing may be reported against *this* entry.
    $mine = \array_filter(
        ImportWarningManager::getWarnings('content', 'unresolved_form_references'),
        static fn (mixed $warning): bool => \is_array($warning)
            && ($warning['entry_id'] ?? null) === $entry->id()
    );

    expect($mine)->toBe([]);
});
