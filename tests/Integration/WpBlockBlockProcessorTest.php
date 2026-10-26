<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpBlockBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpBlockRegistry;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for WpBlockBlockProcessor, which turns a `wp:block` (reusable block) reference into a `reusable_content` set.
 *
 * A reference whose wp_block post is already imported becomes a set pointing at the
 * entry. One that is not yet imported becomes a placeholder set carrying the post id
 * in `import_wordpress_notes`, is remembered as deferred against the entry being
 * imported, and is resolved later by updateBardSetsWithEntryIds(), which rewrites
 * the placeholders in every `pages`, `post` and `people` entry and saves the ones it
 * changed. Saves reach this process's own content directory through
 * `PreventsSavingStacheItemsToDisk`.
 *
 * @command  php vendor/bin/pest tests/Integration/WpBlockBlockProcessorTest.php
 */
uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

beforeEach(function (): void {
    WpBlockRegistry::clear();
    WpBlockBlockProcessor::clearWpBlockPostIds();
    WpBlockBlockProcessor::clearDeferredWpBlockReferences();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
    ProcessingContext::clearStatamicEntryId();
    ProcessingContext::clearCollectionHandle();
});

afterEach(function (): void {
    WpBlockRegistry::clear();
    WpBlockBlockProcessor::clearWpBlockPostIds();
    WpBlockBlockProcessor::clearDeferredWpBlockReferences();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearStatamicEntryId();
    ProcessingContext::clearCollectionHandle();
});

/**
 * Run the processor over a wp:block comment.
 *
 * @param  array<int|string, mixed>  $attributes  The block's attributes
 * @param  ?string  $pageSlug  The page slug
 * @return array<int|string, mixed>|string|null The processor's result
 */
