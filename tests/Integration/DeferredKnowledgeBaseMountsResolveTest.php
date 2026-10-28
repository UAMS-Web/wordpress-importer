<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Collection;
use Statamic\Entries\Entry as ConcreteEntry;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredKnowledgeBaseMounts;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * `DeferredKnowledgeBaseMounts::resolve()` mounts each knowledge base of a site beneath the
 * page its WordPress parent became, and says why when it cannot (#102). The registry itself
 * is covered by `DeferredKnowledgeBaseMountsTest`.
 *
 * @command  php vendor/bin/pest tests/Integration/DeferredKnowledgeBaseMountsResolveTest.php
 */
uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

beforeEach(function (): void {
    config(['statamic.search.indexes' => []]);
    DeferredKnowledgeBaseMounts::clear();

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    foreach (['pages', 'knowledge_bases'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default']);
        $collection->save();
    }
});

afterEach(function (): void {
    DeferredKnowledgeBaseMounts::clear();
});

/**
 * Save an entry with the given id, slug and data.
 *
 * @param  array<string, mixed>  $data
 */
function deferredMountEntry(string $collection, string $id, string $slug, array $data = []): ConcreteEntry
{
    $entry = EntryFacade::make();
    assert($entry instanceof ConcreteEntry);
    $entry->collection($collection);
    $entry->id($id);
    $entry->slug($slug);
    $entry->data(['title' => $slug] + $data);
    $entry->save();

    return $entry;
}

/**
 * The stored knowledge base root, read back fresh.
 */
function deferredMountRoot(string $id): ConcreteEntry
{
    $entry = EntryFacade::find($id);
    assert($entry instanceof ConcreteEntry);

    return $entry;
}

it('mounts a knowledge base beneath the page its WordPress parent became, keeping its data', function (): void {
    deferredMountEntry('pages', 'about-42-abcdefgh', 'about');
    deferredMountEntry('knowledge_bases', 'kb-root-1', 'help', ['intro' => 'Kept']);

    DeferredKnowledgeBaseMounts::defer('kb-root-1', '42', 'default');
    $output = new BufferedOutput;
    DeferredKnowledgeBaseMounts::resolve('default', $output);

    $root = deferredMountRoot('kb-root-1');

    expect($root->get('mount_parent'))->toBe(['about-42-abcdefgh'])
        ->and($root->get('intro'))->toBe('Kept')
        ->and($output->fetch())->toBe("Mounted knowledge base 'help' beneath page 'about'.\n")
        ->and(DeferredKnowledgeBaseMounts::all())->toBe([]);
});

it('says the parent page was not imported, logs it, and drops the mount', function (): void {
    deferredMountEntry('knowledge_bases', 'kb-root-1', 'help');
    $log = Log::spy();

    DeferredKnowledgeBaseMounts::defer('kb-root-1', '42', 'default');
    $output = new BufferedOutput;
    DeferredKnowledgeBaseMounts::resolve('default', $output);

    expect($output->fetch())->toBe("Warning: Could not mount knowledge base \u{2014} its parent page (WordPress ID '42') was not imported. The knowledge base will resolve at its default URL. Import the parent page and re-run to mount it.\n")
        ->and(DeferredKnowledgeBaseMounts::all())->toBe([])
        ->and(deferredMountRoot('kb-root-1')->get('mount_parent'))->toBeNull();

    $log->shouldHaveReceived('warning', [
        Mockery::on(static fn (mixed $message): bool => \is_string($message)
            && \str_contains($message, "DeferredKnowledgeBaseMounts::resolve: Parent page (WordPress ID '42') not found for site default; knowledge base left unmounted.")),
        Mockery::on(static fn (mixed $context): bool => \is_array($context)
            && ($context['kb_root_id'] ?? null) === 'kb-root-1'
            && ($context['parent_wordpress_post_id'] ?? null) === '42'
            && ($context['site'] ?? null) === 'default'),
    ]);
});

it('says the knowledge base root was not found, logs it, and drops the mount', function (): void {
    deferredMountEntry('pages', 'about-42-abcdefgh', 'about');
    $log = Log::spy();

    DeferredKnowledgeBaseMounts::defer('kb-root-9', '42', 'default');
    $output = new BufferedOutput;
    DeferredKnowledgeBaseMounts::resolve('default', $output);

    expect($output->fetch())->toBe("Warning: Could not mount knowledge base \u{2014} root entry 'kb-root-9' was not found.\n")
        ->and(DeferredKnowledgeBaseMounts::all())->toBe([]);

    $log->shouldHaveReceived('warning', [
        Mockery::on(static fn (mixed $message): bool => \is_string($message)
            && \str_contains($message, "DeferredKnowledgeBaseMounts::resolve: Knowledge base root entry 'kb-root-9' not found for site default.")),
        Mockery::on(static fn (mixed $context): bool => \is_array($context)
            && ($context['kb_root_id'] ?? null) === 'kb-root-9'
            && ($context['site'] ?? null) === 'default'),
    ]);
});

it('mounts the knowledge bases after one it cannot, and keeps another site\'s for its pass', function (): void {
    deferredMountEntry('pages', 'about-42-abcdefgh', 'about');
    deferredMountEntry('knowledge_bases', 'kb-root-1', 'help');

    DeferredKnowledgeBaseMounts::defer('kb-root-1', '99', 'default');
    DeferredKnowledgeBaseMounts::defer('kb-root-9', '42', 'default');
    DeferredKnowledgeBaseMounts::defer('kb-root-2', '7', 'research');
    DeferredKnowledgeBaseMounts::defer('kb-root-1', '42', 'default');

    $output = new BufferedOutput;
    DeferredKnowledgeBaseMounts::resolve('default', $output);

    expect(deferredMountRoot('kb-root-1')->get('mount_parent'))->toBe(['about-42-abcdefgh'])
        ->and($output->fetch())->toEndWith("Mounted knowledge base 'help' beneath page 'about'.\n")
        ->and(DeferredKnowledgeBaseMounts::all())->toBe([
            ['kb_root_id' => 'kb-root-2', 'parent_wp_id' => '7', 'site' => 'research'],
        ]);
});

it('looks the parent up among the pages of the site only', function (): void {
    // A knowledge base entry carrying the WordPress id is not a page
    deferredMountEntry('knowledge_bases', 'other-42-abcdefgh', 'other');
    deferredMountEntry('knowledge_bases', 'kb-root-1', 'help');

    DeferredKnowledgeBaseMounts::defer('kb-root-1', '42', 'default');
    $output = new BufferedOutput;
    DeferredKnowledgeBaseMounts::resolve('default', $output);

    expect($output->fetch())->toContain("its parent page (WordPress ID '42') was not imported");
});

it('looks the root up among the knowledge bases only', function (): void {
    deferredMountEntry('pages', 'about-42-abcdefgh', 'about');
    deferredMountEntry('pages', 'kb-root-1', 'not-a-knowledge-base');

    DeferredKnowledgeBaseMounts::defer('kb-root-1', '42', 'default');
    $output = new BufferedOutput;
    DeferredKnowledgeBaseMounts::resolve('default', $output);

    expect($output->fetch())->toContain("root entry 'kb-root-1' was not found");
});

it('saves the mounted knowledge base root', function (): void {
    deferredMountEntry('pages', 'about-42-abcdefgh', 'about');
    deferredMountEntry('knowledge_bases', 'kb-root-1', 'help');
    $saved = [];
    Event::listen(EntrySaved::class, function (EntrySaved $event) use (&$saved): void {
        $entry = $event->entry;
        $saved[] = $entry instanceof ConcreteEntry ? $entry->id() : null;
    });

    DeferredKnowledgeBaseMounts::defer('kb-root-1', '42', 'default');
    DeferredKnowledgeBaseMounts::resolve('default', new BufferedOutput);

    expect($saved)->toBe(['kb-root-1']);
});

it('names an entry without a slug as blank when it reports the mount', function (): void {
    foreach (['pages', 'knowledge_bases'] as $handle) {
        $collection = CollectionFacade::findByHandle($handle);
        assert($collection instanceof Collection);
        $collection->requiresSlugs(false);
        $collection->save();
    }

    foreach ([['pages', 'about-42-abcdefgh'], ['knowledge_bases', 'kb-root-1']] as [$collection, $id]) {
        $entry = EntryFacade::make();
        assert($entry instanceof ConcreteEntry);
        $entry->collection($collection);
        $entry->id($id);
        $entry->data(['title' => $id]);
        $entry->save();
    }

    DeferredKnowledgeBaseMounts::defer('kb-root-1', '42', 'default');
    $output = new BufferedOutput;
    DeferredKnowledgeBaseMounts::resolve('default', $output);

    expect($output->fetch())->toBe("Mounted knowledge base '' beneath page ''.\n");
});
