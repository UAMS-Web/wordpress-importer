<?php

declare(strict_types=1);

/**
 * Tests `PostEntryCreator`: what each post item becomes, from its categories, tags
 * and blueprint to its excerpt, featured image and content, and the paths that
 * skip a post or leave a field out.
 *
 * The term pass runs for real against `post_categories` and `post_tags`
 * collections this test makes, so a post's categories and tags resolve to the
 * entries that pass writes. The content converter and the metadata mapper are
 * recording subclasses, and image uploads are off, so a featured image resolves
 * to its attachment URL.
 *
 * @command  php vendor/bin/pest tests/Integration/PostEntryCreatorTest.php
 */

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Fields\Blueprint;
use Statamic\Taxonomies\Taxonomy;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * A content converter that records each call and returns a chosen body.
 */
final class PostEntryTestConverter extends ContentProcessor
{
    /**
     * The arguments of each call, by name.
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    /**
     * What every call returns.
     *
     * @var array{content: mixed, page_builder: array<int|string, mixed>}
     */
    public array $result = ['content' => null, 'page_builder' => []];

    /**
     * Build the converter without running `ContentProcessor`'s constructor.
     *
     * @param  array{content: mixed, page_builder: array<int|string, mixed>}  $result  What every call returns
     * @return self The converter
     */
    public static function returning(array $result): self
    {
        $converter = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $converter->result = $result;

        return $converter;
    }

    /**
     * Record the call and return the chosen body.
     *
     * @param  string  $content  The item's HTML
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  XmlParser  $xmlParser  The parser
     * @param  string  $pageTitle  The item title
     * @param  string  $pageId  The WordPress post id
     * @param  string  $pageLink  The item URL
     * @param  array<int|string, mixed>  $attachments  Attachment elements
     * @param  array<int|string, mixed>  $terms  Term elements
     * @param  array<int|string, mixed>  $items  Items
     * @param  array<int|string, mixed>  $data  The entry data so far
     * @param  string  $collectionHandle  The collection handle
     * @param  ?string  $pageSlug  The entry slug
     * @param  ?string  $statamicEntryId  The entry id
     * @return array{content: mixed, page_builder: array<int|string, mixed>} The chosen body
     */
    public function process(
        string $content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        XmlParser $xmlParser,
        string $pageTitle = '',
        string $pageId = '',
        string $pageLink = '',
        array $attachments = [],
        array $terms = [],
        array $items = [],
        array $data = [],
        string $collectionHandle = '',
        ?string $pageSlug = null,
        ?string $statamicEntryId = null
    ): array {
        $this->calls[] = [
            'content' => $content,
            'site' => $site,
            'title' => $pageTitle,
            'id' => $pageId,
            'link' => $pageLink,
            'attachments' => \count($attachments),
            'terms' => \count($terms),
            'items' => $items,
            'collection' => $collectionHandle,
            'slug' => $pageSlug,
            'entry' => $statamicEntryId,
            'blueprint' => $data['blueprint'] ?? null,
        ];

        return $this->result;
    }
}

/**
 * A metadata mapper that records each post and marks the data it maps.
 */
final class PostEntryTestMapper extends MetadataMapper
{
    /**
     * The post id of each post mapped.
     *
     * @var list<string>
     */
    public array $mapped = [];

    /**
     * Record the post and mark the data.
     *
     * @param  SimpleXMLElement  $page  The post
     * @param  array<int|string, mixed>  $data  The entry data
     * @param  XmlParser  $xmlParser  The parser
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  array<int|string, mixed>  $attachments  Attachment elements
     * @param  OutputInterface  $output  Console output
     */
    public function mapMetadata(
        SimpleXMLElement $page,
        array &$data,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        array $attachments,
        OutputInterface $output
    ): void {
        $this->mapped[] = (string) $page->children('wp', true)->post_id;
        $data['mapped'] = true;
    }
}

/**
 * A post's string field, or the default when it is absent.
 *
 * @param  array<mixed>  $post  The post's fields
 * @param  string  $key  The field
 * @param  string  $default  The default
 * @return string The value
 */
function postEntryField(array $post, string $key, string $default): string
{
    $value = $post[$key] ?? $default;

    return \is_string($value) ? $value : $default;
}

/**
 * A post's list or map of strings, or none when it is absent.
 *
 * @param  array<mixed>  $post  The post's fields
 * @param  string  $key  The field
 * @return array<string> The values
 */
function postEntryFields(array $post, string $key): array
{
    $values = $post[$key] ?? [];

    return \is_array($values) ? \array_filter($values, \is_string(...)) : [];
}

/**
 * Write an export of categories, tags, attachments and the given posts.
 *
 * @param  list<array<mixed>>  $posts  Each post's fields
 * @return string The export path
 */
