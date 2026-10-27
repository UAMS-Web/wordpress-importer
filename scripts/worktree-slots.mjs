#!/usr/bin/env node
/**
 * worktree-slots.mjs — drive the stable worktree slots described in `.claude/rules/worktrees.md`.
 *
 * Branch work happens in a fixed set of long-lived, pre-bootstrapped worktrees ("slots") rather
 * than a throwaway worktree per ticket. That removes the per-ticket bootstrap, and adds exactly
 * one risk in its place: a slot whose dependencies have drifted from the branch it is sitting on.
 * The old model failed *loudly* — no `vendor/`, hard error, you notice. A stale slot fails
 * *silently*: the tests run, pass, and pass against the wrong versions.
 *
 * The rule calls the refresh a gate, but a gate that depends on remembering four commands is not
 * a gate — so this script is the gate, and `status` is how the drift becomes visible before it is
 * trusted.
 *
 * Subcommands:
 *   status              every tree, its branch, whether it is free, and any detected drift
 *   refresh <target>    reconcile vendor/ (re-applying recorded patches) and node_modules/
 *   release <slot>      assert a clean tree, then detach to origin/main so the branch is free
 *   provision <slot>    create and bootstrap a slot from scratch
 *
 * `refresh` takes `primary` as well as a slot name; `release` and `provision` do not, because the
 * primary must keep `main` checked out and is the clone rather than a worktree of it. Each tree is
 * judged against its own kind: the CI slots are runner-owned, so the local CI runner's
 * `dependencies` job bootstrapping one is the expected state and its absent stamp is not drift.
 *
 * This package's npm half is prose checking and nothing else. `package.json` pins `cspell` for the
 * gate's `spell` job, so a tree now has two installs to reconcile rather than one — there is still
 * no asset build and no Stache to clear. The two run on different terms, and `installNodeModules`
 * carries why. `composer.lock` remains the only install *stamp*: `status` judges drift against it
 * alone, so a tree whose `node_modules/` is absent reports clean and then fails the `spell` job.
 * The stamp exists for `status` to judge drift, not to gate the install.
 *
 * **The patches `patches.lock.json` records are judged from the files, not from the stamp (#113).**
 * `composer install` treats a package already at its locked version as satisfied and does not
 * re-apply a patch added since, and `cweagans/composer-patches` keeps no record in `vendor/` of what
 * it applied. So `status` asks each patch whether it reverse-applies to the installed package, and
 * `refresh` and `provision` reinstall exactly the packages where one does not.
 *
 * Exit codes make it usable as a shell guard:
 *   0  the operation completed, or `status` found no drift
 *   1  refused, or `status` found drift (with `--check`)
 *   2  error (not a git repo, unknown slot, git/composer unavailable)
 *
 * Usage:
 *   node scripts/worktree-slots.mjs status [--json] [--check]
 *   node scripts/worktree-slots.mjs refresh a          (or: refresh primary)
 *   node scripts/worktree-slots.mjs release a
 *   node scripts/worktree-slots.mjs provision b
 */

