<?php

declare(strict_types=1);

/**
 * Tests the entry half of `BaseEntryCreator`: WP-Members gating, status and
 * visibility, the dates it reads and writes, saving an entry under each
 * overwrite behavior, finding an entry an earlier import saved, and the page
 * data and unmapped-taxonomy report it keeps.
 *
 * A probe subclass exposes the protected steps, and every case saves into a real
 * collection whose blueprint carries the fields that case needs.
 *
 * @command  php vendor/bin/pest tests/Integration/BaseEntryCreatorTest.php
 */

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Events\EntryDeleted;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * What the probe `BaseEntryCreator` adds, so the probe can be built inside a test.
 *
 * Building the probe as an anonymous class, the first time a test asks for one,
 * loads `BaseEntryCreator` under Laravel's error handler, which turns a compile
 * warning (such as a `continue` mutated into a switch) into an exception.
 */
interface BaseEntryTestProbe
{
    /**
     * Call a protected or private method.
     *
     * @param  string  $method  The method
     * @param  mixed  ...$arguments  Its arguments
     * @return mixed What it returns
     */
    public function call(string $method, mixed ...$arguments): mixed;

    /**
     * Call `mapStatusAndVisibility()`, returning the data it fills as well.
     *
     * @param  array<int|string, mixed>  $data  The data so far
     * @param  string  $status  The wp:status value
     * @param  string  $postPassword  The wp:post_password value
     * @param  string  $postDate  The wp:post_date value
     * @param  string  $collectionHandle  The collection
     * @param  OutputInterface  $output  Console output
     * @param  ?string  $postDateGmt  The wp:post_date_gmt value, or null to leave it out
     * @param  string  $wpmemBlock  The `_wpmem_block` value
     * @return array{0: bool, 1: array<int|string, mixed>} What it returns, and the data
     */
    public function mapStatus(array $data, string $status, string $postPassword, string $postDate, string $collectionHandle, OutputInterface $output, ?string $postDateGmt = '', string $wpmemBlock = ''): array;

    /**
     * Call `addUpdatedAtField()`, returning the data it fills.
     *
     * @param  array<int|string, mixed>  $data  The data so far
     * @param  SimpleXMLElement  $item  The item
     * @param  string  $methodName  The caller named in the log
     * @return array<int|string, mixed> The data
     */
    public function updatedAt(array $data, SimpleXMLElement $item, string $methodName): array;

    /**
     * Read a property, static or not.
     *
     * @param  string  $property  The property
     * @return mixed Its value
     */
    public function read(string $property): mixed;
}

/**
 * Build a probe `BaseEntryCreator`.
 *
 * @return BaseEntryCreator&BaseEntryTestProbe The probe
 */
function baseEntryProbe(): BaseEntryCreator&BaseEntryTestProbe
{
    return new class extends BaseEntryCreator implements BaseEntryTestProbe
    {
        /**
         * Create no entries.
         *
         * @param  string  $site  The site
         * @param  string  $collectionHandle  The collection
         * @param  XmlParser  $xmlParser  The parser
         * @param  AssetImporter  $assetImporter  The importer
         * @param  OutputInterface  $output  Console output
         * @param  MetadataMapper  $metadataMapper  The mapper
         * @param  ContentProcessor  $contentProcessor  The converter
         * @param  ?array<int|string, mixed>  $minervaKbSettings  The MinervaKB settings
         * @return array<int|string, mixed> No page data
         */
        public function createEntries(string $site, string $collectionHandle, XmlParser $xmlParser, AssetImporter $assetImporter, OutputInterface $output, MetadataMapper $metadataMapper, ContentProcessor $contentProcessor, ?array $minervaKbSettings = null): array
        {
            return [];
        }

        /**
         * No show-people shortcode.
         *
         * @return bool Always false
         */
        public function hasShowPeople(): bool
        {
            return false;
        }

        /**
         * No categories.
         *
         * @return array<int|string, mixed> Always empty
         */
        public function getPostCategories(): array
        {
            return [];
        }

        /**
         * No tags.
         *
         * @return array<int|string, mixed> Always empty
         */
        public function getPostTags(): array
        {
            return [];
        }

        /**
         * Validate nothing.
         *
         * @param  string  $collectionHandle  The collection
         * @param  string  $site  The site
         * @param  OutputInterface  $output  Console output
         */
        public function validateTaxonomies(string $collectionHandle, string $site, OutputInterface $output): void {}

        /**
         * Call a protected or private method.
         *
         * @param  string  $method  The method
         * @param  mixed  ...$arguments  Its arguments
         * @return mixed What it returns
         */
        public function call(string $method, mixed ...$arguments): mixed
        {
            $reflection = new ReflectionMethod(BaseEntryCreator::class, $method);

            return $reflection->invoke($reflection->isStatic() ? null : $this, ...$arguments);
        }

        /**
         * Call `mapStatusAndVisibility()`, returning the data it fills as well.
         *
         * @param  array<int|string, mixed>  $data  The data so far
         * @param  string  $status  The wp:status value
         * @param  string  $postPassword  The wp:post_password value
         * @param  string  $postDate  The wp:post_date value
         * @param  string  $collectionHandle  The collection
         * @param  OutputInterface  $output  Console output
         * @param  string  $postDateGmt  The wp:post_date_gmt value
         * @param  string  $wpmemBlock  The `_wpmem_block` value
         * @return array{0: bool, 1: array<int|string, mixed>} What it returns, and the data
         */
        public function mapStatus(array $data, string $status, string $postPassword, string $postDate, string $collectionHandle, OutputInterface $output, ?string $postDateGmt = '', string $wpmemBlock = ''): array
        {
            $scheduled = $postDateGmt === null
                ? $this->mapStatusAndVisibility($status, $postPassword, $postDate, $data, $collectionHandle, $output)
                : $this->mapStatusAndVisibility($status, $postPassword, $postDate, $data, $collectionHandle, $output, $postDateGmt, $wpmemBlock);

            return [$scheduled, $data];
        }

        /**
         * Call `addUpdatedAtField()`, returning the data it fills.
         *
         * @param  array<int|string, mixed>  $data  The data so far
         * @param  SimpleXMLElement  $item  The item
         * @param  string  $methodName  The caller named in the log
         * @return array<int|string, mixed> The data
         */
        public function updatedAt(array $data, SimpleXMLElement $item, string $methodName): array
        {
            $this->addUpdatedAtField($data, $item, $methodName);

            return $data;
        }

        /**
         * Read a property, static or not.
         *
         * @param  string  $property  The property
         * @return mixed Its value
         */
        public function read(string $property): mixed
        {
            $reflection = new ReflectionProperty(BaseEntryCreator::class, $property);

            return $reflection->getValue($reflection->isStatic() ? null : $this);
        }
    };
}

