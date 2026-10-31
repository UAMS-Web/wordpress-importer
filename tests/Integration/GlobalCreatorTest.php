<?php

declare(strict_types=1);

use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as ConcreteGlobalSet;
use Statamic\Globals\Variables;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\GlobalCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\LfBufferedOutput;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

/**
 * `GlobalCreator` hands a content type's global set to its creator, with the overwrite
 * behavior the command chose, and lists the export keys the import did not read (#102).
 *
 * @command  php vendor/bin/pest tests/Integration/GlobalCreatorTest.php
 */
uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

beforeEach(function (): void {
    config(['statamic.search.indexes' => []]);

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    // The redirects global set, already holding a rule with the source the export carries
    $global = new ConcreteGlobalSet;
    $global->handle('redirects');
    $global->sites(['default']);
    $global->save();

    $variables = $global->in('default');
    assert($variables instanceof Variables);
    $variables->set('redirects', [['source' => '^/give$', 'destination' => 'https://old.test/give']]);
    $variables->save();
});

afterEach(function (): void {
    TemporaryFileRegistry::sweep();
});

/**
 * A parser over an export carrying one regex redirect rule, whose target is used as written.
 */
function globalCreatorParser(): XmlParser
{
    $meta = static fn (string $key, string $value): string => '<wp:postmeta><wp:meta_key><![CDATA['.$key.']]></wp:meta_key>'
        .'<wp:meta_value><![CDATA['.$value.']]></wp:meta_value></wp:postmeta>';

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<channel><title>Site</title><link>https://example.test</link><wp:wxr_version>1.2</wp:wxr_version>'
        .'<wp:base_site_url>https://example.test</wp:base_site_url><wp:base_blog_url>https://example.test</wp:base_blog_url>'
        .'<item><title><![CDATA[^/give$]]></title><wp:post_id>11</wp:post_id>'
        .'<wp:status><![CDATA[publish]]></wp:status><wp:menu_order>0</wp:menu_order>'
        .'<wp:post_type><![CDATA[redirect_rule]]></wp:post_type>'
        .$meta('_redirect_rule_from', '^/give$')
        .$meta('_redirect_rule_to', 'https://new.test/give$1')
        .$meta('_redirect_rule_status_code', '301')
        .$meta('_redirect_rule_from_regex', '1')
        .'</item></channel></rss>';

    $path = TemporaryFileRegistry::create('global_creator_', '.xml');
    \file_put_contents($path, $xml);

    $parser = new XmlParser;
    $parser->parse($path);

    return $parser;
}

/**
 * Run the redirect globals through a creator, and return the output.
 */
function globalCreatorRun(GlobalCreator $creator, string $contentType = 'redirect_rule'): string
{
    $output = new LfBufferedOutput;

    $creator->createGlobals('default', $contentType, 'redirects', globalCreatorParser(), new AssetImporter, $output);

    return $output->fetch();
}

/**
 * A creator over the given metadata mapper.
 */
function globalCreatorWith(?MetadataMapper $mapper = null): GlobalCreator
{
    return new GlobalCreator(
        $mapper ?? new MetadataMapper,
        (new ReflectionClass(ContentProcessor::class))->newInstanceWithoutConstructor()
    );
}

it('overwrites a matching redirect by default', function (): void {
    $out = globalCreatorRun(globalCreatorWith());

    expect($out)->toContain("Overwriting existing redirect rule '^/give$' (ID: 11).");
});

it('hands the overwrite behavior it was given to the redirect creator', function (string $behavior, string $said): void {
    $creator = globalCreatorWith();
    $creator->setGlobalOverwriteBehavior($behavior);

    expect(globalCreatorRun($creator))->toContain($said)
        ->and(globalCreatorRun($creator))->not->toContain('Overwriting existing redirect rule');
})->with([
    'skip' => ['Skip matching sets', "Skipping redirect rule '^/give$' (ID: 11) as it already exists."],
    'create another' => ['Create an additional set', "Creating additional redirect rule '^/give$' with same source URL."],
]);

it('lists each export key the import did not read, once, and as text', function (): void {
    $mapper = new class extends MetadataMapper
    {
        public function getUnaddressedKeys(): array
        {
            return ['_unused_meta', 7, '_unused_meta', 1.5];
        }
    };

    $out = globalCreatorRun(globalCreatorWith($mapper));

    expect($out)->toEndWith("Unaddressed XML keys found:\n- _unused_meta\n- 7\n- \n");
});

it('lists nothing when every export key was read', function (): void {
    $mapper = new class extends MetadataMapper
    {
        public function getUnaddressedKeys(): array
        {
            return [];
        }
    };

    expect(globalCreatorRun(globalCreatorWith($mapper)))->not->toContain('Unaddressed XML keys found:');
});

it('refuses a content type it has no global creator for', function (): void {
    expect(fn (): string => globalCreatorRun(globalCreatorWith(), 'page'))
        ->toThrow(Exception::class, 'Unsupported global content type: page');
});

it('writes the redirect into the global set it names', function (): void {
    globalCreatorRun(globalCreatorWith());

    $variables = GlobalSet::findByHandle('redirects')?->in('default');
    assert($variables instanceof Variables);
    $redirects = $variables->get('redirects');

    expect($redirects)->toBeArray()->toHaveCount(1);
});
