<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection as IlluminateCollection;
use Illuminate\Support\Facades\Http;
use Statamic\Entries\Collection as ConcreteCollection;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\Creators\RedirectRuleGlobalCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * A vanity domain's Redirection and Pretty Links rules import into the redirect domains collection (wordpress-importer-archive#1307).
 *
 * End to end below the command: the migration API is faked with the record shapes
 * `UAMS-Web/uamswp-migration-api-archive#374` serves, the real materializer writes them out, the real
 * parser reads the parts back, and the real creator writes the entries. So every rule the
 * collection can express is checked against what was stored, one entry per rule, and the count
 * of entries against the count the plugins served less the rules they list as not imported.
 *
 * Target resolution goes through `LinkProcessor`, which needs the host application, so it is
 * stubbed to pass every target through, as `RedirectDomainImportTest` does.
 *
 * @command  php vendor/bin/pest tests/Integration/RedirectPluginDomainImportTest.php
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

    ImportWarningManager::clearWarnings();

    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('redirect_plugins_', true);
    \mkdir($this->workingDirectory);
});

afterEach(function (): void {
    $collection = CollectionFacade::findByHandle(RedirectRuleGlobalCreator::DOMAIN_COLLECTION);

    if ($collection instanceof ConcreteCollection) {
        $collection->delete();
    }

    ImportWarningManager::clearWarnings();

    $entries = \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*');

    foreach ($entries === false ? [] : $entries as $entry) {
        \unlink($entry);
    }

    \rmdir($this->workingDirectory);
});

/**
 * A served Redirection rule.
 *
 * @param  array<string, mixed>  $overrides  Fields to replace
 * @return array<string, mixed>
 */
function servedRedirectionRule(int $id, string $from, string $to, array $overrides = []): array
{
    return \array_replace([
        'id' => $id,
        'url' => $from,
        'match_url' => $from,
        'regex' => false,
        'match_type' => 'url',
        'match_data' => ['source' => ['flag_query' => 'exact', 'flag_case' => false, 'flag_trailing' => false, 'flag_regex' => false]],
        'action_type' => 'url',
        'action_code' => 301,
        'action_data' => $to,
        'action_data_parsed' => ['url' => $to],
        'title' => '',
        'position' => $id,
        'enabled' => true,
        'group_id' => 1,
        'group' => ['id' => 1, 'name' => 'Redirections', 'module_id' => 1, 'enabled' => true],
    ], $overrides);
}

/**
 * A served Pretty Links link.
 *
 * @param  array<string, mixed>  $overrides  Fields to replace
 * @return array<string, mixed>
 */
function servedPrettyLink(int $id, string $slug, string $to, array $overrides = []): array
{
    return \array_replace([
        'id' => $id,
        'slug' => $slug,
        'url' => $to,
        'name' => '',
        'description' => '',
        'redirect_type' => '302',
        'param_forwarding' => false,
        'track_me' => false,
        'nofollow' => false,
        'sponsored' => false,
        'new_window' => false,
        'prettypay_link' => false,
        'source' => 'admin',
        'deleted_at' => null,
    ], $overrides);
}

/**
 * An API error response, as the redirects endpoints answer one.
 */
function redirectPluginError(int $status, string $code): PromiseInterface
{
    return Http::response(['code' => $code, 'message' => 'Refused.', 'data' => ['status' => $status]], $status);
}

/**
 * Fake the migration API for one site, serving no posts and the given redirect endpoints.
 *
 * @param  string  $baseBlogUrl  The site's base blog URL
 * @param  mixed  $redirection  The `redirects/redirection` response, or a list of rules
 * @param  mixed  $prettyLinks  The `redirects/pretty-links` response, or a list of links
 */