beforeEach(function (): void {
    config(['statamic.search.indexes' => []]);
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'], 'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/']]);
    BaseEntryCreator::clearTaxonomyCaches();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    BaseEntryCreator::clearTaxonomyCaches();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();

    foreach (['collections.things.thing'] as $handle) {
        $blueprint = BlueprintFacade::find($handle);

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }

    Mockery::close();
});

/**
 * Save the `things` collection, with a blueprint carrying some fields.
 *
 * @param  array<mixed>  $fields  The field handles beyond the title
 * @param  bool  $dated  Whether the collection is dated
 */
function baseEntryCollection(array $fields = [], bool $dated = false): void
{
    $collection = CollectionFacade::make('things');
    $collection->sites(['default', 'other']);
    $collection->dated($dated);
    $collection->save();

    $contents = [['handle' => 'title', 'field' => ['type' => 'text']]];

    foreach ($fields as $field) {
        $field = \is_string($field) ? $field : '';
        $contents[] = ['handle' => $field, 'field' => ['type' => $field === 'date' ? 'date' : 'text']];
    }

    $blueprint = BlueprintFacade::make('thing');
    $blueprint->setNamespace('collections.things');
    $blueprint->setContents(['fields' => $contents]);
    $blueprint->save();
}

/**
 * Parse an item.
 *
 * @param  string  $inner  The item's elements
 * @return SimpleXMLElement The item
 */
function baseEntryItem(string $inner): SimpleXMLElement
{
    $item = \simplexml_load_string('<item xmlns:wp="http://wordpress.org/export/1.2/">'.$inner.'</item>');

    if ($item === false) {
        throw new RuntimeException('Could not parse the item.');
    }

    return $item;
}

/**
 * Save an entry into `things`.
 *
 * @param  string  $id  The id
 * @param  string  $slug  The slug
 * @param  string  $site  The site
 */
function baseEntryMake(string $id, string $slug, string $site = 'default'): Entry
{
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException('Could not make an entry.');
    }

    $entry->collection('things');
    $entry->locale($site);
    $entry->slug($slug);
    $entry->id($id);
    $entry->data(['title' => $slug]);
    $entry->save();

    return $entry;
}

/**
 * The entries in `things`, as slug by id, sorted by slug.
 *
 * @return array<string, string> The slugs by id
 */
function baseEntrySlugs(): array
{
    $slugs = [];

    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'things' && \is_string($entry->id()) && \is_string($entry->slug())) {
            $slugs[$entry->id()] = $entry->slug();
        }
    }

    \asort($slugs);

    return $slugs;
}

/**
 * An entry's data, as an array.
 *
 * @param  Entry  $entry  The entry
 * @return array<int|string, mixed> Its data
 */
function baseEntryData(Entry $entry): array
{
    $data = $entry->data();

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * Collect every warning logged, as message and context.
 *
 * @return ArrayObject<int, array{0: mixed, 1: mixed}> The warnings, filled as they are logged
 */
function baseEntryWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{0: mixed, 1: mixed}> $warnings */
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($warnings): void {
        $warnings->append([$message, $context]);
    });
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();

    return $warnings;
}

/**
 * The messages and contexts of the warnings collected, as pairs.
 *
 * @param  ArrayObject<int, array{0: mixed, 1: mixed}>  $warnings  The warnings
 * @return list<array{0: mixed, 1: mixed}> The warnings
 */
function baseEntryLogged(ArrayObject $warnings): array
{
    return \array_values($warnings->getArrayCopy());
}

