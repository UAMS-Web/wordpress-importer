<?php

declare(strict_types=1);

/**
 * Tests the process-wide registry of WordPress pages replaced by a MinervaKB root
 * entry: registration keyed by page and site, refusal of an empty ID, and clearing
 * (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/KbPageSubstitutionsTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing\KbPageSubstitutions;

beforeEach(function (): void {
    KbPageSubstitutions::clear();
});

afterEach(function (): void {
    KbPageSubstitutions::clear();
});

it('resolves a registered page to its KB root on the same site only', function (): void {
    KbPageSubstitutions::register('42', 'kb-root', 'default');

    expect(KbPageSubstitutions::resolve('42', 'default'))->toBe('kb-root')
        ->and(KbPageSubstitutions::resolve('42', 'other'))->toBeNull()
        ->and(KbPageSubstitutions::resolve('43', 'default'))->toBeNull()
        ->and(KbPageSubstitutions::all())->toBe(['42_default' => 'kb-root']);
});

it('keeps one substitution per page and site, the latest registered', function (): void {
    KbPageSubstitutions::register('42', 'first', 'default');
    KbPageSubstitutions::register('42', 'second', 'default');
    KbPageSubstitutions::register('42', 'elsewhere', 'other');

    expect(KbPageSubstitutions::all())->toBe([
        '42_default' => 'second',
        '42_other' => 'elsewhere',
    ]);
});

it('ignores a registration missing either ID', function (string $pageId, string $rootId): void {
    KbPageSubstitutions::register($pageId, $rootId, 'default');

    expect(KbPageSubstitutions::all())->toBe([]);
})->with([
    'no page ID' => ['', 'kb-root'],
    'no KB root ID' => ['42', ''],
]);

it('forgets every substitution on clear', function (): void {
    KbPageSubstitutions::register('42', 'kb-root', 'default');
    KbPageSubstitutions::clear();

    expect(KbPageSubstitutions::all())->toBe([])
        ->and(KbPageSubstitutions::resolve('42', 'default'))->toBeNull();
});
