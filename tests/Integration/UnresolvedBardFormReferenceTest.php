<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * A Bard form set naming a form the API never served must not abort the import (wordpress-importer-archive#1162).
 *
 * The lookup at `updateAllFormHandlesInArray()` was unguarded, so `Undefined array key`
 * escaped as a fatal `ErrorException` during `Processing form updates` — a pass that runs
 * *after* every entry is written and *before* the post-import work. On the observed run all
 * 366 entries existed and none of the deferred link resolution, tree update, Bard
 * sanitization, search index rebuild or warning summary ran. Re-running reproduced it
 * exactly, because the dead reference lives in the source content.
 *
 * The `! is_string()` branch one line below the throw was always the correct response; it
 * was simply unreachable.
 *
 * @command  php vendor/bin/pest tests/Integration/UnresolvedBardFormReferenceTest.php
 */
uses(StatamicTestCase::class);

/**
 * Drive the private recursive updater, which is where the crash was.
 *
 * @param  array<int|string, mixed>  $content
 * @param  array<int|string, mixed>  $map
 * @return array{array<int|string, mixed>, array<int, mixed>, array<int, mixed>}
 */
function runFormHandleUpdate(array $content, array $map): array
{
    // Resolved from the container rather than constructed: it takes the three plugin
    // coordinators, none of which this test has an opinion about.
    $coordinator = app(FormPluginCoordinator::class);
    $method = new ReflectionMethod($coordinator, 'updateAllFormHandlesInArray');
    $method->setAccessible(true);

    /** @var array{array<int|string, mixed>, array<int, mixed>, array<int, mixed>} $result */
    $result = $method->invoke($coordinator, $content, $map);

    return $result;
}

/**
 * One Bard form set carrying an `import_wordpress_notes` value.
 *
 * @return array<int|string, mixed>
 */
function bardFormSet(string $note): array
{
    return [
        [
            'type' => 'set',
            'attrs' => [
                'id' => 'set-1',
                'values' => [
                    'type' => 'form',
                    'import_wordpress_notes' => $note,
                ],
            ],
        ],
    ];
}

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

it('survives a Bard set naming a form the map does not carry', function (): void {
    // The exact shape from the reported run: `gethealthy.uams.edu` served 29 form
    // definitions, numbered with gaps, and one post still embedded form 14.
    $content = bardFormSet('Gravity Forms form ID 14');

    [$result, $formUpdates, $unresolved] = runFormHandleUpdate($content, [
        'Gravity Forms form ID 15' => 'a_served_form',
    ]);

    // Did not throw, which before the guard it did — fatally, for the whole run.
    expect($unresolved)->toContain('Gravity Forms form ID 14');

    // The set is written back exactly as authored: the note is the only surviving record
    // of which form was meant, and rewriting it destroys what an editor needs.
    expect($result)->toBe($content);

    // Nothing was treated as a successful mapping.
    expect($formUpdates)->toBeEmpty();
});

it('still resolves a form the map does carry', function (): void {
    // Control: the guard must not swallow the case that works, or the fix would be
    // indistinguishable from disabling form updates altogether.
    $content = bardFormSet('Gravity Forms form ID 15');

    [$result, $formUpdates, $unresolved] = runFormHandleUpdate($content, [
        'Gravity Forms form ID 15' => 'a_served_form',
    ]);

    expect($unresolved)->toBeEmpty();
    expect($formUpdates)->toHaveCount(1);
    // Narrowed step by step: the updater returns `mixed` by contract, and asserting the
    // shape here is what lets the value assertions below mean anything.
    $set = $result[0];
    expect($set)->toBeArray();
    $attrs = \is_array($set) ? ($set['attrs'] ?? null) : null;
    expect($attrs)->toBeArray();
    $values = \is_array($attrs) ? ($attrs['values'] ?? null) : null;
    expect($values)->toBeArray();

    expect(\is_array($values) ? ($values['form'] ?? null) : null)->toBe('a_served_form');
    // The note is consumed on success, unlike the unresolved case above.
    expect($values)->not->toHaveKey('import_wordpress_notes');
});

it('reports an unresolved reference nested inside another set', function (): void {
    // The recursion returns its own tuple, so a dead embed inside a replicator would be
    // reported at no level if the nested notes were not merged upward.
    $content = [
        [
            'type' => 'set',
            'attrs' => [
                'id' => 'outer',
                'values' => [
                    'type' => 'columns',
                    'inner' => bardFormSet('Ninja Forms form ID 99'),
                ],
            ],
        ],
    ];

    [, , $unresolved] = runFormHandleUpdate($content, []);

    expect($unresolved)->toContain('Ninja Forms form ID 99');
});

it('carries unresolved notes out even when nothing else in the entry changed', function (): void {
    // The tail returns `[$array, []]` when nothing was updated, and an entry whose *only*
    // form set is unresolved never sets that flag — the guard `continue`s before it. Folding
    // the notes into the updated branch alone would discard them in precisely the case this
    // fix exists for.
    $content = bardFormSet('Formidable Forms form ID 7');

    [$result, $formUpdates, $unresolved] = runFormHandleUpdate($content, []);

    expect($unresolved)->toContain('Formidable Forms form ID 7');
    expect($formUpdates)->toBeEmpty();
    expect($result)->toBe($content);
});