describe('WP-Members', function (): void {
    it('reads the block meta, trimmed, or nothing without it', function (string $inner, string $expected): void {
        expect(baseEntryProbe()->call('readWpMembersBlock', baseEntryItem($inner)))->toBe($expected);
    })->with([
        'a value' => ['<wp:postmeta><wp:meta_key>_other</wp:meta_key><wp:meta_value>9</wp:meta_value></wp:postmeta><wp:postmeta><wp:meta_key>_wpmem_block</wp:meta_key><wp:meta_value> 1 </wp:meta_value></wp:postmeta>', '1'],
        'none' => ['<wp:postmeta><wp:meta_key>_other</wp:meta_key><wp:meta_value>1</wp:meta_value></wp:postmeta>', ''],
    ]);

    it('gates an item blocked or hidden, and no other', function (string $value, bool $gated): void {
        expect(baseEntryProbe()->call('isWpMembersGated', $value))->toBe($gated);
    })->with([
        'blocked' => ['1', true],
        'hidden' => ['2', true],
        'blocked, with space' => [' 2 ', true],
        'open' => ['0', false],
        'blank' => ['', false],
        'another number' => ['3', false],
    ]);

    it('unpublishes a gated published item, naming it, and leaves the rest as they are', function (bool $published, string $block, bool $expected, bool $reported): void {
        $warnings = baseEntryWarnings();
        ProcessingContext::setWordPressPageLink('https://example.test/kb/9/');
        $output = new BufferedOutput;

        $result = baseEntryProbe()->call('publishUnlessWpMembersGated', $published, $block, 'Probe::save', $output);

        expect($result)->toBe($expected)
            ->and($output->fetch())->toBe($reported ? "Imported as unpublished: WP-Members gates https://example.test/kb/9/ (_wpmem_block={$block}).".PHP_EOL : '')
            ->and(baseEntryLogged($warnings))->toBe($reported ? [["Probe::save: WP-Members gates this item (_wpmem_block='{$block}'), importing it as unpublished rather than published (from entry: https://example.test/kb/9/)", ['wpmem_block' => $block, 'entry_url' => 'https://example.test/kb/9/']]] : []);
    })->with([
        'published and gated' => [true, '1', false, true],
        'published and open' => [true, '0', true, false],
        'a draft, gated' => [false, '2', false, false],
    ]);

    it('names a gated item with no page as this item', function (): void {
        baseEntryWarnings();
        $output = new BufferedOutput;

        baseEntryProbe()->call('publishUnlessWpMembersGated', true, '2', 'Probe::save', $output);

        expect($output->fetch())->toBe('Imported as unpublished: WP-Members gates this item (_wpmem_block=2).'.PHP_EOL);
    });
});

describe('status and visibility', function (): void {
    it('maps each status, and holds a published item WP-Members gates as private', function (string $status, string $block, string $expected): void {
        baseEntryCollection(['status_and_visibility']);
        baseEntryWarnings();
        $output = new BufferedOutput;

        [$scheduled, $data] = baseEntryProbe()->mapStatus([], $status, '', '', 'things', $output, '', $block);

        expect($data)->toBe(['status_and_visibility' => $expected])
            ->and($scheduled)->toBeFalse();
    })->with([
        'a draft' => ['draft', '1', 'draft'],
        'pending' => ['pending', '', 'pending'],
        'private' => ['private', '', 'private'],
        'published' => ['publish', '', 'published'],
        'published and gated' => ['publish', '1', 'private'],
        'unknown' => ['inherit', '', 'draft'],
    ]);

    it('names a gated published item it holds as private', function (): void {
        baseEntryCollection(['status_and_visibility']);
        $warnings = baseEntryWarnings();
        $output = new BufferedOutput;

        baseEntryProbe()->mapStatus([], 'publish', '', '', 'things', $output, '', '2');

        expect($output->fetch())->toBe('Imported as private: WP-Members gates this item (_wpmem_block=2).'.PHP_EOL)
            ->and(baseEntryLogged($warnings))->toBe([["BaseEntryCreator::mapStatusAndVisibility: WP-Members gates this item (_wpmem_block='2'), importing it as private rather than published", ['wpmem_block' => '2']]]);
    });

    it('warns of a status it does not know', function (): void {
        baseEntryCollection(['status_and_visibility']);
        $warnings = baseEntryWarnings();

        baseEntryProbe()->mapStatus([], 'inherit', '', '', 'things', new BufferedOutput);

        expect(baseEntryLogged($warnings))->toBe([["BaseEntryCreator::mapStatusAndVisibility: Unsupported wp:status='inherit', defaulting to draft", ['status' => 'inherit']]]);
    });

    it('schedules a future post by status and date, or through publish_at where the blueprint has it', function (array $fields, array $expected, bool $scheduled): void {
        baseEntryCollection($fields);
        baseEntryWarnings();

        [$isScheduled, $data] = baseEntryProbe()->mapStatus([], 'future', '', '2030-01-02 03:04:05', 'things', new BufferedOutput, '2030-01-02 09:04:05');

        expect($data)->toBe($expected)
            ->and($isScheduled)->toBe($scheduled);
    })->with([
        'by status' => [['status_and_visibility'], ['status_and_visibility' => 'scheduled'], true],
        'by status, with a date field' => [['status_and_visibility', 'date'], ['status_and_visibility' => 'scheduled', 'date' => '2030-01-02 03:04:05'], true],
        'through publish_at' => [['status_and_visibility', 'publish_at', 'date'], ['status_and_visibility' => 'draft', 'publish_at' => '2030-01-02 09:04'], false],
        'a date field alone' => [['date'], ['date' => '2030-01-02 03:04:05'], false],
    ]);

    it('leaves publish_at unset when no date can be read', function (): void {
        baseEntryCollection(['status_and_visibility', 'publish_at']);
        baseEntryWarnings();

        [, $data] = baseEntryProbe()->mapStatus([], 'future', '', '', 'things', new BufferedOutput);

        expect($data)->toBe(['status_and_visibility' => 'draft']);
    });

    it('sets no date for a future post with no post date', function (): void {
        baseEntryCollection(['status_and_visibility', 'date']);

        [, $data] = baseEntryProbe()->mapStatus([], 'future', '', '', 'things', new BufferedOutput);

        expect($data)->toBe(['status_and_visibility' => 'scheduled']);
    });

    it('sets no date for a post that is not future', function (): void {
        baseEntryCollection(['date']);

        [, $data] = baseEntryProbe()->mapStatus([], 'publish', '', '2030-01-02 03:04:05', 'things', new BufferedOutput);

        expect($data)->toBe([]);
    });

    it('protects an item with a password, hashing it where the blueprint stores one', function (array $fields, string $password, array $keys): void {
        baseEntryCollection($fields);

        [, $data] = baseEntryProbe()->mapStatus([], 'draft', $password, '', 'things', new BufferedOutput);

        expect(\array_keys($data))->toBe($keys)
            ->and($data['password_protected'] ?? null)->toBe(\in_array('password_protected', $keys, true) ? $password !== '' : null);

        if (isset($data['visibility_password'])) {
            expect(\is_string($data['visibility_password']) && Hash::check($password, $data['visibility_password']))->toBeTrue()
                ->and($data['is_password_hashed'] ?? null)->toBeTrue();
        }
    })->with([
        'a password, stored' => [['password_protected', 'visibility_password'], 'secret', ['password_protected', 'visibility_password', 'is_password_hashed']],
        'a password, not stored' => [['password_protected'], 'secret', ['password_protected']],
        'no password' => [['password_protected', 'visibility_password'], '', ['password_protected']],
        'no field' => [['visibility_password'], 'secret', []],
    ]);

    it('warns and maps nothing for a collection with no blueprint', function (): void {
        $warnings = baseEntryWarnings();

        [$scheduled, $data] = baseEntryProbe()->mapStatus(['kept' => 1], 'future', 'secret', '2030-01-02', 'missing', new BufferedOutput);

        expect($data)->toBe(['kept' => 1])
            ->and($scheduled)->toBeFalse()
            ->and(baseEntryLogged($warnings))->toBe([["BaseEntryCreator::mapStatusAndVisibility: No blueprint found for collection 'missing'", ['collection_handle' => 'missing']]]);
    });
});

