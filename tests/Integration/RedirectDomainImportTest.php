<?php

declare(strict_types=1);

use Illuminate\Support\Collection as IlluminateCollection;
use Statamic\Entries\Collection as ConcreteCollection;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\GlobalSet as GlobalSetFacade;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as ConcreteGlobalSet;
use Statamic\Globals\Variables;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\Creators\RedirectRuleGlobalCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

/**
 * A vanity domain's Safe Redirect Manager rules import into the redirect domains collection (wordpress-importer-archive#1306).
 *
 * `UAMS-Web/uams-statamic#2594` decided that domains which are not Statamic sites keep their
 * redirects in a `redirect_domains` collection, one entry per rule carrying its host. These
 * tests run the real creator against a WXR whose `base_blog_url` is a listed vanity domain, and
 * read the stored entries back.
 *
 * Target resolution goes through `LinkProcessor`, which needs the host application, so it is
 * stubbed here exactly as the creator's unit test stubs it: an external target passes through,
 * and one programmed target resolves to an entry reference.
 *
 * @command  php vendor/bin/pest tests/Integration/RedirectDomainImportTest.php
 */
uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

beforeEach(function (): void {
    config(['statamic.search.indexes' => []]);

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    $collection = CollectionFacade::make(RedirectRuleGlobalCreator::DOMAIN_COLLECTION);
    $collection->sites(['default']);
    $collection->save();
});

afterEach(function (): void {
    $collection = CollectionFacade::findByHandle(RedirectRuleGlobalCreator::DOMAIN_COLLECTION);

    if ($collection instanceof ConcreteCollection) {
        $collection->delete();
    }

    TemporaryFileRegistry::sweep();
});

/**
 * One `redirect_rule` item.
 *
 * @param  array{id: int, from: string, to: string, status?: string, regex?: bool, code?: string, order?: int, notes?: string}  $rule
 */
function redirectDomainItem(array $rule): string
{
    $meta = static fn (string $key, string $value): string => '<wp:postmeta><wp:meta_key><![CDATA['.$key.']]></wp:meta_key>'
        .'<wp:meta_value><![CDATA['.$value.']]></wp:meta_value></wp:postmeta>';

    return '<item><title><![CDATA['.$rule['from'].']]></title>'
        .'<wp:post_id>'.$rule['id'].'</wp:post_id>'
        .'<wp:status><![CDATA['.($rule['status'] ?? 'publish').']]></wp:status>'
        .'<wp:menu_order>'.($rule['order'] ?? 0).'</wp:menu_order>'
        .'<wp:post_type><![CDATA[redirect_rule]]></wp:post_type>'
        .$meta('_redirect_rule_from', $rule['from'])
        .$meta('_redirect_rule_to', $rule['to'])
        .$meta('_redirect_rule_status_code', $rule['code'] ?? '301')
        .$meta('_redirect_rule_from_regex', ($rule['regex'] ?? false) ? '1' : '0')
        .(isset($rule['notes']) ? $meta('_redirect_rule_notes', $rule['notes']) : '')
        .'</item>';
}

/**
 * Write a WXR for the given base blog URL and rules, and return a parser over it.
 *
 * @param  list<array{id: int, from: string, to: string, status?: string, regex?: bool, code?: string, order?: int, notes?: string}>  $rules
 */
function redirectDomainParser(string $baseBlogUrl, array $rules): XmlParser
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<channel><title>Vanity</title><link>'.$baseBlogUrl.'</link><wp:wxr_version>1.2</wp:wxr_version>'
        .'<wp:base_site_url>'.$baseBlogUrl.'</wp:base_site_url><wp:base_blog_url>'.$baseBlogUrl.'</wp:base_blog_url>'
        .\implode('', \array_map(static fn (array $rule): string => redirectDomainItem($rule), $rules))
        .'</channel></rss>';

    $path = TemporaryFileRegistry::create('redirect_domain_', '.xml');
    \file_put_contents($path, $xml);

    $parser = new XmlParser;
    $parser->parse($path);

    return $parser;
}

