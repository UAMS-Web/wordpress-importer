<?php

declare(strict_types=1);

/**
 * Tests `PeopleEntryCreator`: what each `uamspeople` or `tshowcase` item becomes,
 * from its people groups and postmeta fields to its portrait, video, bio and the
 * people-group records it keeps for validation.
 *
 * The term pass runs for real against a `people_groups` collection this test
 * makes, so a person's groups resolve to the entries that pass writes. The bio
 * converter and the metadata mapper are recording subclasses, image uploads are
 * off, and the phone fieldtype is the host application's, so a recording one is
 * supplied through the creator's seam.
 *
 * @command  php vendor/bin/pest tests/Integration/PeopleEntryCreatorTest.php
 */

use App\Fieldtypes\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PeopleEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\CompilesCleanly;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

// The host application's phone fieldtype; standalone, its IDE stub stands in
if (! class_exists(Phone::class)) {
    require_once __DIR__.'/../Support/IdeStubs/App/Fieldtypes/Phone.php';
}

/**
 * A phone fieldtype that records each call and returns a marked number.
 */
final class PeopleEntryTestPhone extends Phone
{
    /**
     * The number and configuration of each call.
     *
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    public static array $calls = [];

    /**
     * Record the call and return the number with a mark.
     *
     * @param  string  $phoneNumber  The number
     * @param  array<string, mixed>  $config  The fieldtype configuration
     * @return string The marked number
     */
    public function normalizeValue(
        string $phoneNumber,
        array $config
    ): string {
        self::$calls[] = [$phoneNumber, $config];

        return "normalized:{$phoneNumber}";
    }
}

/**
 * A people creator whose phone fieldtype is the recording one.
 *
 * Built as an anonymous class, so the creator is first loaded inside a test, where
 * the application's error handler turns a compile warning into a failure, rather
 * than while Pest collects this file.
 *
 * @param  string  $postType  The post type
 * @return PeopleEntryCreator The creator
 */
function peopleEntryTestCreator(string $postType = 'uamspeople'): PeopleEntryCreator
{
    return new class($postType) extends PeopleEntryCreator
    {
        /**
         * The recording phone fieldtype.
         *
         * @return Phone The fieldtype
         */
        protected function phoneFieldtype(): Phone
        {
            return new PeopleEntryTestPhone;
        }
    };
}

/**
 * A bio converter that records each call and returns a chosen body.
 */
final class PeopleEntryTestConverter extends ContentProcessor
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
            'portrait' => $data['portrait'] ?? null,
        ];

        return $this->result;
    }
}

/**
 * A metadata mapper that records each person and marks the data it maps.
 */
final class PeopleEntryTestMapper extends MetadataMapper
{
    /**
     * The post id of each person mapped.
     *
     * @var list<string>
     */
    public array $mapped = [];