describe('the scheduled publish time', function (): void {
    it('reads the GMT date, in UTC, without a warning', function (string $gmt, string $expected): void {
        $warnings = baseEntryWarnings();

        expect(baseEntryProbe()->call('resolveScheduledPublishAt', $gmt, '2030-05-05 05:05:05'))->toBe($expected)
            ->and(baseEntryLogged($warnings))->toBe([]);
    })->with([
        'a plain date' => ['2030-01-02 09:04:59', '2030-01-02 09:04'],
        'a date with an offset' => ['2030-01-02T09:04:00+02:00', '2030-01-02 07:04'],
        'year 1000' => ['1000-01-01 00:00:00', '1000-01-01 00:00'],
    ]);

    it('falls back to the local date as UTC, warning it may be off', function (string $gmt): void {
        $warnings = baseEntryWarnings();

        expect(baseEntryProbe()->call('resolveScheduledPublishAt', $gmt, '2030-05-05 05:05:05'))->toBe('2030-05-05 05:05')
            ->and(baseEntryLogged($warnings))->toBe([['BaseEntryCreator::resolveScheduledPublishAt: No usable wp:post_date_gmt, so publish_at reads wp:post_date as UTC and may be off by the site offset', ['post_date_gmt' => $gmt, 'post_date' => '2030-05-05 05:05:05', 'publish_at' => '2030-05-05 05:05']]]);
    })->with([
        'all zero' => ['0000-00-00 00:00:00'],
        'year 999' => ['0999-12-31 23:59:59'],
        'blank' => [''],
        'space' => ['  '],
        'unreadable' => ['not a date'],
    ]);

    it('gives no time, warning so, when neither date can be read', function (): void {
        $warnings = baseEntryWarnings();

        expect(baseEntryProbe()->call('resolveScheduledPublishAt', '', 'not a date'))->toBeNull()
            ->and(baseEntryLogged($warnings))->toBe([['BaseEntryCreator::resolveScheduledPublishAt: No readable post date, so publish_at is unset and the entry stays a draft', ['post_date_gmt' => '', 'post_date' => 'not a date', 'publish_at' => null]]]);
    });
});