/**
 * A creator whose target resolution is stubbed: one target resolves to an entry, the rest pass through.
 */
function redirectDomainCreator(): RedirectRuleGlobalCreator
{
    return new class extends RedirectRuleGlobalCreator
    {
        protected function processTargetUrl(string $rawTarget, string $site, AssetImporter $assetImporter, OutputInterface $output): string
        {
            return $rawTarget === 'https://uams.edu/resolves-to-an-entry/' ? 'statamic://entry::target-entry-id' : $rawTarget;
        }
    };
}

/**
 * Run the creator against a parser, as the import command does.
 */
function redirectDomainRun(XmlParser $parser, ?RedirectRuleGlobalCreator $creator = null): string
{
    $output = new BufferedOutput;

    ($creator ?? redirectDomainCreator())->createGlobals(
        'default',
        'redirects',
        $parser,
        new AssetImporter,
        $output,
        new MetadataMapper,
        (new ReflectionClass(ContentProcessor::class))->newInstanceWithoutConstructor()
    );

    return $output->fetch();
}

/**
 * Every stored redirect domains entry, keyed by source.
 *
 * @return array<string, Entry>
 */
function redirectDomainEntries(): array
{
    $entries = [];

    foreach (redirectDomainStored() as $entry) {
        $source = $entry->get('source');
        $entries[\is_string($source) ? $source : ''] = $entry;
    }

    return $entries;
}

/**
 * Every stored redirect domains entry.
 *
 * @return list<Entry>
 */
function redirectDomainStored(): array
{
    $query = EntryQueryHelper::query();
    $query->where('collection', RedirectRuleGlobalCreator::DOMAIN_COLLECTION);
    $results = $query->get();

    if (! $results instanceof IlluminateCollection) {
        return [];
    }

    return \array_values(\array_filter($results->all(), static fn (mixed $entry): bool => $entry instanceof Entry));
}

/**
 * An entry's id as a string, for comparing identity across runs.
 */
function redirectDomainId(Entry $entry): string
{
    $id = $entry->id();

    return \is_string($id) ? $id : '';
}

/**
 * The rules every test below starts from: a plain, a wildcard and a regex rule, plus an
 * entry-resolving target, an unpublished rule and a response the blueprint does not offer.
 *
 * @return list<array{id: int, from: string, to: string, status?: string, regex?: bool, code?: string, order?: int, notes?: string}>
 */
function redirectDomainRules(): array
{
    return [
        ['id' => 11, 'from' => '/give', 'to' => 'https://securelb.imodules.com/s/give', 'order' => 5, 'notes' => 'Annual campaign'],
        ['id' => 12, 'from' => '/womens/*', 'to' => 'https://uamshealth.com/womens/*', 'code' => '302'],
        ['id' => 13, 'from' => '^/covid(.*)$', 'to' => 'https://uamshealth.com/covid$1', 'regex' => true, 'code' => '307'],
        ['id' => 14, 'from' => '/clinic', 'to' => 'https://uams.edu/resolves-to-an-entry/'],
        ['id' => 15, 'from' => '/retired', 'to' => 'https://uams.edu/', 'status' => 'draft'],
        ['id' => 16, 'from' => '/gone', 'to' => '', 'code' => '410'],
    ];
}

it('writes one entry per published rule, carrying the vanity domain as its host', function (): void {
    $output = redirectDomainRun(redirectDomainParser('https://uams.info', redirectDomainRules()));

    $entries = redirectDomainEntries();

    // Five published rules, the draft left out
    expect(\array_keys($entries))->toEqualCanonicalizing(['/give', '/womens/*', '^/covid(.*)$', '/clinic', '/gone']);

    foreach ($entries as $entry) {
        expect($entry->get('host'))->toBe('uams.info')
            ->and($entry->published())->toBeTrue()
            ->and($entry->get('enabled'))->toBeTrue()
            ->and($entry->get('whole_domain'))->toBeFalse()
            ->and($entry->locale())->toBe('default');
    }

    expect($output)->toContain('Redirect domain uams.info: 5 created, 0 updated, 0 disabled, 1 unpublished skipped.')
        ->toContain('1 rule(s) for uams.info use a response code');
});