function processWpBlock(array $attributes, ?string $pageSlug = null): array|string|null
{
    return (new WpBlockBlockProcessor)->process(
        new DOMComment(' wp:block {"ref":1} /'),
        $attributes,
        'default',
        (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor(),
        new BufferedOutput,
        [],
        [],
        $pageSlug
    );
}

/**
 * A Bard reusable_content placeholder set.
 *
 * @param  mixed  $notes  The import_wordpress_notes value
 * @return array<string, mixed> The set node
 */
function wpBlockBardPlaceholder(mixed $notes): array
{
    return [
        'type' => 'set',
        'attrs' => [
            'id' => 'set-1',
            'values' => ['type' => 'reusable_content', 'import_wordpress_notes' => $notes],
        ],
    ];
}

/**
 * A page-builder reusable content placeholder row.
 *
 * @param  mixed  $notes  The import_wordpress_notes value
 * @return array<string, mixed> The replicator row
 */
function wpBlockReplicatorPlaceholder(mixed $notes): array
{
    return [
        'id' => 'row-1',
        'type' => 'uamsweb_block_reusable_content_landing_page',
        'import_wordpress_notes' => $notes,
        'enabled' => true,
    ];
}

/**
 * Make the collections updateBardSetsWithEntryIds() reads.
 */
function wpBlockCollections(): void
{
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    foreach (['pages', 'post', 'people'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default']);
        $collection->save();
    }
}

/**
 * Save an entry with the given data.
 *
 * @param  string  $collection  The collection handle
 * @param  string  $slug  The entry slug
 * @param  array<string, mixed>  $data  The entry data
 * @return Entry The saved entry
 */
function wpBlockEntry(string $collection, string $slug, array $data): Entry
{
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException('Could not make an entry.');
    }

    $entry->collection($collection);
    $entry->locale('default');
    $entry->slug($slug);
    $entry->data(['title' => $slug] + $data);
    $entry->published(true);
    $entry->save();

    return $entry;
}

/**
 * Read an entry's stored data back from the Stache.
 *
 * @param  Entry  $entry  The entry
 * @return array<int|string, mixed> Its data
 */
function wpBlockStoredData(Entry $entry): array
{
    $found = EntryFacade::find((string) $entry->id());

    if (! $found instanceof Entry) {
        throw new RuntimeException('Entry was not found.');
    }

    return $found->data()->all();
}

it('accepts only a wp:block comment that carries a JSON object', function (string $comment, bool $expected): void {
    expect((new WpBlockBlockProcessor)->canProcess(new DOMComment($comment), 'default', null))->toBe($expected);
})->with([
    'self-closing block' => [' wp:block {"ref":12} /', true],
    'block' => [' wp:block {"ref":12} ', true],
    'attributes over several lines' => [" wp:block {\"ref\":\n12} ", true],
    'no space before the object' => [' wp:block{"ref":12}', true],
    'no attributes' => [' wp:block ', false],
    'empty object' => [' wp:block {} ', false],
    'text after the object' => [' wp:block {"ref":12} trailing ', false],
    'another block with the same prefix' => [' wp:blocks {"ref":12} ', false],
    'closing comment' => [' /wp:block ', false],
]);

it('refuses a node that is not a comment', function (): void {
    expect((new WpBlockBlockProcessor)->canProcess(new DOMText('wp:block {"ref":12}'), 'default', null))->toBeFalse();
});

it('points the set at the imported reusable block entry and tracks the ref', function (mixed $ref, string $postId): void {
    WpBlockRegistry::registerMapping($postId, 'entry-"1"&2');

    expect(processWpBlock(['ref' => $ref]))->toBe([
        'html' => '<div data-set-type="reusable_content"'.SetMarkupNonce::attribute().' data-set-attr-content="entry-&quot;1&quot;&amp;2"></div>',
        'skipCount' => 1,
        'entryId' => 'entry-"1"&2',
    ])
        ->and(WpBlockBlockProcessor::getWpBlockPostIds())->toBe([$postId])
        ->and(WpBlockBlockProcessor::getDeferredWpBlockReferences())->toBe([]);
})->with([
    'string ref' => ['12', '12'],
    'integer ref' => [12, '12'],
    'boolean ref' => [true, '1'],
]);

it('writes a placeholder and defers the reference when the block is not imported yet', function (): void {
    ProcessingContext::setStatamicEntryId('page-1');
    ProcessingContext::setCollectionHandle('pages');
    WpBlockRegistry::registerMapping('13', '');

    $first = processWpBlock(['ref' => '<12>']);
    $second = processWpBlock(['ref' => 13]);

    expect($first)->toBe([
        'html' => '<div data-set-type="reusable_content"'.SetMarkupNonce::attribute().' data-set-attr-import_wordpress_notes="wp_block post ID &lt;12&gt;"></div>',
        'skipCount' => 1,
        'entryId' => null,
        'wpBlockPostId' => '<12>',
    ])
        ->and($second['wpBlockPostId'] ?? null)->toBe('13')
        ->and(WpBlockBlockProcessor::getWpBlockPostIds())->toBe(['<12>', '13'])
        ->and(WpBlockBlockProcessor::getDeferredWpBlockReferences())->toBe([
            'page-1' => [
                ['wp_block_post_id' => '<12>', 'collection_handle' => 'pages', 'site' => 'default'],
                ['wp_block_post_id' => '13', 'collection_handle' => 'pages', 'site' => 'default'],
            ],
        ]);
});

it('writes the placeholder without deferring when the entry or collection is unknown', function (?string $entryId, ?string $collection): void {
    ProcessingContext::setStatamicEntryId($entryId);
    ProcessingContext::setCollectionHandle($collection);

    expect(processWpBlock(['ref' => '12']))->toBe([
        'html' => '<div data-set-type="reusable_content"'.SetMarkupNonce::attribute().' data-set-attr-import_wordpress_notes="wp_block post ID 12"></div>',
        'skipCount' => 1,
        'entryId' => null,
        'wpBlockPostId' => '12',
    ])
        ->and(WpBlockBlockProcessor::getDeferredWpBlockReferences())->toBe([]);
})->with([
    'no entry' => [null, 'pages'],
    'empty entry' => ['', 'pages'],
    'no collection' => ['page-1', null],
    'empty collection' => ['page-1', ''],
]);

it('warns and returns null when the block has no usable ref', function (array $attributes, ?string $pageSlug, ?string $pageLink): void {
    if ($pageLink !== null) {
        ProcessingContext::setWordPressPageLink($pageLink);
    }

    $warnings = [];
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$warnings): void {
        $warnings[] = [$message, $context];
    });

    $context = ['site' => 'default', 'pageSlug' => $pageSlug ?? 'none', 'attributes' => $attributes];

    if ($pageLink !== null) {
        $context['entry_url'] = $pageLink;
    }

    expect(processWpBlock($attributes, $pageSlug))->toBeNull()
        ->and($warnings)->toBe([[
            'WpBlockBlockProcessor::process: No ref attribute found in wp:block block'.($pageLink !== null ? " (from entry: {$pageLink})" : ''),
            $context,
        ]])
        ->and(WpBlockBlockProcessor::getWpBlockPostIds())->toBe([]);
})->with([
    'no ref' => [[], null, null],
    'empty ref, with slug' => [['ref' => ''], 'about', null],
    'array ref' => [['ref' => [12]], null, null],
    'false ref' => [['ref' => false], null, null],
    'with a page link' => [[], null, 'https://example.test/about/'],
]);