function postEntryExport(array $posts): string
{
    $items = '';

    foreach ($posts as $index => $post) {
        $postId = postEntryField($post, 'id', (string) (300 + $index));
        $slug = postEntryField($post, 'slug', "post-{$postId}");
        $title = postEntryField($post, 'title', "Post {$postId}");
        $status = postEntryField($post, 'status', 'publish');
        $content = postEntryField($post, 'content', '<p>Body.</p>');
        $excerpt = postEntryField($post, 'excerpt', '');
        $sticky = postEntryField($post, 'sticky', '0');
        $stickyXml = $sticky === 'absent' ? '' : "<wp:is_sticky>{$sticky}</wp:is_sticky>";
        $modified = postEntryField($post, 'modified', '2025-04-02 10:00:00');
        $categories = '';

        foreach (['categories' => 'category', 'tags' => 'post_tag', 'formats' => 'post_format'] as $field => $domain) {
            foreach (postEntryFields($post, $field) as $nicename) {
                $categories .= "<category domain=\"{$domain}\" nicename=\"{$nicename}\"><![CDATA[{$nicename}]]></category>";
            }
        }

        $meta = '';

        foreach (postEntryFields($post, 'meta') as $key => $value) {
            $meta .= "<wp:postmeta><wp:meta_key>{$key}</wp:meta_key><wp:meta_value><![CDATA[{$value}]]></wp:meta_value></wp:postmeta>";
        }

        $items .= <<<XML
<item>
  <title><![CDATA[{$title}]]></title>
  <link>https://example.test/news/{$postId}/</link>
  <content:encoded><![CDATA[{$content}]]></content:encoded>
  <excerpt:encoded><![CDATA[{$excerpt}]]></excerpt:encoded>
  <wp:post_id>{$postId}</wp:post_id>
  <wp:post_name>{$slug}</wp:post_name>
  <wp:post_date>2025-04-01 09:00:00</wp:post_date>
  <wp:post_modified>{$modified}</wp:post_modified>
  <wp:status>{$status}</wp:status>
  <wp:post_parent>0</wp:post_parent>
  <wp:menu_order>0</wp:menu_order>
  <wp:post_type>post</wp:post_type>
  {$stickyXml}
  {$categories}
  {$meta}
</item>

XML;
    }

    $path = TemporaryFileRegistry::create('post-entry');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>News</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
<wp:category><wp:term_id>11</wp:term_id><wp:category_nicename>research</wp:category_nicename><wp:category_parent></wp:category_parent><wp:cat_name><![CDATA[Research]]></wp:cat_name></wp:category>
<wp:category><wp:term_id>12</wp:term_id><wp:category_nicename>awards</wp:category_nicename><wp:category_parent></wp:category_parent><wp:cat_name><![CDATA[Awards]]></wp:cat_name></wp:category>
<wp:category><wp:term_id>13</wp:term_id><wp:category_nicename>news-release</wp:category_nicename><wp:category_parent></wp:category_parent><wp:cat_name><![CDATA[News Release]]></wp:cat_name></wp:category>
<wp:category><wp:term_id>14</wp:term_id><wp:category_nicename></wp:category_nicename><wp:category_parent></wp:category_parent><wp:cat_name><![CDATA[Blank]]></wp:cat_name></wp:category>
<wp:tag><wp:term_id>24</wp:term_id><wp:tag_slug></wp:tag_slug><wp:tag_name><![CDATA[Blank Tag]]></wp:tag_name></wp:tag>
<wp:tag><wp:term_id>21</wp:term_id><wp:tag_slug>grants</wp:tag_slug><wp:tag_name><![CDATA[Grants]]></wp:tag_name></wp:tag>
<wp:tag><wp:term_id>22</wp:term_id><wp:tag_slug>faculty</wp:tag_slug><wp:tag_name><![CDATA[Faculty]]></wp:tag_name></wp:tag>
<wp:term><wp:term_id>31</wp:term_id><wp:term_taxonomy>series</wp:term_taxonomy><wp:term_slug>season-one</wp:term_slug><wp:term_name><![CDATA[Season One]]></wp:term_name></wp:term>
<item>
  <title>photo</title>
  <link>https://example.test/news/photo/</link>
  <wp:post_id>900</wp:post_id>
  <wp:post_type>attachment</wp:post_type>
  <wp:attachment_url>https://example.test/wp-content/uploads/2025/04/photo.jpg</wp:attachment_url>
</item>
<item>
  <title>credited</title>
  <link>https://example.test/news/credited/</link>
  <wp:post_id>901</wp:post_id>
  <wp:post_type>attachment</wp:post_type>
  <wp:attachment_url>https://example.test/wp-content/uploads/2025/04/credited.jpg</wp:attachment_url>
  <wp:postmeta><wp:meta_key>_media_credit</wp:meta_key><wp:meta_value><![CDATA[Pat Doe]]></wp:meta_value></wp:postmeta>
</item>
<item>
  <title>unlinked</title>
  <wp:post_id>902</wp:post_id>
  <wp:post_type>attachment</wp:post_type>
  <wp:attachment_url></wp:attachment_url>
  <wp:postmeta><wp:meta_key>_media_credit</wp:meta_key><wp:meta_value><![CDATA[Pat Doe]]></wp:meta_value></wp:postmeta>
</item>
{$items}</channel>
</rss>
XML);

    return $path;
}

/**
 * Run the creator over an export.
 *
 * @param  string  $export  The export path
 * @param  PostEntryTestConverter  $converter  The content converter
 * @param  ?PostEntryCreator  $creator  The creator, or null for a new one
 * @param  ?PostEntryTestMapper  $mapper  The metadata mapper, or null for a new one
 * @param  ?AssetImporter  $assetImporter  The asset importer, or null for a new one
 * @return array{output: string, result: array<int|string, mixed>, mapper: PostEntryTestMapper} What the run produced
 */