it('loses no source, target, status, regex flag or enabled state', function (): void {
    redirectDomainRun(redirectDomainParser('https://uams.info', redirectDomainRules()));

    $entries = redirectDomainEntries();

    // A plain rule
    $plain = [];

    foreach (['notes', 'order', 'response', 'source', 'target_type', 'target_url', 'use_regex'] as $field) {
        $plain[$field] = $entries['/give']->get($field);
    }

    expect($plain)->toBe([
        'notes' => 'Annual campaign',
        'order' => 5,
        'response' => '301',
        'source' => '/give',
        'target_type' => 'url',
        'target_url' => 'https://securelb.imodules.com/s/give',
        'use_regex' => false,
    ]);

    // A wildcard rule keeps its literal `*` with regex off, the shape the host's matcher reads
    expect($entries['/womens/*']->get('use_regex'))->toBeFalse()
        ->and($entries['/womens/*']->get('target_url'))->toBe('https://uamshealth.com/womens/*')
        ->and($entries['/womens/*']->get('response'))->toBe('302');

    // A regex rule keeps its pattern and its capture-substituting target
    expect($entries['^/covid(.*)$']->get('use_regex'))->toBeTrue()
        ->and($entries['^/covid(.*)$']->get('target_url'))->toBe('https://uamshealth.com/covid$1')
        ->and($entries['^/covid(.*)$']->get('response'))->toBe('307');

    // A target that resolves to an imported entry is stored as an entry reference
    expect($entries['/clinic']->get('target_type'))->toBe('entry')
        ->and($entries['/clinic']->get('target_entry'))->toBe(['target-entry-id'])
        ->and($entries['/clinic']->get('target_url'))->toBeNull();

    // A response the blueprint does not offer is kept as WordPress served it
    expect($entries['/gone']->get('response'))->toBe('410');
});

it('does not duplicate entries when the import runs again', function (): void {
    redirectDomainRun(redirectDomainParser('https://uams.info', redirectDomainRules()));

    $first = \array_map(redirectDomainId(...), redirectDomainEntries());

    $rules = \array_map(
        static fn (array $rule): array => $rule['id'] === 11 ? [...$rule, 'to' => 'https://securelb.imodules.com/s/give-again'] : $rule,
        redirectDomainRules()
    );

    $output = redirectDomainRun(redirectDomainParser('https://uams.info', $rules));

    $second = redirectDomainEntries();

    expect(\array_map(redirectDomainId(...), $second))->toBe($first)
        ->and($second['/give']->get('target_url'))->toBe('https://securelb.imodules.com/s/give-again')
        ->and($output)->toContain('Redirect domain uams.info: 0 created, 5 updated, 0 disabled, 1 unpublished skipped.');
});

it('disables an entry whose rule was unpublished or deleted since the last run', function (): void {
    redirectDomainRun(redirectDomainParser('https://uams.info', redirectDomainRules()));

    // Rule 11 (/give) is unpublished at the source, and rule 12 (/womens/*) is deleted
    $rules = \array_values(\array_filter(
        \array_map(
            static fn (array $rule): array => $rule['id'] === 11 ? [...$rule, 'status' => 'draft'] : $rule,
            redirectDomainRules()
        ),
        static fn (array $rule): bool => $rule['id'] !== 12
    ));

    $output = redirectDomainRun(redirectDomainParser('https://uams.info', $rules));

    $entries = redirectDomainEntries();

    // Both are kept and disabled, and the rest stay enabled
    expect($entries['/give']->get('enabled'))->toBeFalse()
        ->and($entries['/give']->published())->toBeTrue()
        ->and($entries['/womens/*']->get('enabled'))->toBeFalse()
        ->and($entries['^/covid(.*)$']->get('enabled'))->toBeTrue()
        ->and($entries['/clinic']->get('enabled'))->toBeTrue()
        ->and($entries['/gone']->get('enabled'))->toBeTrue()
        ->and($output)->toContain('Redirect domain uams.info: 0 created, 3 updated, 2 disabled, 2 unpublished skipped.');

    // A third run with the same source disables nothing more, because both are already off
    $again = redirectDomainRun(redirectDomainParser('https://uams.info', $rules));

    expect($again)->toContain('0 created, 3 updated, 0 disabled, 2 unpublished skipped.');
});

