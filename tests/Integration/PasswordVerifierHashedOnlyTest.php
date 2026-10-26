<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\PasswordVerifier;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that `PasswordVerifier` verifies against a hash and nothing else (issue #5).
 *
 * The class used to fall back to `$input === $stored` whenever `is_password_hashed`
 * was not set. That branch was not legacy residue: until
 * `UAMS-Web/uams-statamic#1862` shipped an `EntrySaving` hashing listener, the
 * Control Panel wrote editor-typed passwords to YAML in plain text with the flag
 * unset, so the fallback was the only thing making those passwords work. With the
 * host app hashing on save, every writer now stores a hash and the fallback is
 * removed.
 *
 * The load-bearing cases here are the ones that would silently pass again if the
 * fallback came back: a plain-text stored password must be REJECTED even when the
 * submitted password matches it exactly, and `is_password_hashed` must not be
 * consulted in either direction.
 *
 * Entries are real `Statamic\Entries\Entry` instances rather than mocks — the
 * verifier only reads `get()` and `id()`, both of which a bare entry serves from
 * its own data, so a mock would add typing friction for no fidelity.
 *
 * @command  php vendor/bin/pest tests/Integration/PasswordVerifierHashedOnlyTest.php
 */
uses(StatamicTestCase::class);

/**
 * Build an entry carrying a stored password and a hashed flag.
 *
 * The flag is always written so that a verifier which starts trusting it fails a
 * test here rather than silently passing.
 *
 * @param  mixed  $storedPassword  The value stored in `visibility_password`
 * @param  bool  $hashedFlag  The value stored in `is_password_hashed`
 * @return Entry The entry to verify against
 */
function passwordVerifierEntry(
    mixed $storedPassword,
    bool $hashedFlag = false
): Entry {
    $entry = new Entry;

    $entry->id('entry-1');
    $entry->set('visibility_password', $storedPassword);
    $entry->set('is_password_hashed', $hashedFlag);

    return $entry;
}

it('accepts the correct password against a stored hash', function (): void {
    $entry = passwordVerifierEntry(Hash::make('correct-horse'), hashedFlag: true);

    expect((new PasswordVerifier)->verify($entry, 'correct-horse'))->toBeTrue();
});

it('rejects an incorrect password against a stored hash', function (): void {
    $entry = passwordVerifierEntry(Hash::make('correct-horse'), hashedFlag: true);

    expect((new PasswordVerifier)->verify($entry, 'wrong-horse'))->toBeFalse();
});

it('rejects a plain-text stored password even when the submitted value matches it', function (): void {
    // The removed fallback returned true here. If it ever returns true again,
    // Control-Panel plain text is being accepted as a credential.
    $entry = passwordVerifierEntry('audit-secret-pw');

    expect((new PasswordVerifier)->verify($entry, 'audit-secret-pw'))->toBeFalse();
});

it('logs a warning when the stored password is not a hash', function (): void {
    $logSpy = Log::spy();

    $entry = passwordVerifierEntry('audit-secret-pw');

    (new PasswordVerifier)->verify($entry, 'audit-secret-pw');

    $logSpy->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, 'non-hashed visibility_password')
        ),
        ['entry' => 'entry-1'],
    ]);
});

it('ignores is_password_hashed and trusts the stored value', function (): void {
    // A hash whose flag was never written must still verify — denying it would
    // lock an editor out of a perfectly good password.
    $entry = passwordVerifierEntry(Hash::make('correct-horse'), hashedFlag: false);

    expect((new PasswordVerifier)->verify($entry, 'correct-horse'))->toBeTrue();
});

it('rejects a plain-text stored password whose flag wrongly claims a hash', function (): void {
    // The mirror case: a stale `true` beside a newly typed plain-text password.
    $entry = passwordVerifierEntry('audit-secret-pw', hashedFlag: true);

    expect((new PasswordVerifier)->verify($entry, 'audit-secret-pw'))->toBeFalse();
});

it('rejects an entry with no stored password', function (): void {
    $entry = passwordVerifierEntry(null);

    expect((new PasswordVerifier)->verify($entry, 'anything'))->toBeFalse();
});

it('rejects an entry whose stored password is an empty string', function (): void {
    $entry = passwordVerifierEntry('');

    expect((new PasswordVerifier)->verify($entry, ''))->toBeFalse();
});

it('does not warn when the entry simply has no password stored', function (): void {
    // An absent password is an ordinary state, not a data anomaly, so it must
    // take the early return rather than falling through to the not-a-hash
    // branch. Both paths deny access, so only the absence of a warning
    // distinguishes them — without this the empty-string guard is untested.
    $logSpy = Log::spy();

    (new PasswordVerifier)->verify(passwordVerifierEntry(''), 'anything');
    (new PasswordVerifier)->verify(passwordVerifierEntry(null), 'anything');

    $logSpy->shouldNotHaveReceived('warning');
});

it('rejects an entry whose stored password is not a string', function (): void {
    $entry = passwordVerifierEntry(['not', 'a', 'string']);

    expect((new PasswordVerifier)->verify($entry, 'anything'))->toBeFalse();
});
