<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;

/**
 * Verifies passwords for Statamic entries with password protection.
 *
 * Verification is **hashed-only**. The stored `visibility_password` must be a
 * password hash, and the submitted value is compared against it with
 * `Hash::check()`. There is no plain-text comparison branch: a stored value
 * that is not a hash cannot be verified and is denied.
 *
 * That branch used to exist, and removing it required a fix in the host app
 * first (`UAMS-Web/wordpress-importer-archive#5`). Until `UAMS-Web/uams-statamic-archive#1862`
 * shipped, the Control Panel wrote an editor-typed password to the entry's YAML
 * in plain text with `is_password_hashed` unset, so the plain-text fallback was
 * the only thing making Control-Panel-authored passwords work — deleting it
 * would have broken exactly the passwords it protected. The host app now hashes
 * on `EntrySaving` (`App\Listeners\HashVisibilityPassword`), and this importer's
 * `BaseEntryCreator` has always hashed at import, so every writer of the field
 * now stores a hash and the fallback has no remaining caller.
 *
 * **`is_password_hashed` is deliberately not consulted.** The stored value
 * decides, exactly as the host app's listener decides — a flag can disagree with
 * the value it describes (left `true` beside a newly typed plain-text password,
 * or left unset beside a hash), and trusting it then either accepts an
 * unverifiable value or locks an editor out of a perfectly good hash. The field
 * is still written, for the host app and for provenance, but nothing here reads
 * it.
 */
class PasswordVerifier
{
    /**
     * The entry field holding the password-protection password.
     */
    private const string PASSWORD_FIELD = 'visibility_password';

    /**
     * Verify a user-entered password against the entry's stored password.
     *
     * @param  Entry  $entry  The Statamic entry that owns the password field
     * @param  string  $inputPassword  The password entered by the user
     * @return bool True if the password is valid, false otherwise
     */
    public function verify(
        Entry $entry,
        string $inputPassword
    ): bool {
        // Read the stored password from the entry's own data. `get()` is
        // deliberate: `visibility_password` is `localizable: false`, so only the
        // origin entry holds it and the caller is responsible for passing the
        // entry that owns it.
        $storedPassword = $entry->get(self::PASSWORD_FIELD);

        // No password stored — there is nothing to verify against.
        if (! \is_string($storedPassword) || $storedPassword === '') {
            return false;
        }

        // A stored value that is not a hash cannot be verified. Deny it rather
        // than comparing it as plain text. This also keeps the failure a clean
        // "invalid password": `Hash::check()` does not guard the algorithm
        // itself, and `BcryptHasher::check()` throws a `RuntimeException` on a
        // non-bcrypt value whenever `hashing.bcrypt.verify` is enabled, which
        // would surface as a 500 instead of a rejected login.
        if (! Hash::isHashed($storedPassword)) {
            $entryId = $entry->id();

            Log::warning(
                'Password-protected entry has a non-hashed visibility_password; denying access.',
                ['entry' => \is_string($entryId) ? $entryId : null]
            );

            return false;
        }

        // Compare the submitted password against the stored hash.
        return Hash::check(
            $inputPassword,
            $storedPassword
        );
    }
}