describe('dates', function (): void {
    it('reads the GMT, post and modified dates, the post date falling back to pubDate', function (string $inner, array $expected): void {
        $creator = baseEntryProbe();
        $item = baseEntryItem($inner);

        expect([$creator->call('extractPostDateGmtString', $item), $creator->call('extractPostDateString', $item), $creator->call('extractPostModifiedDateString', $item)])->toBe($expected);
    })->with([
        'all three' => ['<wp:post_date>2025-01-01 00:00:00</wp:post_date><wp:post_date_gmt>2025-01-01 06:00:00</wp:post_date_gmt><wp:post_modified>2025-02-01 00:00:00</wp:post_modified><pubDate>Mon, 01 Jan 2024 00:00:00 +0000</pubDate>', ['2025-01-01 06:00:00', '2025-01-01 00:00:00', '2025-02-01 00:00:00']],
        'pubDate alone' => ['<pubDate>Mon, 01 Jan 2024 00:00:00 +0000</pubDate>', ['', 'Mon, 01 Jan 2024 00:00:00 +0000', '']],
        'none' => ['', ['', '', '']],
    ]);

    it('adds the modified time as updated_at, or nothing, warning of one it cannot read', function (string $modified, array $expected, bool $warned): void {
        $warnings = baseEntryWarnings();

        $data = baseEntryProbe()->updatedAt(['kept' => 1], baseEntryItem($modified === '' ? '' : "<wp:post_modified>{$modified}</wp:post_modified>"), 'Probe::create');

        expect($data)->toBe($expected)
            ->and(\array_map(static fn (array $warning): mixed => $warning[0], baseEntryLogged($warnings)))->toBe($warned ? ['Probe::create: Failed to parse post modified date string for updated_at field'] : []);
    })->with([
        'a date' => ['2025-02-01 00:00:00', ['kept' => 1, 'updated_at' => (new DateTime('2025-02-01 00:00:00'))->getTimestamp()], false],
        'none' => ['', ['kept' => 1], false],
        'unreadable' => ['not a date', ['kept' => 1], true],
    ]);

    it('logs the date and the error with an unreadable modified time', function (): void {
        $warnings = baseEntryWarnings();

        baseEntryProbe()->updatedAt([], baseEntryItem('<wp:post_modified>not a date</wp:post_modified>'), 'Probe::create');
        $context = baseEntryLogged($warnings)[0][1] ?? null;

        expect(\is_array($context) ? \array_keys($context) : null)->toBe(['post_modified_date', 'error'])
            ->and(\is_array($context) ? $context['post_modified_date'] : null)->toBe('not a date')
            ->and(\is_array($context) && \is_string($context['error']) ? $context['error'] : '')->toContain('not a date');
    });

    it('dates a scheduled post, and no other', function (string $date, bool $scheduled, ?string $expected): void {
        $result = baseEntryProbe()->call('createEntryDate', $date, $scheduled);

        expect($result instanceof DateTime ? $result->format('Y-m-d H:i:s') : null)->toBe($expected);
    })->with([
        'scheduled' => ['2030-01-02 03:04:05', true, '2030-01-02 03:04:05'],
        'not scheduled' => ['2030-01-02 03:04:05', false, null],
        'scheduled with no date' => ['', true, null],
    ]);

    it('generates the slug and id the slug generator does', function (): void {
        $result = baseEntryProbe()->call('generateSlugAndId', '', '42', 'A Title');

        expect(\is_array($result) ? $result[0] : null)->toBe('a-title')
            ->and(\is_array($result) && \is_string($result[1]) ? $result[1] : '')->toMatch('/^a-title-42-[A-Za-z0-9]{16}$/');
    });
});

