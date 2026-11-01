<?php

declare(strict_types=1);

/**
 * Tests that `EntryFieldUpdater` sets fields on an entry, saves it while keeping
 * its original date, and reports a failure through the log instead of throwing
 * (#1314). Routed to the light Statamic band for `Entry` and the `Log` facade.
 *
 * The entry is a subclass that records its calls rather than touching the Stache.
 *
 * @command  php vendor/bin/pest tests/Integration/EntryFieldUpdaterTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryFieldUpdater;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * An entry that records the calls the updater makes on it.
 */
class FieldUpdaterRecordingEntry extends Entry
{
    /**
     * @var list<string> Each call, in order
     */
    public array $calls = [];

    /**
     * @param  ?Carbon  $storedDate  What `date()` reads back
     * @param  mixed  $saveResult  What `save()` returns
     * @param  ?Throwable  $failure  Thrown from `set()` when given
     * @param  mixed  $title  What `get('title')` returns
     * @param  mixed  $entryId  What `id()` returns
     */
    public function __construct(
        private readonly ?Carbon $storedDate = null,
        private readonly mixed $saveResult = true,
        private readonly ?Throwable $failure = null,
        private readonly mixed $title = null,
        private readonly mixed $entryId = null
    ) {
        parent::__construct();
    }

    /**
     * Read the stored date, or record a date being set.
     *
     * @param  mixed  $date  The date to set, or null to read
     * @return mixed The stored date, or this entry
     */
    public function date($date = null)
    {
        if ($date === null) {
            return $this->storedDate;
        }

        $this->calls[] = 'date:'.($date instanceof Carbon ? $date->toDateTimeString() : 'other');

        return $this;
    }

    /**
     * Record a field being set, or throw the configured failure.
     *
     * @param  mixed  $key  The field handle
     * @param  mixed  $value  The value
     * @return $this This entry
     */
    public function set($key, $value)
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->calls[] = 'set:'.(\is_string($key) ? $key : '').'='.(\is_string($value) ? $value : '');

        return $this;
    }

    /**
     * Record the save.
     *
     * @return mixed The configured result
     */
    public function save()
    {
        $this->calls[] = 'save';

        return $this->saveResult;
    }

    /**
     * The configured title, for `get('title')`.
     *
     * @param  mixed  $key  The field handle
     * @param  mixed  $fallback  Unused
     * @return mixed The title, or null for any other key
     */
    public function get($key, $fallback = null)
    {
        return $key === 'title' ? $this->title : null;
    }

    /**
     * The configured id.
     *
     * @param  mixed  $id  Unused
     * @return mixed The id
     */
    public function id($id = null)
    {
        return $this->entryId;
    }
}

it('sets every field, restores the original date and reports the save', function (mixed $saved, bool $expected): void {
    $entry = new FieldUpdaterRecordingEntry(Carbon::parse('2020-05-01 09:30:00'), $saved);

    expect(EntryFieldUpdater::updateEntryFields($entry, ['title' => 'New title', 'summary' => 'Text']))->toBe($expected)
        ->and($entry->calls)->toBe([
            'set:title=New title',
            'set:summary=Text',
            'date:2020-05-01 09:30:00',
            'save',
        ]);
})->with([
    'a save that succeeds' => [true, true],
    'a save that returns false' => [false, false],
    'a save that returns nothing' => [null, true],
]);

it('saves without touching the date when the entry has none', function (): void {
    $entry = new FieldUpdaterRecordingEntry;

    expect(EntryFieldUpdater::updateEntryFields($entry, ['title' => 'T']))->toBeTrue()
        ->and($entry->calls)->toBe(['set:title=T', 'save']);
});

it('returns the entry unsaved when asked not to save', function (): void {
    $entry = new FieldUpdaterRecordingEntry(Carbon::parse('2020-05-01'));

    expect(EntryFieldUpdater::updateEntryFields($entry, ['title' => 'T'], false))->toBe($entry)
        ->and($entry->calls)->toBe(['set:title=T']);
});

it('logs a failure and returns false instead of throwing', function (mixed $title, mixed $id, string $expectedTitle, string $expectedId): void {
    $logSpy = Log::spy();
    $entry = new FieldUpdaterRecordingEntry(null, true, new RuntimeException('disk full'), $title, $id);

    expect(EntryFieldUpdater::updateEntryFields($entry, ['title' => 'T', 'summary' => 'S']))->toBeFalse()
        ->and($entry->calls)->toBe([]);

    $logSpy->shouldHaveReceived('error', [
        "EntryFieldUpdater::updateEntryFields: Failed to update fields [title, summary] on entry '{$expectedTitle}' (ID: {$expectedId})",
        Mockery::on(
            static fn (mixed $context): bool => \is_array($context)
                && ($context['error'] ?? null) === 'disk full'
                && ($context['fields'] ?? null) === ['title' => 'T', 'summary' => 'S']
                && ($context['entry_id'] ?? null) === $id
        ),
    ]);
})->with([
    'a string title and id' => ['Clinic', 'entry-1', 'Clinic', 'entry-1'],
    'an integer id' => ['Clinic', 42, 'Clinic', '42'],
    'no title and no id' => [null, null, '', ''],
]);
