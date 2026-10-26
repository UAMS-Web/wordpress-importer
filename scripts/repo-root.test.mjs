// Unit tests for repo-root.mjs. Run: node --test scripts/repo-root.test.mjs
//
// `primaryCheckoutFrom` is pure, so the interesting cases are tested without spawning git at all.
// The one spawning test asserts the real answer against this checkout.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import { COMMON_DIR_ARGS, primaryCheckout, primaryCheckoutFrom } from './repo-root.mjs';

const HERE = fileURLToPath(new URL('.', import.meta.url));

describe('COMMON_DIR_ARGS', () => {
    it('asks for the COMMON dir, absolute — not the worktree toplevel', () => {
        // --show-toplevel would answer for the worktree the command ran from, which is exactly
        // the wrong anchor for a sibling slot path.
        assert.deepEqual([...COMMON_DIR_ARGS], ['rev-parse', '--path-format=absolute', '--git-common-dir']);
    });

    it('is frozen, so a caller cannot mutate the shared argv', () => {
        assert.equal(Object.isFrozen(COMMON_DIR_ARGS), true);
    });
});

describe('primaryCheckoutFrom', () => {
    it('takes the parent of a .git directory as the primary checkout', () => {
        assert.equal(primaryCheckoutFrom('/home/u/repos/wordpress-importer/.git'), '/home/u/repos/wordpress-importer');
    });

    it('normalizes Windows separators and a trailing slash', () => {
        assert.equal(primaryCheckoutFrom('D:\\GitHub Repos\\wordpress-importer\\.git\\'), 'D:/GitHub Repos/wordpress-importer');
    });

    it('refuses a submodule gitdir rather than nesting a slot inside .git', () => {
        // Run from inside a submodule, git answers with the submodule's own gitdir.
        assert.throws(
            () => primaryCheckoutFrom('/repo/.git/modules/src/Services/WordPressImport/Tests/Exports'),
            /not a '\.git' directory/,
        );
    });

    it('names the submodule cause in the refusal, so the message is actionable', () => {
        assert.throws(
            () => primaryCheckoutFrom('/repo/.git/modules/x'),
            /inside a submodule/,
        );
    });

    it('throws when git answered with nothing', () => {
        assert.throws(() => primaryCheckoutFrom(null), /Not inside a git repository/);
        assert.throws(() => primaryCheckoutFrom(''), /Not inside a git repository/);
    });

    it("carries git's own stderr into the message rather than discarding the cause", () => {
        assert.throws(
            () => primaryCheckoutFrom(null, '  fatal: not a git repository  '),
            /git said: fatal: not a git repository/,
        );
    });
});

describe('primaryCheckout', () => {
    it('resolves this checkout, and agrees with git run directly', () => {
        const expected = execFileSync('git', ['-C', HERE, ...COMMON_DIR_ARGS], { encoding: 'utf8' })
            .trim()
            .replace(/\\/g, '/')
            .replace(/\/\.git$/, '');

        assert.equal(primaryCheckout(HERE).replace(/\\/g, '/'), expected);
    });

    it('reports a git that could not be started, instead of a bare throw', () => {
        assert.throws(
            () => primaryCheckout(HERE, '/nonexistent/bin/git'),
            /git could not be started|Not inside a git repository/,
        );
    });
});