function postEntryRun(string $export, PostEntryTestConverter $converter, ?PostEntryCreator $creator = null, ?PostEntryTestMapper $mapper = null, ?AssetImporter $assetImporter = null): array
{
    $parser = new XmlParser;
    $parser->parse($export);
    $output = new BufferedOutput;
    $creator ??= new PostEntryCreator;
    $mapper ??= new PostEntryTestMapper;

    $result = $creator->createEntries('default', 'posts', $parser, $assetImporter ?? new AssetImporter, $output, $mapper, $converter);

    return ['output' => $output->fetch(), 'result' => $result, 'mapper' => $mapper];
}

/**
 * The saved post with the given slug.
 *
 * @param  string  $slug  The slug
 * @return Entry The entry
 */
function postEntry(string $slug): Entry
{
    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'posts' && $entry->slug() === $slug) {
            return $entry;
        }
    }

    throw new RuntimeException("No posts entry '{$slug}'.");
}

/**
 * Everything an entry stores.
 *
 * @param  Entry  $entry  The entry
 * @return array<mixed> The entry's data
 */
function postEntryData(Entry $entry): array
{
    $data = $entry->data();

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * The handle of an entry's blueprint.
 *
 * @param  Entry  $entry  The entry
 * @return ?string The handle, or null when it has none
 */
function postEntryBlueprint(Entry $entry): ?string
{
    $blueprint = $entry->blueprint();

    return $blueprint instanceof Blueprint ? $blueprint->handle() : null;
}

/**
 * A body converter result with the given Bard content.
 *
 * @param  mixed  $content  The content
 * @return array{content: mixed, page_builder: array<int|string, mixed>} The result
 */
function postEntryBody(mixed $content = null): array
{
    return [
        'content' => $content ?? [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]],
        'page_builder' => [],
    ];
}

