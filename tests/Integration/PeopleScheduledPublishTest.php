<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery\LegacyMockInterface;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fieldtype;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins how a WordPress `future` post maps when its blueprint schedules through
 * `publish_at` (wordpress-importer-archive#1233).
 *
 * `UAMS-Web/uams-statamic-archive#2552` made the host app's `people` collection undated. A
 * scheduled person is now a `draft` with `publish_at` set, and a scheduled job
 * publishes it once that time passes. The importer used to map `future` to
 * `scheduled`, which the host treats as live, so a scheduled person was public at
 * import. Here the probe blueprint carries the host's `publish_at` field as
 * `person.yaml` declares it, and a `posts` blueprint without it is the control
 * that keeps the old mapping.
 *
 * @command  php vendor/bin/pest tests/Integration/PeopleScheduledPublishTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    // The host's undated people collection, with publish_at as person.yaml declares it
    people1233MakeCollection('people', false, [
        ['handle' => 'status_and_visibility', 'field' => ['type' => 'select']],
        ['handle' => 'password_protected', 'field' => ['type' => 'toggle']],
        ['handle' => 'publish_at', 'field' => ['type' => 'date', 'mode' => 'single', 'time_enabled' => true]],
    ]);

    // A dated collection without publish_at, which keeps the scheduled-status mapping
    people1233MakeCollection('posts', true, [
        ['handle' => 'status_and_visibility', 'field' => ['type' => 'select']],
        ['handle' => 'password_protected', 'field' => ['type' => 'toggle']],
        ['handle' => 'date', 'field' => ['type' => 'date']],
    ]);
});

afterEach(function (): void {
    // Blueprints are not Stache items, so they are removed by hand
    foreach (['collections.people.people', 'collections.posts.posts'] as $handle) {
        $blueprint = BlueprintFacade::find($handle);

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }
});

it('imports a future person as a draft with publish_at at its GMT post date', function (): void {
    $data = [];

    $scheduled = (new People1233Probe)->map('future', 'people', '2026-10-01 09:30:00', '2026-10-01 14:30:00', $data);

    expect($data['status_and_visibility'])->toBe('draft')
        ->and($data['publish_at'])->toBe('2026-10-01 14:30')
        ->and($data)->not->toHaveKey('date')
        ->and($scheduled)->toBeFalse();
});

it('writes a publish_at the host fieldtype reads back as the same UTC instant', function (): void {
    $data = [];

    (new People1233Probe)->map('future', 'people', '2026-10-01 09:30:00', '2026-10-01 14:30:00', $data);

    // The host job resolves publish_at through the field's own fieldtype, as here
    $blueprint = BlueprintFacade::find('collections.people.people');
    $field = $blueprint instanceof Blueprint ? $blueprint->field('publish_at') : null;

    if (! $field instanceof Field) {
        throw new RuntimeException('The probe blueprint has no publish_at field.');
    }

    $fieldtype = $field->fieldtype();

    if (! $fieldtype instanceof Fieldtype) {
        throw new RuntimeException('The publish_at field has no fieldtype.');
    }

    $time = $fieldtype->augment($data['publish_at']);

    expect($time)->toBeInstanceOf(Carbon::class);

    if (! $time instanceof Carbon) {
        return;
    }

    expect($time->toIso8601ZuluString())->toBe('2026-10-01T14:30:00Z');
});

it('falls back to the local post date as UTC when WordPress wrote no GMT date, and says so', function (): void {
    $logSpy = people1233SpyLog();
    $data = [];

    (new People1233Probe)->map('future', 'people', '2026-10-01 09:30:00', '0000-00-00 00:00:00', $data);

    expect($data['status_and_visibility'])->toBe('draft')
        ->and($data['publish_at'])->toBe('2026-10-01 09:30');

    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, 'may be off by the site offset')
        ),
        Mockery::any(),
    ]);
});

it('leaves publish_at unset and keeps the person a draft when no date can be read', function (): void {
    $logSpy = people1233SpyLog();
    $data = [];

    (new People1233Probe)->map('future', 'people', '', '', $data);

    expect($data['status_and_visibility'])->toBe('draft')
        ->and($data)->not->toHaveKey('publish_at');

    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, 'publish_at is unset')
        ),
        Mockery::any(),
    ]);
});

it('never writes an already-due publish_at from WordPress zero dates', function (string $postDate): void {
    $data = [];

    (new People1233Probe)->map('future', 'people', $postDate, '0000-00-00 00:00:00', $data);

    expect($data['status_and_visibility'])->toBe('draft')
        ->and($data)->not->toHaveKey('publish_at');
})->with([
    'all-zero post date' => '0000-00-00 00:00:00',
    'all-zero pubDate' => 'Mon, 30 Nov -0001 00:00:00 +0000',
]);

