<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\LegacyMockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Pins that a `show-people` shortcode imports an `order_by` the host app offers (wordpress-importer-archive#1233).
 *
 * The People Showcase block in `uams-statamic` offers `none`, `title`,
 * `sortpriority`, `sorttext` and `modified`. `date` left that list when people became
 * undated (`UAMS-Web/uams-statamic-archive#2552`), and values such as `lastname` were never
 * on it, yet the processor passed `orderby` through unchanged. An unsupported value
 * now imports as `none` and is logged. The host block's sort `switch` already sends
 * any value it does not list to `order`, the same sort as `none`, so the rendered
 * order is unchanged. A missing `orderby` keeps the default, `sortpriority`. Routed to
 * the light Statamic band because the processor logs through the `Log` facade and
 * builds its set with Statamic's `RowId`.
 *
 * @command  php vendor/bin/pest tests/Integration/PeopleShortcodeOrderByTest.php
 */
it('passes a supported orderby through unchanged', function (string $orderBy): void {
    $logSpy = people1233OrderBySpyLog();

    expect(people1233OrderBy($this->createMock(AssetImporter::class), ['orderby' => $orderBy]))->toBe($orderBy);

    $logSpy->shouldNotHaveReceived('warning', [
        Mockery::pattern('/resolveOrderBy/'),
        Mockery::any(),
    ]);
})->with(['none', 'title', 'sortpriority', 'sorttext', 'modified']);

it('maps menu_order to none, which the plugin sorted the same way', function (): void {
    $logSpy = people1233OrderBySpyLog();

    expect(people1233OrderBy($this->createMock(AssetImporter::class), ['orderby' => 'menu_order']))->toBe('none');

    $logSpy->shouldNotHaveReceived('warning', [
        Mockery::pattern('/resolveOrderBy/'),
        Mockery::any(),
    ]);
});

it('imports orderby=date as none and logs it', function (): void {
    $logSpy = people1233OrderBySpyLog();

    expect(people1233OrderBy($this->createMock(AssetImporter::class), ['orderby' => 'date']))->toBe('none');

    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, "orderby='date' has no People Showcase equivalent")
        ),
        Mockery::any(),
    ]);
});

it('imports another unsupported orderby as none and logs it', function (string $orderBy): void {
    $logSpy = people1233OrderBySpyLog();

    expect(people1233OrderBy($this->createMock(AssetImporter::class), ['orderby' => $orderBy]))->toBe('none');

    $logSpy->shouldHaveReceived('warning', [
        Mockery::pattern('/resolveOrderBy/'),
        Mockery::any(),
    ]);
})->with(['lastname', 'firstname', 'id', 'rand']);

it('matches orderby case-insensitively, as the processor lowercases attributes', function (): void {
    expect(people1233OrderBy($this->createMock(AssetImporter::class), ['orderby' => 'Menu_Order']))->toBe('none')
        ->and(people1233OrderBy($this->createMock(AssetImporter::class), ['orderby' => 'DATE']))->toBe('none');
});

it('imports a missing orderby as the default without a warning', function (): void {
    $logSpy = people1233OrderBySpyLog();

    expect(people1233OrderBy($this->createMock(AssetImporter::class), []))->toBe('sortpriority');

    $logSpy->shouldNotHaveReceived('warning', [
        Mockery::pattern('/resolveOrderBy/'),
        Mockery::any(),
    ]);
});

/**
 * Parse a show-people shortcode and return the order_by it imports as.
 *
 * @param  AssetImporter  $assetImporter  A mock; no asset is imported
 * @param  array<string, string>  $attributes  The shortcode attributes
 * @return mixed The imported order_by value
 */
function people1233OrderBy(AssetImporter $assetImporter, array $attributes): mixed
{
    $set = (new PeopleShortcodeProcessor('show-people'))->parse(
        $attributes,
        'default',
        $assetImporter,
        new NullOutput,
        [],
        'test-page'
    );

    $attrs = $set['attrs'] ?? null;
    $values = \is_array($attrs) ? ($attrs['values'] ?? null) : null;

    return \is_array($values) ? ($values['order_by'] ?? null) : null;
}

/**
 * Spy on the Log facade.
 */
function people1233OrderBySpyLog(): LegacyMockInterface
{
    return Log::spy();
}