it('re-enables an entry whose rule is published again', function (): void {
    redirectDomainRun(redirectDomainParser('https://uams.info', redirectDomainRules()));

    $unpublished = \array_map(
        static fn (array $rule): array => $rule['id'] === 11 ? [...$rule, 'status' => 'draft'] : $rule,
        redirectDomainRules()
    );
    redirectDomainRun(redirectDomainParser('https://uams.info', $unpublished));

    expect(redirectDomainEntries()['/give']->get('enabled'))->toBeFalse();

    redirectDomainRun(redirectDomainParser('https://uams.info', redirectDomainRules()));

    expect(redirectDomainEntries()['/give']->get('enabled'))->toBeTrue();
});

it('disables nothing for another host or an entry an editor authored', function (): void {
    redirectDomainRun(redirectDomainParser('https://uams.health', [['id' => 1, 'from' => '/other', 'to' => 'https://a.example/']]));

    // An entry authored in the control panel carries no WordPress post id
    $authored = new Entry;
    $authored->collection(RedirectRuleGlobalCreator::DOMAIN_COLLECTION);
    $authored->locale('default');
    $authored->data(['title' => 'uams.info/by-hand', 'host' => 'uams.info', 'source' => '/by-hand', 'enabled' => true]);
    $authored->published(true);
    $authored->save();

    // A run for uams.info with no rules at all
    redirectDomainRun(redirectDomainParser('https://uams.info', []));

    $entries = redirectDomainEntries();

    expect($entries['/other']->get('enabled'))->toBeTrue()
        ->and($entries['/by-hand']->get('enabled'))->toBeTrue();
});

it('keeps two vanity domains\' rules for the same source apart', function (): void {
    redirectDomainRun(redirectDomainParser('https://uams.info', [['id' => 1, 'from' => '/give', 'to' => 'https://a.example/']]));
    redirectDomainRun(redirectDomainParser('https://uams.health', [['id' => 1, 'from' => '/give', 'to' => 'https://b.example/']]));

    $hosts = [];

    foreach (redirectDomainStored() as $entry) {
        $host = $entry->get('host');
        $hosts[\is_string($host) ? $host : ''] = $entry->get('target_url');
    }

    \ksort($hosts);

    expect($hosts)->toBe(['uams.health' => 'https://b.example/', 'uams.info' => 'https://a.example/']);
});

it('leaves a site that is not a listed vanity domain on the redirects global set', function (): void {
    $output = redirectDomainRun(redirectDomainParser('https://supplychain.uams.edu', redirectDomainRules()));

    // No entries, and the global-set path ran: it reports the set this test does not create
    expect(redirectDomainEntries())->toBe([])
        ->and($output)->toContain("Global set 'redirects' not found - skipping redirect rules.");
});

