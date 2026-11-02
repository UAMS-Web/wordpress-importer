<?php

declare(strict_types=1);

use Illuminate\Support\Collection as IlluminateCollection;
use Illuminate\Support\Facades\Event;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Entries\Collection as ConcreteCollection;
use Statamic\Entries\Entry;
use Statamic\Events\EntrySaved;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\GlobalSet as GlobalSetFacade;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as ConcreteGlobalSet;
use Statamic\Globals\Variables;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\Creators\RedirectRuleGlobalCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\CompilesCleanly;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\TemporaryFileRegistry;

/**
 * Tests RedirectRuleGlobalCreator's write to a site's `redirects` global set, and the edges of
 * its write to the redirect domains collection that `RedirectDomainImportTest` does not reach.
 *
 * The global-set path reads each `redirect_rule`, builds a row, and appends it to the set's
 * `redirects` field. A row whose source the set already holds is skipped, added again or
 * replaced, as the overwrite behavior says. These tests run the real creator against a WXR for
 * an ordinary site and read the stored rows back.
 *
 * Target resolution goes through `LinkProcessor`, which needs the host application, so every
 * target passes through unchanged here, as `RedirectDomainImportTest` stubs it.
 *
 * @command  php vendor/bin/pest tests/Integration/RedirectRuleGlobalSetImportTest.php
 */
uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

// A `continue` mutant of a `break` in this class's switches only compiles with a
// warning; fail on it whichever test file loads the class first
beforeEach(function (): void {
    CompilesCleanly::assert(RedirectRuleGlobalCreator::class);
    config(['statamic.search.indexes' => []]);

    // Multisite has to be on for a collection whose first site is not the default
    config()->set('statamic.system.multisite', true);

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/'],
    ]);

    $collection = CollectionFacade::make(RedirectRuleGlobalCreator::DOMAIN_COLLECTION);
    $collection->sites(['default']);
    $collection->save();
});

afterEach(function (): void {
    foreach ([RedirectRuleGlobalCreator::DOMAIN_COLLECTION, 'other_collection'] as $handle) {
        $collection = CollectionFacade::findByHandle($handle);

        if ($collection instanceof ConcreteCollection) {
            $collection->delete();
        }
    }

    $global = GlobalSetFacade::findByHandle('redirects');

    if ($global instanceof ConcreteGlobalSet) {
        $global->delete();
    }

    TemporaryFileRegistry::sweep();
    Mockery::close();
});

/**
 * One `redirect_rule` item. A field left out of the rule is left out of the item.
 *
 * @param  array{id: int|string, from: string, to?: string, status?: string, regex?: bool, code?: string, order?: int, message?: string, notes?: string}  $rule
 */
function redirectGlobalItem(array $rule): string
{
    $meta = static fn (string $key, string $value): string => '<wp:postmeta><wp:meta_key><![CDATA['.$key.']]></wp:meta_key>'
        .'<wp:meta_value><![CDATA['.$value.']]></wp:meta_value></wp:postmeta>';

    return '<item><title><![CDATA['.$rule['from'].']]></title>'
        .'<wp:post_id>'.$rule['id'].'</wp:post_id>'
        .'<wp:status><![CDATA['.($rule['status'] ?? 'publish').']]></wp:status>'
        .(isset($rule['order']) ? '<wp:menu_order>'.$rule['order'].'</wp:menu_order>' : '')
        .'<wp:post_type><![CDATA[redirect_rule]]></wp:post_type>'
        .$meta('_redirect_rule_from', $rule['from'])
        .$meta('_redirect_rule_to', $rule['to'] ?? 'https://example.com/')
        .(isset($rule['code']) ? $meta('_redirect_rule_status_code', $rule['code']) : '')
        .(isset($rule['regex']) ? $meta('_redirect_rule_from_regex', $rule['regex'] ? '1' : '0') : '')
        .(isset($rule['message']) ? $meta('_redirect_rule_message', $rule['message']) : '')
        .(isset($rule['notes']) ? $meta('_redirect_rule_notes', $rule['notes']) : '')
        .'</item>';
}