function fakeRedirectPluginApi(string $baseBlogUrl, mixed $redirection, mixed $prettyLinks): void
{
    $respond = static fn (mixed $served): mixed => \is_array($served)
        ? Http::response($served, 200, ['X-WP-Total' => (string) \count($served)])
        : $served;

    // A fresh factory, so a second call replaces the first run's responses rather than queueing behind them
    Http::swap(new Factory);

    Http::fake([
        '*/sites' => Http::response([[
            'id' => 3,
            'title' => 'Go',
            'link' => $baseBlogUrl,
            'description' => '',
            'language' => 'en-US',
            'wxr_version' => '1.2',
            'base_site_url' => $baseBlogUrl,
            'base_blog_url' => $baseBlogUrl,
        ]], 200),
        '*/post-types*' => Http::response([], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/redirects/redirection*' => $respond($redirection),
        '*/redirects/pretty-links*' => $respond($prettyLinks),
    ]);
}

/**
 * Materialize site 3 and return the materializer, with its parts parsed.
 *
 * @return array{0: WxrMaterializer, 1: XmlParser}
 */
function materializeRedirectPlugins(string $directory, ?string $contentType = null): array
{
    $materializer = new WxrMaterializer(new Client('https://network.test', 'shhh'), $directory);
    $paths = $materializer->materialize(3, null, [], $contentType);

    $parser = new XmlParser;
    $parser->parse($paths[0]);

    return [$materializer, $parser];
}

/**
 * Run the creator, with target resolution passing every target through.
 */
function importRedirectPluginRules(XmlParser $parser): string
{
    $creator = new class extends RedirectRuleGlobalCreator
    {
        protected function processTargetUrl(string $rawTarget, string $site, AssetImporter $assetImporter, OutputInterface $output): string
        {
            return $rawTarget;
        }
    };

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

    return $output->fetch();
}

/**
 * Every stored redirect domains entry for a host, keyed by source.
 *
 * @return array<string, Entry>
 */
function redirectPluginEntries(string $host): array
{
    $query = EntryQueryHelper::query();
    $query->where('collection', RedirectRuleGlobalCreator::DOMAIN_COLLECTION);
    $query->where('host', $host);

    $results = $query->get();
    $entries = [];

    if (! $results instanceof IlluminateCollection) {
        return [];
    }

    foreach ($results->all() as $entry) {
        if ($entry instanceof Entry && \is_string($entry->get('source'))) {
            $entries[$entry->get('source')] = $entry;
        }
    }

    return $entries;
}

/**
 * An entry's stored data, without its title.
 *
 * @return array<array-key, mixed>
 */
function redirectPluginData(Entry $entry): array
{
    $data = $entry->data();

    return $data instanceof IlluminateCollection ? $data->except(['title'])->all() : [];
}

it('imports every expressible Redirection and Pretty Links rule as one entry for the domain', function (): void {
    fakeRedirectPluginApi(
        'https://go.uams.edu',
        [
            servedRedirectionRule(1, '/old', 'https://uams.edu/new/', ['title' => 'Old page']),
            servedRedirectionRule(2, '^/news/(.*)$', 'https://news.uams.edu/$1', ['regex' => true, 'action_code' => 302]),
            servedRedirectionRule(3, '/members', 'https://uams.edu/a/', ['match_type' => 'login']),
            servedRedirectionRule(4, '/retired', 'https://uams.edu/b/', ['enabled' => false]),
        ],
        [
            servedPrettyLink(10, 'flu', 'https://uamshealth.com/flu/', ['track_me' => true, 'name' => 'Flu']),
            servedPrettyLink(11, 'go/clinic', 'https://uamshealth.com/clinic/', ['redirect_type' => 'cloak']),
            servedPrettyLink(12, 'pixel', '', ['redirect_type' => 'pixel']),
        ]
    );

    [$materializer, $parser] = materializeRedirectPlugins($this->workingDirectory);
    $output = importRedirectPluginRules($parser);
    $entries = redirectPluginEntries('go.uams.edu');

    // Seven served; two not importable; one disabled and so not live
    expect($materializer->redirectPluginRules())->toBe([
        [
            'host' => 'go.uams.edu',
            'plugin' => 'Redirection',
            'rules' => 4,
            'written' => 3,
            'unsupported' => ['not imported: matches on login, not on the URL alone' => 1],
        ],
        [
            'host' => 'go.uams.edu',
            'plugin' => 'Pretty Links',
            'rules' => 3,
            'written' => 2,
            'unsupported' => [
                'click tracking' => 1,
                'cloak redirect, imported as a 302' => 1,
                'not imported: a pixel link with no target URL' => 1,
            ],
        ],
    ])
        ->and(\array_keys($entries))->toEqualCanonicalizing(['/old', '^/news/(.*)$', '/flu', '/go/clinic'])
        ->and($output)->toContain('Redirect domain go.uams.edu: 4 created, 0 updated, 0 disabled, 1 unpublished skipped, 0 shadowed.');

    expect(redirectPluginData($entries['/old']))->toBe([
        'host' => 'go.uams.edu',
        'wordpress_post_id' => 'redirection-1',
        'enabled' => true,
        'whole_domain' => false,
        'source' => '/old',
        'use_regex' => false,
        'response' => '301',
        'order' => 1,
        'target_type' => 'url',
        'target_url' => 'https://uams.edu/new/',
        'notes' => 'Old page',
    ])
        ->and($entries['^/news/(.*)$']->get('use_regex'))->toBeTrue()
        ->and($entries['^/news/(.*)$']->get('response'))->toBe('302')
        ->and($entries['^/news/(.*)$']->get('target_url'))->toBe('https://news.uams.edu/$1')
        ->and($entries['/flu']->get('wordpress_post_id'))->toBe('pretty-links-10')
        ->and($entries['/flu']->get('target_url'))->toBe('https://uamshealth.com/flu/')
        ->and($entries['/flu']->get('response'))->toBe('302')
        ->and($entries['/flu']->get('notes'))->toBe('Flu')
        ->and($entries['/go/clinic']->get('response'))->toBe('302');

    // Listed per domain in the end-of-import summary, not only on the console
    expect(ImportWarningManager::getWarnings('redirects', 'unsupported_plugin_features'))->toContain([
        'host' => 'go.uams.edu',
        'plugin' => 'Pretty Links',
        'feature' => 'click tracking',
        'rules' => 1,
    ]);
});

it('disables an imported plugin rule gone from the source on the next run', function (): void {
    fakeRedirectPluginApi('https://gouams.com', [servedRedirectionRule(1, '/a', 'https://uams.edu/a/')], [servedPrettyLink(5, 'b', 'https://uams.edu/b/')]);
    importRedirectPluginRules(materializeRedirectPlugins($this->workingDirectory)[1]);

    fakeRedirectPluginApi('https://gouams.com', [servedRedirectionRule(1, '/a', 'https://uams.edu/a2/')], []);
    $output = importRedirectPluginRules(materializeRedirectPlugins($this->workingDirectory)[1]);
    $entries = redirectPluginEntries('gouams.com');

    expect($output)->toContain('Redirect domain gouams.com: 0 created, 1 updated, 1 disabled, 0 unpublished skipped, 0 shadowed.')
        ->and($entries['/a']->get('target_url'))->toBe('https://uams.edu/a2/')
        ->and($entries['/a']->get('enabled'))->toBeTrue()
        ->and($entries['/b']->get('enabled'))->toBeFalse();
});

it('records a plugin the site does not run as absent, and reads the other', function (string $code): void {
    fakeRedirectPluginApi('https://go.uams.edu', redirectPluginError(404, $code), [servedPrettyLink(1, 'x', 'https://uams.edu/x/')]);

    [$materializer, $parser] = materializeRedirectPlugins($this->workingDirectory);

    expect(\array_column($materializer->redirectPluginRules(), 'plugin'))->toBe(['Pretty Links'])
        ->and($parser->getItems('redirect_rule'))->toHaveCount(1);
})->with([
    'not loaded' => ['uamswp_migration_api_redirect_plugin_not_loaded'],
    'not active' => ['uamswp_migration_api_redirect_plugin_not_active'],
]);

it('fails the read when a plugin is served but its rules cannot be read', function (int $status, string $code): void {
    fakeRedirectPluginApi('https://go.uams.edu', [], redirectPluginError($status, $code));

    expect(fn (): array => materializeRedirectPlugins($this->workingDirectory))
        ->toThrow(MigrationApiException::class, $code);
})->with([
    'tables missing' => [500, 'uamswp_migration_api_redirect_tables_missing'],
    'read failed' => [500, 'uamswp_migration_api_redirect_read_failed'],
    'some other refusal' => [404, 'rest_no_route'],
]);

it('does not ask for plugin rules on a site that is not a listed redirect domain', function (): void {
    fakeRedirectPluginApi('https://news.uams.edu', [servedRedirectionRule(1, '/a', 'https://uams.edu/a/')], []);

    [$materializer, $parser] = materializeRedirectPlugins($this->workingDirectory);

    Http::assertNotSent(fn (Request $request): bool => \str_contains($request->url(), '/redirects/'));
    expect($materializer->redirectPluginRules())->toBe([])
        ->and($parser->getItems('redirect_rule'))->toBe([]);
});

it('reads plugin rules for a run scoped to redirect rules, and not for one scoped to another type', function (?string $contentType, int $items): void {
    fakeRedirectPluginApi('https://go.uams.edu', [servedRedirectionRule(1, '/a', 'https://uams.edu/a/')], []);

    [, $parser] = materializeRedirectPlugins($this->workingDirectory, $contentType);

    expect($parser->getItems('redirect_rule'))->toHaveCount($items);
})->with([
    'redirect rules' => ['redirect_rule', 1],
    'pages' => ['page', 0],
    'every type' => [null, 1],
]);

/**
 * The redirect domains summary line's counts, by name.
 *
 * @return array<string, int>
 */
function redirectPluginSummary(string $output, string $host): array
{
    $pattern = '/Redirect domain '.\preg_quote($host, '/').': (\d+) created, (\d+) updated, (\d+) disabled, (\d+) unpublished skipped, (\d+) shadowed\./';

    expect(\preg_match($pattern, $output, $matches))->toBe(1);

    return [
        'created' => (int) $matches[1],
        'updated' => (int) $matches[2],
        'disabled' => (int) $matches[3],
        'skipped' => (int) $matches[4],
        'shadowed' => (int) $matches[5],
    ];
}

it('keeps the Pretty Links link when a Redirection rule shares its source, and reports the collapse (#108)', function (): void {
    fakeRedirectPluginApi(
        'https://go.uams.edu',
        [
            servedRedirectionRule(1, '/flu', 'https://uams.edu/redirection-flu/', ['position' => 0]),
            servedRedirectionRule(2, '/old', 'https://uams.edu/new/'),
        ],
        [servedPrettyLink(10, 'flu', 'https://uamshealth.com/flu/')]
    );

    [, $parser] = materializeRedirectPlugins($this->workingDirectory);
    $output = importRedirectPluginRules($parser);
    $entries = redirectPluginEntries('go.uams.edu');
    $counts = redirectPluginSummary($output, 'go.uams.edu');

    // Pretty Links redirects on `init` at priority 1, ahead of Redirection's priority 10
    expect(\array_keys($entries))->toEqualCanonicalizing(['/flu', '/old'])
        ->and($entries['/flu']->get('wordpress_post_id'))->toBe('pretty-links-10')
        ->and($entries['/flu']->get('target_url'))->toBe('https://uamshealth.com/flu/')
        ->and($counts)->toBe(['created' => 2, 'updated' => 0, 'disabled' => 0, 'skipped' => 0, 'shadowed' => 1])
        ->and($counts['created'] + $counts['updated'] + $counts['skipped'] + $counts['shadowed'])->toBe(\count($parser->getItems('redirect_rule')))
        ->and($output)->toContain('go.uams.edu/flu: redirection-1 shares its source with pretty-links-10 (/flu), which WordPress serves; only pretty-links-10 was imported.')
        ->and(ImportWarningManager::getWarnings('redirects', 'shadowed_rules'))->toBe([
            ['host' => 'go.uams.edu', 'source' => '/flu', 'served' => 'pretty-links-10', 'served_source' => '/flu', 'shadowed' => 'redirection-1'],
        ]);
});

it('keeps the Redirection rule tried first when two share a source, whatever the export order (#108)', function (): void {
    fakeRedirectPluginApi(
        'https://go.uams.edu',
        [
            // Two groups; the lower position is tried first, and on a tie the lower id, compared as a number
            servedRedirectionRule(3, '/give', 'https://uams.edu/third/', ['position' => 9]),
            servedRedirectionRule(10, '/same', 'https://uams.edu/tenth/', ['position' => 2]),
            servedRedirectionRule(7, '/give', 'https://uams.edu/seventh/', ['position' => 5, 'group_id' => 2, 'group' => ['id' => 2, 'name' => 'Campaigns', 'module_id' => 1, 'enabled' => true]]),
            servedRedirectionRule(9, '/same', 'https://uams.edu/ninth/', ['position' => 2]),
            servedRedirectionRule(12, '/give', 'https://uams.edu/disabled/', ['enabled' => false, 'position' => 0]),
        ],
        []
    );

    [, $parser] = materializeRedirectPlugins($this->workingDirectory);
    $output = importRedirectPluginRules($parser);
    $entries = redirectPluginEntries('go.uams.edu');

    // The disabled rule serves nothing, so it does not shadow the rules after it
    expect($entries)->toHaveCount(2)
        ->and($entries['/give']->get('wordpress_post_id'))->toBe('redirection-7')
        ->and($entries['/give']->get('target_url'))->toBe('https://uams.edu/seventh/')
        ->and($entries['/same']->get('wordpress_post_id'))->toBe('redirection-9')
        ->and(redirectPluginSummary($output, 'go.uams.edu'))->toBe(['created' => 2, 'updated' => 0, 'disabled' => 0, 'skipped' => 1, 'shadowed' => 2])
        ->and(ImportWarningManager::getWarnings('redirects', 'shadowed_rules'))->toEqualCanonicalizing([
            ['host' => 'go.uams.edu', 'source' => '/give', 'served' => 'redirection-7', 'served_source' => '/give', 'shadowed' => 'redirection-3'],
            ['host' => 'go.uams.edu', 'source' => '/same', 'served' => 'redirection-9', 'served_source' => '/same', 'shadowed' => 'redirection-10'],
        ]);
});

it('keeps the same rule and reports the same collapse on a second run (#108)', function (): void {
    $rules = [
        servedRedirectionRule(5, '/a', 'https://uams.edu/fifth/', ['position' => 1]),
        servedRedirectionRule(6, '/a', 'https://uams.edu/sixth/', ['position' => 1]),
    ];

    fakeRedirectPluginApi('https://go.uams.edu', $rules, []);
    importRedirectPluginRules(materializeRedirectPlugins($this->workingDirectory)[1]);
    $first = redirectPluginEntries('go.uams.edu');
    $firstWarnings = ImportWarningManager::getWarnings('redirects', 'shadowed_rules');

    ImportWarningManager::clearWarnings();

    fakeRedirectPluginApi('https://go.uams.edu', $rules, []);
    $output = importRedirectPluginRules(materializeRedirectPlugins($this->workingDirectory)[1]);
    $second = redirectPluginEntries('go.uams.edu');

    expect(redirectPluginSummary($output, 'go.uams.edu'))->toBe(['created' => 0, 'updated' => 1, 'disabled' => 0, 'skipped' => 0, 'shadowed' => 1])
        ->and($second)->toHaveCount(1)
        ->and($second['/a']->id())->toBe($first['/a']->id())
        ->and($second['/a']->get('wordpress_post_id'))->toBe('redirection-5')
        ->and($second['/a']->get('enabled'))->toBeTrue()
        ->and(ImportWarningManager::getWarnings('redirects', 'shadowed_rules'))->toBe($firstWarnings)
        ->and($firstWarnings)->toBe([['host' => 'go.uams.edu', 'source' => '/a', 'served' => 'redirection-5', 'served_source' => '/a', 'shadowed' => 'redirection-6']]);
});

it('does not collapse the same source on two domains, or two sources on one (#108)', function (): void {
    fakeRedirectPluginApi('https://go.uams.edu', [servedRedirectionRule(1, '/a', 'https://uams.edu/go-a/'), servedRedirectionRule(2, '/b', 'https://uams.edu/go-b/')], []);
    $go = importRedirectPluginRules(materializeRedirectPlugins($this->workingDirectory)[1]);

    fakeRedirectPluginApi('https://gouams.com', [servedRedirectionRule(1, '/a', 'https://uams.edu/gouams-a/')], []);
    $gouams = importRedirectPluginRules(materializeRedirectPlugins($this->workingDirectory)[1]);

    expect(redirectPluginSummary($go, 'go.uams.edu')['shadowed'])->toBe(0)
        ->and(redirectPluginSummary($gouams, 'gouams.com'))->toBe(['created' => 1, 'updated' => 0, 'disabled' => 0, 'skipped' => 0, 'shadowed' => 0])
        ->and(\array_keys(redirectPluginEntries('go.uams.edu')))->toEqualCanonicalizing(['/a', '/b'])
        ->and(redirectPluginEntries('gouams.com')['/a']->get('target_url'))->toBe('https://uams.edu/gouams-a/')
        ->and(redirectPluginEntries('go.uams.edu')['/a']->get('target_url'))->toBe('https://uams.edu/go-a/')
        ->and(ImportWarningManager::getWarnings('redirects', 'shadowed_rules'))->toBe([]);
});

it('counts each unsupported feature by the rules that carry it, passing over a rule that is not one', function (): void {
    $caseless = ['match_data' => ['source' => ['flag_query' => 'exact', 'flag_case' => true, 'flag_trailing' => false, 'flag_regex' => false]]];

    fakeRedirectPluginApi('https://go.uams.edu', [
        'not a rule',
        servedRedirectionRule(1, '/a', 'https://uams.edu/a', $caseless),
        servedRedirectionRule(2, '/b', 'https://uams.edu/b', $caseless),
    ], []);

    [$materializer] = materializeRedirectPlugins($this->workingDirectory);

    expect($materializer->redirectPluginRules()[0] ?? null)->toBe([
        'host' => 'go.uams.edu',
        'plugin' => 'Redirection',
        'rules' => 3,
        'written' => 2,
        'unsupported' => ['matches the source ignoring case' => 2],
    ]);
});
