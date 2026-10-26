<?php

/**
 * Point git at the committed `.githooks/` directory so the CI-parity pre-commit
 * hook is active in every clone, without each developer running the config
 * command by hand.
 *
 * Invoked from composer `post-install-cmd` / `post-update-cmd`. It is a no-op
 * outside a git work tree — e.g. when this package is installed as a dependency
 * (a dist/tarball extract with no `.git`) — so composer never fails on a
 * checkout that has no repository.
 */

declare(strict_types=1);

// Bail out quietly when we are not inside a git work tree (dist installs, or
// this package vendored into the host app) — there is nothing to configure and
// we must not fail composer.
exec('git rev-parse --is-inside-work-tree 2>&1', $output, $exitCode);

if ($exitCode !== 0 || trim(implode('', $output)) !== 'true') {
    return;
}

// Wire the hooks path. Idempotent — safe to run on every install/update.
exec('git config core.hooksPath .githooks', $configOutput, $configExit);

if ($configExit === 0) {
    echo "CI-parity git hook installed (core.hooksPath -> .githooks).\n";

    return;
}

fwrite(
    STDERR,
    "Warning: could not set core.hooksPath; run 'git config core.hooksPath .githooks' manually.\n"
);