it('builds a replicator row for an entry', function (): void {
    $row = (new WpBlockBlockProcessor)->createReplicatorSet('entry-1');

    expect($row['id'] ?? null)->toBeString()->not->toBe('')
        ->and(array_diff_key($row, ['id' => true]))->toBe([
            'type' => 'uamsweb_block_reusable_content_landing_page',
            'content' => ['entry-1'],
            'enabled' => true,
        ])
        ->and(array_key_first($row))->toBe('id');
});

it('builds a placeholder replicator row for a post id', function (): void {
    $row = (new WpBlockBlockProcessor)->createPlaceholderReplicatorSet('12');

    expect($row['id'] ?? null)->toBeString()->not->toBe('')
        ->and(array_diff_key($row, ['id' => true]))->toBe([
            'type' => 'uamsweb_block_reusable_content_landing_page',
            'import_wordpress_notes' => 'wp_block post ID 12',
            'enabled' => true,
        ])
        ->and(array_key_first($row))->toBe('id');
});

it('tracks post ids in order, with duplicates, and skips an empty one', function (): void {
    WpBlockBlockProcessor::addWpBlockPostId('12');
    WpBlockBlockProcessor::addWpBlockPostId('');
    WpBlockBlockProcessor::addWpBlockPostId('7');
    WpBlockBlockProcessor::addWpBlockPostId('12');

    expect(WpBlockBlockProcessor::getWpBlockPostIds())->toBe(['12', '7', '12']);

    WpBlockBlockProcessor::clearWpBlockPostIds();

    expect(WpBlockBlockProcessor::getWpBlockPostIds())->toBe([]);
});

it('extracts the unique refs of the wp:block comments in raw content', function (): void {
    $content = '<!-- wp:block {"ref":12} /-->'
        .'<p>text</p>'
        .'<!-- wp:block {"ref":"7"} -->'
        .'<!--wp:block{"ref":12}-->'
        ."<!-- wp:block {\"ref\":\n30} /-->"
        .'<!-- wp:block {"other":1} /-->'
        .'<!-- wp:block {"ref":""} /-->'
        .'<!-- wp:block {"ref":[5]} /-->'
        .'<!-- wp:block {not json} /-->'
        .'<!-- wp:block {"ref":null} /-->'
        .'<!-- wp:block {"ref":true} /-->';

    expect(WpBlockBlockProcessor::extractWpBlockPostIdsFromContent($content))
        ->toBe([0 => '12', 1 => '7', 3 => '30', 4 => '1']);
});

