<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi;

/**
 * Reads a migration API key from the first line of an open stream.
 *
 * **This exists so an API-sourced import can run with no terminal attached.** The key is
 * otherwise asked at a prompt, which is correct for an operator and impossible for local
 * CI, a scheduled check, or any unattended run — and `wordpress-importer-archive#588`'s dual-import verification is
 * exactly such a run, so the criterion could not be executed at all.
 *
 * **Stdin was chosen over the two alternatives because of where a credential comes to
 * rest, not because it is more convenient.** A command-line option lands the key in shell
 * history and in the process list, readable by any other account on the machine, and it
 * outlives the run in both. An environment variable avoids those two but is inherited by
 * every child process and readable through `/proc` on Linux. A stream is neither: it is
 * consumed once, by this process, and leaves nothing behind.
 *
 * **What it does not protect against, stated because the guarantee is narrower than it
 * looks:** stdin is only as private as whatever fills it. A key piped from a file still
 * lives in that file, and a heredoc typed at an interactive shell still reaches that
 * shell's history. This class removes the exposure it can remove; the caller owns the rest.
 *
 * **Taking only the first line is deliberate.** The remainder of the stream is left
 * unread, so a caller may go on using it, and a key is a single opaque token that cannot
 * legitimately span lines.
 */
class StdinKeyReader
{
    /**
     * Read a migration API key from the first line of the given stream.
     *
     * Line endings are stripped and nothing else is; surrounding whitespace is left for
     * `Client::forRun()` to trim, so there is one place that decides what a key looks like.
     *
     * @param  resource  $stream  An open, readable stream. `STDIN` in production; a
     *                            `php://memory` handle under test.
     * @return string The first line, with its line ending removed.
     *
     * @throws MigrationApiException When the stream is unusable or holds no key.
     */
    public static function read($stream): string
    {
        // Refuse a handle that cannot be read rather than reporting an empty key. The two
        // are indistinguishable downstream -- both end as "no key was supplied" -- and they
        // need opposite fixes, so collapsing them sends the operator the wrong way.
        if (! \is_resource($stream)) {
            throw new MigrationApiException(
                'The migration API key was to be read from standard input, but no readable '
                .'stream was available. Pipe the key in, or drop --key-from-stdin and let '
                .'the import ask for it at the prompt.'
            );
        }

        $line = \fgets($stream);

        // `fgets` answers `false` at end of stream, which is what an empty pipe looks like:
        // the flag was passed and nothing was sent. Naming the flag matters here, because
        // the run was configured to expect input that never arrived.
        if ($line === false) {
            throw new MigrationApiException(
                'No migration API key arrived on standard input. --key-from-stdin expects '
                .'the key on the first line; nothing was received before the stream ended.'
            );
        }

        $key = \rtrim($line, "\r\n");

        // A blank first line is a setup slip rather than an anonymous request, and it is
        // worth catching here: the API answers `401` to a missing header, so letting it
        // through would report the slip as an authentication failure against a network that
        // is reachable and correctly configured.
        if (\trim($key) === '') {
            throw new MigrationApiException(
                'The first line of standard input was empty, so no migration API key was '
                .'read. Generate one for this network from the plugin admin page and pipe '
                .'it in as the first line.'
            );
        }

        return $key;
    }
}