describe('saving an entry', function (): void {
    it('saves under the id in the data, or the slug when there is none', function (array $data, string $expected): void {
        baseEntryCollection();
        $output = new BufferedOutput;

        $entry = baseEntryProbe()->call('saveEntry', 'things', 'default', 'thing', ['title' => 'T', ...$data], $output);

        expect($entry instanceof Entry ? $entry->id() : null)->toBe($expected)
            ->and(baseEntrySlugs())->toBe([$expected => 'thing'])
            ->and($entry instanceof Entry ? baseEntryData($entry) : null)->toBe(['title' => 'T'])
            ->and($output->fetch())->toBe("  - Created entry 'thing' in Statamic for the 'default' site in the 'things' collection.".PHP_EOL);
    })->with([
        'an id' => [['id' => 'thing-7-AAAAAAAAAAAAAAAA'], 'thing-7-AAAAAAAAAAAAAAAA'],
        'a blank id' => [['id' => ''], 'thing'],
        'an id that is not a string' => [['id' => 7], 'thing'],
        'no id' => [[], 'thing'],
    ]);

    it('saves it in its site', function (): void {
        baseEntryCollection();

        $entry = baseEntryProbe()->call('saveEntry', 'things', 'other', 'thing', ['title' => 'T'], new BufferedOutput);

        expect($entry instanceof Entry ? $entry->locale() : null)->toBe('other');
    });

    it('publishes by the status, the published flag, or not at all, unless told', function (array $data, ?bool $published, bool $expected): void {
        baseEntryCollection();

        $entry = baseEntryProbe()->call('saveEntry', 'things', 'default', 'thing', ['title' => 'T', ...$data], new BufferedOutput, null, $published);

        expect($entry instanceof Entry ? $entry->published() : null)->toBe($expected);
    })->with([
        'published' => [['status_and_visibility' => 'published'], null, true],
        'private' => [['status_and_visibility' => 'private'], null, true],
        'scheduled' => [['status_and_visibility' => 'scheduled'], null, true],
        'a draft' => [['status_and_visibility' => 'draft', 'published' => true], null, false],
        'a published flag' => [['published' => true], null, true],
        'a draft flag' => [['published' => false], null, false],
        'nothing' => [[], null, false],
        'told to publish' => [['status_and_visibility' => 'draft'], true, true],
        'told not to' => [['status_and_visibility' => 'published'], false, false],
    ]);

    it('stores a false published flag, and drops a true one', function (bool $flag, array $expected): void {
        baseEntryCollection();

        $entry = baseEntryProbe()->call('saveEntry', 'things', 'default', 'thing', ['title' => 'T', 'published' => $flag], new BufferedOutput);

        expect($entry instanceof Entry ? baseEntryData($entry) : null)->toBe($expected);
    })->with([
        'false' => [false, ['title' => 'T', 'published' => false]],
        'true' => [true, ['title' => 'T']],
    ]);

    it('skips an entry that exists, returning it', function (): void {
        baseEntryCollection();
        $existing = baseEntryMake('thing-7-AAAAAAAAAAAAAAAA', 'thing');
        $creator = baseEntryProbe();
        $creator->setEntryOverwriteBehavior('Skip matching files');
        $output = new BufferedOutput;

        $result = $creator->call('saveEntry', 'things', 'default', 'thing', ['title' => 'New', 'id' => 'thing-7-AAAAAAAAAAAAAAAA'], $output);

        expect($result)->toBe($existing)
            ->and(baseEntryData($existing))->toBe(['title' => 'thing'])
            ->and($output->fetch())->toBe("Skipped entry 'thing' as it already exists.".PHP_EOL);
    });

    it('overwrites an entry that exists, keeping its id', function (): void {
        baseEntryCollection();
        baseEntryMake('thing-7-AAAAAAAAAAAAAAAA', 'thing');
        $output = new BufferedOutput;

        $result = baseEntryProbe()->call('saveEntry', 'things', 'default', 'thing', ['title' => 'New', 'id' => 'thing-7-BBBBBBBBBBBBBBBB'], $output);

        expect($result instanceof Entry ? $result->id() : null)->toBe('thing-7-AAAAAAAAAAAAAAAA')
            ->and(baseEntrySlugs())->toBe(['thing-7-AAAAAAAAAAAAAAAA' => 'thing'])
            ->and($result instanceof Entry ? baseEntryData($result) : null)->toBe(['title' => 'New'])
            ->and($output->fetch())->toContain("Created entry 'thing'");
    });

    it('saves an additional entry beside one that exists, under its first numbered slug and its own id', function (): void {
        // ---
        // Pins today's behavior, which #265 records: Statamic stores the slug without
        // its dot, so the search for a free slug never finds the stored one and every
        // copy takes `.1`, and the copy keeps the id generated for this run rather
        // than the one the search made.
        // ---
        baseEntryCollection();
        baseEntryMake('thing-7-AAAAAAAAAAAAAAAA', 'thing');
        baseEntryMake('other-1', 'thing.1');
        $creator = baseEntryProbe();
        $creator->setEntryOverwriteBehavior('Create an additional file');
        $output = new BufferedOutput;

        $result = $creator->call('saveEntry', 'things', 'default', 'thing', ['title' => 'New', 'id' => 'thing-7-BBBBBBBBBBBBBBBB'], $output);

        expect($result instanceof Entry ? [$result->id(), $result->slug()] : null)->toBe(['thing-7-BBBBBBBBBBBBBBBB', 'thing1'])
            ->and($result instanceof Entry ? baseEntryData($result) : null)->toBe(['title' => 'New'])
            ->and(baseEntrySlugs())->toBe(['thing-7-AAAAAAAAAAAAAAAA' => 'thing', 'other-1' => 'thing1', 'thing-7-BBBBBBBBBBBBBBBB' => 'thing1'])
            ->and($output->fetch())->toBe("  - Created entry 'thing.1' in Statamic for the 'default' site in the 'things' collection.".PHP_EOL);
    });

    it('numbers an additional entry from the slug, dropping a number it already carries', function (): void {
        baseEntryCollection();
        baseEntryMake('thing-7-AAAAAAAAAAAAAAAA', 'thing.4');
        $creator = baseEntryProbe();
        $creator->setEntryOverwriteBehavior('Create an additional file');
        $output = new BufferedOutput;

        $creator->call('saveEntry', 'things', 'default', 'thing.4', ['title' => 'New', 'id' => 'thing-7-BBBBBBBBBBBBBBBB'], $output);

        expect($output->fetch())->toContain("Created entry 'thing.1'");
    });
    it('dates an entry in a dated collection from its date, its post date, or now', function (?string $date, ?string $postDate, ?string $expected, bool $warned): void {
        baseEntryCollection(['date'], dated: true);
        $warnings = baseEntryWarnings();
        $output = new BufferedOutput;
        $before = new DateTime;

        $entry = baseEntryProbe()->call('saveEntry', 'things', 'default', 'thing', ['title' => 'T'], $output, $date === null ? null : new DateTime($date), true, $postDate);
        $saved = $entry instanceof Entry ? $entry->date() : null;

        expect($saved instanceof CarbonInterface)->toBeTrue()
            ->and(\array_map(static fn (array $warning): mixed => $warning[0], baseEntryLogged($warnings)))->toBe($warned ? ['BaseEntryCreator::saveEntry: Failed to parse post date string'] : []);

        if ($expected !== null) {
            expect($saved instanceof CarbonInterface ? $saved->format('Y-m-d H:i') : null)->toBe($expected)
                ->and($output->fetch())->toContain("Created entry 'thing' (date: {$expected}");
        } else {
            expect($saved instanceof CarbonInterface ? $saved->getTimestamp() : 0)->toBeGreaterThanOrEqual($before->getTimestamp() - 60);
        }
    })->with([
        'a date' => ['2024-03-04 05:06:07', '2020-01-01 00:00:00', '2024-03-04 05:06', false],
        'a post date' => [null, '2020-01-01 10:11:00', '2020-01-01 10:11', false],
        'neither' => [null, null, null, false],
        'a blank post date' => [null, '', null, false],
        'an unreadable post date' => [null, 'not a date', null, true],
    ]);

    it('logs the date it cannot read, and its error', function (): void {
        baseEntryCollection(['date'], dated: true);
        $warnings = baseEntryWarnings();

        baseEntryProbe()->call('saveEntry', 'things', 'default', 'thing', ['title' => 'T'], new BufferedOutput, null, true, 'not a date');
        $context = baseEntryLogged($warnings)[0][1] ?? null;

        expect(\is_array($context) ? \array_keys($context) : null)->toBe(['collection_handle', 'post_date_str', 'error'])
            ->and(\is_array($context) ? [$context['collection_handle'], $context['post_date_str']] : null)->toBe(['things', 'not a date']);
    });

    it('dates nothing in an undated collection, warning when given a date', function (?string $date, bool $warned): void {
        baseEntryCollection();
        $warnings = baseEntryWarnings();
        $output = new BufferedOutput;

        $entry = baseEntryProbe()->call('saveEntry', 'things', 'default', 'thing', ['title' => 'T'], $output, $date === null ? null : new DateTime($date), true, '2020-01-01');

        expect($entry instanceof Entry ? $entry->date() : 'no entry')->toBeNull()
            ->and(baseEntryLogged($warnings))->toBe($warned ? [['BaseEntryCreator::saveEntry: Skipping date setting - collection not dated', ['collection_handle' => 'things', 'collection_dated' => false]]] : [])
            ->and($output->fetch())->toBe($warned
                ? "  - Created entry 'thing' (date: 2024-03-04 05:06:07) in Statamic for the 'default' site in the 'things' collection.".PHP_EOL
                : "  - Created entry 'thing' in Statamic for the 'default' site in the 'things' collection.".PHP_EOL);
    })->with([
        'a date' => ['2024-03-04 05:06:07', true],
        'none' => [null, false],
    ]);
});