it('extracts nothing from content without wp:block comments', function (string $content): void {
    expect(WpBlockBlockProcessor::extractWpBlockPostIdsFromContent($content))->toBe([]);
})->with([
    'empty' => [''],
    'other blocks' => ['<!-- wp:paragraph {"ref":12} --><p>x</p><!-- /wp:paragraph -->'],
    'a block with no attributes' => ['<!-- wp:block -->'],
]);

it('replaces a Bard placeholder with the imported entry', function (string $notes): void {
    WpBlockRegistry::registerMapping('12', 'entry-1');

    $set = wpBlockBardPlaceholder($notes);
    $set['attrs']['values']['label'] = 'kept';
    $set['attrs']['extra'] = 'dropped';
    $set['extra'] = 'dropped';

    expect(WpBlockBlockProcessor::updateBardSetsInArray([$set], ['12']))->toBe([[
        'type' => 'set',
        'attrs' => [
            'id' => 'set-1',
            'values' => ['type' => 'reusable_content', 'label' => 'kept', 'content' => 'entry-1'],
        ],
    ]]);
})->with([
    'exact note' => ['wp_block post ID 12'],
    'note inside other text' => ['see wp_block post ID 12 here'],
    'digits followed by more text' => ['wp_block post ID 12abc'],
]);

it('gives a replaced Bard placeholder a new id when it had none', function (): void {
    WpBlockRegistry::registerMapping('12', 'entry-1');

    $set = wpBlockBardPlaceholder('wp_block post ID 12');
    unset($set['attrs']['id']);

    $result = WpBlockBlockProcessor::updateBardSetsInArray([$set], ['12']);

    expect($result[0]['attrs']['id'] ?? null)->toBeString()->not->toBe('')
        ->and($result[0]['attrs']['values'] ?? null)->toBe(['type' => 'reusable_content', 'content' => 'entry-1']);
});

it('replaces a page-builder placeholder with the imported entry', function (): void {
    WpBlockRegistry::registerMapping('12', 'entry-1');

    expect(WpBlockBlockProcessor::updateBardSetsInArray(['row' => wpBlockReplicatorPlaceholder('wp_block post ID 12')], ['12']))->toBe([
        'row' => [
            'id' => 'row-1',
            'type' => 'uamsweb_block_reusable_content_landing_page',
            'enabled' => true,
            'content' => ['entry-1'],
        ],
    ]);
});

it('keeps a placeholder it cannot resolve', function (array $placeholder): void {
    WpBlockRegistry::registerMapping('13', '');

    expect(WpBlockBlockProcessor::updateBardSetsInArray([$placeholder], ['13']))->toBe([$placeholder]);
})->with([
    'Bard, not imported' => [wpBlockBardPlaceholder('wp_block post ID 12')],
    'Bard, imported with no entry' => [wpBlockBardPlaceholder('wp_block post ID 13')],
    'Bard, note without an id' => [wpBlockBardPlaceholder('wp_block post ID abc')],
    'Bard, empty note' => [wpBlockBardPlaceholder('')],
    'Bard, note that is not text' => [wpBlockBardPlaceholder(['wp_block post ID 12'])],
    'Bard, note of another kind' => [wpBlockBardPlaceholder('form ID 12')],
    'page builder, not imported' => [wpBlockReplicatorPlaceholder('wp_block post ID 12')],
    'page builder, imported with no entry' => [wpBlockReplicatorPlaceholder('wp_block post ID 13')],
    'page builder, note without an id' => [wpBlockReplicatorPlaceholder('wp_block post ID abc')],
    'page builder, empty note' => [wpBlockReplicatorPlaceholder('')],
    'page builder, note that is not text' => [wpBlockReplicatorPlaceholder(['wp_block post ID 12'])],
]);