/**
 * Write a WXR for the given base blog URL and rules, and return a parser over it.
 *
 * @param  list<array{id: int|string, from: string, to?: string, status?: string, regex?: bool, code?: string, order?: int, message?: string, notes?: string}>  $rules
 */
function redirectGlobalParser(string $baseBlogUrl, array $rules): XmlParser
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<channel><title>Site</title><link>'.$baseBlogUrl.'</link><wp:wxr_version>1.2</wp:wxr_version>'
        .'<wp:base_site_url>'.$baseBlogUrl.'</wp:base_site_url><wp:base_blog_url>'.$baseBlogUrl.'</wp:base_blog_url>'
        .\implode('', \array_map(static fn (array $rule): string => redirectGlobalItem($rule), $rules))
        .'</channel></rss>';

    $path = TemporaryFileRegistry::create('redirect_global_', '.xml');
    \file_put_contents($path, $xml);

    $parser = new XmlParser;
    $parser->parse($path);

    return $parser;
}

/**
 * Run a creator whose targets pass through unchanged, and return its console output.
 *
 * @param  XmlParser  $parser  The export
 * @param  ?string  $behavior  The overwrite behavior, or null for the default
 */
function redirectGlobalRun(XmlParser $parser, ?string $behavior = null): string
{
    $creator = new class extends RedirectRuleGlobalCreator
    {
        protected function processTargetUrl(string $rawTarget, string $site, AssetImporter $assetImporter, OutputInterface $output): string
        {
            return $rawTarget;
        }
    };

    if ($behavior !== null) {
        $creator->setGlobalOverwriteBehavior($behavior);
    }

    $output = new BufferedOutput;

    $creator->createGlobals(
        'default',
        'redirects',
        $parser,
        new AssetImporter,
        $output,
        new MetadataMapper,
        (new ReflectionClass(ContentProcessor::class))->newInstanceWithoutConstructor()
    );

    // Console output ends lines with PHP_EOL, which is \r\n on Windows
    return \str_replace(\PHP_EOL, "\n", $output->fetch());
}

/**
 * Make the `redirects` global set, enabled for the given sites, holding the given rows.
 *
 * @param  list<string>  $sites  The sites the set is enabled for
 * @param  mixed  $rows  What the default site's `redirects` field holds
 */
function redirectGlobalSet(array $sites = ['default'], mixed $rows = null): void
{
    // The GlobalSet facade declares no make(), so construct the concrete set
    $global = new ConcreteGlobalSet;
    $global->handle('redirects');
    $global->sites($sites);
    $global->save();

    if ($rows === null) {
        return;
    }

    $variables = $global->in('default');

    if (! $variables instanceof Variables) {
        throw new RuntimeException('The redirects global set is not enabled for the default site.');
    }

    $variables->set('redirects', $rows);
    $variables->save();
}

/**
 * The default site's stored redirect rows.
 *
 * @return list<array<string, mixed>>
 */
function redirectGlobalRows(): array
{
    $global = GlobalSetFacade::findByHandle('redirects');
    $variables = $global instanceof ConcreteGlobalSet ? $global->in('default') : null;
    $rows = $variables instanceof Variables ? $variables->get('redirects') : null;

    if (! \is_array($rows)) {
        throw new RuntimeException('The redirects global set has no rows.');
    }

    $list = [];

    foreach ($rows as $row) {
        if (! \is_array($row)) {
            throw new RuntimeException('A redirect row is not an array.');
        }

        $fields = [];

        foreach ($row as $field => $value) {
            $fields[(string) $field] = $value;
        }

        $list[] = $fields;
    }

    return $list;
}

/**
 * One stored row with its generated id left out, so it can be compared whole.
 *
 * @param  array<string, mixed>  $row  The stored row
 * @return array<string, mixed> The row without its id
 */
function redirectGlobalRowWithoutId(array $row): array
{
    unset($row['id']);

    return $row;
}

/**
 * Every stored entry in a collection.
 *
 * @param  string  $handle  The collection handle
 * @return list<Entry>
 */