describe('finding an entry an earlier import saved', function (): void {
    it('finds one by its id, in its collection and site', function (string $site, ?string $expected): void {
        baseEntryCollection();
        baseEntryMake('exact', 'exact');

        $found = baseEntryProbe()->call('findExistingEntry', 'things', $site, 'exact', 'exact');

        expect($found instanceof Entry ? $found->id() : null)->toBe($expected);
    })->with([
        'its site' => ['default', 'exact'],
        'another site' => ['other', null],
    ]);

    it('finds one with the same post id and base slug and another random part', function (string $existingId, string $existingSlug, string $id, ?string $expected): void {
        baseEntryCollection();
        baseEntryMake($existingId, $existingSlug);
        baseEntryMake('decoy-9-CCCCCCCCCCCCCCCC', 'my-page-9');

        $found = baseEntryProbe()->call('findExistingEntry', 'things', 'default', $id, 'my-page');

        expect($found instanceof Entry ? $found->id() : null)->toBe($expected);
    })->with([
        'the same base' => ['my-page-7-AAAAAAAAAAAAAAAA', 'my-page', 'my-page-7-BBBBBBBBBBBBBBBB', 'my-page-7-AAAAAAAAAAAAAAAA'],
        'a numbered slug' => ['my-page2-7-AAAAAAAAAAAAAAAA', 'my-page.2', 'my-page-7-BBBBBBBBBBBBBBBB', 'my-page2-7-AAAAAAAAAAAAAAAA'],
        'another post id' => ['my-page-8-AAAAAAAAAAAAAAAA', 'my-page', 'my-page-7-BBBBBBBBBBBBBBBB', null],
        'a short random part' => ['my-page-7-AAAAAAAAAAAAAAA', 'my-page', 'my-page-7-BBBBBBBBBBBBBBBB', null],
        'another base' => ['my-pages-7-AAAAAAAAAAAAAAAA', 'my-pagesx', 'my-page-7-BBBBBBBBBBBBBBBB', null],
        'an id of two parts' => ['my-page-7-AAAAAAAAAAAAAAAA', 'my-page', 'page-7', null],
        'an existing id of two parts' => ['page-AAAAAAAAAAAAAAAA', 'my-page', 'my-page-7-BBBBBBBBBBBBBBBB', null],
    ]);
});

