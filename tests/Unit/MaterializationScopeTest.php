<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * `MaterializationScope` records which post types a scoped run read, and reports what that
 * scope cost, word for word (#102). Its readers are driven in `ScopedReadReportingTest`; this
 * file pins the record and the reports themselves.
 *
 * @command  php vendor/bin/pest tests/Unit/MaterializationScopeTest.php
 */
beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
    MaterializationScope::clear();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
    MaterializationScope::clear();
});

/**
 * The warnings of one migration API type.
 *
 * @return array<int|string, mixed>
 */
function materializationScopeWarnings(string $type): array
{
    $warnings = ImportWarningManager::getWarningsByCategory('migration_api')[$type] ?? [];

    return \is_array($warnings) ? $warnings : [];
}

it('records the post types of a scoped run, and none once cleared', function (): void {
    expect(MaterializationScope::isScoped())->toBeFalse()
        ->and(MaterializationScope::postTypes())->toBeNull();

    MaterializationScope::set(['page', 'attachment']);

    expect(MaterializationScope::isScoped())->toBeTrue()
        ->and(MaterializationScope::postTypes())->toBe(['page', 'attachment']);

    MaterializationScope::clear();

    expect(MaterializationScope::isScoped())->toBeFalse()
        ->and(MaterializationScope::postTypes())->toBeNull();
});

it('reports an unresolved link on a site, word for word, once per run', function (): void {
    MaterializationScope::set(['page']);

    MaterializationScope::reportUnresolvedLink('https://example.test/a/');
    MaterializationScope::reportUnresolvedLink('https://example.test/a/');
    MaterializationScope::reportUnresolvedLink('https://example.test/b/');

    expect(materializationScopeWarnings('scope_unresolved_links'))->toBe([
        [
            'url' => 'https://example.test/a/',
            'read_post_types' => ['page'],
            'consequence' => 'the link was left unconverted; if its target is of a type this run did not read, an unscoped run converts it',
        ],
        [
            'url' => 'https://example.test/b/',
            'read_post_types' => ['page'],
            'consequence' => 'the link was left unconverted; if its target is of a type this run did not read, an unscoped run converts it',
        ],
    ]);
});

it('reports an unresolved link on a vanity redirect domain with its narrower consequence', function (): void {
    MaterializationScope::set(['redirect_rule'], true);

    MaterializationScope::reportUnresolvedLink('https://vanity.test/a/');

    expect(materializationScopeWarnings('scope_unresolved_links'))->toBe([[
        'url' => 'https://vanity.test/a/',
        'read_post_types' => ['redirect_rule'],
        'consequence' => 'the link was left unconverted; this site is a vanity redirect domain, whose pages are never imported, so an unscoped run converts it only if its target is an attachment page',
    ]]);
});

it('reports a link again in a new run', function (): void {
    MaterializationScope::set(['page']);
    MaterializationScope::reportUnresolvedLink('https://example.test/a/');

    MaterializationScope::set(['page']);
    MaterializationScope::reportUnresolvedLink('https://example.test/a/');

    expect(materializationScopeWarnings('scope_unresolved_links'))->toHaveCount(2);
});

it('reports nothing on a run that was not scoped', function (): void {
    MaterializationScope::reportUnresolvedLink('https://example.test/a/');
    MaterializationScope::reportUnreadType('FindADocProvidersParser', 'provider', '9');

    expect(ImportWarningManager::getWarningsByCategory('migration_api'))->toBe([]);
});

it('reports a lookup of a type the run did not read, word for word, and not one it did', function (): void {
    MaterializationScope::set(['page', 'location']);

    MaterializationScope::reportUnreadType('FindADocLocationsParser', 'location', '4');
    MaterializationScope::reportUnreadType('FindADocProvidersParser', 'provider', '9');

    expect(materializationScopeWarnings('scope_unread_references'))->toBe([[
        'reader' => 'FindADocProvidersParser',
        'post_type' => 'provider',
        'post_id' => '9',
        'read_post_types' => ['page', 'location'],
        'consequence' => 'the reference was dropped because this run did not read that type; an unscoped run resolves it',
    ]]);
});