    /**
     * Record the person and mark the data.
     *
     * @param  SimpleXMLElement  $page  The person
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
 * A person's string field, or the default when it is absent.
 *
 * @param  array<mixed>  $person  The person's fields
 * @param  string  $key  The field
 * @param  string  $default  The default
 * @return string The value
 */
function peopleEntryField(array $person, string $key, string $default): string
{
    $value = $person[$key] ?? $default;

    return \is_string($value) ? $value : $default;
}

/**
 * A person's list or map of strings, or none when it is absent.
 *
 * @param  array<mixed>  $person  The person's fields
 * @param  string  $key  The field
 * @return array<string> The values
 */
function peopleEntryFields(array $person, string $key): array
{
    $values = $person[$key] ?? [];

    return \is_array($values) ? \array_filter($values, \is_string(...)) : [];
}

/**
 * Write an export of people groups, attachments and the given people.
 *
 * @param  list<array<mixed>>  $people  Each person's fields
 * @param  string  $postType  The post type, `uamspeople` or `tshowcase`
 * @return string The export path
 */
function peopleEntryExport(array $people, string $postType = 'uamspeople'): string
{
    $taxonomy = "{$postType}-categories";
    $items = '';

    foreach ($people as $index => $person) {
        $postId = peopleEntryField($person, 'id', (string) (500 + $index));
        $slug = peopleEntryField($person, 'slug', "person-{$postId}");
        $title = peopleEntryField($person, 'title', "Person {$postId}");
        $status = peopleEntryField($person, 'status', 'publish');
        $content = peopleEntryField($person, 'content', '<p>Bio.</p>');
        $date = peopleEntryField($person, 'date', '2025-04-01 09:00:00');
        $groups = '';

        foreach (peopleEntryFields($person, 'groups') as $nicename => $name) {
            $groups .= "<category domain=\"{$taxonomy}\" nicename=\"{$nicename}\"><![CDATA[{$name}]]></category>";
        }

        $meta = '';

        foreach (peopleEntryFields($person, 'meta') as $key => $value) {
            $meta .= "<wp:postmeta><wp:meta_key>{$key}</wp:meta_key><wp:meta_value><![CDATA[{$value}]]></wp:meta_value></wp:postmeta>";
        }

        $items .= <<<XML
<item>
  <title><![CDATA[{$title}]]></title>
  <link>https://example.test/people/{$postId}/</link>
  <content:encoded><![CDATA[{$content}]]></content:encoded>
  <wp:post_id>{$postId}</wp:post_id>
  <wp:post_name>{$slug}</wp:post_name>
  <wp:post_date>{$date}</wp:post_date>
  <wp:post_modified>2025-04-02 10:00:00</wp:post_modified>
  <wp:status>{$status}</wp:status>
  <wp:post_parent>0</wp:post_parent>
  <wp:menu_order>0</wp:menu_order>
  <wp:post_type>{$postType}</wp:post_type>
  {$groups}
  {$meta}
</item>

XML;
    }

    $path = TemporaryFileRegistry::create('people-entry');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>People</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
<wp:term><wp:term_id>61</wp:term_id><wp:term_taxonomy>{$taxonomy}</wp:term_taxonomy><wp:term_slug>faculty</wp:term_slug><wp:term_name><![CDATA[Faculty]]></wp:term_name></wp:term>
<wp:term><wp:term_id>62</wp:term_id><wp:term_taxonomy>{$taxonomy}</wp:term_taxonomy><wp:term_slug>surgery</wp:term_slug><wp:term_parent>faculty</wp:term_parent><wp:term_name><![CDATA[Surgery]]></wp:term_name></wp:term>
<item>
  <title>portrait</title>
  <link>https://example.test/people/portrait/</link>
  <wp:post_id>900</wp:post_id>
  <wp:post_type>attachment</wp:post_type>
  <wp:attachment_url>https://example.test/wp-content/uploads/2025/04/portrait.jpg</wp:attachment_url>
</item>
{$items}</channel>
</rss>
XML);

    return $path;
}

/**
 * An asset importer with uploads on, which records each import and returns a chosen path.
 */
final class PeopleEntryTestImporter extends AssetImporter
{
    /**
     * The URL, site and container of each import.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public array $imports = [];

    /**
     * Build the importer.
     *
     * @param  string  $path  What every import returns
     */
    public function __construct(
        private readonly string $path
    ) {
        parent::__construct();
    }

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
     * Record the import and return the chosen path.
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
     * @return string The chosen path
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
        $this->imports[] = [$url, $site, $container];

        return $this->path;
    }
}

/**
 * Run the creator over an export.
 *
 * @param  string  $export  The export path
 * @param  PeopleEntryTestConverter  $converter  The bio converter
 * @param  ?PeopleEntryCreator  $creator  The creator, or null for a new recording one
 * @param  ?AssetImporter  $assetImporter  The asset importer, or null for a new one
 * @return array{output: string, result: array<int|string, mixed>, mapper: PeopleEntryTestMapper, creator: PeopleEntryCreator} What the run produced
 */
function peopleEntryRun(string $export, PeopleEntryTestConverter $converter, ?PeopleEntryCreator $creator = null, ?AssetImporter $assetImporter = null): array
{
    $parser = new XmlParser;
    $parser->parse($export);
    $output = new BufferedOutput;
    $creator ??= peopleEntryTestCreator();
    $mapper = new PeopleEntryTestMapper;

    $result = $creator->createEntries('default', 'people', $parser, $assetImporter ?? new AssetImporter, $output, $mapper, $converter);

    return ['output' => $output->fetch(), 'result' => $result, 'mapper' => $mapper, 'creator' => $creator];
}

