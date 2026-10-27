<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Statamic\Entries\Entry as StatamicEntry;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PageEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

/**
 * Proves an imported image set keeps its alt text override under `alt_override` (#141).
 *
 * `ImageAltOverrideHandleTest` pins the key the image pipeline writes, in the
 * package's own gate. What only this file can reach is the saved entry: the
 * content passes through the host app's `BardContentSanitizer` before it is
 * stored, and that class does not exist in the minimal Statamic band.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/ImageAltOverrideImportTest.php
 */

/**
 * The Statamic site this imports into.
 */
const IMAGE_ALT_OVERRIDE_SITE = 'default';

/**
 * Page slug the fixture declares, namespaced so it cannot collide with content.
 */
const IMAGE_ALT_OVERRIDE_SLUG = 'zz-image-alt-override-probe';

beforeEach(function (): void {
    // Guard the live `content/` submodule against anything this test leaves behind (wordpress-importer-archive#570)
    $this->captureSubmoduleStates();

    // Image uploads off, so the source URL stands in for an asset and nothing is downloaded
    Config::set('wordpress-import.upload_images', false);
    app()->forgetInstance(AssetImporter::class);

    BaseEntryCreator::clearTaxonomyCaches();
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    // Remove the page this import created, however the test ended
    $created = imageAltOverridePage();

    if ($created instanceof StatamicEntry) {
        $created->delete();
    }

    BaseEntryCreator::clearTaxonomyCaches();
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();

    foreach (imageAltOverrideFixtures() as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    $this->assertSubmodulesUnchanged();
});

/**
 * Track fixture paths so they can be removed however a test ends.
 *
 * @param  ?string  $path  A path to remember, or null to drain the list
 * @return list<string> The tracked paths
 */
function imageAltOverrideFixtures(
    ?string $path = null
): array {
    /** @var list<string> $paths */
    static $paths = [];

    if ($path === null) {
        $drained = $paths;
        $paths = [];

        return $drained;
    }

    $paths[] = $path;

    return $paths;
}

/**
 * The page the fixture creates, found by its slug.
 */
function imageAltOverridePage(): ?StatamicEntry
{
    $query = EntryQueryHelper::query();
    $query->where('collection', 'pages');
    $query->where('locale', IMAGE_ALT_OVERRIDE_SITE);
    $query->where('slug', IMAGE_ALT_OVERRIDE_SLUG);

    $found = $query->first();

    return $found instanceof StatamicEntry ? $found : null;
}

/**
 * The values of every image set in a value, at any depth.
 *
 * @param  mixed  $node  An entry's data, or any part of it
 * @return list<array<int|string, mixed>>
 */
function imageAltOverrideImageSets(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $found = [];

    // A Bard set carries its fields under attrs.values
    $values = $node['attrs']['values'] ?? null;

    if (($node['type'] ?? null) === 'set' && is_array($values) && ($values['type'] ?? null) === 'image') {
        $found[] = $values;
    }

    foreach ($node as $child) {
        array_push($found, ...imageAltOverrideImageSets($child));
    }

    return $found;
}

/**
 * Import a page whose body is one `wp:image` block, and return the saved page.
 *
 * @param  string  $alt  The image's alt text
 */
function importImageAltOverridePage(string $alt): ?StatamicEntry
{
    $slug = IMAGE_ALT_OVERRIDE_SLUG;

    $fixture = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0"
        xmlns:content="http://purl.org/rss/1.0/modules/content/"
        xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
        xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <wp:base_blog_url>https://image-alt-override-probe.uams.test</wp:base_blog_url>
            <item>
                <title><![CDATA[Image Alt Override Probe]]></title>
                <link>https://image-alt-override-probe.uams.test/{$slug}/</link>
                <wp:post_id>990141</wp:post_id>
                <wp:post_name><![CDATA[{$slug}]]></wp:post_name>
                <wp:status><![CDATA[publish]]></wp:status>
                <wp:post_type><![CDATA[page]]></wp:post_type>
                <wp:post_parent>0</wp:post_parent>
                <wp:menu_order>0</wp:menu_order>
                <wp:post_date><![CDATA[2026-01-01 00:00:00]]></wp:post_date>
                <content:encoded><![CDATA[<!-- wp:image {"id":990142} --><figure class="wp-block-image"><img src="https://image-alt-override-probe.uams.test/wp-content/uploads/a.jpg" alt="{$alt}"/></figure><!-- /wp:image -->]]></content:encoded>
                <excerpt:encoded><![CDATA[Image alt override probe.]]></excerpt:encoded>
            </item>
        </channel>
    </rss>
    XML;

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_altoverride_');
    file_put_contents($path, $fixture);
    imageAltOverrideFixtures($path);

    $xmlParser = new XmlParser;
    $xmlParser->parse($path);

    $creator = new PageEntryCreator(app(EntryCreator::class));
    $creator->createEntries(
        IMAGE_ALT_OVERRIDE_SITE,
        'pages',
        $xmlParser,
        app(AssetImporter::class),
        new BufferedOutput,
        app(MetadataMapper::class),
        app(ContentProcessor::class)
    );

    return imageAltOverridePage();
}

it('saves the image alt override under alt_override', function (): void {
    $entry = importImageAltOverridePage('A nurse at work');

    expect($entry)->toBeInstanceOf(StatamicEntry::class);

    $sets = imageAltOverrideImageSets($entry?->data()->all());

    expect($sets)->toHaveCount(1)
        ->and($sets[0]['alt_override'] ?? null)->toBe('A nurse at work')
        ->and($sets[0])->not->toHaveKey('altOverride')
        ->and($sets[0])->not->toHaveKey('altoverride');
});

it('saves no override for an image without alt text', function (): void {
    $entry = importImageAltOverridePage('');

    expect($entry)->toBeInstanceOf(StatamicEntry::class);

    $sets = imageAltOverrideImageSets($entry?->data()->all());

    expect($sets)->toHaveCount(1)
        ->and($sets[0])->not->toHaveKey('alt_override');
});