function redirectGlobalEntries(string $handle = RedirectRuleGlobalCreator::DOMAIN_COLLECTION): array
{
    $query = EntryQueryHelper::query();
    $query->where('collection', $handle);
    $results = $query->get();

    if (! $results instanceof IlluminateCollection) {
        return [];
    }

    return \array_values(\array_filter($results->all(), static fn (mixed $entry): bool => $entry instanceof Entry));
}

/**
 * Save an entry by hand, as an editor or another collection would hold one.
 *
 * @param  string  $handle  The collection handle
 * @param  array<string, mixed>  $data  The entry's data
 */
function redirectGlobalSaveEntry(string $handle, array $data): Entry
{
    $entry = new Entry;
    $entry->collection($handle);
    $entry->locale('default');
    $entry->data($data);
    $entry->published(true);
    $entry->save();

    return $entry;
}

it('writes a row for each rule with its fields, defaults and messages', function (): void {
    redirectGlobalSet();
    Event::fake([GlobalVariablesSaved::class]);

    $output = redirectGlobalRun(redirectGlobalParser('https://supplychain.uams.edu', [
        ['id' => 11, 'from' => '<b>/give</b>', 'to' => 'https://give.example/', 'code' => '302', 'order' => 4, 'regex' => true, 'message' => '<i>Moved</i>', 'notes' => 'Campaign'],
        ['id' => 12, 'from' => '/plain', 'status' => 'draft'],
    ]));

    $rows = \array_map(redirectGlobalRowWithoutId(...), redirectGlobalRows());

    expect($rows)->toBe([
        [
            'target_type' => 'url',
            'target_url' => 'https://give.example/',
            'source' => '/give',
            'use_regex' => true,
            'response' => '302',
            'order' => 4,
            'type' => 'redirect',
            'status' => 'approved',
            'enabled' => true,
            'message' => 'Moved',
            'notes' => 'Campaign',
        ],
        [
            'target_type' => 'url',
            'target_url' => 'https://example.com/',
            'source' => '/plain',
            'use_regex' => false,
            'response' => '301',
            'order' => 30,
            'type' => 'redirect',
            'status' => 'approved',
            'enabled' => false,
        ],
    ])
        ->and($output)->toContain("Processing redirect rule '/give' with ID: 11...\n  - Created redirect rule '/give' (ID: 11).\n")
        ->and($output)->toContain("Processing redirect rule '/plain' with ID: 12...\n  - Created redirect rule '/plain' (ID: 12).\n")
        ->and($output)->toEndWith("Updated global set 'redirects' for site 'default'.\n");

    Event::assertDispatched(GlobalVariablesSaved::class);
});

it('keeps an empty target as an empty URL', function (): void {
    redirectGlobalSet();

    redirectGlobalRun(redirectGlobalParser('https://supplychain.uams.edu', [
        ['id' => 11, 'from' => '/gone', 'to' => '', 'code' => '410'],
    ]));

    $row = redirectGlobalRows()[0];

    expect($row['target_type'])->toBe('url')
        ->and($row['target_url'])->toBe('');
});

it('reports a global set not enabled for the site and writes nothing', function (): void {
    redirectGlobalSet(['other']);

    $output = redirectGlobalRun(redirectGlobalParser('https://supplychain.uams.edu', [
        ['id' => 11, 'from' => '/give'],
    ]));

    expect($output)->toBe("  - Global set 'redirects' is not enabled for site 'default' - skipping redirect rules.\n");
});

it('replaces a stored value that is not a list of rows', function (): void {
    redirectGlobalSet(rows: 'not-a-list');

    redirectGlobalRun(redirectGlobalParser('https://supplychain.uams.edu', [
        ['id' => 11, 'from' => '/give'],
    ]));

    expect(\array_column(redirectGlobalRows(), 'source'))->toBe(['/give']);
});

