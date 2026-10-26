/**
 * repo-root.mjs — resolve the primary checkout from anywhere in the repository.
 *
 * "The repo root" has two answers in a repository with linked worktrees, and picking the wrong one
 * decides where a sibling path lands. `git rev-parse --show-toplevel` answers for the worktree you
 * are standing in; `--git-common-dir` answers for the primary checkout no matter which worktree
 * asks. The slot model in `.claude/rules/worktrees.md` places every slot as a **sibling of the
 * primary**, so the primary is the only correct anchor — and `--show-toplevel` silently nests the
 * slot inside whatever tree the command happened to run from.
 *
 * The failure has no symptom at the time: `git worktree add` succeeds at the wrong path, and
 * `.claude/worktrees/` is git-ignored, so a misplaced tree never appears in `git status`.
 *
 * Kept in one module so the two runner scripts cannot answer it differently.
 */

import { spawnSync } from 'node:child_process';
import { basename, dirname } from 'node:path';
import { needsShell, which, winQuote } from './spawn-helpers.mjs';

/** What `primaryCheckout` asks git. Exported so a test can assert the call is the intended one. */
export const COMMON_DIR_ARGS = Object.freeze(['rev-parse', '--path-format=absolute', '--git-common-dir']);

/**
 * Derive the primary checkout from a `--git-common-dir` answer.
 *
 * The common dir is the primary's `.git`, so its parent is the primary checkout — but only when it
 * really is a `.git` directory, which is why that is checked rather than assumed. Run from inside
 * a submodule (the exports submodule, before wordpress-importer-archive#1426), git answers with the submodule's own gitdir:
 *
 *     …/wordpress-importer/.git/modules/src/Services/WordPressImport/Tests/Exports
 *
 * whose parent is a path inside `.git`. Taking that as the repo root would put a sibling worktree
 * *inside the git directory* — a failure with no symptom until something much later goes wrong. A
 * named error here costs nothing and is the difference between a wrong answer and no answer.
 *
 * @param  ?string  commonDir
 * @param  ?string  detail
 * @return string
 */
export function primaryCheckoutFrom(commonDir, detail = null) {
    if (!commonDir) {
        // `detail` carries git's own stderr. Without it every failure — no repository, a broken
        // index, a permissions error, a git that would not start — collapses into the same
        // sentence, and the one line that would have named the cause is the line thrown away.
        throw new Error(
            'Not inside a git repository (git rev-parse --git-common-dir returned nothing).'
            + (detail ? `\ngit said: ${String(detail).trim()}` : ''),
        );
    }

    const normalized = String(commonDir).replace(/\\/g, '/').replace(/\/+$/, '');

    if (basename(normalized) !== '.git') {
        throw new Error(
            `Cannot derive the primary checkout: git reported its common dir as '${normalized}', `
            + "which is not a '.git' directory.\n"
            + "The usual cause is running from inside a submodule, where git answers with the submodule's "
            + 'own gitdir. A checkout using --separate-git-dir or an external $GIT_DIR reaches this too, '
            + 'and is deliberately unsupported: the slot model places every slot beside the primary, which '
            + "assumes the primary is its .git directory's parent.",
        );
    }

    return dirname(normalized);
}

/**
 * Ask git for the primary checkout containing `cwd`.
 *
 * `gitExe` accepts either a bare command name or an already-resolved absolute path, so a caller
 * that has resolved its own git keeps that resolution.
 *
 * @param  string  cwd
 * @param  string  gitExe
 * @return string
 */
export function primaryCheckout(cwd, gitExe = 'git') {
    const resolved = /[\\/]/.test(gitExe) ? gitExe : (which(gitExe) ?? gitExe);
    const shell = needsShell(resolved);
    const args = ['-C', cwd, ...COMMON_DIR_ARGS];

    const result = spawnSync(
        shell ? winQuote(resolved) : resolved,
        shell ? args.map(winQuote) : args,
        { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], shell },
    );

    if (result.error) {
        throw new Error(`git could not be started (${resolved}): ${result.error.message}`);
    }

    const stderr = String(result.stderr ?? '').trim();

    return primaryCheckoutFrom(
        result.status === 0 ? String(result.stdout ?? '').trim() : null,
        stderr || (result.status === null ? 'git was terminated by a signal' : `git exited ${result.status}`),
    );
}