it('reads a numeric placeholder note as text', function (): void {
    WpBlockRegistry::registerMapping('12', 'entry-1');

    expect(WpBlockBlockProcessor::updateBardSetsInArray([wpBlockBardPlaceholder(12)], ['12']))
        ->toBe([wpBlockBardPlaceholder(12)]);
});

it('leaves sets that are not reusable content placeholders alone', function (array $node): void {
    WpBlockRegistry::registerMapping('12', 'entry-1');

    expect(WpBlockBlockProcessor::updateBardSetsInArray([$node], ['12']))->toBe([$node]);
})->with([
    'a Bard set of another type' => [['type' => 'set', 'attrs' => ['id' => 'x', 'values' => ['type' => 'image', 'import_wordpress_notes' => 'wp_block post ID 12x']]]],
    'a node that is not a set' => [['type' => 'paragraph', 'attrs' => ['values' => ['type' => 'reusable_content', 'import_wordpress_notes' => 'wp_block post ID 1x']]]],
    'a set with no attributes' => [['type' => 'set']],
    'a set whose attributes are not an array' => [['type' => 'set', 'attrs' => 'reusable_content']],
    'a set with no values' => [['type' => 'set', 'attrs' => ['id' => 'x']]],
    'a set whose values are not an array' => [['type' => 'set', 'attrs' => ['values' => 'reusable_content']]],
    'a set with no value type' => [['type' => 'set', 'attrs' => ['values' => ['import_wordpress_notes' => 'wp_block post ID 1x']]]],
    'a reusable content set with no note' => [['type' => 'set', 'attrs' => ['values' => ['type' => 'reusable_content', 'content' => 'entry-9']]]],
    'a reusable content set with a null note' => [['type' => 'set', 'attrs' => ['values' => ['type' => 'reusable_content', 'import_wordpress_notes' => null]]]],
    'a page-builder row with no note' => [['type' => 'uamsweb_block_reusable_content_landing_page', 'content' => ['entry-9']]],
    'another page-builder row' => [['type' => 'uamsweb_block_text', 'import_wordpress_notes' => 'wp_block post ID 1x']],
    'a node with no type' => [['attrs' => ['values' => ['type' => 'reusable_content', 'import_wordpress_notes' => 'wp_block post ID 1x']]]],
]);

it('finds placeholders at any depth and keeps everything around them', function (): void {
    WpBlockRegistry::registerMapping('12', 'entry-1');

    $array = [
        'title' => 'Page',
        'count' => 3,
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi']]],
            [
                'type' => 'set',
                'attrs' => ['id' => 'outer', 'values' => [
                    'type' => 'columns',
                    'items' => [wpBlockBardPlaceholder('wp_block post ID 12')],
                ]],
            ],
        ],
        'untouched' => ['a' => ['b' => 'c']],
    ];

    $expected = $array;
    $expected['content'][1]['attrs']['values']['items'][0] = [
        'type' => 'set',
        'attrs' => ['id' => 'set-1', 'values' => ['type' => 'reusable_content', 'content' => 'entry-1']],
    ];

    expect(WpBlockBlockProcessor::updateBardSetsInArray($array, ['12']))->toBe($expected);
});

it('returns an array with no placeholders unchanged', function (): void {
    $array = ['a' => [1, ['b' => null]], 'c' => 'd'];

    expect(WpBlockBlockProcessor::updateBardSetsInArray($array, ['12']))->toBe($array)
        ->and(WpBlockBlockProcessor::updateBardSetsInArray([], ['12']))->toBe([]);
});