import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { hostname } from 'node:os';
import { basename, dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { nodeModulesDrift } from './node-modules-drift.mjs';
import { primaryCheckout } from './repo-root.mjs';
import { needsShell, which, winQuote } from './spawn-helpers.mjs';

/**
 * Where the install stamp lives.
 *
 * Deliberately inside `vendor/`, which buys two properties no other location does. It is already
 * git-ignored (`/vendor`, line 2 of `.gitignore`), so the stamp can never show up as untracked and
 * break the clean-tree check `release` depends on. And a hand-deleted or half-removed `vendor/`
 * loses the stamp with it, so the tree is correctly re-read as stale — the stamp cannot outlive
 * the thing it describes. A top-level stamp was rejected for exactly that: it survives
 * `rm -rf vendor` and would certify a tree with no dependencies at all as current.
 */
const STAMP_PATH = join('vendor', '.worktree-slot-stamp.json');

/** Tree kinds. `slot` is the ordinary case; the other three are judged by their own expectations. */
const PRIMARY = 'primary';
const CI = 'ci';
const SLOT = 'slot';
const EPHEMERAL = 'ephemeral';

/**
 * The slot name of the shared local CI runner's `--branch` slot (wordpress-importer-archive#1319), placed there by
 * `scripts/ci-local.mjs`. Runner-owned, like `ci`: the old `<primary>-ci` tree was created by
 * the private runner this replaced, carries no `local-ci-slot` marker, and so is refused by
 * the shared runner, which is why the shared runner's slot has a name of its own.
 */
export const LOCAL_CI_SLOT = 'local-ci';

/** The slots `claim` will attempt, in order. `ci` is runner-owned and never claimed by hand. */
const CLAIMABLE_SLOTS = Object.freeze(['a', 'b']);

/**
 * Normalize a filesystem path for comparison.
 *
 * `git worktree list --porcelain` prints forward slashes on every platform, while Node's `join`
 * yields backslashes on Windows — so a path built here never string-matches one git reported, and
 * every slot would resolve as "not a registered worktree". Case is folded because Windows paths
 * are case-insensitive and git's casing of the drive letter is not guaranteed to match Node's.
 *
 * @param  string  value
 * @return string
 */
export function normalizePath(value) {
    return String(value).replace(/\\/g, '/').replace(/\/+$/, '').toLowerCase();
}

/**
 * Parse `git worktree list --porcelain` into one record per tree.
 *
 * Records are separated by a blank line. `branch refs/heads/<name>` is present only when the tree
 * has a branch checked out; a detached tree carries a bare `detached` line instead, which is the
 * resting state a free slot sits in.
 *
 * @param  string  porcelain
 * @return array
 */
export function parseWorktreeList(porcelain) {
    return String(porcelain)
        .split(/\n\s*\n/)
        .map((block) => block.trim())
        .filter(Boolean)
        .map((block) => {
            const record = { path: null, head: null, branch: null, detached: false };

            for (const line of block.split('\n')) {
                const [key, ...rest] = line.trim().split(' ');
                const value = rest.join(' ');

                if (key === 'worktree') { record.path = value; }
                if (key === 'HEAD') { record.head = value; }
                if (key === 'branch') { record.branch = value.replace(/^refs\/heads\//, ''); }
                if (key === 'detached') { record.detached = true; }
            }

            return record;
        })
        .filter((record) => record.path);
}

/**
 * The directory name a slot takes, derived from the primary checkout rather than hard-coded.
 *
 * The rule states slot paths relative to the primary (`../<primary>-a`) precisely so they do not
 * name one machine's absolute path, and deriving the prefix here keeps that true for a clone under
 * any directory name.
 *
 * @param  string  repoRoot
 * @param  string  name
 * @return string
 */
export function slotDirName(repoRoot, name) {
    return `${basename(repoRoot)}-${name}`;
}

/**
 * Resolve a target name to the worktree git actually knows about.
 *
 * Refuses rather than guessing when the resolved path is not a registered worktree. The whole
 * point of a slot is that operations land on a known tree; acting on an unregistered path would
 * silently install into, or detach, whatever happens to sit there.
 *
 * `primary` names the repo root itself rather than a `<basename>-primary` sibling. It is a real
 * target, not a convenience: the primary is a tree people work in, and `status` reports its drift,
 * so `refresh` has to be able to reach it.
 *
 * @param  array   worktrees
 * @param  string  repoRoot
 * @param  string  name
 * @return object
 */
export function resolveSlot(worktrees, repoRoot, name) {
    if (!name || !/^[A-Za-z][\w-]*$/.test(name)) {
        throw new Error(`Invalid slot name '${name}'. Slot names are alphanumeric and start with a letter (primary, a, b, ci).`);
    }

    const path = name === PRIMARY ? repoRoot : join(dirname(repoRoot), slotDirName(repoRoot, name));
    const match = worktrees.find((tree) => normalizePath(tree.path) === normalizePath(path));

    if (!match) {
        const known = worktrees.map((tree) => basename(tree.path)).join(', ');
        // No `provision primary` exists to suggest — the primary is the clone itself.
        const hint = name === PRIMARY ? '' : ` Run 'provision ${name}' to create it.`;

        throw new Error(`No registered worktree at ${path}. Registered: ${known}.${hint}`);
    }

    return match;
}

/**
 * Which kind of tree this is, which decides what it is expected to carry.
 *
 * @param  string  treePath
 * @param  string  repoRoot
 * @return string
 */
export function treeKind(treePath, repoRoot) {
    if (normalizePath(treePath) === normalizePath(repoRoot)) {
        return PRIMARY;
    }

    // Both runner-owned trees: the old runner's, and the shared runner's (wordpress-importer-archive#1319).
    if ([CI, LOCAL_CI_SLOT].some((name) => basename(treePath) === slotDirName(repoRoot, name))) {
        return CI;
    }

    // A slot is a SIBLING of the primary named `<primary-basename>-<name>` — that is what
    // `slotDirName` builds and what `resolveSlot` looks for. Anything else git has registered is an
    // ephemeral worktree (`.claude/worktrees/…`, a subagent's isolated tree, a hand-made one), and
    // judging those as slots is a bug with the same shape as the CI slot's: they never carry a
    // bootstrap, `refresh` cannot address them because their path is not `<basename>-<name>`, and
    // they would therefore pin `--check` at 1 for as long as one exists.
    const sibling = normalizePath(dirname(treePath)) === normalizePath(dirname(repoRoot))
        && basename(treePath).startsWith(`${basename(repoRoot)}-`);

    return sibling ? SLOT : EPHEMERAL;
}

/**
 * What a tree of this kind must have before it can be trusted.
 *
 * Only a slot and the primary are held to the bootstrap, because they are the only trees this
 * script owns. Two exceptions, both deliberate:
 *
 * The **CI slot** is runner-owned. `.claude/rules/worktrees.md` holds it out of pre-provisioning
 * because no session's working directory sits in it and the local CI runner's `dependencies` job
 * (`scripts/prepare-dependencies.mjs`) bootstraps it on every run, without writing this script's
 * stamp. That holds for both runner-owned trees, `<primary>-ci` and `<primary>-local-ci`. Judging it by a slot's expectations would report
 * permanent drift for a tree in exactly the state it is supposed to be in, and a guard that can
 * never go green is one people learn to skip.
 *
 * An **ephemeral** worktree is nobody's to keep current. Whoever created it bootstraps whatever
 * that narrow task needs and tears it down after, so this script reports its branch and stays out
 * of the way rather than holding a temporary tree to a slot's standard forever.
 *
 * **Base position is judged separately from the bootstrap, and by a narrower rule.** The primary
 * tracks `main` and is always judged. A slot is judged only *at rest*: a slot holding a
 * `<N>-<slug>` branch is SUPPOSED to sit behind `main` while its ticket is in flight, and
 * `.claude/rules/sync-pr-branch.md` owns bringing that branch current — reporting it here would
 * pin `--check` at 1 for the whole life of every ticket, which is the "guard that can never go
 * green" failure this file already avoids twice above.
 *
 * `atRest` defaults to true so the STRICT reading is the default, matching `driftReasons`: an
 * exemption has to be asked for.
 *
 * @param  string  kind
 * @param  bool  atRest
 * @return object
 */
export function expectationsFor(kind, atRest = true) {
    return {
        checkInstall: kind === PRIMARY || kind === SLOT,
        checkBase: kind === PRIMARY || (kind === SLOT && atRest),
    };
}

/**
 * Classify a tree and report only the drift its kind actually cares about.
 *
 * This composition is the seam that decides `status --check`'s exit code, and it is deliberately a
 * separate exported function rather than two calls inline in `inspect`: `driftReasons` defaults to
 * the STRICT reading when `expected` is omitted, so dropping the wiring silently restores the
 * "`--check` can never exit 0" bug. Testing the halves in isolation cannot catch that, so the
 * composition is what gets tested.
 *
 * `probe` carries the measurements `driftReasons` reads — `composerHash`, `stampComposerHash`,
 * `unpatched`, `patchesLockUnreadable`, `behind` — plus `atRest`, which is not a measurement but the occupancy that
 * decides whether base position is judged at all.
 *
 * @param  string  treePath
 * @param  string  repoRoot
 * @param  object  probe
 * @return object
 */
export function judgeTree(treePath, repoRoot, probe) {
    const kind = treeKind(treePath, repoRoot);
    // `atRest` decides an expectation rather than a drift reason, so it is destructured out here
    // instead of riding into `driftReasons` as an unread key.
    const { atRest = true, ...measurements } = probe;

    return { kind, drift: driftReasons({ ...measurements, expected: expectationsFor(kind, atRest) }) };
}

/**
 * Whether a slot is free to claim.
 *
 * A slot at rest is **detached** at `origin/main`, never on `main` — git allows a branch in one
 * worktree only, so `main` lives in the primary and two slots could not both rest on it. A slot
 * carrying a branch is held, by an earlier session or a live one.
 *
 * @param  object  worktree
 * @return string
 */
export function slotOccupancy(worktree) {
    return worktree.branch ? 'held' : 'free';
}

/**
 * The occupancy a reader can act on: whether this slot can be claimed right now.
 *
 * `slotOccupancy` answers from the branch alone. That is correct for `atRest` — a detached slot is
 * at its resting state for drift purposes whatever its working tree holds, so its base position
 * still gets judged. It is wrong for the printed role: a detached slot carrying uncommitted work
 * reads as `free`, and claiming it means branching over somebody's scratch (wordpress-importer-archive#962).
 *
 * The two are kept apart deliberately. Teaching `slotOccupancy` about dirtiness would make such a
 * slot `atRest === false`, which stops `status` reporting that it is behind `origin/main` — on
 * precisely the slots most likely to be stale, and silently.
 *
 * @param  object  worktree
 * @param  string  porcelainStatus
 * @return string
 */
export function slotClaimability(worktree, porcelainStatus, claim = null) {
    if (worktree.branch || claim) {
        return 'held';
    }

    return releaseRefusalReason(porcelainStatus) ? 'dirty' : 'free';
}

/**
 * The ref a slot's claim lives under.
 *
 * A ref rather than a lock file because `git update-ref` with an all-zero old value is **atomic and
 * create-only**: the second of two concurrent claimants is refused under the ref lock rather than
 * both reading `free` and both proceeding. That is the race wordpress-importer-archive#1140 exists to close, and it is the
 * property a `status` read followed by a checkout does not have at any polling interval.
 *
 * @param  string  slot
 * @return string
 */
export function claimRefName(slot) {
    return `refs/slots/${slot}`;
}

/**
 * The text stored in a claim's blob.
 *
 * Kept as `key=value` lines rather than JSON so `git cat-file -p` is readable by a human debugging a
 * stuck claim, which is the only time anyone reads one.
 *
 * @param  string  owner
 * @param  string  at
 * @return string
 */
export function claimRecord(owner, at) {
    return `owner=${owner}\nat=${at}\n`;
}

/**
 * Read a claim blob back, tolerating anything that is not a claim.
 *
 * Returns null rather than throwing on junk: a ref under `refs/slots/` that this script did not
 * write is somebody else's business, and treating it as an unreadable claim is safer than treating
 * it as an absent one.
 *
 * @param  ?string  text
 * @return ?object
 */
export function parseClaimRecord(text) {
    if (!text) {
        return null;
    }

    const fields = Object.fromEntries(
        text.split('\n')
            .filter((line) => line.includes('='))
            .map((line) => [line.slice(0, line.indexOf('=')), line.slice(line.indexOf('=') + 1)]),
    );

    return fields.owner ? { owner: fields.owner, at: fields.at || '' } : null;
}

/**
 * Whether `release` may clear a claim it found.
 *
 * **The granularity is the machine, not the session, and that is deliberate rather than an
 * oversight.** Two sessions on one machine hold *different* slots, so each still clears only its
 * own; what this refuses is a different machine's claim, which is the case where the holder cannot
 * be asked. Stated so nobody reads it as per-session mutual exclusion it does not provide.
 *
 * @param  ?object  claim
 * @param  string   owner
 * @return bool
 */
export function mayClearClaim(claim, owner) {
    return claim === null || claim.owner === owner;
}

/**
 * The role `status` prints for one tree.
 *
 * Extracted rather than left inline in `inspect` for the same reason `judgeTree` is: `inspect`
 * does IO and cannot be unit-tested, so a composition left inside it is unverified. Reverting this
 * to `slotOccupancy` would restore wordpress-importer-archive#962 with every test still green, which is precisely the
 * false-green this repository's review rule exists to catch.
 *
 * @param  string  kind
 * @param  object  worktree
 * @param  string  porcelainStatus
 * @return string
 */
export function roleFor(kind, worktree, porcelainStatus, claim = null) {
    return kind === PRIMARY || kind === EPHEMERAL ? kind : slotClaimability(worktree, porcelainStatus, claim);
}

/**
 * Decide whether `vendor/` needs reinstalling.
 *
 * A missing stamp means unknown — a cold install, or a tree from before this script existed — and
 * is treated as stale. Reinstalling costs a minute; a false "fresh" is the silent-wrong-versions
 * failure this whole script exists to prevent, so the asymmetry is deliberate.
 *
 * @param  ?string  lockHash
 * @param  ?string  stampHash
 * @return ?string
 */
export function installStaleReason(lockHash, stampHash) {
    if (!lockHash) {
        // No lockfile to compare against; nothing declared, nothing to verify.
        return null;
    }
    if (!stampHash) {
        return 'no record of a previous install';
    }
    if (stampHash !== lockHash) {
        return `lockfile changed (${stampHash.slice(0, 12)} -> ${lockHash.slice(0, 12)})`;
    }

    return null;
}

/**
 * The patches a tree's `patches.lock.json` records, grouped by package.
 *
 * Returns null when the text is not a lock this script can read, so a corrupt file is reported
 * rather than read as "no patches", which would certify every package as patched.
 *
 * @param  string  text  The contents of `patches.lock.json`
 * @return ?array  `[{ package, patches: [{ url, depth }] }]`, in the lock's order
 */
export function recordedPatches(text) {
    let lock;

    try {
        lock = JSON.parse(text);
    } catch {
        return null;
    }

    if (lock === null || typeof lock !== 'object' || lock.patches === null || typeof lock.patches !== 'object') {
        return null;
    }

    const groups = [];

    for (const [name, list] of Object.entries(lock.patches)) {
        if (!Array.isArray(list) || list.some((patch) => typeof patch?.url !== 'string')) {
            return null;
        }

        // The plugin records the `-p` depth it applied with; 1 is its own default.
        const patches = list.map((patch) => ({ url: patch.url, depth: Number.isInteger(patch.depth) ? patch.depth : 1 }));

        if (patches.length) {
            groups.push({ package: name, patches });
        }
    }

    return groups;
}

/**
 * The packages carrying at least one recorded patch that is not applied.
 *
 * `isApplied` is passed in so the decision is testable without a `vendor/`; `patchDrift` supplies
 * the real probe.
 *
 * @param  array     groups     What `recordedPatches` returned
 * @param  function  isApplied  `(package, patch) => bool`
 * @return array  Package names, in the lock's order
 */
export function unpatchedPackages(groups, isApplied) {
    return groups
        .filter((group) => group.patches.some((patch) => !isApplied(group.package, patch)))
        .map((group) => group.package);
}

/**
 * Summarize every drift a tree is carrying, as human-readable reasons.
 *
 * Returns an empty array when the tree is current, so the caller can treat "no reasons" as the
 * green state without inspecting shapes.
 *
 * `expected` scopes what counts, so a tree is judged against its own kind rather than against a
 * slot's. It defaults to a slot's expectations, which keeps the strict reading the default and
 * makes every exemption an explicit one.
 *
 * **Base position is judged as well, and it is the only part about the tree's place in history
 * rather than its environment.** The two were previously conflated by the word `current`: a tree
 * roughly a hundred commits behind satisfied this gate, because nothing here compared HEAD to
 * `origin/main` at all. It is scoped by `expected.checkBase` — see `expectationsFor`.
 *
 * @param  object  input
 * @return array
 */
export function driftReasons({
    composerHash = null,
    stampComposerHash = null,
    unpatched = [],
    patchesLockUnreadable = false,
    behind = null,
    expected = null,
}) {
    const reasons = [];
    const expect = expected ?? expectationsFor(SLOT);

    // A null `behind` means the distance could not be measured — no `origin/main` ref, or git
    // declined to answer. That is not drift: a distance never measured is not evidence of
    // staleness, and faulting it would be a red with nothing behind it, on a guard whose value
    // depends on its reds meaning something.
    //
    // The opposite risk is the real one, and it is NOT handled here. An unmeasured base is
    // silent, and silence is indistinguishable from `behind=0` — the precise failure this check
    // exists to remove, one layer in. `commandStatus` and `commandRefresh` therefore report the
    // non-measurement explicitly, without counting it as drift.
    if (expect.checkBase && behind !== null && behind > 0) {
        reasons.push(`${behind} behind origin/main`);
    }

    if (expect.checkInstall) {
        const vendor = installStaleReason(composerHash, stampComposerHash);

        if (vendor) {
            reasons.push(`vendor: ${vendor}`);
        }

        // Named per package, because the remedy is a reinstall of exactly those packages.
        if (unpatched.length) {
            reasons.push(`vendor: recorded patches not applied to ${unpatched.join(', ')}`);
        }

        if (patchesLockUnreadable) {
            reasons.push('vendor: patches.lock.json could not be read');
        }
    }

    return reasons;
}

/**
 * Why a release must be refused, or null when it may proceed.
 *
 * Releasing detaches the tree, which discards nothing by itself — but a dirty tree means the work
 * was never committed, and detaching strands it where the next claim will not look. Refusing is
 * the whole value: the failure this prevents is silent, and the fix afterwards is not.
 *
 * @param  string  porcelainStatus
 * @return ?string
 */
export function releaseRefusalReason(porcelainStatus) {
    const entries = String(porcelainStatus).split('\n').map((line) => line.trim()).filter(Boolean);

    if (entries.length === 0) {
        return null;
    }

    const shown = entries.slice(0, 5).map((entry) => `    ${entry}`).join('\n');
    const more = entries.length > 5 ? `\n    … and ${entries.length - 5} more` : '';

    return `the tree has ${entries.length} uncommitted change(s):\n${shown}${more}`;
}

/**
 * Arguments for a detaching checkout that will not recurse into submodules.
 *
 * **`--no-recurse-submodules` is load-bearing here, not tidy (wordpress-importer-archive#1142).** `submodule.active` is set in
 * the shared `.git/config`, so it applies to every linked worktree; where a machine also has
 * `submodule.recurse` on, a plain checkout that crosses a commit moving the exports gitlink tries to
 * reset a submodule index the slot has no gitdir for. The checkout aborts, and git leaves behind a
 * `.git` pointer to a stub gitdir that makes every later checkout in that tree fail — presenting as a
 * dirty tree, because the aborted checkout also left the target commit's own content applied.
 *
 * It is gated on `submodule.recurse` rather than on the platform: measured on macOS, unset it exits 0
 * and set it exits 128, identically to the Windows report. wordpress-importer-archive#961 fixed exactly this in
 * the private runner of its day and left this script unguarded, which is why the flag lives in a helper now —
 * a call site added later inherits it instead of depending on whoever adds it having read this.
 *
 * `main` no longer records the gitlink (wordpress-importer-archive#1426), and the flag stays: a slot still checks out commits
 * from before that, and crossing from one of them to `main` crosses the gitlink's removal.
 *
 * @param  string  path
 * @param  string  ref
 * @param  object  options
 * @return array
 */
export function checkoutArgs(path, ref, { create = false } = {}) {
    return create
        ? ['-C', path, 'checkout', '--no-recurse-submodules', '-b', ref]
        : ['-C', path, 'checkout', '--detach', '--no-recurse-submodules', ref];
}

/**
 * Spawn a command, resolving Windows shims first.
 *
 * @param  string   file
 * @param  array    args
 * @param  ?string  cwd
 * @param  mixed    stdio
 * @return object
 */
function spawn(file, args, cwd, stdio) {
    const exe = which(file) || file;
    const shell = needsShell(exe);

    return spawnSync(
        shell ? winQuote(exe) : exe,
        shell ? args.map(winQuote) : args,
        { cwd, stdio, shell, encoding: 'utf8' },
    );
}

/**
 * Run a command and return its trimmed stdout, or null when it fails.
 *
 * @param  string   file
 * @param  array    args
 * @param  ?string  cwd
 * @return ?string
 */
function capture(file, args, cwd = undefined) {
    const result = spawn(file, args, cwd, ['ignore', 'pipe', 'ignore']);

    return result.status === 0 && typeof result.stdout === 'string' ? result.stdout.trim() : null;
}

/**
 * Run a command, streaming its output, and throw when it fails.
 *
 * @param  string  file
 * @param  array   args
 * @param  string  cwd
 * @return void
 */
function run(file, args, cwd) {
    const result = spawn(file, args, cwd, 'inherit');

    if (result.error) {
        throw new Error(`${file} could not be started: ${result.error.message}`);
    }
    if (result.status !== 0) {
        throw new Error(`${file} ${args.join(' ')} exited ${result.status}`);
    }
}

/**
 * Every worktree git knows about, parsed.
 *
 * @param  string  repoRoot
 * @return array
 */
function worktreesOf(repoRoot) {
    return parseWorktreeList(capture('git', ['-C', repoRoot, 'worktree', 'list', '--porcelain']) || '');
}

/**
 * The sha256 of a tree's `composer.lock`, or null when it has none.
 *
 * @param  string  treePath
 * @return ?string
 */
function composerHashOf(treePath) {
    const file = join(treePath, 'composer.lock');

    return existsSync(file) ? createHash('sha256').update(readFileSync(file)).digest('hex') : null;
}

/**
 * Record what the install was run against, so a later `status` can tell current from stale.
 *
 * Skipped when `vendor/` is absent, since that is where the stamp lives — a tree with no install
 * has nothing to certify, and reads as stale on the next status, which is the safe direction.
 *
 * @param  string  treePath
 * @return void
 */
function writeInstallStamp(treePath) {
    if (!existsSync(join(treePath, 'vendor'))) {
        return;
    }

    writeFileSync(
        join(treePath, STAMP_PATH),
        `${JSON.stringify({ composerHash: composerHashOf(treePath) }, null, 2)}\n`,
    );
}

/**
 * Read the recorded hash out of a tree's install stamp.
 *
 * @param  string  treePath
 * @return ?string
 */
function stampHashOf(treePath) {
    const stamp = join(treePath, STAMP_PATH);

    if (!existsSync(stamp)) {
        return null;
    }

    try {
        return JSON.parse(readFileSync(stamp, 'utf8')).composerHash || null;
    } catch {
        return null;
    }
}

/**
 * Whether one recorded patch is applied to the package installed in a tree.
 *
 * A patch that reverse-applies cleanly is present in the files, which is the only evidence there
 * is: the plugin writes no record of what it applied. `--check` makes it a dry run, so nothing in
 * `vendor/` is touched.
 *
 * @param  string  treePath
 * @param  string  name      The package, as `vendor/name`
 * @param  object  patch     `{ url, depth }` from `recordedPatches`
 * @return bool
 */
export function patchApplied(treePath, name, patch) {
    const result = spawn(
        'git',
        ['-C', treePath, 'apply', '--check', '--reverse', `--directory=vendor/${name}`, `-p${patch.depth}`, join(treePath, patch.url)],
        undefined,
        'ignore',
    );

    return result.status === 0;
}

/**
 * Which packages in a tree are missing a patch its `patches.lock.json` records.
 *
 * Two cases are not drift, each for a reason:
 *
 *   - a package absent from `vendor/` has nothing to patch, and a tree with no install is
 *     already reported by the install stamp;
 *   - a patch recorded by URL rather than by path is not on disk to compare against. This
 *     repository records none; one added later would go unjudged here rather than pin `--check`
 *     at 1.
 *
 * @param  string  treePath
 * @return object  `{ unpatched, patchesLockUnreadable }`
 */
export function patchDrift(treePath) {
    const lockFile = join(treePath, 'patches.lock.json');

    if (!existsSync(lockFile)) {
        return { unpatched: [], patchesLockUnreadable: false };
    }

    const groups = recordedPatches(readFileSync(lockFile, 'utf8'));

    if (groups === null) {
        return { unpatched: [], patchesLockUnreadable: true };
    }

    const unpatched = unpatchedPackages(groups, (name, patch) => !existsSync(join(treePath, 'vendor', ...name.split('/')))
        || patch.url.includes('://')
        || patchApplied(treePath, name, patch));

    return { unpatched, patchesLockUnreadable: false };
}

/**
 * Reinstall every package whose recorded patches are not applied, then confirm they are.
 *
 * Only the drifted packages are reinstalled: a reinstall deletes and re-extracts the package, which
 * is wasted work on one already patched. `composer reinstall` runs the plugin's install hook, which
 * is what `composer install` skips for a package at its locked version (#113).
 *
 * @param  string  path
 * @return void
 */
function reapplyPatches(path) {
    const before = patchDrift(path);

    if (before.patchesLockUnreadable) {
        throw new Error('patches.lock.json could not be read; run `composer patches-relock` and retry');
    }

    if (!before.unpatched.length) {
        process.stdout.write('every patch in patches.lock.json is applied\n');

        return;
    }

    for (const name of before.unpatched) {
        process.stdout.write(`${name}: recorded patches not applied; reinstalling\n`);
        run('composer', ['reinstall', name, '--no-interaction'], path);
    }

    const after = patchDrift(path);

    if (after.unpatched.length) {
        throw new Error(`recorded patches still not applied after reinstalling: ${after.unpatched.join(', ')}`);
    }
}

/**
 * What `status` and `refresh` report when a tree's base position could not be read.
 *
 * **It names no cause, and that is the point.** `baseProbe` reduces every failure to `unreadable`:
 * `capture` runs git with stderr `ignore`, so the reason is discarded before it can reach a call
 * site. The wording this replaces asserted `no origin/main ref` — one of several conditions that
 * arrive here, and established for none of them. An unborn `HEAD` reaches this line with the ref
 * present, which is the reproduction recorded in wordpress-importer-archive#880.
 *
 * It is one constant rather than a literal at each call site because the defect was two sites
 * independently naming the same unchecked cause. They cannot drift apart again.
 *
 * @var string
 */
export const BASE_UNMEASURED = 'base position unmeasured';

/**
 * Where a tree's HEAD sits relative to `origin/main`, as a state rather than a bare count.
 *
 * **The four fields answer four different questions, and `unreadable` is the one that misleads.**
 * `unreadable: true` means a comparison was attempted and failed. `false` means it succeeded *or*
 * that there was nothing to attempt — so `!unreadable` is not a green light, and a caller that
 * treats it as one folds an unmeasurable tree in with a measured, current one. Read `tracked`
 * before trusting `behind`.
 *
 * **It names no cause, deliberately.** `capture` runs git with stderr `ignore`, so a missing ref
 * and a failed call arrive here indistinguishably; `unreadable` reports that the answer is absent
 * and never why. An unparseable answer is not zero — reading it as zero would report a tree as
 * being at its base on the strength of git having failed, which is the fail-open this guard exists
 * to avoid.
 *
 * `tracked` is a FACT ABOUT THE BRANCH, not a gate, and that is a deliberate divergence from the
 * sibling probe in `uams-statamic`, which suppresses the comparison when a tree sits on a branch of
 * its own. Suppressing it here would delete a signal: a primary on a branch nobody set is the
 * oldest drift signal `.claude/rules/worktrees.md` records, and it is reported precisely because
 * the primary is supposed to hold `main`. Whether the position is JUDGED remains
 * `expectationsFor().checkBase`'s decision, unchanged.
 *
 * **This compares against the last-fetched `origin/main`, and deliberately does not fetch.**
 * `status` is the cheap guard run before other work, and a command that reaches the network on
 * every invocation is one people route around. The consequence is worth stating plainly: a tree
 * whose remote-tracking ref is itself stale reports a distance that is too small, so `git fetch`
 * before trusting a zero.
 *
 * @param  ?string  branch
 * @param  ?string  countOutput
 * @param  string   ref
 * @return {{behind: ?number, tracked: bool, ref: string, unreadable: bool}}
 */
export function baseState(branch, countOutput, ref = 'origin/main') {
    // An empty branch is a detached HEAD, which is a slot at rest and exactly the case that must
    // stay measurable — so absent-branch counts as tracked rather than as a branch of its own.
    const tracked = !branch || branch === 'main';

    if (countOutput === null) {
        return { behind: null, tracked, ref, unreadable: true };
    }

    const count = Number.parseInt(String(countOutput).trim(), 10);

    // A non-numeric answer is a failed read wearing a result's shape. It must not become 0.
    if (Number.isNaN(count)) {
        return { behind: null, tracked, ref, unreadable: true };
    }

    return { behind: count, tracked, ref, unreadable: false };
}

/**
 * Run the two git reads `baseState` interprets.
 *
 * Split from `baseState` for the reason the sibling implementation splits them: the interpretation
 * is the part with states worth asserting, and it cannot be tested while it is welded to a process
 * launch. This half is deliberately trivial so that everything worth getting wrong lives in the
 * half that a test can reach.
 *
 * @param  string  treePath
 * @return {{behind: ?number, tracked: bool, ref: string, unreadable: bool}}
 */
function baseProbe(treePath) {
    const ref = 'origin/main';

    return baseState(
        capture('git', ['-C', treePath, 'branch', '--show-current']),
        capture('git', ['-C', treePath, 'rev-list', '--count', `HEAD..${ref}`]),
        ref,
    );
}

/**
 * The command that actually moves a tree to its base, which differs by kind.
 *
 * **`release` is refused for the primary by design** — releasing detaches the tree, and the primary
 * must keep `main` checked out. Naming it for a primary would prescribe a command this script
 * itself rejects, so the two arms are not a formatting choice: they are the difference between a
 * runnable instruction and one that cannot work.
 *
 * It is a pure exported function rather than a ternary at the print site, and that is the whole
 * point of extracting it. Inline, the verb is unreachable from any unit test — which is exactly how
 * the sibling implementation shipped a WRONG verb under a full green suite: every assertion was
 * that drift had been DETECTED, and nothing could assert that what it prescribed could be run.
 * A second ticket existed only because a human read the output.
 *
 * @param  string  kind
 * @param  string  name
 * @return string
 */
export function baseRemedy(kind, name) {
    return kind === PRIMARY ? 'git merge --ff-only origin/main' : `release ${name}`;
}

/**
 * Rendered when the release distance could not be read, in place of a number.
 *
 * Separate from `BASE_UNMEASURED` because the two answer different questions and a reader who sees
 * one must not conclude anything about the other: a tree can sit exactly on `origin/main` while the
 * repository is a hundred commits past its newest tag, and vice versa.
 */
export const RELEASE_UNMEASURED = 'release distance unmeasured';

/**
 * How far the shipped surface is behind the tip — a four-state model, not a number.
 *
 * `uams-statamic` pins this package by a tagged caret range rather than `dev-main`, so merged work
 * is unreachable by the app until a tag exists. Every other signal this script reports is about a
 * TREE; this one is about the REPOSITORY, and it is invisible from inside a session by
 * construction: `main` is current, the tree is clean, the gate is green, and nothing says the
 * shipped surface is behind.
 *
 * **The states are four because two of them mean "nothing to report" for opposite reasons.**
 * A repository with no tags has shipped nothing and cannot be behind anything; a repository level
 * with its newest tag has shipped everything. Collapsing them loses the distinction between "this
 * package has never been released" and "this package is fully released", which are not the same
 * fact about a dependency. `unreadable` is the third: a probe that could not answer must never
 * render as a repository that is current, which is `baseState`'s rule applied to a second question.
 *
 * **`ageDays` is carried because the number alone does not size the risk.** 139 commits accumulated
 * over fifteen days and over one afternoon warrant different attention, and a reader who sees only
 * a count has to go and find the date themselves.
 *
 * **It names no cause, for `baseState`'s reason.** `capture` runs git with stderr `ignore`, so a
 * repository with no tags and a git invocation that failed arrive here indistinguishably — which is
 * why an absent tag is reported as `untagged` only when git answered and answered empty, and any
 * unparseable count is `unreadable` rather than zero.
 *
 * @param  ?string  tag         newest tag reachable from the shipped ref, or null/empty when none
 * @param  ?string  countOutput commits on the shipped ref since that tag
 * @param  ?string  tagDate     ISO-8601 committer date of the tagged commit
 * @param  ?Date    now         injected for testability; defaults to the current time
 * @return {{tag: ?string, ahead: ?number, ageDays: ?number, untagged: bool, unreadable: bool}}
 */
export function releaseState(tag, countOutput, tagDate, now = new Date()) {
    const named = typeof tag === 'string' ? tag.trim() : '';

    // git answered and answered empty: there is no tag, which is a state rather than a failure.
    if (tag !== null && named === '') {
        return { tag: null, ahead: null, ageDays: null, untagged: true, unreadable: false };
    }

    if (tag === null || countOutput === null) {
        return { tag: named || null, ahead: null, ageDays: null, untagged: false, unreadable: true };
    }

    const ahead = Number.parseInt(String(countOutput).trim(), 10);

    // A non-numeric answer is a failed read wearing a result's shape. It must not become 0.
    if (Number.isNaN(ahead)) {
        return { tag: named, ahead: null, ageDays: null, untagged: false, unreadable: true };
    }

    // The age is a nicety, not the finding: an unparseable date degrades to a null age beside a
    // usable count rather than discarding the count with it.
    const parsed = tagDate === null ? NaN : Date.parse(String(tagDate).trim());
    const ageDays = Number.isNaN(parsed) ? null : Math.floor((now.getTime() - parsed) / 86400000);

    return { tag: named, ahead, ageDays, untagged: false, unreadable: false };
}

/**
 * The one line `status` prints for the repository, given a `releaseState`.
 *
 * Extracted for `baseRemedy`'s reason: rendered inline it is unreachable from any unit test, and
 * the wording is the part a reader acts on. Each state gets its own sentence so that no two of them
 * are byte-identical on screen.
 *
 * @param  object  state
 * @return string
 */
export function releaseLine(state) {
    if (state.unreadable) {
        return `${RELEASE_UNMEASURED} — 'git describe --tags --abbrev=0 origin/main' reports why.`;
    }

    if (state.untagged) {
        return 'no tags in this repository — nothing has been released, so nothing is unreleased.';
    }

    if (state.ahead === 0) {
        return `origin/main is level with ${state.tag} — everything merged is released.`;
    }

    const age = state.ageDays === null ? '' : `, cut ${state.ageDays}d ago`;

    // Deliberately not phrased as an instruction. `.claude/rules/cutting-releases.md` owns WHEN to
    // tag, and a status line that told a session to cut a release would be prescribing a reserved
    // action on the strength of a count.
    return `origin/main is ${state.ahead} commits ahead of ${state.tag}${age} — unreachable by anything pinning a tagged range.`;
}

/**
 * Run the three git reads `releaseState` interprets.
 *
 * Split from `releaseState` for `baseProbe`'s reason: the interpretation has states worth asserting
 * and cannot be tested while welded to a process launch.
 *
 * **Measured against `origin/main` rather than the primary's HEAD**, because the question is what a
 * consumer can reach, not where any one tree happens to sit. It does not fetch, for `baseState`'s
 * reason — a command that reaches the network on every invocation is one people route around. A
 * stale remote-tracking ref therefore under-reports, which fails quiet rather than loud; run
 * `git fetch` before trusting a zero.
 *
 * A bare `git fetch origin` keeps the tags current, so this probe is safe without a fetch of its
 * own: measured on git 2.32.0.windows.2 against a fresh repository, a bare fetch brought all 20 tag
 * objects, matching an explicit `--tags` exactly.
 *
 * @param  string  repoRoot
 * @return {{tag: ?string, ahead: ?number, ageDays: ?number, untagged: bool, unreadable: bool}}
 */
function releaseProbe(repoRoot) {
    const ref = 'origin/main';
    const tag = capture('git', ['-C', repoRoot, 'describe', '--tags', '--abbrev=0', ref]);

    // No tag means the follow-up reads have nothing to measure from, and running them would turn a
    // clean `untagged` into an `unreadable` by way of two failed git calls.
    if (tag === null || String(tag).trim() === '') {
        return releaseState(tag, null, null);
    }

    const named = String(tag).trim();

    return releaseState(
        named,
        capture('git', ['-C', repoRoot, 'rev-list', '--count', `${named}..${ref}`]),
        capture('git', ['-C', repoRoot, 'log', '-1', '--format=%cI', named]),
    );
}

/**
 * The one line `--check` prints, so its verdict survives a pipe.
 *
 * **The exit code is not the problem; being the ONLY channel is.** A pipe replaces `$?` with the
 * last command's status, and the table above is byte-identical whether or not a tree has drifted —
 * so a masked verdict is indistinguishable from a pass. On 2026-09-06 five false greens were
 * published in one evening by four concurrent sessions on that shape, two of them by sessions that
 * already knew about the trap. That is why this is a tool change and not another instruction to be
 * careful: the remedy that depends on attention was measured failing in the presence of full
 * knowledge.
 *
 * **It names the trees and their drift rather than saying `DRIFT`.** The table already carried
 * `2 behind origin/main` on the evening above, and it was read as informational because the exit
 * code said clean — so a bare verdict word would be a second signal to reconcile against the same
 * wrong one. Naming the tree makes the line answer the question the reader would otherwise take to
 * the table.
 *
 * Pure and exported for `baseRemedy`'s reason: the wording is the part a reader acts on, and
 * inline it is unreachable from any test. It also makes the CLEAN arm testable at all — four
 * simultaneously-current trees is a state a working repository passes through rather than holds,
 * so a test that waits for one never runs.
 *
 * @param  array  trees
 * @return string
 */
export function checkVerdict(trees) {
    const drifted = trees.filter((tree) => tree.drift.length);
    const count = (n) => `${n} ${n === 1 ? 'tree' : 'trees'}`;

    if (!drifted.length) {
        return `CLEAN: ${count(trees.length)}`;
    }

    const named = drifted.map((tree) => `${tree.name}, ${tree.drift.join('; ')}`).join(' | ');

    return `DRIFT: ${count(drifted.length)} (${named})`;
}

/**
 * Every tree, with its kind, occupancy and drift.
 *
 * @param  string  repoRoot
 * @return array
 */
/**
 * The working-tree state `slotClaimability` judges.
 *
 * `--ignore-submodules=all` is load-bearing here for the same reason `release` needs it at its own
 * call site: a tree that checks out a commit from before wordpress-importer-archive#1426 carries the exports gitlink again,
 * and its working copy shows as modified independently of anyone's changes.
 *
 * @param  string  treePath
 * @return string
 */
function porcelainStatusOf(treePath) {
    return capture('git', ['-C', treePath, 'status', '--porcelain', '--ignore-submodules=all']) || '';
}

function inspect(repoRoot) {
    return worktreesOf(repoRoot).map((tree) => {
        const base = baseProbe(tree.path);
        const behind = base.behind;
        const atRest = slotOccupancy(tree) === 'free';
        const { kind, drift } = judgeTree(tree.path, repoRoot, {
            composerHash: composerHashOf(tree.path),
            stampComposerHash: stampHashOf(tree.path),
            ...patchDrift(tree.path),
            behind,
            atRest,
        });

        return {
            ...tree,
            name: basename(tree.path),
            kind,
            role: roleFor(kind, tree, porcelainStatusOf(tree.path), claimOf(tree, repoRoot)),
            drift,
            // Reported, never counted. A tree whose base could not be read must not look like one
            // measured at zero — see `driftReasons`.
            baseUnmeasured: expectationsFor(kind, atRest).checkBase && base.unreadable,
        };
    });
}

/**
 * `status` — print every tree, and exit 1 under `--check` when any has drifted.
 *
 * @param  string  repoRoot
 * @param  array   argv
 * @return number
 */
function commandStatus(repoRoot, argv) {
    const trees = inspect(repoRoot);

    if (argv.includes('--json')) {
        // Shaped as `{trees, release}` rather than a bare array: the release distance is a fact
        // about the repository and has no tree to hang from. A consumer reading the old shape gets
        // an object where it expected an array, which fails loudly rather than dropping the field.
        process.stdout.write(`${JSON.stringify({ trees, release: releaseProbe(repoRoot) }, null, 2)}\n`);
    } else {
        const width = Math.max(...trees.map((tree) => tree.name.length), 12);

        for (const tree of trees) {
            const full = tree.branch || '(detached)';
            // Issue-linked branch names run long enough to break the column; the number identifies
            // the ticket, which is what the reader is scanning for.
            const branch = full.length > 34 ? `${full.slice(0, 33)}…` : full;

            // The unmeasured note sits in the same column as drift and is deliberately NOT part
            // of the exit code: it says the base was not read, which is not a claim that the tree
            // is stale. Rendering it is what stops "could not check" reading as "checked, fine".
            const notes = tree.baseUnmeasured ? [BASE_UNMEASURED] : [];
            const cells = [...tree.drift, ...notes];

            process.stdout.write(`${tree.name.padEnd(width)}  ${branch.padEnd(34)}  ${tree.role.padEnd(9)}  ${cells.length ? cells.join('; ') : '-'}\n`);
        }

        // Repository-wide rather than per-tree, so it sits below the table and prints on every
        // run - including when every tree is clean, which is exactly when a release gap is
        // invisible. It is never part of the exit code: unreleased work is a NORMAL state, and a
        // gate that failed on it would be switched off within a day.
        process.stdout.write(`\n${releaseLine(releaseProbe(repoRoot))}\n`);
    }

    // Printed LAST so it survives `| tail -1`, and only under `--check` — bare `status` is a
    // report and has no verdict to give. Never under `--json`: a consumer parses that whole stream,
    // and a human-readable line in it would break the parse this command's callers depend on.
    if (argv.includes('--check') && !argv.includes('--json')) {
        process.stdout.write(`${checkVerdict(trees)}\n`);
    }

    // Unchanged, deliberately. This adds a CHANNEL rather than replacing one: a scripted caller
    // branching on the exit code must keep behaving exactly as before.
    return argv.includes('--check') && trees.some((tree) => tree.drift.length) ? 1 : 0;
}

/**
 * The value of a `--flag value` pair, or null.
 *
 * @param  array   argv
 * @param  string  flag
 * @return ?string
 */
function flagValue(argv, flag) {
    const at = argv.indexOf(flag);

    return at !== -1 && argv[at + 1] && !argv[at + 1].startsWith('--') ? argv[at + 1] : null;
}

/** The flags `claim` reads, each followed by a value. */
const CLAIM_VALUE_FLAGS = ['--owner', '--branch', '--at'];

/**
 * The positional arguments passed to `claim`, which takes none (wordpress-importer-archive#1198).
 *
 * **A flag's value is not positional.** `claim --branch 1198-x` carries the word `1198-x`, and a
 * test that counted every non-flag word would refuse the ordinary form. So the values of the
 * flags `claim` reads are skipped, and anything left over is an argument `claim` would otherwise
 * have ignored in silence: `claim b` took slot A and exited 0.
 *
 * @param  array  argv  the arguments after the command name
 * @return array
 */
export function claimPositionals(argv) {
    const positionals = [];

    for (let i = 0; i < argv.length; i++) {
        if (CLAIM_VALUE_FLAGS.includes(argv[i])) {
            i++;

            continue;
        }

        if (!argv[i].startsWith('--')) {
            positionals.push(argv[i]);
        }
    }

    return positionals;
}

/** `git update-ref`'s sentinel for "this ref must not exist yet" — the whole atomicity rests on it. */
const NO_SUCH_REF = '0'.repeat(40);

/** `claim` found no provisioned slots: the caller prompts before provisioning. */
const CLAIM_UNPROVISIONED = 4;

/** `claim` found every slot held: the caller prompts rather than evicting one. */
const CLAIM_EXHAUSTED = 3;

/**
 * Who a claim is recorded against when the caller does not say.
 *
 * @return string
 */
function defaultOwner() {
    return process.env.CLAUDE_SLACK_MACHINE || hostname();
}

/**
 * The worktree for a slot, or null when that slot is not provisioned.
 *
 * `resolveSlot` throws for an unregistered name, which is right for a command given a target and
 * wrong for `claim`, whose job is to survey what exists.
 *
 * @param  array   worktrees
 * @param  string  repoRoot
 * @param  string  slot
 * @return ?object
 */
function slotWorktree(worktrees, repoRoot, slot) {
    const path = join(dirname(repoRoot), slotDirName(repoRoot, slot));

    return worktrees.find((tree) => normalizePath(tree.path) === normalizePath(path)) || null;
}

/**
 * Read a slot's claim, or null when it is unclaimed.
 *
 * @param  string  repoRoot
 * @param  string  slot
 * @return ?object
 */
function readClaim(repoRoot, slot) {
    const oid = capture('git', ['-C', repoRoot, 'rev-parse', '--verify', '--quiet', claimRefName(slot)], repoRoot);

    return oid ? parseClaimRecord(capture('git', ['-C', repoRoot, 'cat-file', '-p', oid], repoRoot)) : null;
}

/**
 * Attempt to claim a slot, atomically.
 *
 * Returns false when another claimant got there first, which is an ordinary outcome rather than an
 * error — so this does not throw the way `run` does.
 *
 * @param  string  repoRoot
 * @param  string  slot
 * @param  string  owner
 * @param  string  at
 * @return ?string  the claim object written, or null when another claimant got there first
 */
function tryClaim(repoRoot, slot, owner, at) {
    const scratch = join(repoRoot, '.git', `slot-claim-${slot}.tmp`);

    writeFileSync(scratch, claimRecord(owner, at));

    const oid = capture('git', ['-C', repoRoot, 'hash-object', '-w', scratch], repoRoot);

    rmSync(scratch, { force: true });

    // The create-only update. git refuses under the ref lock when the name already exists, so
    // exactly one of two concurrent claimants succeeds however their reads interleaved.
    // Returns the object written, so a claim that has to be rolled back can name exactly what it
    // wrote rather than whatever the ref holds by then (wordpress-importer-archive#1201).
    return oid && spawn('git', ['-C', repoRoot, 'update-ref', claimRefName(slot), oid, NO_SUCH_REF], repoRoot, 'ignore').status === 0
        ? oid
        : null;
}

/**
 * Give back a claim this invocation wrote, and only that claim (wordpress-importer-archive#1201).
 *
 * `update-ref -d <ref> <oid>` deletes under the ref lock only while the ref still holds `oid`. So a
 * claim some other session wrote in the meantime, after a release, is left alone, where a bare
 * `-d` would remove it.
 *
 * @param  string  repoRoot
 * @param  string  slot
 * @param  string  oid  the claim object this invocation wrote
 * @return bool         whether the ref was deleted
 */
export function releaseOwnClaim(repoRoot, slot, oid) {
    return spawn('git', ['-C', repoRoot, 'update-ref', '-d', claimRefName(slot), oid], repoRoot, 'ignore').status === 0;
}

/**
 * Delete a slot's claim.
 *
 * @param  string  repoRoot
 * @param  string  slot
 * @return void
 */
function clearClaim(repoRoot, slot) {
    spawn('git', ['-C', repoRoot, 'update-ref', '-d', claimRefName(slot)], repoRoot, 'ignore');
}

/**
 * The claim on the slot a worktree represents, or null.
 *
 * Only a claimable slot can carry one, so the primary, the CI tree and any ephemeral worktree
 * answer null without a ref read.
 *
 * @param  object  tree
 * @param  string  repoRoot
 * @return ?object
 */
function claimOf(tree, repoRoot) {
    const prefix = `${basename(repoRoot)}-`;
    const name = basename(tree.path);
    const slot = name.startsWith(prefix) ? name.slice(prefix.length) : null;

    return slot && CLAIMABLE_SLOTS.includes(slot) ? readClaim(repoRoot, slot) : null;
}

/**
 * `claim` — take a slot atomically and report which one, so a session names itself from what it
 * holds rather than from what it read a moment ago.
 *
 * **The whole point is that the claim precedes the checkout.** A session that reads `status`, sees
 * `free`, and then checks a branch out has a window in which a second session does the same; git's
 * per-branch exclusivity does not close it, because the second session creating a *different*
 * branch in the same tree is not refused — it simply swaps the tree out from under the first.
 *
 * Prints the slot on the first line and the derived session label on the second, so a caller reads
 * two fields without parsing prose. Exits `CLAIM_UNPROVISIONED` and `CLAIM_EXHAUSTED` distinctly,
 * because those are the two cases where a human has to be asked and the caller must tell them apart
 * without matching on text.
 *
 * @param  string  repoRoot
 * @param  array   argv
 * @return number
 */
function commandClaim(repoRoot, argv) {
    // `claim` picks the first free slot itself. A slot name passed here used to be ignored, so
    // `claim b` claimed A and exited 0; refusing is what tells the caller it asked for something
    // this command does not do (wordpress-importer-archive#1198).
    const stray = claimPositionals(argv.slice(argv.indexOf('claim') + 1));

    if (stray.length > 0) {
        process.stderr.write(
            `claim takes no slot argument (got '${stray.join(' ')}'); it claims the first free slot, A then B.\n`
            + 'Run it bare, and read the slot it took from the first line of output.\n',
        );

        return 2;
    }

    const owner = flagValue(argv, '--owner') || defaultOwner();
    const branch = flagValue(argv, '--branch');
    const at = flagValue(argv, '--at') || new Date().toISOString();
    const worktrees = worktreesOf(repoRoot);
    const provisioned = CLAIMABLE_SLOTS
        .map((slot) => ({ slot, tree: slotWorktree(worktrees, repoRoot, slot) }))
        .filter((candidate) => candidate.tree !== null);

    if (provisioned.length === 0) {
        process.stderr.write(`No slot is provisioned here. Run 'provision a' before claiming.\n`);

        return CLAIM_UNPROVISIONED;
    }

    for (const { slot, tree } of provisioned) {
        if (readClaim(repoRoot, slot) || slotOccupancy(tree) !== 'free') {
            continue;
        }

        const claimed = tryClaim(repoRoot, slot, owner, at);

        if (!claimed) {
            continue;
        }

        // Only after the claim is held, so a losing claimant never touches a tree.
        //
        // **A branch step that fails gives the claim back (wordpress-importer-archive#1201).** Otherwise the command exits
        // non-zero while the slot reads `held`, detached and branchless, and a retry takes the
        // other slot. The session that failed does not hold the slot in any useful sense, and no one
        // else can take it until someone runs `release`.
        if (branch) {
            try {
                run('git', ['-C', tree.path, 'fetch', 'origin'], tree.path);
                run('git', checkoutArgs(tree.path, 'origin/main'), tree.path);
                run('git', checkoutArgs(tree.path, branch, { create: true }), tree.path);
            } catch (error) {
                const released = releaseOwnClaim(repoRoot, slot, claimed);

                throw new Error(
                    `${error.message}; the claim on ${slot} was ${released ? 'released' : 'NOT released (it no longer holds this claim)'}`,
                );
            }
        }

        process.stdout.write(`${slot}\n${basename(tree.path)}-${owner}\n`);

        return 0;
    }

    process.stderr.write('Every provisioned slot is held — take an ephemeral worktree rather than evicting one.\n');

    return CLAIM_EXHAUSTED;
}

/**
 * Install node dependencies in a slot, so it can run the gate's default job set.
 *
 * **A slot this script provisions must be able to run the gate.** `composer install` has always run
 * here; nothing ever ran `npm`, which was invisible until the `spell` job entered the default set —
 * it is the only job needing `node_modules`, since the `scripts/*.test.mjs` files import `node:`
 * builtins and local modules only. A slot provisioned before this change could be perfectly current
 * by every check `status` makes and still refuse to run the gate.
 *
 * **Skipped when `cspell` is present and the installed tree matches the lockfile, unlike the
 * Composer install beside it.** `composer install` is idempotent and cheap enough to run
 * unconditionally; `npm ci` deletes `node_modules` and rebuilds it, so running it for its own
 * idempotence would add tens of seconds to every `refresh` for no change. Presence alone was the
 * old test, and it left a slot on an old `cspell` after `package-lock.json` moved (wordpress-importer-archive#1335).
 *
 * @param  string  path
 * @return void
 */
function installNodeModules(path) {
    const readOrNull = (file) => {
        try {
            return readFileSync(file, 'utf8');
        } catch {
            return null;
        }
    };

    const drift = existsSync(join(path, 'node_modules', 'cspell'))
        ? nodeModulesDrift(readOrNull(join(path, 'package-lock.json')), readOrNull(join(path, 'node_modules', '.package-lock.json')))
        : 'node_modules/cspell is missing';

    if (drift === null) {
        process.stdout.write('node_modules matches package-lock.json; npm ci skipped\n');
        return;
    }

    process.stdout.write(`node_modules: ${drift}; running npm ci\n`);
    run('npm', ['ci', '--no-audit', '--no-fund'], path);
}

/**
 * `refresh` — reconcile a tree against the branch it is sitting on.
 *
 * @param  string  repoRoot
 * @param  string  name
 * @return number
 */
function commandRefresh(repoRoot, name) {
    const slot = resolveSlot(worktreesOf(repoRoot), repoRoot, name);
    const path = slot.path;
    // Reconcile against the same expectations `status` judges by, so the two halves of the gate
    // cannot disagree.
    const expected = expectationsFor(treeKind(path, repoRoot), slotOccupancy(slot) === 'free');

    process.stdout.write(`Refreshing ${basename(path)} (${slot.branch || 'detached'})\n`);

    if (expected.checkInstall) {
        // Idempotent and cheap — it reports "Nothing to install, update or remove" when the tree
        // is already current, so there is nothing to reason about before running it.
        run('composer', ['install', '--no-interaction'], path);
        reapplyPatches(path);
        installNodeModules(path);
        writeInstallStamp(path);
    } else {
        process.stdout.write(`composer install skipped — ${basename(path)} is not this script's to bootstrap\n`);
    }

    // Never an unqualified "is current": `refresh` reconciles the ENVIRONMENT against whatever
    // commit the tree is sitting on, and says nothing about whether that commit is current. The
    // two were read as one thing, which is the defect this reports its way out of rather than
    // fixing by moving the tree — repositioning belongs to `release`, and moving a slot under its
    // holder is forbidden outright by `.claude/rules/worktrees.md`.
    const head = capture('git', ['-C', path, 'rev-parse', '--short', 'HEAD']) || 'unknown';
    const reconciled = expected.checkInstall ? 'vendor reconciled against' : 'nothing reconciled at';

    process.stdout.write(`${basename(path)}: ${reconciled} HEAD ${head}.\n`);

    // Position is reported, never acted on, and only where `status` would judge it — so a slot
    // holding a ticket branch is not nagged for the state it is supposed to be in.
    const base = baseProbe(path);

    if (expected.checkBase && base.unreadable) {
        // Without this the output is byte-identical to a tree measured at zero, so a base that
        // could not be read would read as one that is current — the same substitution of silence
        // for a result that this whole change exists to remove.
        process.stdout.write(`${basename(path)}: ${BASE_UNMEASURED} — 'git -C "${path}" rev-list --count HEAD..origin/main' reports why.
`);
    } else if (expected.checkBase && base.behind > 0) {
        const move = baseRemedy(treeKind(path, repoRoot), name);

        process.stdout.write(`${basename(path)} is ${base.behind} behind origin/main — this did not move it; '${move}' does.\n`);
    }

    return 0;
}

/**
 * `release` — return a slot to rest so its branch is free.
 *
 * @param  string  repoRoot
 * @param  string  name
 * @param  array   argv
 * @return number
 */
function commandRelease(repoRoot, name, argv = []) {
    // `refresh` gained the primary as a target; release and provision must NOT. Releasing detaches
    // the tree at origin/main, and the primary is the one tree that has to keep `main` checked out
    // — git allows a branch in one worktree only, so detaching it puts `main` nowhere.
    if (name === PRIMARY) {
        process.stderr.write('The primary checkout holds `main` and is never released — only a slot is.\n');

        return 1;
    }

    const slot = resolveSlot(worktreesOf(repoRoot), repoRoot, name);
    const path = slot.path;
    // `--ignore-submodules=all` is load-bearing, not inherited boilerplate: a tree on a commit from
    // before wordpress-importer-archive#1426 carries the exports gitlink, whose working copy shows as modified independently
    // of anyone's changes, so without it such a release would be refused.
    const refusal = releaseRefusalReason(capture('git', ['-C', path, 'status', '--porcelain', '--ignore-submodules=all']) || '');

    if (refusal) {
        process.stderr.write(`Refusing to release ${basename(path)} — ${refusal}\n`);
        process.stderr.write('Commit or move the work first; releasing detaches the tree and would strand it.\n');

        return 1;
    }

    // A claim belonging to another machine is not this caller's to clear: the holder cannot be
    // asked, and releasing detaches a tree they may be mid-run in. Same refusal the dirty-tree
    // check above makes, for the same reason.
    const owner = flagValue(argv, '--owner') || defaultOwner();
    const claim = readClaim(repoRoot, name);

    if (!mayClearClaim(claim, owner)) {
        process.stderr.write(`Refusing to release ${basename(path)} — claimed by '${claim.owner}', not '${owner}'.\n`);
        process.stderr.write('Ask that holder to release it, or clear the claim deliberately with --owner.\n');

        return 1;
    }

    run('git', ['-C', path, 'fetch', 'origin'], path);
    run('git', checkoutArgs(path, 'origin/main'), path);
    clearClaim(repoRoot, name);

    process.stdout.write(`${basename(path)} released — detached at origin/main, branch '${slot.branch || '(none)'}' preserved.\n`);

    return 0;
}

/**
 * `provision` — create a slot as a sibling of the primary and bootstrap it.
 *
 * @param  string  repoRoot
 * @param  string  name
 * @return number
 */
function commandProvision(repoRoot, name) {
    if (name === PRIMARY) {
        process.stderr.write('The primary checkout is the clone itself — there is nothing to provision.\n');

        return 1;
    }

    const path = join(dirname(repoRoot), slotDirName(repoRoot, name));

    if (worktreesOf(repoRoot).some((tree) => normalizePath(tree.path) === normalizePath(path))) {
        process.stderr.write(`${basename(path)} is already a registered worktree. Use 'refresh ${name}' instead.\n`);

        return 1;
    }

    run('git', ['-C', repoRoot, 'fetch', 'origin'], repoRoot);
    // Detached, not on a branch: `main` belongs to the primary, and git allows a branch in exactly
    // one worktree.
    run('git', ['-C', repoRoot, 'worktree', 'add', '--detach', path, 'origin/main'], repoRoot);

    run('composer', ['install', '--no-interaction'], path);
    reapplyPatches(path);
    installNodeModules(path);
    writeInstallStamp(path);

    process.stdout.write(`${basename(path)} provisioned — detached at origin/main.\n`);

    return 0;
}

function main() {
    const [command, name] = process.argv.slice(2).filter((arg) => !arg.startsWith('--'));
    const argv = process.argv.slice(2);

    let repoRoot;

    try {
        repoRoot = primaryCheckout(process.cwd());
    } catch (error) {
        process.stderr.write(`worktree-slots: ${error.message}\n`);
        process.exit(2);
    }

    try {
        switch (command) {
            case 'status': process.exit(commandStatus(repoRoot, argv)); break;
            case 'claim': process.exit(commandClaim(repoRoot, argv)); break;
            case 'refresh': process.exit(commandRefresh(repoRoot, name)); break;
            case 'release': process.exit(commandRelease(repoRoot, name, argv)); break;
            case 'provision': process.exit(commandProvision(repoRoot, name)); break;
            default:
                process.stderr.write(
                    'Usage: worktree-slots.mjs status [--json] [--check]\n'
                    + '       worktree-slots.mjs claim [--owner <id>] [--branch <name>]   (no slot: it takes the first free one)\n'
                    + '       worktree-slots.mjs refresh|provision <slot>\n'
                    + '       worktree-slots.mjs release <slot> [--owner <id>]\n',
                );
                process.exit(2);
        }
    } catch (error) {
        process.stderr.write(`worktree-slots: ${(error.message || '').split('\n')[0]}\n`);
        process.exit(2);
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main();
}