it('skips a rule whose source is stored, and adds the rules after it', function (): void {
    redirectGlobalSet(rows: [['id' => 'kept', 'source' => '/give', 'target_url' => 'https://old.example/']]);

    $output = redirectGlobalRun(redirectGlobalParser('https://supplychain.uams.edu', [
        ['id' => 11, 'from' => '/give', 'to' => 'https://new.example/'],
        ['id' => 12, 'from' => '/apply'],
    ]), 'Skip matching sets');

    $rows = redirectGlobalRows();

    expect(\array_column($rows, 'source'))->toBe(['/give', '/apply'])
        ->and($rows[0]['target_url'])->toBe('https://old.example/')
        ->and($output)->toContain("Processing redirect rule '/give' with ID: 11...\n  - Skipping redirect rule '/give' (ID: 11) as it already exists.\n")
        ->and($output)->not->toContain("Created redirect rule '/give'");
});

it('adds a second row for a stored source when told to create an additional set', function (): void {
    redirectGlobalSet(rows: [['id' => 'kept', 'source' => '/give', 'target_url' => 'https://old.example/']]);

    $output = redirectGlobalRun(redirectGlobalParser('https://supplychain.uams.edu', [
        ['id' => 11, 'from' => '/give', 'to' => 'https://new.example/'],
    ]), 'Create an additional set');

    expect(\array_column(redirectGlobalRows(), 'target_url'))->toBe(['https://old.example/', 'https://new.example/'])
        ->and($output)->toContain("  - Creating additional redirect rule '/give' with same source URL.\n  - Created redirect rule '/give' (ID: 11).\n");
});

it('replaces each stored row whose source a rule matches', function (): void {
    // The second match reads its position from the rows left after the first is removed,
    // so the rows have to be renumbered between the two
    redirectGlobalSet(rows: [
        ['id' => 'a', 'source' => '/give', 'target_url' => 'https://old.example/give'],
        ['id' => 'b', 'source' => '/apply', 'target_url' => 'https://old.example/apply'],
        ['id' => 'c', 'source' => '/keep', 'target_url' => 'https://old.example/keep'],
    ]);

    $output = redirectGlobalRun(redirectGlobalParser('https://supplychain.uams.edu', [
        ['id' => 11, 'from' => '/give', 'to' => 'https://new.example/give'],
        ['id' => 12, 'from' => '/apply', 'to' => 'https://new.example/apply'],
    ]));

    expect(\array_column(redirectGlobalRows(), 'target_url'))->toBe([
        'https://old.example/keep',
        'https://new.example/give',
        'https://new.example/apply',
    ])
        ->and($output)->toContain("  - Overwriting existing redirect rule '/give' (ID: 11).\n  - Updated redirect rule '/give' (ID: 11).\n")
        ->and($output)->toContain("  - Updated redirect rule '/apply' (ID: 12).\n")
        ->and($output)->not->toContain('Created redirect rule');
});

it('leaves the redirects global set alone for a vanity domain', function (): void {
    redirectGlobalSet();

    redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 11, 'from' => '/give'],
    ]));

    $global = GlobalSetFacade::findByHandle('redirects');
    $variables = $global instanceof ConcreteGlobalSet ? $global->in('default') : null;

    expect($variables instanceof Variables ? $variables->get('redirects') : 'no variables')->toBeNull()
        ->and(redirectGlobalEntries())->toHaveCount(1);
});

it('matches a listed host whatever case the configuration writes it in', function (): void {
    config(['wordpress-import.redirect_domains.hosts' => ['UAMS.Info']]);

    expect(RedirectRuleGlobalCreator::redirectDomainHost('https://uams.info'))->toBe('uams.info');
});

it('writes a vanity domain entry with its title, defaults and no empty notes', function (): void {
    $output = redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 11, 'from' => '/give'],
    ]));

    $entry = redirectGlobalEntries()[0];

    expect($entry->get('title'))->toBe('uams.info/give')
        ->and($entry->get('use_regex'))->toBeFalse()
        ->and($entry->get('order'))->toBe(30)
        ->and($entry->get('response'))->toBe('301')
        ->and($entry->get('notes'))->toBeNull()
        ->and($entry->get('target_url'))->toBe('https://example.com/')
        ->and($output)->not->toContain('response code the redirect domains blueprint does not offer');
});

it('writes a vanity domain entry in the collection\'s first site', function (): void {
    $collection = CollectionFacade::findByHandle(RedirectRuleGlobalCreator::DOMAIN_COLLECTION);

    if (! $collection instanceof ConcreteCollection) {
        throw new RuntimeException('The redirect domains collection is missing.');
    }

    $collection->sites(['other', 'default']);
    $collection->save();

    redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 11, 'from' => '/give'],
    ]));

    expect(redirectGlobalEntries()[0]->locale())->toBe('other');
});