/**
 * The saved person with the given slug.
 *
 * @param  string  $slug  The slug
 * @return Entry The entry
 */
function peopleEntry(string $slug): Entry
{
    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'people' && $entry->slug() === $slug) {
            return $entry;
        }
    }

    throw new RuntimeException("No people entry '{$slug}'.");
}

/**
 * Everything an entry stores.
 *
 * @param  Entry  $entry  The entry
 * @return array<mixed> The entry's data
 */
function peopleEntryData(Entry $entry): array
{
    $data = $entry->data();

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * A bio converter result with the given Bard content.
 *
 * @param  mixed  $content  The content
 * @return array{content: mixed, page_builder: array<int|string, mixed>} The result
 */
function peopleEntryBody(mixed $content = null): array
{
    return [
        'content' => $content ?? [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bio.']]]],
        'page_builder' => [],
    ];
}

beforeEach(function (): void {
    CompilesCleanly::assert(PeopleEntryCreator::class);
    config([
        'statamic.search.indexes' => [],
        'wordpress-import.upload_images' => false,
        'wordpress-import.upload_files' => false,
    ]);
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    $people = CollectionFacade::make('people');
    $people->sites(['default']);
    $people->save();

    $groups = CollectionFacade::make('people_groups');
    $groups->sites(['default']);
    $groups->structureContents(['root' => false]);
    $groups->save();

    $blueprint = BlueprintFacade::make('person');
    $blueprint->setNamespace('collections.people');
    $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
    $blueprint->save();

    PeopleEntryTestPhone::$calls = [];
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

    $blueprint = BlueprintFacade::find('collections.people.person');

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }

    TemporaryFileRegistry::sweep();
    Mockery::close();
});

/**
 * The fields every person in the main test carries.
 *
 * @return array<string, string> The postmeta
 */
function peopleEntryMeta(): array
{
    return [
        '_pssorttext' => ' Doe ',
        '_psposition' => ' Professor ',
        '_psemail' => 'pat@example.test',
        '_pstel' => '501-555-0100',
        '_psfax' => '501-555-0101',
        '_pslocation' => 'Room 1',
        '_pspersonal' => 'https://example.test/clinical',
        '_pspersonalanchor' => '',
        '_psdeptweb' => 'https://example.test/academic',
        '_psdeptwebanchor' => '',
        '_pstriweb' => 'https://example.test/research',
        '_pstriwebanchor' => '',
        '_pstwitter' => 'https://x.test/pat',
        '_psyoutube' => 'https://youtube.test/v',
        '_psvimeo' => 'https://vimeo.test/v',
        '_pssortpriority' => '7',
        '_thumbnail_id' => '900',
    ];
}

describe('importing a person', function (): void {
    it('saves a person with every postmeta field, its groups, portrait, video and bio', function (): void {
        $converter = PeopleEntryTestConverter::returning(peopleEntryBody());
        $run = peopleEntryRun(peopleEntryExport([[
            'id' => '501',
            'slug' => 'pat-doe',
            'title' => 'Pat &amp; Doe',
            'groups' => ['faculty' => 'Faculty', 'surgery' => 'Surgery', 'unknown' => 'Unknown'],
            'meta' => peopleEntryMeta(),
        ]]), $converter);
        $entry = peopleEntry('pat-doe');
        $data = peopleEntryData($entry);
        $jobTitle = $data['job_title'] ?? null;
        unset($data['job_title']);

        expect($data)->toBe([
            'blueprint' => 'person',
            'title' => 'Pat & Doe',
            'slug' => 'pat-doe',
            'sort_text_alpha' => 'Doe',
            'people_groups' => [
                TermDestinationRegistry::referenceFor('uamspeople-categories', '61'),
                TermDestinationRegistry::referenceFor('uamspeople-categories', '62'),
                'default_unknown',
            ],
            'priority' => 7,
            'bio' => peopleEntryBody()['content'],
            'bio_short' => null,
            'email' => 'pat@example.test',
            'telephone' => 'normalized:501-555-0100',
            'fax' => 'normalized:501-555-0101',
            'location' => 'Room 1',
            'clinical_profile_url' => 'https://example.test/clinical',
            'clinical_profile_link_text' => 'UAMS Health Provider Profile',
            'academic_profile_url' => 'https://example.test/academic',
            'academic_profile_link_text' => 'UAMS Academic Profile',
            'research_profile_url' => 'https://example.test/research',
            'research_profile_link_text' => 'UAMS Research Profile',
            'twitter' => 'https://x.test/pat',
            'institutional_video_url' => 'https://youtube.test/v',
            'institutional_video_link_text' => 'Video Referencing Pat & Doe',
            'portrait' => 'portrait.jpg',
            'original_wordpress_url' => 'https://example.test/people/501/',
            'date' => '2025-04-01 09:00:00',
            '_thumbnail_id' => '900',
            'import_wordpress_notes' => 'Video imported to the Institutional context; WordPress recorded no academic, clinical, '
                .'institutional or research context for it. A second video was not imported, because the blueprint carries '
                .'one URL per context: https://vimeo.test/v',
            'mapped' => true,
            'updated_at' => (new DateTime('2025-04-02 10:00:00'))->getTimestamp(),
        ])
            ->and($entry->id())->toStartWith('pat-doe-501-')
            ->and(\is_array($jobTitle) ? \array_map(static fn (mixed $set): mixed => \is_array($set) ? \array_diff_key($set, ['id' => true]) : $set, $jobTitle) : $jobTitle)
            ->toBe([['title' => 'Professor', 'type' => 'free_text', 'enabled' => true]])
            ->and(PeopleEntryTestPhone::$calls)->toBe([
                ['501-555-0100', ['preset' => 'tel-national', 'country' => 'US']],
                ['501-555-0101', ['preset' => 'tel-national', 'country' => 'US']],
            ])
            ->and($converter->calls)->toBe([[
                'content' => '<p>Bio.</p>',
                'site' => 'default',
                'title' => 'Pat & Doe',
                'id' => '501',
                'link' => 'https://example.test/people/501/',
                'attachments' => 1,
                'terms' => 2,
                'items' => [],
                'collection' => 'people',
                'slug' => 'pat-doe',
                'entry' => $entry->id(),
                'portrait' => 'portrait.jpg',
            ]])
            ->and($run['mapper']->mapped)->toBe(['501'])
            ->and($run['output'])->toContain("Processing person 'pat-doe' with content length: 11".PHP_EOL)
            ->and(ProcessingContext::getWordPressPageLink())->toBe('https://example.test/people/501/')
            ->and($run['result'])->toHaveKey('501.title', 'Pat & Doe');
    });

    it('reads a tshowcase person through its own prefix and taxonomy', function (): void {
        $run = peopleEntryRun(peopleEntryExport([[
            'slug' => 'showcase',
            'groups' => ['faculty' => 'Faculty'],
            'meta' => ['_tsemail' => 'show@example.test', '_psemail' => 'ignored@example.test'],
        ]], 'tshowcase'), PeopleEntryTestConverter::returning(peopleEntryBody()), peopleEntryTestCreator('tshowcase'));
        $data = peopleEntryData(peopleEntry('showcase'));

        expect($data['email'] ?? null)->toBe('show@example.test')
            ->and($data['people_groups'] ?? null)->toBe([TermDestinationRegistry::referenceFor('tshowcase-categories', '61')])
            ->and($run['output'])->toContain("Processing 'tshowcase-categories' taxonomy terms");
    });

    it('ignores postmeta of the other post type\'s prefix and unknown fields', function (): void {
        peopleEntryRun(peopleEntryExport([['slug' => 'plain', 'meta' => ['_tsemail' => 'show@example.test', '_psunknown' => 'x', 'email' => 'bare']]]), PeopleEntryTestConverter::returning(peopleEntryBody()));
        $data = peopleEntryData(peopleEntry('plain'));

        expect($data)->toHaveKey('email')
            ->and($data['email'])->toBeNull()
            ->and($data)->not->toHaveKeys(['unknown', '_psunknown', '_thumbnail_id', 'import_wordpress_notes']);
    });

    it('defaults every field a person blueprint expects', function (): void {
        peopleEntryRun(peopleEntryExport([['slug' => 'bare', 'content' => '']]), PeopleEntryTestConverter::returning(['content' => [], 'page_builder' => []]));
        $data = peopleEntryData(peopleEntry('bare'));

        $expected = [
            'sort_text_alpha' => null,
            'job_title' => null,
            'people_groups' => [],
            'priority' => 0,
            // #257: a bio that converts to nothing is stored as an empty list
            'bio' => [],
            'bio_short' => null,
            'email' => null,
            'telephone' => null,
            'fax' => null,
            'location' => null,
            'clinical_profile_url' => null,
            'clinical_profile_link_text' => null,
            'academic_profile_url' => null,
            'academic_profile_link_text' => null,
            'research_profile_url' => null,
            'research_profile_link_text' => null,
            'twitter' => null,
            'institutional_video_url' => null,
            'institutional_video_link_text' => null,
            'portrait' => null,
        ];

        // toMatchArray() compares loosely, which would let '' or false stand for null
        expect(\array_intersect_key($data, $expected))->toBe($expected)
            ->and(PeopleEntryTestPhone::$calls)->toBe([]);
    });

    it('keeps the anchor text a person gives, stripped of markup', function (): void {
        peopleEntryRun(peopleEntryExport([['slug' => 'anchored', 'meta' => [
            '_pspersonalanchor' => 'Book a visit',
            '_psdeptwebanchor' => '<b>Department</b>',
            '_pstriwebanchor' => '<i>Lab</i>',
        ]]]), PeopleEntryTestConverter::returning(peopleEntryBody()));
        $data = peopleEntryData(peopleEntry('anchored'));

        expect($data['clinical_profile_link_text'] ?? null)->toBe('Book a visit')
            ->and($data['academic_profile_link_text'] ?? null)->toBe('Department')
            ->and($data['research_profile_link_text'] ?? null)->toBe('Lab');
    });

    it('omits the job title when the position is blank', function (): void {
        peopleEntryRun(peopleEntryExport([['slug' => 'untitled-role', 'meta' => ['_psposition' => '  ']]]), PeopleEntryTestConverter::returning(peopleEntryBody()));

        expect(peopleEntry('untitled-role')->get('job_title'))->toBeNull();
    });

    it('lands a Vimeo URL when there is no YouTube one, and ignores blank video keys', function (array $meta, ?string $expected): void {
        peopleEntryRun(peopleEntryExport([['slug' => 'video', 'meta' => $meta]]), PeopleEntryTestConverter::returning(peopleEntryBody()));

        $entry = peopleEntry('video');

        expect($entry->get('institutional_video_url'))->toBe($expected)
            ->and($entry->get('import_wordpress_notes'))->toBe($expected === null ? null : 'Video imported to the Institutional context; WordPress recorded no '
                .'academic, clinical, institutional or research context for it.');
    })->with([
        'only Vimeo' => [['_psvimeo' => 'https://vimeo.test/v'], 'https://vimeo.test/v'],
        'a blank YouTube beside Vimeo' => [['_psyoutube' => '', '_psvimeo' => 'https://vimeo.test/v'], 'https://vimeo.test/v'],
        'a blank Vimeo beside YouTube' => [['_psyoutube' => 'https://youtube.test/v', '_psvimeo' => ''], 'https://youtube.test/v'],
        'both blank' => [['_psyoutube' => '', '_psvimeo' => ''], null],
    ]);

    it('converts a non-blank free HTML field into the short bio', function (): void {
        peopleEntryRun(peopleEntryExport([['id' => '505', 'slug' => 'short-bio', 'meta' => ['_psfreehtml' => 'Hello there.']]]), PeopleEntryTestConverter::returning(peopleEntryBody()));
        $entry = peopleEntry('short-bio');

        expect($entry->get('bio_short'))->toBe([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello there.']], 'attrs' => []]])
            ->and(ProcessingContext::getCollectionHandle())->toBe('people')
            ->and(ProcessingContext::getStatamicEntryId())->toBe($entry->id());
    });

    it('stores no short bio for a blank free HTML field, or one that converts to nothing', function (string $html): void {
        peopleEntryRun(peopleEntryExport([['slug' => 'no-short-bio', 'meta' => ['_psfreehtml' => $html]]]), PeopleEntryTestConverter::returning(peopleEntryBody()));

        expect(peopleEntry('no-short-bio')->get('bio_short'))->toBeNull();
    })->with(['blank' => [''], 'nothing to convert' => ['<p></p>']]);

    it('stores no portrait for a thumbnail with no attachment', function (): void {
        peopleEntryRun(peopleEntryExport([['slug' => 'faceless', 'meta' => ['_thumbnail_id' => '999']]]), PeopleEntryTestConverter::returning(peopleEntryBody()));
        $data = peopleEntryData(peopleEntry('faceless'));

        expect($data['_thumbnail_id'] ?? null)->toBe('999')
            ->and($data)->toHaveKey('portrait')
            ->and($data['portrait'])->toBeNull();
    });
});

describe('naming a person', function (): void {
    it('falls back to a slug from the title, then from the post id', function (string $title, string $expectedSlug, string $expectedTitle): void {
        $converter = PeopleEntryTestConverter::returning(peopleEntryBody());
        $run = peopleEntryRun(peopleEntryExport([['id' => '507', 'slug' => '', 'title' => $title]]), $converter);
        $entry = peopleEntry($expectedSlug);

        expect($entry->get('title'))->toBe($expectedTitle)
            ->and($converter->calls[0]['title'] ?? null)->toBe($expectedTitle)
            ->and($run['result'])->toHaveKey('507.title', $expectedTitle);
    })->with([
        'from the title' => ['Dr. Jamie Fox', 'dr-jamie-fox', 'Dr. Jamie Fox'],
        'from the post id' => ['', 'person-507', 'Untitled Person'],
    ]);
});

describe('people groups', function (): void {
    it('records each group once, however many people carry it', function (): void {
        $run = peopleEntryRun(peopleEntryExport([
            ['slug' => 'first', 'groups' => ['faculty' => 'Faculty']],
            ['slug' => 'second', 'groups' => ['faculty' => 'Faculty', 'surgery' => 'Surgery']],
        ]), PeopleEntryTestConverter::returning(peopleEntryBody()));

        expect($run['creator']->getSiteSpecificPeopleGroupSlugs())->toBe(['default_faculty', 'default_faculty_surgery'])
            ->and($run['creator']->getPeopleGroups())->toBe([
                'faculty' => [['page_slug' => 'people-entries', 'original' => 'faculty', 'name' => 'Faculty', 'site_specific' => 'default_faculty']],
                'faculty_surgery' => [['page_slug' => 'people-entries', 'original' => 'faculty_surgery', 'name' => 'Surgery', 'site_specific' => 'default_faculty_surgery']],
            ]);
    });

    it('falls back to the slug for a group with a blank name, and skips a blank site slug', function (): void {
        $creator = new PeopleEntryCreator;
        (new ReflectionProperty(PeopleEntryCreator::class, 'siteSpecificPeopleGroups'))->setValue($creator, [
            ['site_specific' => 'default_alpha', 'original' => '', 'name' => ''],
            ['site_specific' => '', 'original' => 'beta', 'name' => 'Beta'],
            ['site_specific' => 'default_alpha', 'original' => 'alpha', 'name' => ''],
            ['site_specific' => 'other_alpha', 'original' => 'alpha', 'name' => 'Alpha'],
        ]);

        expect($creator->getPeopleGroups())->toBe([
            'default_alpha' => [['page_slug' => 'people-entries', 'original' => 'default_alpha', 'name' => 'default_alpha', 'site_specific' => 'default_alpha']],
            'beta' => [['page_slug' => 'people-entries', 'original' => 'beta', 'name' => 'Beta', 'site_specific' => '']],
            'alpha' => [
                ['page_slug' => 'people-entries', 'original' => 'alpha', 'name' => 'alpha', 'site_specific' => 'default_alpha'],
                ['page_slug' => 'people-entries', 'original' => 'alpha', 'name' => 'Alpha', 'site_specific' => 'other_alpha'],
            ],
        ])
            ->and(\array_values($creator->getSiteSpecificPeopleGroupSlugs()))->toBe(['default_alpha', 'other_alpha']);
    });
});

describe('the content type\'s other answers', function (): void {
    it('has no people, post categories or post tags', function (): void {
        $creator = new PeopleEntryCreator;

        expect($creator->hasShowPeople())->toBeFalse()
            ->and($creator->getPostCategories())->toBe([])
            ->and($creator->getPostTags())->toBe([]);
    });

    it('reports no page data for a person that fails to save', function (): void {
        $creator = new class extends PeopleEntryCreator
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

        expect(peopleEntryRun(peopleEntryExport([['slug' => 'unsaved']]), PeopleEntryTestConverter::returning(peopleEntryBody()), $creator)['result'])->toBe([]);
    });
});

describe('validating people groups', function (): void {
    it('hands the validator the groups the import read, and nothing when it read none', function (): void {
        $run = peopleEntryRun(peopleEntryExport([['slug' => 'grouped', 'groups' => ['faculty' => 'Faculty']]]), PeopleEntryTestConverter::returning(peopleEntryBody()));
        $output = new BufferedOutput;
        $run['creator']->validateTaxonomies('people', 'default', $output);
        $empty = new BufferedOutput;
        (new PeopleEntryCreator)->validateTaxonomies('people', 'default', $empty);

        // This suite writes no files, and the validator reads the collection from disk
        expect($output->fetch())->toContain('people entries')
            ->toContain("Warning: cannot create 'default_faculty'")
            ->and($empty->fetch())->toContain('No people entries found that reference')
            ->not->toContain('Used in: People');
    });
});

describe('creating flat terms', function (): void {
    it('sends both people taxonomies to people_groups, and any other handle through unchanged', function (string $handle, array $expected): void {
        $errors = new ArrayObject;
        Log::shouldReceive('error')->andReturnUsing(static function (mixed $message) use ($errors): void {
            $errors->append($message);
        });
        $method = new ReflectionMethod(PeopleEntryCreator::class, 'createTaxonomyTermsFromStoredData');

        $method->invoke(new PeopleEntryCreator, [['original' => 'term', 'name' => 'Term']], $handle, 'default', new BufferedOutput);

        expect($errors->getArrayCopy())->toBe($expected);
    })->with([
        // people_groups is a structured destination, which the base class leaves to the term pass
        'uamspeople' => ['uamspeople-categories', []],
        'tshowcase' => ['tshowcase-categories', []],
        'another handle' => ['flat_terms', ["BaseEntryCreator::createTaxonomyTermsFromStoredData - Taxonomy 'flat_terms' does not exist"]],
    ]);
});

describe('what else reaches the entry', function (): void {
    it('decodes the title twice, so a double-encoded entity comes out as its character', function (): void {
        peopleEntryRun(peopleEntryExport([['slug' => 'quoted', 'title' => 'Say &AMP;quot;hi&AMP;quot;']]), PeopleEntryTestConverter::returning(peopleEntryBody()));

        expect(peopleEntry('quoted')->get('title'))->toBe('Say "hi"');
    });

    it('stores no date string for a scheduled person, whose entry carries its date', function (): void {
        $blueprint = BlueprintFacade::find('collections.people.person');

        if (! $blueprint instanceof Blueprint) {
            throw new RuntimeException('No person blueprint.');
        }

        $blueprint->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'status_and_visibility', 'field' => ['type' => 'text']],
        ]]);
        $blueprint->save();

        peopleEntryRun(peopleEntryExport([['slug' => 'scheduled', 'status' => 'future', 'date' => '2099-01-01 09:00:00']]), PeopleEntryTestConverter::returning(peopleEntryBody()));

        expect(peopleEntryData(peopleEntry('scheduled')))->not->toHaveKey('date');
    });

    it('imports the portrait to the root of the portraits container, keeping only its file name', function (string $path, ?string $expected): void {
        $importer = new PeopleEntryTestImporter($path);

        peopleEntryRun(peopleEntryExport([['slug' => 'pictured', 'meta' => ['_thumbnail_id' => '900']]]), PeopleEntryTestConverter::returning(peopleEntryBody()), assetImporter: $importer);

        expect($importer->imports)->toBe([['https://example.test/wp-content/uploads/2025/04/portrait.jpg', '', 'portraits']])
            ->and(peopleEntryData(peopleEntry('pictured')))->toHaveKey('portrait')
            ->and(peopleEntryData(peopleEntry('pictured'))['portrait'])->toBe($expected);
    })->with([
        'a path' => ['2025/04/portrait-1.jpg', 'portrait-1.jpg'],
        'an empty path' => ['', null],
    ]);

    it('keeps two records for a group whose names differ only as numbers', function (): void {
        $run = peopleEntryRun(peopleEntryExport([
            ['slug' => 'one', 'groups' => ['numbered' => '10']],
            ['slug' => 'two', 'groups' => ['numbered' => '1e1']],
        ]), PeopleEntryTestConverter::returning(peopleEntryBody()));

        expect($run['creator']->getPeopleGroups())->toBe(['numbered' => [
            ['page_slug' => 'people-entries', 'original' => 'numbered', 'name' => '10', 'site_specific' => 'default_numbered'],
            ['page_slug' => 'people-entries', 'original' => 'numbered', 'name' => '1e1', 'site_specific' => 'default_numbered'],
        ]]);
    });

});

