<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;

/**
 * Tests for the per-process Bard set markup nonce.
 *
 * Covers the properties the wordpress-importer-archive#494 trust boundary rests on: the nonce is stable
 * for the life of the importing process, it is not a constant, and comparison
 * rejects both an absent and a wrong value.
 *
 * @command  php vendor/bin/pest tests/Unit/SetMarkupNonceTest.php
 */
afterEach(function (): void {
    // Leave no nonce behind for a later test in this process
    SetMarkupNonce::reset();
});

it('returns the same nonce for the life of the process', function (): void {
    $first = SetMarkupNonce::value();

    expect(SetMarkupNonce::value())->toBe($first);
});

it('generates a fresh nonce after a reset', function (): void {
    $first = SetMarkupNonce::value();

    SetMarkupNonce::reset();

    expect(SetMarkupNonce::value())->not->toBe($first);
});

it('is long enough not to be guessed', function (): void {
    // 16 random bytes, hex-encoded
    expect(SetMarkupNonce::value())->toMatch('/^[0-9a-f]{32}$/');
});

it('renders as a spliceable html attribute', function (): void {
    expect(SetMarkupNonce::attribute())
        ->toBe(' data-set-nonce="'.SetMarkupNonce::value().'"');
});

it('matches its own value', function (): void {
    expect(SetMarkupNonce::matches(SetMarkupNonce::value()))->toBeTrue();
});

it('rejects an absent or wrong nonce', function (string $candidate): void {
    expect(SetMarkupNonce::matches($candidate))->toBeFalse();
})->with([
    'absent (getAttribute returns an empty string)' => [''],
    'forged' => ['deadbeefdeadbeefdeadbeefdeadbeef'],
    'the attribute name itself' => ['data-set-nonce'],
]);

it('rejects a nonce minted before a reset', function (): void {
    $stale = SetMarkupNonce::value();

    SetMarkupNonce::reset();

    expect(SetMarkupNonce::matches($stale))->toBeFalse();
});
