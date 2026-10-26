<?php

declare(strict_types=1);

/**
 * Tests the shape of each redirect row the importer writes.
 *
 * `UAMS-Web/uams-statamic#1357` added an approval gate to the Statamic
 * `redirects` serving path: a row serves only when its `status` is `approved`,
 * checked beside the existing per-row `enabled` flag. WordPress `redirect_rule`
 * posts are already-live, trusted redirects, so they are stamped approved on
 * import rather than landing in a human review queue (wordpress-importer-archive#440).
 *
 * The app currently grandfathers a MISSING `status` as approved, so today's
 * output already serves — these assertions exist so the mapping stays explicit
 * and survives any future tightening that stops grandfathering.
 *
 * `status` (approval) and `enabled` (active) are orthogonal: a row imported from
 * a WordPress draft is approved but not enabled.
 *
 * @command  php vendor/bin/pest tests/Unit/RedirectRowApprovalTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\Creators\RedirectRuleGlobalCreator;

beforeEach(function (): void {
    $this->creator = new RedirectRuleGlobalCreator;
});

it('stamps every imported redirect row as approved', function (bool $enabled): void {
    // Approval does not depend on the WordPress publish state
    $row = $this->creator->buildRedirectRow(
        '/old-path',
        false,
        ['target' => '/new-path'],
        '301',
        30,
        $enabled
    );

    expect($row['status'] ?? null)->toBe('approved');
})->with([
    'from a published rule' => [true],
    'from an unpublished rule' => [false],
]);

it('keeps enabled independent of approval', function (bool $enabled): void {
    $row = $this->creator->buildRedirectRow(
        '/old-path',
        false,
        ['target' => '/new-path'],
        '301',
        30,
        $enabled
    );

    expect($row['enabled'] ?? null)->toBe($enabled);
})->with([
    'published' => [true],
    'unpublished' => [false],
]);

it('carries the resolved target data onto the row', function (): void {
    $row = $this->creator->buildRedirectRow(
        '/old-path',
        true,
        ['target' => 'entry::abc123', 'target_type' => 'entry'],
        '302',
        10,
        true
    );

    expect($row['source'] ?? null)->toBe('/old-path');
    expect($row['use_regex'] ?? null)->toBeTrue();
    expect($row['target'] ?? null)->toBe('entry::abc123');
    expect($row['target_type'] ?? null)->toBe('entry');
    expect($row['response'] ?? null)->toBe('302');
    expect($row['order'] ?? null)->toBe(10);
    expect($row['type'] ?? null)->toBe('redirect');
});

it('gives every row a distinct id', function (): void {
    $first = $this->creator->buildRedirectRow('/a', false, [], '301', 30, true);
    $second = $this->creator->buildRedirectRow('/b', false, [], '301', 30, true);

    expect($first['id'] ?? null)->toBeString();
    expect($first['id'] ?? '')->not->toBe('');
    expect($first['id'])->not->toBe($second['id']);
});

it('does not let target data override the row envelope', function (): void {
    // ---
    // `$targetData` is spread into the row, so a resolver that returned an `id`,
    // `status`, or `type` key could otherwise silently replace the envelope and
    // produce an unapproved or mistyped row.
    // ---
    $row = $this->creator->buildRedirectRow(
        '/old-path',
        false,
        [
            'target' => '/new-path',
            'status' => 'pending',
            'type' => 'not-a-redirect',
        ],
        '301',
        30,
        true
    );

    expect($row['status'])->toBe('approved');
    expect($row['type'])->toBe('redirect');
});