it('rewrites placeholders in every collection it reads and saves the changed entries', function (): void {
    wpBlockCollections();

    $first = wpBlockEntry('pages', 'first', [
        'content' => [wpBlockBardPlaceholder('wp_block post ID 12')],
        'page_builder' => [wpBlockReplicatorPlaceholder('wp_block post ID 13')],
    ]);
    $second = wpBlockEntry('pages', 'second', [
        'page_builder' => [wpBlockReplicatorPlaceholder('wp_block post ID 12')],
    ]);
    $unresolved = wpBlockEntry('pages', 'unresolved', [
        'content' => [wpBlockBardPlaceholder('wp_block post ID 99')],
        'page_builder' => 'not an array',
    ]);
    $plain = wpBlockEntry('pages', 'plain', ['content' => 'not an array']);
    $post = wpBlockEntry('post', 'news', ['content' => [wpBlockBardPlaceholder('wp_block post ID 13')]]);

    WpBlockRegistry::registerMapping('12', 'entry-12');
    WpBlockRegistry::registerMapping('13', 'entry-13');

    $output = new BufferedOutput;
    WpBlockBlockProcessor::updateBardSetsWithEntryIds('default', $output);

    expect($output->fetch())->toBe(
        '  - Updating wp_block placeholder Bard sets and replicator sets...'.PHP_EOL
        ."  - Updated 2 entries in 'pages' collection.".PHP_EOL
        ."  - Updated 1 entry in 'post' collection.".PHP_EOL
        .'Updated 3 total entries with wp_block placeholder Bard sets and replicator sets.'.PHP_EOL
    )
        ->and(wpBlockStoredData($first)['content'] ?? null)->toBe([[
            'type' => 'set',
            'attrs' => ['id' => 'set-1', 'values' => ['type' => 'reusable_content', 'content' => 'entry-12']],
        ]])
        ->and(wpBlockStoredData($first)['page_builder'] ?? null)->toBe([[
            'id' => 'row-1',
            'type' => 'uamsweb_block_reusable_content_landing_page',
            'enabled' => true,
            'content' => ['entry-13'],
        ]])
        ->and(wpBlockStoredData($second)['page_builder'][0]['content'] ?? null)->toBe(['entry-12'])
        ->and(wpBlockStoredData($unresolved)['content'] ?? null)->toBe([wpBlockBardPlaceholder('wp_block post ID 99')])
        ->and(wpBlockStoredData($plain)['content'] ?? null)->toBe('not an array')
        ->and(wpBlockStoredData($post)['content'][0]['attrs']['values']['content'] ?? null)->toBe('entry-13');
});

it('reports one updated entry in the singular', function (): void {
    wpBlockCollections();
    wpBlockEntry('people', 'ann', ['content' => [wpBlockBardPlaceholder('wp_block post ID 12')]]);
    WpBlockRegistry::registerMapping('12', 'entry-12');

    $output = new BufferedOutput;
    WpBlockBlockProcessor::updateBardSetsWithEntryIds('default', $output);

    expect($output->fetch())->toBe(
        '  - Updating wp_block placeholder Bard sets and replicator sets...'.PHP_EOL
        ."  - Updated 1 entry in 'people' collection.".PHP_EOL
        .'Updated 1 total entry with wp_block placeholder Bard sets and replicator sets.'.PHP_EOL
    );
});

it('reads only the given site', function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/'],
    ]);

    $collection = CollectionFacade::make('pages');
    $collection->sites(['default', 'other']);
    $collection->save();

    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException('Could not make an entry.');
    }

    $entry->collection('pages');
    $entry->locale('other');
    $entry->slug('elsewhere');
    $entry->data(['title' => 'Elsewhere', 'content' => [wpBlockBardPlaceholder('wp_block post ID 12')]]);
    $entry->save();

    WpBlockRegistry::registerMapping('12', 'entry-12');

    $output = new BufferedOutput;
    WpBlockBlockProcessor::updateBardSetsWithEntryIds('default', $output);

    expect($output->fetch())->toBe('  - Updating wp_block placeholder Bard sets and replicator sets...'.PHP_EOL)
        ->and(wpBlockStoredData($entry)['content'] ?? null)->toBe([wpBlockBardPlaceholder('wp_block post ID 12')]);
});

it('does nothing when no reusable blocks were imported', function (): void {
    EntryFacade::shouldReceive('query')->never();

    $output = new BufferedOutput;
    WpBlockBlockProcessor::updateBardSetsWithEntryIds('default', $output);

    expect($output->fetch())->toBe('');
});