it('still writes an ordinary site\'s rules to its redirects global set', function (): void {
    // The GlobalSet facade declares no make(), so construct the concrete set
    $global = new ConcreteGlobalSet;
    $global->handle('redirects');
    $global->sites(['default']);
    $global->save();

    try {
        redirectDomainRun(redirectDomainParser('https://supplychain.uams.edu', redirectDomainRules()));

        $stored = GlobalSetFacade::findByHandle('redirects');
        $variables = $stored instanceof ConcreteGlobalSet ? $stored->in('default') : null;
        $rows = $variables instanceof Variables ? $variables->get('redirects') : null;

        if (! \is_array($rows)) {
            throw new RuntimeException('The redirects global set has no rows.');
        }

        // Every rule, the draft included as a disabled row, exactly as before wordpress-importer-archive#1306
        expect(\array_column($rows, 'source'))->toBe(['/give', '/womens/*', '^/covid(.*)$', '/clinic', '/retired', '/gone'])
            ->and(\array_column($rows, 'enabled'))->toBe([true, true, true, true, false, true])
            ->and(redirectDomainEntries())->toBe([]);
    } finally {
        $global->delete();
    }
});

it('reports a missing collection rather than creating it', function (): void {
    CollectionFacade::findByHandle(RedirectRuleGlobalCreator::DOMAIN_COLLECTION)?->delete();

    $output = redirectDomainRun(redirectDomainParser('https://uams.info', redirectDomainRules()));

    expect($output)->toContain("Collection 'redirect_domains' not found - skipping redirect rules for uams.info.")
        ->and(CollectionFacade::findByHandle(RedirectRuleGlobalCreator::DOMAIN_COLLECTION))->toBeNull()
        ->and(GlobalSetFacade::findByHandle('redirects'))->toBeNull();
});

it('decides by the exact host of the export\'s base_blog_url', function (string $url, ?string $expected): void {
    expect(RedirectRuleGlobalCreator::redirectDomainHost($url))->toBe($expected);
})->with([
    'a listed domain' => ['https://uams.info', 'uams.info'],
    'a listed domain with a path and a different case' => ['https://UAMS.Health/blog/', 'uams.health'],
    'the third listed domain' => ['https://uamsbaptist.health', 'uamsbaptist.health'],
    'a host that only ends with a listed domain' => ['https://go.uams.info', null],
    'an ordinary site' => ['https://supplychain.uams.edu', null],
    'no host' => ['uams.info', null],
    'empty' => ['', null],
]);

it('reads the list from configuration', function (): void {
    config(['wordpress-import.redirect_domains.hosts' => ['gouams.com']]);

    expect(RedirectRuleGlobalCreator::redirectDomainHost('https://gouams.com'))->toBe('gouams.com')
        ->and(RedirectRuleGlobalCreator::redirectDomainHost('https://uams.info'))->toBeNull();

    config(['wordpress-import.redirect_domains.hosts' => 'uams.info']);

    expect(RedirectRuleGlobalCreator::redirectDomainHost('https://uams.info'))->toBeNull();
});

it('reports progress through the rules and the stale-entry pass, leaving the summary as it was (#103)', function (): void {
    // Console output ends lines with PHP_EOL, which is \r\n on Windows
    $output = \str_replace(\PHP_EOL, "\n", redirectDomainRun(redirectDomainParser('https://uams.info', redirectDomainRules())));

    // Six rules, the draft among them, so the count reaches six
    expect($output)->toContain("Progress: redirect_rule (uams.info) 0 of 6 (0%)\n")
        ->toContain("Progress: redirect_rule (uams.info) 6 of 6 (100%)\n")
        ->toContain('Redirect domain uams.info: 5 created, 0 updated, 0 disabled, 1 unpublished skipped.');

    // The second run passes over the five entries the first one wrote
    $again = \str_replace(\PHP_EOL, "\n", redirectDomainRun(redirectDomainParser('https://uams.info', redirectDomainRules())));

    expect($again)->toContain("Progress: stale redirect_rule entries (uams.info) 5 of 5 (100%)\n")
        ->toContain('Redirect domain uams.info: 0 created, 5 updated, 0 disabled, 1 unpublished skipped.')
        ->toMatch('/Progress: redirect_rule \(uams\.info\) 6 of 6 \(100%\)\n.*Redirect domain uams\.info:/s');
});