describe('landing a person video', function (): void {
    it('names an untitled person in the link text', function (array $data): void {
        (new PeopleEntryCreator)->applyPersonVideo($data, 'https://youtube.test/v', null);

        expect($data['institutional_video_link_text'] ?? null)->toBe('Video Referencing Untitled Person');
    })->with([
        'no title' => [[]],
        'a blank title' => [['title' => '']],
    ]);

    it('extends a note already recorded, and replaces a blank one', function (string $existing, string $expected): void {
        $data = ['title' => 'Pat', 'import_wordpress_notes' => $existing];

        (new PeopleEntryCreator)->applyPersonVideo($data, null, 'https://vimeo.test/v');

        expect($data['import_wordpress_notes'] ?? null)->toBe($expected);
    })->with([
        'an earlier note' => ['Earlier.', 'Earlier. Video imported to the Institutional context; WordPress recorded no academic, clinical, institutional or research context for it.'],
        'a blank note' => ['', 'Video imported to the Institutional context; WordPress recorded no academic, clinical, institutional or research context for it.'],
    ]);
});

describe('the class itself', function (): void {
    it('compiles without a warning, which the host application would raise as an exception', function (): void {
        // ---
        // The class is already loaded here, so its source is compiled again under a new
        // name with warnings thrown. A compile warning, such as `continue` targeting a
        // switch, fails this test, where in the host it would abort the import. A
        // mutation run does not select this test, since the copy covers none of the
        // class's own lines; there the import tests fail instead, because each first
        // loads the class under the application's error handler.
        // ---
        $file = (new ReflectionClass(PeopleEntryCreator::class))->getFileName();
        $source = \is_string($file) ? \file_get_contents($file) : false;

        if (! \is_string($source)) {
            throw new RuntimeException('Could not read the class source.');
        }

        $name = 'PeopleEntryCreatorCompileCheck'.\bin2hex(\random_bytes(4));
        $copy = TemporaryFileRegistry::create('people-compile');
        \file_put_contents($copy, \str_replace('class PeopleEntryCreator extends', "class {$name} extends", $source));

        \set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            require $copy;
        } finally {
            \restore_error_handler();
        }

        expect(\class_exists('UAMSWeb\\WordPressImporter\\Services\\WordPressImport\\Entries\\Creators\\'.$name, false))->toBeTrue();
    });
});