describe('what it keeps', function (): void {
    it('reports each unmapped taxonomy once per site', function (): void {
        $creator = baseEntryProbe();
        $output = new BufferedOutput;

        $creator->call('reportUnmappedTaxonomies', ['genre' => 3, 'mood' => 1], 'default', $output);
        $creator->call('reportUnmappedTaxonomies', ['genre' => 3], 'default', $output);
        $creator->call('reportUnmappedTaxonomies', ['genre' => 2], 'other', $output);

        expect($output->fetch())->toBe(
            "  - Skipping 'genre': recorded as having no Statamic destination. 3 term(s) not imported.".PHP_EOL
            ."  - Skipping 'mood': recorded as having no Statamic destination. 1 term(s) not imported.".PHP_EOL
            ."  - Skipping 'genre': recorded as having no Statamic destination. 2 term(s) not imported.".PHP_EOL
        )
            ->and(ImportWarningManager::getWarnings('content', 'taxonomy_deliberately_unmapped'))->toBe([
                ['taxonomy' => 'genre', 'term_count' => 3, 'site' => 'default'],
                ['taxonomy' => 'mood', 'term_count' => 1, 'site' => 'default'],
                ['taxonomy' => 'genre', 'term_count' => 2, 'site' => 'other'],
            ]);
    });

    it('records page data, by the entry id it was saved as, with its link and entry when given', function (): void {
        baseEntryCollection();
        $entry = baseEntryMake('saved-id', 'saved');
        $creator = baseEntryProbe();

        $creator->call('addPageData', '7', 'generated-id', '3', 2, 'Seven', 'https://example.test/7/', $entry);
        $creator->call('addPageData', '8', 'eight-id', '0', 0, 'Eight');

        expect($creator->getPageData())->toBe([
            '7' => ['entry' => 'saved-id', 'parent' => '3', 'menu_order' => 2, 'title' => 'Seven', 'link' => 'https://example.test/7/', 'entry_object' => $entry],
            '8' => ['entry' => 'eight-id', 'parent' => '0', 'menu_order' => 0, 'title' => 'Eight'],
        ]);
    });

    it('reads content as empty when it has nothing or only the empty note', function (array $content, bool $empty): void {
        expect(baseEntryProbe()->call('isEmptyContent', $content))->toBe($empty);
    })->with([
        'nothing' => [[], true],
        'the empty note' => [[['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Imported from WordPress XML. Content empty.']]]], true],
        'other text' => [[['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]], false],
    ]);

    it('starts with no page data and no people groups, and keeps its overwrite behaviors', function (): void {
        $creator = baseEntryProbe();
        $creator->setEntryOverwriteBehavior('Skip matching files');
        $creator->setTaxonomyOverwriteBehavior('Skip matching terms');

        expect($creator->getPageData())->toBe([])
            ->and($creator->getPeopleGroups())->toBe([])
            ->and($creator->read('entryOverwriteBehavior'))->toBe('Skip matching files')
            ->and($creator->read('taxonomyOverwriteBehavior'))->toBe('Skip matching terms');
    });
});

describe('the entry half, more closely', function (): void {
    it('names a gated item with a blank page link as this item', function (): void {
        baseEntryWarnings();
        ProcessingContext::setWordPressPageLink('');
        $output = new BufferedOutput;

        baseEntryProbe()->call('publishUnlessWpMembersGated', true, '1', 'Probe::save', $output);

        expect($output->fetch())->toBe('Imported as unpublished: WP-Members gates this item (_wpmem_block=1).'.PHP_EOL);
    });

    it('reads no GMT date when none is given', function (): void {
        baseEntryCollection(['status_and_visibility', 'publish_at']);
        $warnings = baseEntryWarnings();

        [, $data] = baseEntryProbe()->mapStatus([], 'future', '', '2030-01-02 03:04:05', 'things', new BufferedOutput, null);

        expect($data)->toBe(['status_and_visibility' => 'draft', 'publish_at' => '2030-01-02 03:04'])
            ->and(baseEntryLogged($warnings)[0][1] ?? null)->toBe(['post_date_gmt' => '', 'post_date' => '2030-01-02 03:04:05', 'publish_at' => '2030-01-02 03:04']);
    });

    it('publishes by an exact status only', function (): void {
        baseEntryCollection();

        $entry = baseEntryProbe()->call('saveEntry', 'things', 'default', 'thing', ['title' => 'T', 'status_and_visibility' => true], new BufferedOutput);

        expect($entry instanceof Entry ? $entry->published() : null)->toBeFalse();
    });

    it('deletes the entry it overwrites', function (): void {
        baseEntryCollection();
        baseEntryMake('thing-7-AAAAAAAAAAAAAAAA', 'thing');
        $deleted = new ArrayObject;
        Event::listen(EntryDeleted::class, static function (EntryDeleted $event) use ($deleted): void {
            $deleted->append($event->entry instanceof Entry ? $event->entry->id() : null);
        });

        baseEntryProbe()->call('saveEntry', 'things', 'default', 'thing', ['title' => 'New', 'id' => 'thing-7-BBBBBBBBBBBBBBBB'], new BufferedOutput);

        expect($deleted->getArrayCopy())->toBe(['thing-7-AAAAAAAAAAAAAAAA']);
    });

    it('reports a new unmapped taxonomy after one already reported', function (): void {
        $creator = baseEntryProbe();
        $output = new BufferedOutput;
        $creator->call('reportUnmappedTaxonomies', ['genre' => 3], 'default', $output);

        $creator->call('reportUnmappedTaxonomies', ['genre' => 3, 'mood' => 1], 'default', $output);

        expect($output->fetch())->toBe(
            "  - Skipping 'genre': recorded as having no Statamic destination. 3 term(s) not imported.".PHP_EOL
            ."  - Skipping 'mood': recorded as having no Statamic destination. 1 term(s) not imported.".PHP_EOL
        );
    });

    it('looks for an earlier entry only in its collection, its site, and slugs that start with its base', function (Closure $setup): void {
        baseEntryCollection();
        $others = CollectionFacade::make('others');
        $others->sites(['default', 'other']);
        $others->save();
        $setup();

        $found = baseEntryProbe()->call('findExistingEntry', 'things', 'default', 'my-page-7-BBBBBBBBBBBBBBBB', 'my-page');

        expect($found)->toBeNull();
    })->with([
        'another collection' => [static function (): void {
            $entry = EntryFacade::make();

            if (! $entry instanceof Entry) {
                throw new RuntimeException('Could not make an entry.');
            }

            $entry->collection('others');
            $entry->locale('default');
            $entry->slug('my-page');
            $entry->id('my-page-7-AAAAAAAAAAAAAAAA');
            $entry->save();
        }],
        'another site' => [static fn (): Entry => baseEntryMake('my-page-7-AAAAAAAAAAAAAAAA', 'my-page', 'other')],
        'another slug' => [static fn (): Entry => baseEntryMake('my-page-7-AAAAAAAAAAAAAAAA', 'unrelated')],
    ]);

    it('matches an earlier entry by its id base alone, or by its slug with numbers removed', function (string $existingId, string $existingSlug, string $id, ?string $expected): void {
        baseEntryCollection();
        baseEntryMake($existingId, $existingSlug);

        $found = baseEntryProbe()->call('findExistingEntry', 'things', 'default', $id, 'ignored');

        expect($found instanceof Entry ? $found->id() : null)->toBe($expected);
    })->with([
        'the id base, under a longer slug' => ['my-page-7-AAAAAAAAAAAAAAAA', 'my-pageextra', 'my-page-7-BBBBBBBBBBBBBBBB', 'my-page-7-AAAAAAAAAAAAAAAA'],
        'a numbered base' => ['my-page-7-AAAAAAAAAAAAAAAA', 'my-page2', 'my-page2-7-BBBBBBBBBBBBBBBB', 'my-page-7-AAAAAAAAAAAAAAAA'],
        'an id of two parts, beside a slug of numbers' => ['q-x-AAAAAAAAAAAAAAAA', '12', 'x-7', null],
        'an existing id of two parts' => ['x-AAAAAAAAAAAAAAAA', 'q', 'q-x-BBBBBBBBBBBBBBBB', null],
    ]);
});