it('skips query results it cannot read', function (): void {
    WpBlockRegistry::registerMapping('12', 'entry-12');

    $noData = Mockery::mock(Entry::class);
    $noData->shouldReceive('data')->andReturn('not an object');
    $noData->shouldNotReceive('save');

    $noArray = Mockery::mock(Entry::class);
    $noArray->shouldReceive('data')->andReturn(new stdClass);
    $noArray->shouldNotReceive('save');

    $notArray = Mockery::mock(Entry::class);
    $notArray->shouldReceive('data')->andReturn(new class
    {
        /**
         * Return something that is not an array.
         */
        public function toArray(): string
        {
            return 'not an array';
        }
    });
    $notArray->shouldNotReceive('save');

    $query = Mockery::mock(EntryQueryBuilder::class);
    $query->shouldReceive('where')->with('collection', Mockery::type('string'))->times(3)->andReturnSelf();
    $query->shouldReceive('where')->with('site', 'default')->times(3)->andReturnSelf();
    $query->shouldReceive('get')->times(3)->andReturn(
        'not iterable',
        collect(['not an entry', $noData, $noArray, $notArray]),
        collect([])
    );

    EntryFacade::shouldReceive('query')->times(3)->andReturn($query);

    $output = new BufferedOutput;
    WpBlockBlockProcessor::updateBardSetsWithEntryIds('default', $output);

    expect($output->fetch())->toBe('  - Updating wp_block placeholder Bard sets and replicator sets...'.PHP_EOL);
});

it('queries the pages, post and people collections of the site', function (): void {
    WpBlockRegistry::registerMapping('12', 'entry-12');

    $collections = [];

    $query = Mockery::mock(EntryQueryBuilder::class);
    $query->shouldReceive('where')->with('collection', Mockery::type('string'))->andReturnUsing(
        function (string $column, string $handle) use (&$collections, $query): EntryQueryBuilder {
            $collections[] = $handle;

            return $query;
        }
    );
    $query->shouldReceive('where')->with('site', 'main')->times(3)->andReturnSelf();
    $query->shouldReceive('get')->andReturn(collect([]));

    EntryFacade::shouldReceive('query')->andReturn($query);

    WpBlockBlockProcessor::updateBardSetsWithEntryIds('main', new BufferedOutput);

    expect($collections)->toBe(['pages', 'post', 'people']);
});

it('resolves the deferred references and then forgets them', function (): void {
    wpBlockCollections();
    ProcessingContext::setStatamicEntryId('page-1');
    ProcessingContext::setCollectionHandle('pages');
    processWpBlock(['ref' => '12']);

    $entry = wpBlockEntry('pages', 'first', ['content' => [wpBlockBardPlaceholder('wp_block post ID 12')]]);
    WpBlockRegistry::registerMapping('12', 'entry-12');

    expect(WpBlockBlockProcessor::getDeferredWpBlockReferences())->not->toBe([]);

    $output = new BufferedOutput;
    WpBlockBlockProcessor::processDeferredWpBlockReferences('default', $output);

    expect(WpBlockBlockProcessor::getDeferredWpBlockReferences())->toBe([])
        ->and($output->fetch())->toContain("Updated 1 entry in 'pages' collection.")
        ->and(wpBlockStoredData($entry)['content'][0]['attrs']['values']['content'] ?? null)->toBe('entry-12');
});

it('forgets the deferred references even when nothing was imported', function (): void {
    ProcessingContext::setStatamicEntryId('page-1');
    ProcessingContext::setCollectionHandle('pages');
    processWpBlock(['ref' => '12']);

    WpBlockBlockProcessor::processDeferredWpBlockReferences('default', new BufferedOutput);

    expect(WpBlockBlockProcessor::getDeferredWpBlockReferences())->toBe([]);
});