it('counts a response code the blueprint offers only when it matches exactly', function (): void {
    // A loose comparison would read '301.0' as the offered '301'
    $output = redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 11, 'from' => '/give', 'code' => '301.0'],
    ]));

    expect($output)->toContain('1 rule(s) for uams.info use a response code the redirect domains blueprint does not offer');
});

it('shadows a rule whose source differs in case from the served one', function (): void {
    $output = redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 11, 'from' => '/flu', 'to' => 'https://served.example/', 'order' => 0],
        ['id' => 12, 'from' => '/FLU', 'to' => 'https://shadowed.example/', 'order' => 5],
    ]));

    $entries = redirectGlobalEntries();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->get('target_url'))->toBe('https://served.example/')
        ->and($output)->toContain('1 created, 0 updated, 0 disabled, 0 unpublished skipped, 1 shadowed.');
});

it('still shadows after an unpublished rule with the same source', function (): void {
    $output = redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 10, 'from' => '/flu', 'status' => 'draft'],
        ['id' => 11, 'from' => '/flu', 'to' => 'https://served.example/', 'order' => 0],
        ['id' => 12, 'from' => '/flu', 'to' => 'https://shadowed.example/', 'order' => 5],
    ]));

    expect(redirectGlobalEntries()[0]->get('target_url'))->toBe('https://served.example/')
        ->and($output)->toContain('1 created, 0 updated, 0 disabled, 1 unpublished skipped, 1 shadowed.');
});

it('reads a rule with no position as position 0, ahead of a rule at position 1', function (): void {
    redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 20, 'from' => '/flu', 'to' => 'https://no-position.example/'],
        ['id' => 10, 'from' => '/flu', 'to' => 'https://position-1.example/', 'order' => 1],
    ]));

    expect(redirectGlobalEntries()[0]->get('target_url'))->toBe('https://no-position.example/');
});

it('reads a rule with no position as tied with one at position 0, which the lower id wins', function (): void {
    redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 20, 'from' => '/flu', 'to' => 'https://no-position.example/'],
        ['id' => 10, 'from' => '/flu', 'to' => 'https://position-0.example/', 'order' => 0],
    ]));

    expect(redirectGlobalEntries()[0]->get('target_url'))->toBe('https://position-0.example/');
});

it('breaks a tie in plugin, position and number by the whole id', function (): void {
    redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 'redirection-5', 'from' => '/flu', 'to' => 'https://first-in-export.example/', 'order' => 0],
        ['id' => 'redirection-05', 'from' => '/flu', 'to' => 'https://lower-id.example/', 'order' => 0],
    ]));

    expect(redirectGlobalEntries()[0]->get('target_url'))->toBe('https://lower-id.example/');
});

it('ranks a Pretty Links link ahead of a Redirection rule, and both ahead of a post', function (): void {
    redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 1, 'from' => '/a', 'to' => 'https://post.example/', 'order' => 0],
        ['id' => 'redirection-9', 'from' => '/a', 'to' => 'https://redirection.example/', 'order' => 0],
        ['id' => 2, 'from' => '/b', 'to' => 'https://post.example/', 'order' => 0],
        ['id' => 'redirection-1', 'from' => '/b', 'to' => 'https://redirection.example/', 'order' => 9],
        ['id' => 'pretty-links-9', 'from' => '/b', 'to' => 'https://pretty-links.example/', 'order' => 9],
    ]));

    $targets = [];

    foreach (redirectGlobalEntries() as $entry) {
        $source = $entry->get('source');
        $targets[\is_string($source) ? $source : ''] = $entry->get('target_url');
    }

    \ksort($targets);

    expect($targets)->toBe([
        '/a' => 'https://redirection.example/',
        '/b' => 'https://pretty-links.example/',
    ]);
});