it('does not warn when the GMT date is present', function (): void {
    $logSpy = people1233SpyLog();
    $data = [];

    (new People1233Probe)->map('future', 'people', '2026-10-01 09:30:00', '2026-10-01 14:30:00', $data);

    $logSpy->shouldNotHaveReceived('warning');
});

it('keeps a published person published, with no publish_at', function (): void {
    $data = [];

    (new People1233Probe)->map('publish', 'people', '2026-10-01 09:30:00', '2026-10-01 14:30:00', $data);

    expect($data['status_and_visibility'])->toBe('published')
        ->and($data)->not->toHaveKey('publish_at');
});

it('still schedules by status and date where the blueprint has no publish_at', function (): void {
    $data = [];

    $scheduled = (new People1233Probe)->map('future', 'posts', '2026-10-01 09:30:00', '2026-10-01 14:30:00', $data);

    expect($data['status_and_visibility'])->toBe('scheduled')
        ->and($data['date'])->toBe('2026-10-01 09:30:00')
        ->and($data)->not->toHaveKey('publish_at')
        ->and($scheduled)->toBeTrue();
});

it('reads wp:post_date_gmt from a WXR item', function (): void {
    $item = new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<wp:post_date>2026-10-01 09:30:00</wp:post_date>'
        .'<wp:post_date_gmt>2026-10-01 14:30:00</wp:post_date_gmt>'
        .'</item>'
    );

    expect((new People1233Probe)->gmt($item))->toBe('2026-10-01 14:30:00');
});

/**
 * Create a collection with a single entry blueprint.
 *
 * @param  string  $handle  The collection handle, also used as the blueprint handle
 * @param  bool  $dated  Whether the collection is dated
 * @param  list<array<string, mixed>>  $fields  The blueprint's fields
 */
function people1233MakeCollection(string $handle, bool $dated, array $fields): void
{
    $collection = CollectionFacade::make($handle);
    $collection->sites(['default']);
    $collection->dated($dated);
    $collection->save();

    $blueprint = BlueprintFacade::make($handle);
    $blueprint->setNamespace('collections.'.$handle);
    $blueprint->setContents([
        'fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ...$fields,
        ],
    ]);
    $blueprint->save();
}

/**
 * Spy on the Log facade.
 */
function people1233SpyLog(): LegacyMockInterface
{
    return Log::spy();
}

/**
 * Exposes `mapStatusAndVisibility()` so its status mapping can be driven directly.
 */
final class People1233Probe extends BaseEntryCreator
{
    /**
     * Map a WordPress status through the real `mapStatusAndVisibility()`.
     *
     * @param  string  $status  The wp:status value
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $postDate  The wp:post_date value
     * @param  string  $postDateGmt  The wp:post_date_gmt value
     * @param  array<int|string, mixed>  &$data  The entry data array to populate
     * @return bool Whether the entry is dated as a scheduled post
     */
    public function map(
        string $status,
        string $collectionHandle,
        string $postDate,
        string $postDateGmt,
        array &$data
    ): bool {
        return $this->mapStatusAndVisibility(
            $status,
            '',
            $postDate,
            $data,
            $collectionHandle,
            new NullOutput,
            $postDateGmt
        );
    }

    /**
     * Read the GMT post date through the real `extractPostDateGmtString()`.
     *
     * @param  SimpleXMLElement  $item  The WXR item
     * @return string The wp:post_date_gmt value
     */
    public function gmt(SimpleXMLElement $item): string
    {
        return $this->extractPostDateGmtString($item);
    }

    /**
     * Not exercised here.
     *
     * @param  string  $site  The Statamic site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  XmlParser  $xmlParser  Parser for the export
     * @param  mixed  $assetImporter  Unused
     * @param  OutputInterface  $output  Console output
     * @param  mixed  $metadataMapper  Unused
     * @param  mixed  $contentProcessor  Unused
     * @param  ?array<int|string, mixed>  $minervaKbSettings  Unused
     * @return array<int|string, mixed> Always empty
     */
    public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        $assetImporter,
        OutputInterface $output,
        $metadataMapper,
        $contentProcessor,
        ?array $minervaKbSettings = null
    ): array {
        return [];
    }

    /**
     * Not exercised here.
     */
    public function hasShowPeople(): bool
    {
        return false;
    }

    /**
     * Not exercised here.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostCategories(): array
    {
        return [];
    }

    /**
     * Not exercised here.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Not exercised here.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {}
}