beforeEach(function (): void {
    config([
        'statamic.search.indexes' => [],
        'wordpress-import.upload_images' => false,
        'wordpress-import.upload_files' => false,
        'wordpress-import.import_tags' => true,
    ]);
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    $posts = CollectionFacade::make('posts');
    $posts->sites(['default']);
    $posts->dated(true);
    $posts->save();

    foreach (['post_categories', 'post_tags'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default']);
        $collection->structureContents(['root' => false]);
        $collection->save();
    }

    foreach (['post', 'news_release', 'audio'] as $handle) {
        $blueprint = BlueprintFacade::make($handle);
        $blueprint->setNamespace('collections.posts');
        $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
        $blueprint->save();
    }

    BaseEntryCreator::clearTaxonomyCaches();
    TermDestinationRegistry::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    TermDestinationRegistry::clear();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();

    foreach (['post', 'news_release', 'audio'] as $handle) {
        $blueprint = BlueprintFacade::find("collections.posts.{$handle}");

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }

    TemporaryFileRegistry::sweep();
    Mockery::close();
});

describe('importing a post', function (): void {
    it('saves a post with its categories, tags, defaults and content, and reports it as page data', function (): void {
        $converter = PostEntryTestConverter::returning(postEntryBody());
        $run = postEntryRun(postEntryExport([[
            'id' => '301',
            'slug' => 'grant-awarded',
            'title' => 'Grant &amp; Award',
            'categories' => ['research', 'awards'],
            'tags' => ['grants', 'faculty', 'grants'],
            'sticky' => '1',
            'content' => '<p>Hello</p>',
        ]]), $converter);
        $entry = postEntry('grant-awarded');

        expect(postEntryBlueprint($entry))->toBe('post')
            ->and($entry->get('title'))->toBe('Grant & Award')
            ->and($entry->get('post_categories'))->toBe([
                TermDestinationRegistry::referenceFor('category', '11'),
                TermDestinationRegistry::referenceFor('category', '12'),
            ])
            ->and($entry->get('post_tags'))->toBe([
                TermDestinationRegistry::referenceFor('post_tag', '21'),
                TermDestinationRegistry::referenceFor('post_tag', '22'),
            ])
            ->and($entry->get('sticky_query'))->toBeTrue()
            ->and($entry->get('content'))->toBe(postEntryBody()['content'])
            ->and($entry->get('mapped'))->toBeTrue()
            ->and($entry->get('original_wordpress_url'))->toBe('https://example.test/news/301/')
            ->and($entry->get('updated_at'))->toBe((new DateTime('2025-04-02 10:00:00'))->getTimestamp())
            ->and($entry->get('post_format'))->toBe('standard')
            ->and($entry->get('scripts_head'))->toBe(['code' => null, 'mode' => 'htmlmixed'])
            ->and($entry->get('scripts_body_position'))->toBe('bottom')
            ->and($converter->calls)->toHaveCount(1)
            ->and(\array_diff_key($converter->calls[0] ?? [], ['entry' => true]))->toBe([
                'content' => '<p>Hello</p>',
                'site' => 'default',
                'title' => 'Grant & Award',
                'id' => '301',
                'link' => 'https://example.test/news/301/',
                'attachments' => 3,
                'terms' => 1,
                'items' => [],
                'collection' => 'posts',
                'slug' => 'grant-awarded',
                'blueprint' => 'post',
            ])
            ->and($converter->calls[0]['entry'] ?? null)->toBe($entry->id())
            ->and($run['mapper']->mapped)->toBe(['301'])
            ->and($run['output'])->toContain("Processing post 'grant-awarded' with content length: 12".PHP_EOL)
            ->and($run['result'])->toHaveKey('301');
    });

    it('lists the Yoast primary category first', function (): void {
        postEntryRun(postEntryExport([[
            'slug' => 'primary',
            'categories' => ['research', 'awards'],
            'meta' => ['_yoast_wpseo_primary_category' => '12'],
        ]]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntry('primary')->get('post_categories'))->toBe([
            TermDestinationRegistry::referenceFor('category', '12'),
            TermDestinationRegistry::referenceFor('category', '11'),
        ]);
    });

    it('leaves out a category or tag that does not resolve', function (): void {
        postEntryRun(postEntryExport([[
            'slug' => 'unknown',
            'categories' => ['no-such', 'research'],
            'tags' => ['no-such-tag', 'faculty'],
        ]]), PostEntryTestConverter::returning(postEntryBody()));
        $entry = postEntry('unknown');

        expect($entry->get('post_categories'))->toBe([TermDestinationRegistry::referenceFor('category', '11')])
            ->and($entry->get('post_tags'))->toBe([TermDestinationRegistry::referenceFor('post_tag', '22')]);
    });

    it('imports no tags when tag import is off', function (): void {
        config(['wordpress-import.import_tags' => false]);

        postEntryRun(postEntryExport([['slug' => 'untagged', 'tags' => ['grants']]]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntry('untagged')->get('post_tags'))->toBe([])
            ->and(PostEntryCreator::getTaxonomyHandlesForPostImport())->toBe(['category']);
    });

    it('names a post without a title', function (): void {
        $converter = PostEntryTestConverter::returning(postEntryBody());
        $run = postEntryRun(postEntryExport([['id' => '305', 'slug' => 'untitled', 'title' => '']]), $converter);

        expect($converter->calls[0]['title'] ?? null)->toBe('Untitled Post')
            ->and($run['result'])->toHaveKey('305.title', 'Untitled Post');
    });

    it('stores no content when the body converts to nothing', function (mixed $content): void {
        postEntryRun(postEntryExport([['slug' => 'empty']]), PostEntryTestConverter::returning(['content' => $content, 'page_builder' => []]));

        expect(postEntry('empty')->get('content'))->toBeNull();
    })->with([
        'no content' => [[]],
        'content that is not a list' => ['text'],
    ]);
});

describe('choosing a blueprint', function (): void {
    it('uses the news release blueprint for a release', function (array $post): void {
        postEntryRun(postEntryExport([['slug' => 'release', ...$post]]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntryBlueprint(postEntry('release')))->toBe('news_release');
    })->with([
        'a PDF URL' => [['meta' => ['news_release_pdf_url' => 'https://example.test/r.pdf']]],
        'a PDF' => [['meta' => ['news_release_pdf' => '42']]],
        'a media contact' => [['meta' => ['media_contact' => 'Pat']]],
        'the news release category' => [['categories' => ['news-release']]],
        'a PDF URL among other meta' => [['meta' => ['news_release_pdf_url' => 'https://example.test/r.pdf', 'media_contact' => 'null']]],
    ]);

    it('does not treat an empty or null release field as a release', function (string $key, string $value): void {
        postEntryRun(postEntryExport([['slug' => 'ordinary', 'meta' => [$key => $value]]]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntryBlueprint(postEntry('ordinary')))->toBe('post');
    })->with([
        'an empty PDF URL' => ['news_release_pdf_url', ''],
        'a null PDF URL' => ['news_release_pdf_url', 'null'],
        'an empty PDF' => ['news_release_pdf', ''],
        'a null PDF' => ['news_release_pdf', 'null'],
        'an empty media contact' => ['media_contact', ''],
        'a null media contact' => ['media_contact', 'null'],
    ]);
});

describe('reading the excerpt, short title and featured image', function (): void {
    it('keeps a short title only when it differs from the title', function (string $seoTitle, ?string $expected): void {
        postEntryRun(postEntryExport([['slug' => 'seo', 'title' => 'Full Title', 'meta' => ['_seopress_titles_title' => $seoTitle]]]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntry('seo')->get('title_short'))->toBe($expected);
    })->with([
        'a different title' => ['<b>Short</b>', 'Short'],
        'the same title' => ['Full Title', null],
        'none' => ['', null],
    ]);

    it('takes the excerpt, then the SEO description, then the meta description', function (array $post, ?string $expected): void {
        postEntryRun(postEntryExport([['slug' => 'excerpt', ...$post]]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntry('excerpt')->get('excerpt'))->toBe($expected);
    })->with([
        'the excerpt' => [['excerpt' => 'Written', 'meta' => ['_seopress_titles_desc' => 'Seo', 'seo_meta_description' => 'Meta']], 'Written'],
        'the SEO description' => [['meta' => ['_seopress_titles_desc' => 'Seo', 'seo_meta_description' => 'Meta']], 'Seo'],
        'the meta description' => [['meta' => ['seo_meta_description' => 'Meta']], 'Meta'],
        'none' => [[], null],
    ]);

    it('imports the featured image from its attachment', function (): void {
        postEntryRun(postEntryExport([['slug' => 'pictured', 'meta' => ['_thumbnail_id' => '900']]]), PostEntryTestConverter::returning(postEntryBody()));
        $entry = postEntry('pictured');

        expect($entry->get('_thumbnail_id'))->toBe('900')
            ->and($entry->get('featured_image_16_9'))->toBe('https://example.test/wp-content/uploads/2025/04/photo.jpg')
            ->and($entry->get('featured_image_credit_query'))->toBeNull();
    });

    it('stores no featured image for a post without one, or one with no attachment', function (array $meta): void {
        postEntryRun(postEntryExport([['slug' => 'bare', 'meta' => $meta]]), PostEntryTestConverter::returning(postEntryBody()));
        $entry = postEntry('bare');

        expect($entry->get('featured_image_16_9'))->toBeNull()
            ->and($entry->get('featured_image_credit_query'))->toBeNull();
    })->with([
        'no thumbnail' => [[]],
        'a thumbnail with no attachment' => [['_thumbnail_id' => '999']],
    ]);
});

describe('importing a post that already exists', function (): void {
    it('skips it when told to, still registering its URL', function (): void {
        $export = postEntryExport([['id' => '301', 'slug' => 'kept']]);
        postEntryRun($export, PostEntryTestConverter::returning(postEntryBody()));
        LinkProcessor::clearDeferredData();
        BaseEntryCreator::clearTaxonomyCaches();

        $creator = new PostEntryCreator;
        $creator->setEntryOverwriteBehavior('Skip matching files');
        $converter = PostEntryTestConverter::returning(postEntryBody());
        $run = postEntryRun($export, $converter, $creator);

        $map = (new ReflectionProperty(LinkProcessor::class, 'urlToPostIdMap'))->getValue();

        expect($converter->calls)->toBe([])
            ->and($run['output'])->toContain("Skipped entry 'kept' as it already exists.".PHP_EOL)
            ->and(\is_array($map) ? ($map['https://example.test/news/301/'] ?? null) : null)->toBe('301');
    });

    it('imports a post that does not exist yet when told to skip existing ones', function (): void {
        $creator = new PostEntryCreator;
        $creator->setEntryOverwriteBehavior('Skip matching files');
        $converter = PostEntryTestConverter::returning(postEntryBody());

        postEntryRun(postEntryExport([['slug' => 'fresh']]), $converter, $creator);

        expect($converter->calls)->toHaveCount(1)
            ->and(postEntry('fresh')->get('title'))->toBe('Post 300');
    });
});

describe('the content type\'s other answers', function (): void {
    it('has no people, post categories or post tags, and nothing to validate', function (): void {
        $creator = new PostEntryCreator;
        $output = new BufferedOutput;
        $creator->validateTaxonomies('posts', 'default', $output);

        expect($creator->hasShowPeople())->toBeFalse()
            ->and($creator->getPostCategories())->toBe([])
            ->and($creator->getPostTags())->toBe([])
            ->and($output->fetch())->toBe('');
    });
});

describe('the entry data', function (): void {
    it('writes every default field a post blueprint expects', function (): void {
        postEntryRun(postEntryExport([['slug' => 'defaults']]), PostEntryTestConverter::returning(postEntryBody()));
        $data = postEntryData(postEntry('defaults'));

        expect($data)->toMatchArray([
            'sticky_query' => false,
            'post_categories' => [],
            'post_tags' => [],
            'post_format' => 'standard',
            'boilerplate_query' => false,
            'scripts_head' => ['code' => null, 'mode' => 'htmlmixed'],
            'scripts_body' => ['code' => null, 'mode' => 'htmlmixed'],
            'scripts_body_position' => 'bottom',
            'robots_index_meta' => false,
            'robots_follow_meta' => false,
            'robots_odp_meta' => false,
            'robots_imageindex_meta' => false,
            'robots_archive_meta' => false,
            'robots_snippet_meta' => false,
            'hide_creator_query' => false,
            'segments' => [],
            'intro' => [],
        ])
            ->and($data)->toHaveKeys([
                'news_release_pdf',
                'title_short',
                'excerpt',
                'featured_image_4_3',
                'featured_image_1_1',
                'robots_canonical_meta',
                'series',
            ])
            ->and($data)->not->toHaveKeys(['_thumbnail_id', 'featured_image_16_9', 'featured_image_credit_query']);
    });

    it('stores the fields in a fixed order, so a re-import does not reorder an entry\'s file', function (): void {
        postEntryRun(postEntryExport([['slug' => 'ordered', 'excerpt' => 'Words']]), PostEntryTestConverter::returning(postEntryBody()));

        expect(\array_keys(postEntryData(postEntry('ordered'))))->toBe([
            'blueprint', 'title', 'slug', 'sticky_query', 'post_categories', 'post_tags', 'post_format',
            'boilerplate_query', 'news_release_pdf', 'title_short', 'excerpt', 'featured_image_4_3',
            'featured_image_1_1', 'scripts_head', 'scripts_body', 'scripts_body_position', 'robots_index_meta',
            'robots_follow_meta', 'robots_odp_meta', 'robots_imageindex_meta', 'robots_archive_meta',
            'robots_snippet_meta', 'robots_canonical_meta', 'hide_creator_query', 'series', 'segments', 'intro',
            'original_wordpress_url', 'wordpress_post_id', 'content', 'mapped', 'updated_at',
        ]);
    });

    it('decodes the title twice, so a double-encoded entity comes out as its character', function (): void {
        $converter = PostEntryTestConverter::returning(postEntryBody());

        postEntryRun(postEntryExport([['slug' => 'quoted', 'title' => 'Say &AMP;quot;hi&AMP;quot;']]), $converter);

        expect(postEntry('quoted')->get('title'))->toBe('Say "hi"');
    });

    it('leaves the last post\'s link in the processing context', function (): void {
        postEntryRun(postEntryExport([['id' => '301'], ['id' => '302']]), PostEntryTestConverter::returning(postEntryBody()));

        expect(ProcessingContext::getWordPressPageLink())->toBe('https://example.test/news/302/');
    });

    it('reports every saved post as page data, keyed by its post id', function (): void {
        $run = postEntryRun(postEntryExport([['id' => '301', 'title' => 'One'], ['id' => '302', 'title' => '']]), PostEntryTestConverter::returning(postEntryBody()));

        expect(\array_keys($run['result']))->toBe([301, 302])
            ->and($run['result'])->toHaveKey('301.title', 'One')
            ->and($run['result'])->toHaveKey('302.title', 'Untitled Post');
    });

    it('carries on to the next post after skipping one', function (): void {
        $export = postEntryExport([['id' => '301', 'slug' => 'first'], ['id' => '302', 'slug' => 'second']]);
        postEntryRun(postEntryExport([['id' => '301', 'slug' => 'first']]), PostEntryTestConverter::returning(postEntryBody()));
        LinkProcessor::clearDeferredData();
        BaseEntryCreator::clearTaxonomyCaches();

        $creator = new PostEntryCreator;
        $creator->setEntryOverwriteBehavior('Skip matching files');
        $converter = PostEntryTestConverter::returning(postEntryBody());
        $run = postEntryRun($export, $converter, $creator);

        expect(\array_column($converter->calls, 'slug'))->toBe(['second'])
            ->and($run['output'])->toContain("Skipped entry 'first' as it already exists.".PHP_EOL)
            ->and($run['output'])->toContain("Processing post 'second' with content length: 12".PHP_EOL);
    });

    it('overwrites an existing post when not told to skip it', function (): void {
        $export = postEntryExport([['id' => '301', 'slug' => 'again']]);
        postEntryRun($export, PostEntryTestConverter::returning(postEntryBody()));
        LinkProcessor::clearDeferredData();
        BaseEntryCreator::clearTaxonomyCaches();

        $converter = PostEntryTestConverter::returning(postEntryBody());
        $run = postEntryRun($export, $converter);

        expect($converter->calls)->toHaveCount(1)
            ->and($run['output'])->not->toContain('Skipped entry');
    });
});

describe('the featured image credit', function (): void {
    it('shows the credit when the featured image carries one', function (): void {
        postEntryRun(postEntryExport([['slug' => 'credited', 'meta' => ['_thumbnail_id' => '901']]]), PostEntryTestConverter::returning(postEntryBody()));
        $entry = postEntry('credited');

        expect($entry->get('featured_image_16_9'))->toBe('https://example.test/wp-content/uploads/2025/04/credited.jpg')
            ->and($entry->get('featured_image_credit_query'))->toBeTrue();
    });

    it('stores neither the image nor its credit when the attachment has no URL', function (): void {
        postEntryRun(postEntryExport([['slug' => 'unlinked', 'meta' => ['_thumbnail_id' => '902']]]), PostEntryTestConverter::returning(postEntryBody()));
        $data = postEntryData(postEntry('unlinked'));

        expect($data['_thumbnail_id'] ?? null)->toBe('902')
            ->and($data)->not->toHaveKeys(['featured_image_16_9', 'featured_image_credit_query']);
    });
});

describe('an audio post', function (): void {
    beforeEach(function (): void {
        $taxonomy = TaxonomyFacade::make('audio_series');

        if (! $taxonomy instanceof Taxonomy) {
            throw new RuntimeException('Could not make the audio_series taxonomy.');
        }

        $taxonomy->sites(['default']);
        $taxonomy->save();
    });

    it('uses the audio blueprint and defaults, and processes its content as audio', function (): void {
        $converter = PostEntryTestConverter::returning(postEntryBody());
        $run = postEntryRun(postEntryExport([[
            'slug' => 'episode',
            'formats' => ['post-format-audio'],
            'categories' => ['heres-to-your-health', 'research'],
            'content' => 'Welcome to the show.',
        ]]), $converter);
        $entry = postEntry('episode');

        expect(postEntryBlueprint($entry))->toBe('audio')
            ->and($converter->calls)->toBe([])
            ->and($entry->get('series'))->toBe('heres-to-your-health')
            ->and($entry->get('post_categories'))->toBe([TermDestinationRegistry::referenceFor('category', '11')])
            ->and($entry->get('excerpt_display_query'))->toBeTrue()
            ->and($entry->get('title_options'))->toBe('normal')
            ->and($entry->get('cover_image_query'))->toBeTrue()
            ->and($entry->get('series_boilerplate_display_query'))->toBeTrue()
            ->and($entry->get('template'))->toBe('post/audio')
            ->and($entry->get('segments'))->toBe([])
            ->and($entry->get('intro'))->not->toBe([])
            ->and($entry->get('content'))->toBeNull()
            ->and(TermFacade::find('audio_series::heres-to-your-health'))->not->toBeNull()
            ->and($run['mapper']->mapped)->toHaveCount(1);
    });

    it('creates no series term when no post names a series', function (): void {
        postEntryRun(postEntryExport([['slug' => 'plain-episode', 'formats' => ['post-format-audio']]]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntry('plain-episode')->get('series'))->toBeNull()
            ->and(TermFacade::find('audio_series::heres-to-your-health'))->toBeNull();
    });
});

/**
 * A nicename-to-term-id map, built at run time so an integer-string nicename keeps
 * the int key PHP gives it.
 *
 * @param  list<string>  $nicenames  The nicenames
 * @param  list<string>  $termIds  Their term ids
 * @return array<string, string> The map
 */
function postEntryTermIds(array $nicenames, array $termIds): array
{
    /** @var array<string, string> */
    return \array_combine($nicenames, $termIds);
}

describe('ordering categories primary first', function (): void {
    it('trims the recorded id', function (): void {
        expect((new PostEntryCreator)->orderCategoriesPrimaryFirst(['a', 'b'], ' 12 ', ['a' => '11', 'b' => '12']))->toBe(['b', 'a']);
    });

    it('treats a blank or zero id as no primary, even when a category has that id', function (string $primary): void {
        expect((new PostEntryCreator)->orderCategoriesPrimaryFirst(['a', 'b'], $primary, ['a' => '1', 'b' => $primary]))->toBe(['a', 'b']);
    })->with(['blank' => [''], 'zero' => ['0']]);

    it('keeps document order when the primary is unknown, even with a blank nicename in the list', function (): void {
        expect((new PostEntryCreator)->orderCategoriesPrimaryFirst(['a', ''], '9', ['a' => '1']))->toBe(['a', '']);
    });

    it('keeps document order when the post carries only a nicename equal to the primary\'s as a number', function (): void {
        expect((new PostEntryCreator)->orderCategoriesPrimaryFirst(['01'], '6', ['01' => '5', '1.0' => '6']))->toBe(['01']);
    });

    it('moves only the primary when two nicenames compare equal as numbers', function (): void {
        expect((new PostEntryCreator)->orderCategoriesPrimaryFirst(['01', '1.0'], '6', ['01' => '5', '1.0' => '6']))->toBe(['1.0', '01']);
    });

    it('moves a primary whose nicename is an integer string, which PHP keys as an int', function (): void {
        expect((new PostEntryCreator)->orderCategoriesPrimaryFirst(['a', '2024'], '5', postEntryTermIds(['a', '2024'], ['1', '5'])))->toBe(['2024', 'a']);
    });

    it('matches an integer term id, and passes over one that is not a scalar', function (): void {
        $creator = new PostEntryCreator;

        expect($creator->orderCategoriesPrimaryFirst(['a', 'b'], '2', ['a' => 1, 'b' => 2]))->toBe(['b', 'a'])
            ->and($creator->orderCategoriesPrimaryFirst(['a', 'b'], '1', ['a' => ['1'], 'b' => '1']))->toBe(['b', 'a']);
    });
});

describe('resolving term entry references', function (): void {
    it('skips a blank term id or a blank entry id, even when one is registered', function (): void {
        TermDestinationRegistry::registerCollectionEntry('category', '', 'post_categories', 'entry-blank');
        TermDestinationRegistry::registerCollectionEntry('category', '7', 'post_categories', '');
        TermDestinationRegistry::registerCollectionEntry('category', '8', 'post_categories', 'entry-eight');

        expect((new PostEntryCreator)->resolveTermEntryReferences('category', ['blank', 'false', 'empty', 'eight'], ['blank' => '', 'false' => false, 'empty' => '7', 'eight' => '8']))
            ->toBe(['entry-eight']);
    });

    it('keeps entry ids that differ as strings but compare equal as numbers, and goes on past a duplicate', function (): void {
        TermDestinationRegistry::registerCollectionEntry('category', '1', 'post_categories', '01');
        TermDestinationRegistry::registerCollectionEntry('category', '2', 'post_categories', '1.0');
        TermDestinationRegistry::registerCollectionEntry('category', '3', 'post_categories', 'three');

        expect((new PostEntryCreator)->resolveTermEntryReferences('category', ['a', 'a', 'b', 'c'], ['a' => '1', 'b' => '2', 'c' => '3']))
            ->toBe(['01', '1.0', 'three']);
    });

    it('reads an integer term id, and skips one that is not a scalar', function (): void {
        TermDestinationRegistry::registerCollectionEntry('category', '4', 'post_categories', 'entry-four');

        expect((new PostEntryCreator)->resolveTermEntryReferences('category', ['four', 'list'], ['four' => 4, 'list' => ['4']]))
            ->toBe(['entry-four']);
    });
});

describe('what reaches the entry from the export', function (): void {
    it('omits a category or tag with a blank nicename', function (): void {
        postEntryRun(postEntryExport([['slug' => 'blank', 'categories' => ['', 'research'], 'tags' => ['']]]), PostEntryTestConverter::returning(postEntryBody()));
        $entry = postEntry('blank');

        expect($entry->get('post_categories'))->toBe([TermDestinationRegistry::referenceFor('category', '11')])
            ->and($entry->get('post_tags'))->toBe([]);
    });

    it('treats a post with no sticky flag as not sticky', function (): void {
        postEntryRun(postEntryExport([['slug' => 'unflagged', 'sticky' => 'absent']]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntry('unflagged')->get('sticky_query'))->toBeFalse();
    });

    it('imports tags when tag import is not configured', function (): void {
        $settings = config('wordpress-import');
        config(['wordpress-import' => \array_diff_key(\is_array($settings) ? $settings : [], ['import_tags' => true])]);

        postEntryRun(postEntryExport([['slug' => 'tagged', 'tags' => ['grants']]]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntry('tagged')->get('post_tags'))->toBe([TermDestinationRegistry::referenceFor('post_tag', '21')])
            ->and(PostEntryCreator::getTaxonomyHandlesForPostImport())->toBe(['category', 'post_tag']);
    });

    it('records the WordPress post id the redirect generator reads', function (): void {
        postEntryRun(postEntryExport([['id' => '301', 'slug' => 'redirected']]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntry('redirected')->get('wordpress_post_id'))->toBe(301);
    });

    it('stores no thumbnail id when the recorded one is blank', function (): void {
        postEntryRun(postEntryExport([['slug' => 'blank-thumb', 'meta' => ['_thumbnail_id' => '']]]), PostEntryTestConverter::returning(postEntryBody()));

        expect(postEntryData(postEntry('blank-thumb')))->not->toHaveKeys(['_thumbnail_id', 'featured_image_16_9']);
    });

    it('stores no featured image when the upload returns an empty path', function (): void {
        $importer = new class extends AssetImporter
        {
            /**
             * Report image uploads as on.
             *
             * @return bool Always true
             */
            public function getUploadImages(): bool
            {
                return true;
            }

            /**
             * Report file uploads as on.
             *
             * @return bool Always true
             */
            public function getUploadFiles(): bool
            {
                return true;
            }

            /**
             * Return the empty path an overwrite handler can give back.
             *
             * @param  string  $url  The file URL
             * @param  string  $site  The site handle
             * @param  OutputInterface  $output  Console output
             * @param  string  $container  The container handle
             * @param  SameSitePolicy  $sameSitePolicy  The same-site policy
             * @param  ?string  $pageUrl  The page URL
             * @param  ?string  $title  The title
             * @param  ?array<int|string, mixed>  $additionalFileMetadata  Extra metadata
             * @param  ?string  $attachmentPageUrl  The attachment page URL
             * @return string Always empty
             */
            public function importFile(
                string $url,
                string $site,
                OutputInterface $output,
                string $container = 'files',
                SameSitePolicy $sameSitePolicy = SameSitePolicy::SKIP_DIFFERENT_SITE,
                ?string $pageUrl = null,
                ?string $title = null,
                ?array $additionalFileMetadata = null,
                ?string $attachmentPageUrl = null
            ): string {
                return '';
            }
        };

        postEntryRun(postEntryExport([['slug' => 'empty-path', 'meta' => ['_thumbnail_id' => '901']]]), PostEntryTestConverter::returning(postEntryBody()), assetImporter: $importer);
        $data = postEntryData(postEntry('empty-path'));

        expect($data['_thumbnail_id'] ?? null)->toBe('901')
            ->and($data)->not->toHaveKeys(['featured_image_16_9', 'featured_image_credit_query']);
    });

    it('registers the post\'s URL for link resolution', function (): void {
        postEntryRun(postEntryExport([['id' => '301', 'slug' => 'linked']]), PostEntryTestConverter::returning(postEntryBody()));
        $map = (new ReflectionProperty(LinkProcessor::class, 'urlToPostIdMap'))->getValue();

        expect(\is_array($map) ? ($map['https://example.test/news/301/'] ?? null) : null)->toBe('301');
    });

    it('reports no page data for a post that fails to save', function (): void {
        $creator = new class extends PostEntryCreator
        {
            /**
             * Fail every save.
             *
             * @param  string  $collectionHandle  The collection handle
             * @param  string  $site  The site handle
             * @param  string  $entrySlug  The entry slug
             * @param  array<int|string, mixed>  $data  The entry data
             * @param  OutputInterface  $output  Console output
             * @param  ?DateTime  $entryDate  The entry date
             * @param  ?bool  $published  Whether the entry is published
             * @param  ?string  $postDateStr  The post date
             * @return ?Entry Always null
             */
            protected function saveEntry(
                string $collectionHandle,
                string $site,
                string $entrySlug,
                array $data,
                OutputInterface $output,
                ?DateTime $entryDate = null,
                ?bool $published = null,
                ?string $postDateStr = null
            ): ?Entry {
                return null;
            }
        };

        $run = postEntryRun(postEntryExport([['id' => '301', 'slug' => 'unsaved']]), PostEntryTestConverter::returning(postEntryBody()), $creator);

        expect($run['result'])->toBe([]);
    });
});

describe('creating flat terms', function (): void {
    it('hands a taxonomy it does not rename to the base class unchanged', function (string $handle, string $expected): void {
        $errors = new ArrayObject;
        Log::shouldReceive('error')->andReturnUsing(static function (mixed $message) use ($errors): void {
            $errors->append($message);
        });
        $method = new ReflectionMethod(PostEntryCreator::class, 'createTaxonomyTermsFromStoredData');

        $method->invoke(new PostEntryCreator, [['original' => 'term', 'name' => 'Term']], $handle, 'default', new BufferedOutput);

        expect($errors->getArrayCopy())->toBe(["BaseEntryCreator::createTaxonomyTermsFromStoredData - Taxonomy '{$expected}' does not exist"]);
    })->with([
        'an unmapped taxonomy' => ['flat_terms', 'flat_terms'],
    ]);
});