it('skips a rule whose entry cannot be made, and writes the rules after it', function (): void {
    // The repository's make() resolves the entry class from the container, so the first
    // entry made is a stand-in that is not the concrete class, and the rest are real
    $made = 0;
    app()->bind(EntryContract::class, static function () use (&$made): object {
        $made++;

        return $made === 1 ? Mockery::mock(EntryContract::class) : new Entry;
    });

    $output = redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 11, 'from' => '/give'],
        ['id' => 12, 'from' => '/apply'],
    ]));

    expect(\array_map(static fn (Entry $entry): mixed => $entry->get('source'), redirectGlobalEntries()))->toBe(['/apply'])
        ->and($output)->toContain('Redirect domain uams.info: 1 created, 0 updated');
});

it('saves an entry it disables', function (): void {
    redirectGlobalRun(redirectGlobalParser('https://uams.info', [['id' => 11, 'from' => '/give']]));

    Event::fake([EntrySaved::class]);

    redirectGlobalRun(redirectGlobalParser('https://uams.info', []));

    Event::assertDispatched(
        EntrySaved::class,
        static fn (EntrySaved $event): bool => $event->entry instanceof Entry && $event->entry->get('enabled') === false
    );
});

it('disables neither another collection\'s entry nor one with an empty post id', function (): void {
    $other = CollectionFacade::make('other_collection');
    $other->sites(['default']);
    $other->save();

    $elsewhere = redirectGlobalSaveEntry('other_collection', ['host' => 'uams.info', 'source' => '/x', 'wordpress_post_id' => '7', 'enabled' => true]);
    $blankId = redirectGlobalSaveEntry(RedirectRuleGlobalCreator::DOMAIN_COLLECTION, ['host' => 'uams.info', 'source' => '/y', 'wordpress_post_id' => '', 'enabled' => true]);

    $output = redirectGlobalRun(redirectGlobalParser('https://uams.info', []));

    expect($elsewhere->get('enabled'))->toBeTrue()
        ->and($blankId->get('enabled'))->toBeTrue()
        ->and($output)->toContain('0 disabled');
});

it('does not update another collection\'s entry for the same host and source', function (): void {
    $other = CollectionFacade::make('other_collection');
    $other->sites(['default']);
    $other->save();

    $elsewhere = redirectGlobalSaveEntry('other_collection', ['host' => 'uams.info', 'source' => '/give', 'target_url' => 'https://elsewhere.example/']);

    $output = redirectGlobalRun(redirectGlobalParser('https://uams.info', [['id' => 11, 'from' => '/give']]));

    expect($output)->toContain('1 created, 0 updated')
        ->and($elsewhere->get('target_url'))->toBe('https://elsewhere.example/')
        ->and(redirectGlobalEntries())->toHaveCount(1);
});

it('matches a stored source only when it is the same string', function (): void {
    // A source stored as a number is not the rule's text source, so it is kept and the rule added
    redirectGlobalSet(rows: [['id' => 'kept', 'source' => 301, 'target_url' => 'https://old.example/']]);

    redirectGlobalRun(redirectGlobalParser('https://supplychain.uams.edu', [
        ['id' => 11, 'from' => '301'],
    ]));

    expect(\array_column(redirectGlobalRows(), 'source'))->toBe([301, '301']);
});

it('counts none of the five response codes the blueprint offers as unsupported', function (): void {
    $output = redirectGlobalRun(redirectGlobalParser('https://uams.info', [
        ['id' => 11, 'from' => '/a', 'code' => '301'],
        ['id' => 12, 'from' => '/b', 'code' => '302'],
        ['id' => 13, 'from' => '/c', 'code' => '303'],
        ['id' => 14, 'from' => '/d', 'code' => '307'],
        ['id' => 15, 'from' => '/e', 'code' => '308'],
    ]));

    expect($output)->toContain('5 created')
        ->and($output)->not->toContain('response code the redirect domains blueprint does not offer');
});

it('treats each of the five vanity domains as a redirect domain', function (string $host): void {
    expect(RedirectRuleGlobalCreator::redirectDomainHost("https://{$host}/"))->toBe($host);
})->with(['uams.health', 'uams.info', 'uamsbaptist.health', 'go.uams.edu', 'gouams.com']);
