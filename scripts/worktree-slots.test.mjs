// Unit tests for worktree-slots.mjs's pure functions. Run: node --test scripts/worktree-slots.test.mjs
//
// Importing worktree-slots.mjs is side-effect-free — its main() is guarded to run only when the
// file is invoked directly, so this import never shells out to git or composer.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
    baseRemedy,
    baseState,
    checkoutArgs,
    checkVerdict,
    claimPositionals,
    releaseOwnClaim,
    claimRecord,
    claimRefName,
    mayClearClaim,
    parseClaimRecord,
    releaseLine,
    releaseState,
    RELEASE_UNMEASURED,
    driftReasons,
    expectationsFor,
    installStaleReason,
    judgeTree,
    normalizePath,
    parseWorktreeList,
    patchApplied,
    patchDrift,
    recordedPatches,
    unpatchedPackages,
    BASE_UNMEASURED,
    releaseRefusalReason,
    resolveSlot,
    slotDirName,
    slotClaimability,
    roleFor,
    slotOccupancy,
    treeKind,
} from './worktree-slots.mjs';

const ROOT = '/repos/wordpress-importer';

/** A worktree record as `parseWorktreeList` would yield it. */
const tree = (path, branch = null) => ({ path, head: 'abc123', branch, detached: !branch });

describe('parseWorktreeList', () => {
    it('reads one record per tree, stripping the refs/heads prefix', () => {
        const out = parseWorktreeList(
            'worktree /repos/wordpress-importer\nHEAD abc\nbranch refs/heads/main\n\n'
            + 'worktree /repos/wordpress-importer-a\nHEAD def\nbranch refs/heads/590-slots\n',
        );

        assert.equal(out.length, 2);
        assert.equal(out[0].branch, 'main');
        assert.equal(out[1].path, '/repos/wordpress-importer-a');
        assert.equal(out[1].branch, '590-slots');
    });

    it('marks a detached tree as detached with no branch', () => {
        const out = parseWorktreeList('worktree /repos/wordpress-importer-b\nHEAD abc\ndetached\n');

        assert.equal(out[0].branch, null);
        assert.equal(out[0].detached, true);
    });

    it('tolerates blank padding between records', () => {
        const out = parseWorktreeList('\n\nworktree /a\nHEAD x\ndetached\n\n   \n\nworktree /b\nHEAD y\ndetached\n\n');

        assert.deepEqual(out.map((r) => r.path), ['/a', '/b']);
    });

    it('returns nothing for empty input rather than a phantom record', () => {
        assert.deepEqual(parseWorktreeList(''), []);
        assert.deepEqual(parseWorktreeList('\n\n'), []);
    });
});

describe('normalizePath', () => {
    it('treats a Windows path and its git-reported form as equal', () => {
        assert.equal(
            normalizePath('D:\\GitHub Repos\\wordpress-importer'),
            normalizePath('D:/GitHub Repos/wordpress-importer'),
        );
    });

    it('ignores drive-letter casing and a trailing separator', () => {
        assert.equal(normalizePath('D:/Repos/x/'), normalizePath('d:/repos/x'));
    });
});

describe('slotDirName', () => {
    it('derives the slot directory from the primary checkout, not a hard-coded name', () => {
        assert.equal(slotDirName(ROOT, 'a'), 'wordpress-importer-a');
        assert.equal(slotDirName('/tmp/my-clone', 'ci'), 'my-clone-ci');
    });
});

describe('resolveSlot', () => {
    const worktrees = [tree(ROOT, 'main'), tree('/repos/wordpress-importer-a')];

    it('resolves a slot name to its registered worktree', () => {
        assert.equal(resolveSlot(worktrees, ROOT, 'a').path, '/repos/wordpress-importer-a');
    });

    it('resolves `primary` to the repo root itself, not a -primary sibling', () => {
        assert.equal(resolveSlot(worktrees, ROOT, 'primary').path, ROOT);
    });

    it('refuses a slot that is not a registered worktree, and suggests provisioning it', () => {
        assert.throws(() => resolveSlot(worktrees, ROOT, 'b'), /No registered worktree.*provision b/s);
    });

    it('omits the provision hint when the primary is somehow unregistered', () => {
        assert.throws(() => resolveSlot([], ROOT, 'primary'), (error) => !/provision/.test(error.message));
    });

    it('rejects names that are not plain slot identifiers', () => {
        assert.throws(() => resolveSlot(worktrees, ROOT, '../evil'), /Invalid slot name/);
        assert.throws(() => resolveSlot(worktrees, ROOT, ''), /Invalid slot name/);
        assert.throws(() => resolveSlot(worktrees, ROOT, '1a'), /Invalid slot name/);
    });
});

describe('treeKind', () => {
    it('separates the primary, the CI slot, and an ordinary slot', () => {
        assert.equal(treeKind(ROOT, ROOT), 'primary');
        assert.equal(treeKind('/repos/wordpress-importer-ci', ROOT), 'ci');
        assert.equal(treeKind('/repos/wordpress-importer-a', ROOT), 'slot');
    });

    it('identifies the CI slot of a differently-named clone', () => {
        assert.equal(treeKind('/tmp/my-clone-ci', '/tmp/my-clone'), 'ci');
    });

    it('reads the shared runner\'s slot as runner-owned, not as a work slot (wordpress-importer-archive#1319)', () => {
        // Without this the sibling-name test below it would call the tree a slot, and
        // `status --check` would hold a runner-owned tree to a slot's bootstrap forever.
        assert.equal(treeKind('/repos/wordpress-importer-local-ci', ROOT), 'ci');
        assert.equal(treeKind('/tmp/my-clone-local-ci', '/tmp/my-clone'), 'ci');
    });

    it('treats a nested worktree as ephemeral, not a slot', () => {
        assert.equal(treeKind(`${ROOT}/.claude/worktrees/t590-slots`, ROOT), 'ephemeral');
    });

    it('does not mistake an unrelated sibling directory for a slot', () => {
        assert.equal(treeKind('/repos/wordpress-wxr-cleanup', ROOT), 'ephemeral');
    });
});

describe('expectationsFor', () => {
    it('holds a slot and the primary to the install', () => {
        assert.equal(expectationsFor('slot').checkInstall, true);
        assert.equal(expectationsFor('primary').checkInstall, true);
    });

    it('exempts the runner-owned CI slot and an ephemeral worktree', () => {
        assert.equal(expectationsFor('ci').checkInstall, false);
        assert.equal(expectationsFor('ephemeral').checkInstall, false);
    });

    it('judges base position for the primary and for a slot at rest', () => {
        assert.equal(expectationsFor('primary').checkBase, true);
        assert.equal(expectationsFor('slot', true).checkBase, true);
    });

    it('exempts a slot holding a ticket branch, which is supposed to be behind main', () => {
        assert.equal(expectationsFor('slot', false).checkBase, false);
    });

    it('judges the primary regardless of rest state, since it always sits on a branch', () => {
        // The primary holds `main`, so `slotOccupancy` reads it as held — that must not exempt it.
        assert.equal(expectationsFor('primary', false).checkBase, true);
    });

    it('exempts the CI slot and an ephemeral worktree from base position too', () => {
        assert.equal(expectationsFor('ci', true).checkBase, false);
        assert.equal(expectationsFor('ephemeral', true).checkBase, false);
    });

    it('defaults to the strict reading, so an exemption has to be asked for', () => {
        assert.equal(expectationsFor('slot').checkBase, true);
    });
});

describe('driftReasons — base position', () => {
    const behindProbe = (behind, expected) => driftReasons({
        composerHash: 'lock', stampComposerHash: 'lock', behind, expected,
    });

    it('reports the distance when a judged tree is behind', () => {
        const reasons = behindProbe(14, expectationsFor('primary'));

        assert.deepEqual(reasons, ['14 behind origin/main']);
    });

    it('says nothing when the tree is level', () => {
        assert.deepEqual(behindProbe(0, expectationsFor('primary')), []);
    });

    it('treats an unmeasurable distance as not drift, never as drift', () => {
        // No `origin/main` ref, or git declined — a green from a measurement never taken is the
        // same unfalsifiable pass this check exists to remove, so it must not invent one either.
        assert.deepEqual(behindProbe(null, expectationsFor('primary')), []);
    });

    it('stays silent for a slot holding a ticket branch, however far behind', () => {
        assert.deepEqual(behindProbe(120, expectationsFor('slot', false)), []);
    });

    it('reports alongside the bootstrap reasons rather than replacing them', () => {
        const reasons = driftReasons({
            composerHash: 'new',
            stampComposerHash: 'old',
            behind: 5,
            expected: expectationsFor('slot', true),
        });

        assert.equal(reasons.length, 2);
        assert.match(reasons[0], /^5 behind origin\/main$/);
        assert.ok(reasons.some((reason) => reason.startsWith('vendor:')));
    });
});

describe('judgeTree — the composition that decides --check', () => {
    // A tree with no install stamp at all: the CI slot's prescribed state.
    const probe = { composerHash: 'lock', stampComposerHash: null };

    it('clears the CI slot in exactly the state the rule prescribes for it', () => {
        const judged = judgeTree('/repos/wordpress-importer-ci', ROOT, probe);

        assert.equal(judged.kind, 'ci');
        assert.deepEqual(judged.drift, []);
    });

    it('faults the identical state in a real slot', () => {
        const judged = judgeTree('/repos/wordpress-importer-a', ROOT, probe);

        assert.equal(judged.kind, 'slot');
        assert.equal(judged.drift.length, 1);
        assert.match(judged.drift[0], /^vendor:/);
    });

    it('clears an ephemeral worktree, which is nobody\'s to keep current', () => {
        assert.deepEqual(judgeTree(`${ROOT}/.claude/worktrees/t1`, ROOT, probe).drift, []);
    });

    it('faults a resting slot that is behind origin/main', () => {
        const judged = judgeTree('/repos/wordpress-importer-a', ROOT, {
            composerHash: 'lock', stampComposerHash: 'lock', behind: 5, atRest: true,
        });

        assert.equal(judged.kind, 'slot');
        assert.deepEqual(judged.drift, ['5 behind origin/main']);
    });

    it('clears that same slot once it is holding a ticket branch', () => {
        const judged = judgeTree('/repos/wordpress-importer-a', ROOT, {
            composerHash: 'lock', stampComposerHash: 'lock', behind: 5, atRest: false,
        });

        assert.deepEqual(judged.drift, []);
    });

    it('faults the primary for being behind even though it sits on a branch', () => {
        const judged = judgeTree(ROOT, ROOT, {
            composerHash: 'lock', stampComposerHash: 'lock', behind: 14, atRest: false,
        });

        assert.equal(judged.kind, 'primary');
        assert.deepEqual(judged.drift, ['14 behind origin/main']);
    });

});

describe('BASE_UNMEASURED', () => {
    // wordpress-importer-archive#880: both call sites previously hard-coded `no origin/main ref`, a cause neither had
    // checked. `capture` runs git with stderr ignored, so `behindCountOf` cannot know why it
    // failed - an unborn HEAD reaches the same branch with the ref present. These assert the
    // message states only what was established, and would fail if a cause were reintroduced.
    it('states that the position was not read, and nothing about why', () => {
        assert.equal(BASE_UNMEASURED, 'base position unmeasured');
    });

    it('names no cause, since none is knowable at the point it is emitted', () => {
        assert.doesNotMatch(BASE_UNMEASURED, /origin\/main|\bref\b|missing|absent|no such|because/i);
    });
});

describe('slotOccupancy', () => {
    it('calls a detached slot free and a branched slot held', () => {
        assert.equal(slotOccupancy(tree('/x')), 'free');
        assert.equal(slotOccupancy(tree('/x', '590-slots')), 'held');
    });
});

describe('roleFor', () => {
    it('passes the primary and ephemeral kinds straight through', () => {
        assert.equal(roleFor('primary', tree('/x', 'main'), ''), 'primary');
        assert.equal(roleFor('ephemeral', tree('/x', 't590'), ' M a.php\n'), 'ephemeral');
    });

    // The wiring test. `inspect` does IO and cannot be unit-tested, so without this the fix for
    // wordpress-importer-archive#962 could be reverted to `slotOccupancy` with the whole suite still green.
    it('reports a dirty detached slot as dirty, not free (wordpress-importer-archive#962)', () => {
        assert.equal(roleFor('slot', tree('/x'), ' M src/A.php\n'), 'dirty');
        assert.equal(roleFor('slot', tree('/x'), ''), 'free');
        assert.equal(roleFor('slot', tree('/x', '962-x'), ''), 'held');
    });
});

describe('slotClaimability', () => {
    it('calls a detached slot with a clean tree free', () => {
        assert.equal(slotClaimability(tree('/x'), ''), 'free');
        assert.equal(slotClaimability(tree('/x'), '\n  \n'), 'free');
    });

    it('calls a detached slot carrying uncommitted work dirty, not free (wordpress-importer-archive#962)', () => {
        assert.equal(slotClaimability(tree('/x'), ' M src/A.php\n'), 'dirty');
        assert.equal(slotClaimability(tree('/x'), '?? scratch.xml\n'), 'dirty');
    });

    it('reports a branched slot held whatever its working tree holds', () => {
        assert.equal(slotClaimability(tree('/x', '590-slots'), ''), 'held');
        assert.equal(slotClaimability(tree('/x', '590-slots'), ' M src/A.php\n'), 'held');
    });

    // The two functions are deliberately separate, and this is the test that says so. Folding
    // dirtiness into `slotOccupancy` would make a dirty detached slot `atRest === false`, and
    // `expectationsFor` then drops `checkBase` -- so `status` would stop reporting that slot as
    // behind `origin/main`, on exactly the trees most likely to be stale, and silently.
    it('leaves slotOccupancy branch-only, so a dirty slot is still judged at rest', () => {
        assert.equal(slotOccupancy(tree('/x')), 'free');
        assert.equal(slotClaimability(tree('/x'), ' M src/A.php\n'), 'dirty');
    });
});

describe('installStaleReason', () => {
    it('is silent when the stamp matches the lockfile', () => {
        assert.equal(installStaleReason('same', 'same'), null);
    });

    it('reports a moved lockfile with both hashes', () => {
        assert.match(installStaleReason('b'.repeat(64), 'a'.repeat(64)), /lockfile changed \(aaaaaaaaaaaa -> bbbbbbbbbbbb\)/);
    });

    it('treats a missing stamp as stale rather than current', () => {
        assert.equal(installStaleReason('lock', null), 'no record of a previous install');
    });

    it('is silent when there is no lockfile at all', () => {
        assert.equal(installStaleReason(null, 'stamp'), null);
    });
});

describe('driftReasons', () => {
    const current = { composerHash: 'lock', stampComposerHash: 'lock' };

    it('reports nothing for a current slot', () => {
        assert.deepEqual(driftReasons(current), []);
    });

    it('defaults to the STRICT reading when `expected` is omitted', () => {
        // judgeTree is what supplies `expected`; dropping that wiring must fail loudly, not
        // silently exempt every tree.
        assert.equal(driftReasons({ composerHash: 'lock', stampComposerHash: null }).length, 1);
    });

    it('accumulates every reason rather than stopping at the first', () => {
        const reasons = driftReasons({
            composerHash: 'lock',
            stampComposerHash: null,
            behind: 3,
        });

        assert.equal(reasons.length, 2);
    });
});

describe('releaseRefusalReason', () => {
    it('permits a release from a clean tree', () => {
        assert.equal(releaseRefusalReason(''), null);
        assert.equal(releaseRefusalReason('\n  \n'), null);
    });

    it('refuses a dirty tree and names the offending files', () => {
        const reason = releaseRefusalReason(' M src/A.php\n?? scratch.xml\n');

        assert.match(reason, /2 uncommitted change\(s\)/);
        assert.match(reason, /src\/A\.php/);
        assert.match(reason, /scratch\.xml/);
    });

    it('truncates a long change list but reports the true count', () => {
        const reason = releaseRefusalReason(Array.from({ length: 9 }, (_, i) => ` M f${i}.php`).join('\n'));

        assert.match(reason, /9 uncommitted change\(s\)/);
        assert.match(reason, /… and 4 more/);
    });
});


describe('baseState — the four-state base model', () => {
    it('reports a measured distance for a detached tree, which is a slot at rest', () => {
        assert.deepEqual(baseState(null, '3'), { behind: 3, tracked: true, ref: 'origin/main', unreadable: false });
    });

    it('reports a measured distance on main', () => {
        assert.deepEqual(baseState('main', '0'), { behind: 0, tracked: true, ref: 'origin/main', unreadable: false });
    });

    it('marks a tree on its own branch untracked, and STILL measures it', () => {
        // Deliberate divergence from the sibling probe, which suppresses the comparison here.
        // A primary sitting on a branch nobody set is the drift signal the worktrees rule calls
        // the oldest one there is; suppressing the measurement would delete the report that names it.
        assert.deepEqual(baseState('879-slug', '5'), { behind: 5, tracked: false, ref: 'origin/main', unreadable: false });
    });

    it('marks a failed read unreadable rather than zero', () => {
        assert.deepEqual(baseState(null, null), { behind: null, tracked: true, ref: 'origin/main', unreadable: true });
    });

    it('treats a non-numeric answer as a failed read, not as a count', () => {
        // A failed read wearing a result's shape. Number.parseInt would yield NaN, and a caller
        // comparing NaN > 0 gets false — reporting a tree as current on the strength of a failure.
        assert.deepEqual(baseState(null, 'fatal: bad revision'), { behind: null, tracked: true, ref: 'origin/main', unreadable: true });
    });

    it('never reports behind as 0 when the read failed — the fail-open this guard exists to prevent', () => {
        for (const bad of [null, '', 'fatal', 'HEAD']) {
            const state = baseState(null, bad);
            assert.equal(state.unreadable, true, `expected unreadable for ${JSON.stringify(bad)}`);
            assert.notEqual(state.behind, 0);
            assert.equal(state.behind, null);
        }
    });

    it('does not let `!unreadable` stand in for "this reading is valid"', () => {
        // unreadable:false means "nothing failed", which includes "nothing worth trusting".
        // A caller reading only this field cannot tell an untracked tree from a measured, clean one,
        // which is why `tracked` has to be consulted first.
        const untracked = baseState('879-slug', '5');
        const clean = baseState(null, '0');

        assert.equal(untracked.unreadable, false);
        assert.equal(clean.unreadable, false);
        assert.notEqual(untracked.tracked, clean.tracked);
    });

    it('carries the ref it compared against, so a caller need not re-derive it', () => {
        assert.equal(baseState(null, '1').ref, 'origin/main');
        assert.equal(baseState(null, '1', 'origin/trunk').ref, 'origin/trunk');
    });
});


describe('baseRemedy — the drift hint must prescribe a command that can actually be run', () => {
    // Closes wordpress-importer-archive#889. The sibling implementation shipped the wrong verb here under a full green
    // suite, because every assertion was that drift had been DETECTED and none could reach the
    // verb. These assert the COUPLING rather than the wording, so they survive a rephrasing.

    it('never tells the primary to run `release`, which the script refuses for it', () => {
        assert.doesNotMatch(baseRemedy('primary', 'wordpress-importer'), /release/);
    });

    it('tells the primary to fast-forward instead', () => {
        assert.equal(baseRemedy('primary', 'wordpress-importer'), 'git merge --ff-only origin/main');
    });

    it('tells a slot to release, naming the slot', () => {
        assert.equal(baseRemedy('slot', 'a'), 'release a');
        assert.equal(baseRemedy('slot', 'b'), 'release b');
    });

    it('holds for every slot name, so the arms cannot be swapped without a failure', () => {
        for (const name of ['a', 'b', 'ci']) {
            assert.match(baseRemedy('slot', name), /^release /);
            assert.doesNotMatch(baseRemedy('primary', name), /^release /);
        }
    });

    it('treats an ephemeral tree as a slot rather than as the primary', () => {
        // Only the primary refuses `release`; anything else is releasable, so the ternary must
        // key on PRIMARY and not on "is it a slot".
        assert.equal(baseRemedy('ephemeral', 't879-slug'), 'release t879-slug');
    });
});

describe('releaseState — the four-state release model', () => {
    const NOW = new Date('2026-09-06T00:00:00Z');

    it('reports the distance, the tag and its age when main is ahead', () => {
        const state = releaseState('v0.16.0', '139', '2026-08-21T13:38:44Z', NOW);

        assert.deepEqual(state, { tag: 'v0.16.0', ahead: 139, ageDays: 15, untagged: false, unreadable: false });
    });

    it('reports level rather than untagged when everything merged is released', () => {
        const state = releaseState('v0.16.0', '0', '2026-08-21T13:38:44Z', NOW);

        assert.equal(state.ahead, 0);
        assert.equal(state.untagged, false);
        assert.equal(state.unreadable, false);
    });

    it('reports untagged when git answered and answered empty', () => {
        assert.deepEqual(
            releaseState('', null, null, NOW),
            { tag: null, ahead: null, ageDays: null, untagged: true, unreadable: false },
        );
    });

    it('reports unreadable when the tag read itself failed', () => {
        const state = releaseState(null, null, null, NOW);

        assert.equal(state.unreadable, true);
        assert.equal(state.untagged, false, 'a failed read is not a repository without tags');
    });

    it('reports unreadable when the count is absent or unparseable, never zero', () => {
        for (const bad of [null, 'fatal: bad revision', '', 'abc']) {
            const state = releaseState('v0.16.0', bad, '2026-08-21T13:38:44Z', NOW);

            assert.equal(state.unreadable, true, `count ${JSON.stringify(bad)} must not parse`);
            assert.equal(state.ahead, null, 'an unreadable count must not become 0');
        }
    });

    it('keeps a usable count when only the date is unparseable', () => {
        const state = releaseState('v0.16.0', '139', 'not-a-date', NOW);

        assert.equal(state.ahead, 139);
        assert.equal(state.ageDays, null);
        assert.equal(state.unreadable, false, 'a bad date must not discard the finding');
    });
});

describe('releaseLine — no two states render alike', () => {
    const NOW = new Date('2026-09-06T00:00:00Z');

    // The point of the four-state model: "no tags at all" and "level with the newest tag" both
    // mean nothing needs releasing, and they are not the same fact about a dependency. Neither is
    // a probe that could not answer. If any two of these collapse, the model has been flattened
    // back into a number and this test is what catches it.
    it('renders untagged, level, ahead and unreadable as four distinct sentences', () => {
        const lines = [
            releaseLine(releaseState('', null, null, NOW)),
            releaseLine(releaseState('v0.16.0', '0', '2026-08-21T13:38:44Z', NOW)),
            releaseLine(releaseState('v0.16.0', '139', '2026-08-21T13:38:44Z', NOW)),
            releaseLine(releaseState(null, null, null, NOW)),
        ];

        assert.equal(new Set(lines).size, 4, 'two states render identically');
    });

    it('names the unmeasured case with the command that reports why', () => {
        const line = releaseLine(releaseState(null, null, null, NOW));

        assert.match(line, new RegExp(RELEASE_UNMEASURED));
        assert.match(line, /git describe/);
    });

    it('carries the count and the tag when ahead, and omits the age when it is unknown', () => {
        const dated = releaseLine(releaseState('v0.16.0', '139', '2026-08-21T13:38:44Z', NOW));
        const undated = releaseLine(releaseState('v0.16.0', '139', 'not-a-date', NOW));

        assert.match(dated, /139 commits ahead of v0\.16\.0/);
        assert.match(dated, /cut 15d ago/);
        assert.match(undated, /139 commits ahead of v0\.16\.0/);
        assert.doesNotMatch(undated, /cut/, 'an unknown age must not render as an empty clause');
    });

    it('does not tell a session to cut a release', () => {
        // cutting-releases owns WHEN to tag. A status line that prescribed it would be pushing a
        // reserved action on the strength of a count.
        for (const state of [releaseState('v0.16.0', '139', '2026-08-21T13:38:44Z', NOW), releaseState('', null, null, NOW)]) {
            assert.doesNotMatch(releaseLine(state), /\b(run|cut|tag) a release\b/i);
        }
    });
});

describe('the source files carry no control characters', () => {
    // Both this file and worktree-slots.mjs get edited through shells and heredocs, where a
    // doubled backslash collapses before the interpreter sees it. A word-boundary escape then
    // arrives as a literal BACKSPACE (0x08) instead, and the damage is invisible: the file looks
    // right, the suite stays green, and the affected alternative can never match.
    //
    // Not hypothetical. `main` carried two such characters inside the `doesNotMatch` guarding
    // BASE_UNMEASURED's wording, so that arm could not fail and what it guarded was unprotected.
    // An INVALID escape survives the same collapse untouched, which is why the corruption is
    // selective: some escapes come through and some are silently eaten.
    for (const name of ['worktree-slots.mjs', 'worktree-slots.test.mjs']) {
        it(`${name} contains no C0 control characters other than newline and tab`, () => {
            const source = readFileSync(new URL(name, import.meta.url), 'utf8');
            const codes = [...source]
                .map((c) => c.charCodeAt(0))
                .filter((n) => n < 32 && n !== 10 && n !== 9);

            assert.deepEqual(codes, [], `control characters in ${name}`);
        });
    }
});

describe('checkVerdict — the line that survives a pipe', () => {
    const clean = (name) => ({ name, drift: [] });
    const drifted = (name, ...reasons) => ({ name, drift: reasons });

    it('reports CLEAN with the tree count when nothing has drifted', () => {
        // Tested through the pure function deliberately: four simultaneously-current trees is a
        // state a working repository passes through rather than holds, so a test that waits for
        // one never runs.
        assert.equal(
            checkVerdict([clean('a'), clean('b'), clean('c'), clean('d')]),
            'CLEAN: 4 trees',
        );
    });

    it('names the drifted tree AND its reason, not merely that drift exists', () => {
        // The table already carried "2 behind origin/main" on the evening this was filed, and it
        // was read as informational because the exit code said clean. A bare verdict word would be
        // a second signal to reconcile against the same wrong one.
        const line = checkVerdict([clean('primary'), drifted('wordpress-importer-a', '2 behind origin/main')]);

        assert.equal(line, 'DRIFT: 1 tree (wordpress-importer-a, 2 behind origin/main)');
        assert.match(line, /2 behind origin\/main/);
    });

    it('names every drifted tree when more than one has drifted', () => {
        const line = checkVerdict([
            drifted('wordpress-importer', '1 behind origin/main'),
            clean('wordpress-importer-b'),
            drifted('wordpress-importer-a', 'vendor: no record of a previous install'),
        ]);

        assert.match(line, /^DRIFT: 2 trees /);
        assert.match(line, /wordpress-importer, 1 behind origin/);
        assert.match(line, /wordpress-importer-a, vendor: no record/);
    });

    it('joins multiple drift reasons for one tree rather than reporting only the first', () => {
        const line = checkVerdict([drifted('slot', 'vendor stale', '3 behind origin/main')]);

        assert.match(line, /vendor stale; 3 behind origin\/main/);
    });

    it('agrees with the exit code by construction: CLEAN iff no tree carries drift', () => {
        // The exit expression is `trees.some((t) => t.drift.length)`. This asserts the verdict is
        // computed from the same predicate, so the two channels cannot disagree.
        for (const trees of [
            [clean('a')],
            [clean('a'), clean('b')],
            [drifted('a', 'x')],
            [clean('a'), drifted('b', 'y')],
        ]) {
            const wouldExitOne = trees.some((tree) => tree.drift.length);

            assert.equal(
                checkVerdict(trees).startsWith('DRIFT:'),
                wouldExitOne,
                `verdict and exit code disagree for ${JSON.stringify(trees.map((t) => t.drift))}`,
            );
        }
    });
});

describe('status --check, end to end', () => {
    const cli = (args) => spawnSync(
        process.execPath,
        [fileURLToPath(new URL('worktree-slots.mjs', import.meta.url)), ...args],
        { encoding: 'utf8' },
    );

    it('prints a verdict whose form matches the exit code, whatever this repo currently is', () => {
        // An invariant rather than a value: the trees' real state varies run to run, so asserting
        // "DRIFT" or "CLEAN" would be a test of the checkout rather than of the code.
        const out = cli(['status', '--check']);
        const last = out.stdout.trimEnd().split('\n').pop();

        assert.match(last, /^(DRIFT|CLEAN): /, `last stdout line was ${JSON.stringify(last)}`);
        assert.equal(last.startsWith('DRIFT:'), out.status === 1);
    });

    it('puts the verdict LAST, so a `| tail -1` still carries it', () => {
        const out = cli(['status', '--check']);

        assert.match(out.stdout.trimEnd().split('\n').pop(), /^(DRIFT|CLEAN): /);
    });

    it('emits no verdict without --check, so bare status is unaffected', () => {
        assert.doesNotMatch(cli(['status']).stdout, /^(DRIFT|CLEAN): /m);
    });

    it('emits no verdict under --json, and the JSON still parses with --check', () => {
        const out = cli(['status', '--check', '--json']);

        assert.doesNotMatch(out.stdout, /^(DRIFT|CLEAN): /m);
        assert.doesNotThrow(() => JSON.parse(out.stdout), 'the --json stream must stay parseable');
        assert.ok(Array.isArray(JSON.parse(out.stdout).trees));
    });
});

describe('the printed role, end to end against a throwaway repo', () => {
    // These are the only tests here that stand up real git trees. They exist because `inspect()`
    // does IO and is not exported, so the expression that wires `roleFor` into `status` is
    // reachable no other way -- and reverting that one line restores wordpress-importer-archive#962 with every pure-function
    // test still green (wordpress-importer-archive#1005).
    const git = (cwd, ...args) => {
        const out = spawnSync('git', args, { cwd, encoding: 'utf8' });

        assert.equal(out.status, 0, `git ${args.join(' ')} failed: ${out.stderr}`);

        return out.stdout;
    };

    const roleOf = (primary, name) => {
        const out = spawnSync(
            process.execPath,
            [fileURLToPath(new URL('worktree-slots.mjs', import.meta.url)), 'status', '--json'],
            { cwd: primary, encoding: 'utf8' },
        );

        assert.equal(out.status, 0, `status --json failed: ${out.stderr}`);

        return JSON.parse(out.stdout).trees.find((entry) => entry.name === name)?.role;
    };

    /** A repo named so `treeKind` reads the sibling as a slot, with one commit and a detached slot. */
    const fixture = () => {
        const root = mkdtempSync(join(tmpdir(), 'wt-slots-'));
        const primary = join(root, 'wordpress-importer');

        mkdirSync(primary);
        git(primary, 'init', '-q', '.');
        git(primary, 'config', 'user.email', 'test@example.invalid');
        git(primary, 'config', 'user.name', 'test');
        writeFileSync(join(primary, 'a.txt'), 'hi');
        git(primary, 'add', 'a.txt');
        git(primary, 'commit', '-qm', 'init');
        git(primary, 'branch', '-M', 'main');

        return { root, primary, slot: join(root, 'wordpress-importer-a') };
    };

    const discard = (root) => {
        // Windows holds git object files open briefly; a failed cleanup must not fail the test.
        try {
            rmSync(root, { recursive: true, force: true, maxRetries: 3 });
        } catch {
            // left in the OS temp directory
        }
    };

    it('prints dirty for a detached slot carrying uncommitted work (wordpress-importer-archive#962, wordpress-importer-archive#1005)', () => {
        const { root, primary, slot } = fixture();

        try {
            git(primary, 'worktree', 'add', '-q', '--detach', slot, 'HEAD');
            writeFileSync(join(slot, 'scratch.xml'), 'untracked');

            assert.equal(roleOf(primary, 'wordpress-importer-a'), 'dirty');
        } finally {
            discard(root);
        }
    });

    it('prints free for a detached slot that is clean, so dirty is not the default', () => {
        const { root, primary, slot } = fixture();

        try {
            git(primary, 'worktree', 'add', '-q', '--detach', slot, 'HEAD');

            assert.equal(roleOf(primary, 'wordpress-importer-a'), 'free');
        } finally {
            discard(root);
        }
    });

    // wordpress-importer-archive#1021 — `atRest` feeds `expectationsFor().checkBase`, so wiring it to dirtiness instead of
    // occupancy stops base-position drift being checked for any dirty slot. That is a green that
    // means nothing, on the exit code the `worktrees` refresh gate is built on. These two assert
    // the CONSEQUENCE rather than the expression's text: the first fails under that mutation, the
    // second is the control that stops it passing by reporting drift unconditionally.
    const driftOf = (primary, name) => {
        const out = spawnSync(
            process.execPath,
            [fileURLToPath(new URL('worktree-slots.mjs', import.meta.url)), 'status', '--json'],
            { cwd: primary, encoding: 'utf8' },
        );

        assert.equal(out.status, 0, `status --json failed: ${out.stderr}`);

        return JSON.parse(out.stdout).trees.find((entry) => entry.name === name)?.drift ?? [];
    };

    /** Put `origin/main` one commit ahead of the slot, without needing a real remote. */
    const advanceOriginPast = (primary, slot) => {
        git(primary, 'worktree', 'add', '-q', '--detach', slot, 'HEAD');
        writeFileSync(join(primary, 'b.txt'), 'second');
        git(primary, 'add', 'b.txt');
        git(primary, 'commit', '-qm', 'second');
        git(primary, 'update-ref', 'refs/remotes/origin/main', git(primary, 'rev-parse', 'HEAD').trim());
    };

    it('still reports base drift for a slot that is dirty AND behind (wordpress-importer-archive#1021)', () => {
        const { root, primary, slot } = fixture();

        try {
            advanceOriginPast(primary, slot);
            writeFileSync(join(slot, 'scratch.xml'), 'untracked');

            // The precondition, asserted rather than assumed: the slot really is both.
            assert.equal(git(slot, 'rev-list', '--count', 'HEAD..refs/remotes/origin/main').trim(), '1');
            assert.notEqual(git(slot, 'status', '--porcelain', '--ignore-submodules=all').trim(), '');

            assert.ok(
                driftOf(primary, 'wordpress-importer-a').some((reason) => /behind origin\/main/.test(reason)),
                'a dirty slot that is behind must still have its base position checked',
            );
        } finally {
            discard(root);
        }
    });

    it('reports no base drift for a slot that is dirty and NOT behind, so the assertion above is not unconditional', () => {
        const { root, primary, slot } = fixture();

        try {
            git(primary, 'update-ref', 'refs/remotes/origin/main', git(primary, 'rev-parse', 'HEAD').trim());
            git(primary, 'worktree', 'add', '-q', '--detach', slot, 'HEAD');
            writeFileSync(join(slot, 'scratch.xml'), 'untracked');

            assert.equal(git(slot, 'rev-list', '--count', 'HEAD..refs/remotes/origin/main').trim(), '0');
            assert.notEqual(git(slot, 'status', '--porcelain', '--ignore-submodules=all').trim(), '');

            assert.equal(
                driftOf(primary, 'wordpress-importer-a').some((reason) => /behind origin\/main/.test(reason)),
                false,
            );
        } finally {
            discard(root);
        }
    });

    it('ignores a submodule-only modification, so the exclusion is exercised rather than trusted', () => {
        const { root, primary, slot } = fixture();

        try {
            const source = join(root, 'sub');

            mkdirSync(source);
            git(source, 'init', '-q', '.');
            git(source, 'config', 'user.email', 'test@example.invalid');
            git(source, 'config', 'user.name', 'test');
            writeFileSync(join(source, 's.txt'), 'sub');
            git(source, 'add', 's.txt');
            git(source, 'commit', '-qm', 'sub');

            git(primary, '-c', 'protocol.file.allow=always', 'submodule', 'add', '-q', source, 'vendorsub');
            git(primary, 'commit', '-qm', 'add submodule');
            git(primary, 'worktree', 'add', '-q', '--detach', slot, 'HEAD');
            git(slot, '-c', 'protocol.file.allow=always', 'submodule', 'update', '--init', '-q');

            // The control: without --ignore-submodules=all this is the slot's only change, and it
            // reads as dirty. With the flag git emits nothing, so the assertion below is a test of
            // the flag rather than of an empty tree.
            writeFileSync(join(slot, 'vendorsub', 's.txt'), 'modified');
            assert.match(git(slot, 'status', '--porcelain'), /vendorsub/);
            assert.equal(git(slot, 'status', '--porcelain', '--ignore-submodules=all').trim(), '');

            assert.equal(roleOf(primary, 'wordpress-importer-a'), 'free');
        } finally {
            discard(root);
        }
    });
});

describe('checkoutArgs — the submodule-recursion guard (wordpress-importer-archive#1142)', () => {
    it('always carries --no-recurse-submodules', () => {
        assert.ok(checkoutArgs('/tree', 'origin/main').includes('--no-recurse-submodules'));
    });

    it('detaches at the ref it is given', () => {
        assert.deepEqual(
            checkoutArgs('/tree', 'origin/main'),
            ['-C', '/tree', 'checkout', '--detach', '--no-recurse-submodules', 'origin/main'],
        );
    });

    // The branch-creating form exists because wordpress-importer-archive#1140's `claim` needs one, and routing it through the
    // same helper is what kept the flag on it — the call-site scan below caught it otherwise.
    it('carries the flag on the branch-creating form too', () => {
        assert.deepEqual(
            checkoutArgs('/tree', 'topic', { create: true }),
            ['-C', '/tree', 'checkout', '--no-recurse-submodules', '-b', 'topic'],
        );
    });

    // The point of the helper is that a call site added later cannot forget the flag, which is
    // exactly what wordpress-importer-archive#961 did by fixing the old private runner and leaving this script unguarded.
    // A reviewer will not catch the third instance either, so the check is mechanical.
    it('leaves no unguarded checkout call site in either runner script', () => {
        const dir = fileURLToPath(new URL('.', import.meta.url));
        const raw = [];

        // The shared runner is scanned too because wordpress-importer-archive#961 guarded the runner of its day and wordpress-importer-archive#1142
        // is what happened to the script wordpress-importer-archive#961 did not touch. The shared runner moves its slot
        // with `--no-recurse-submodules` today (wordpress-importer-archive#1319); a sync that dropped it is the same
        // defect a third time.
        for (const file of ['worktree-slots.mjs', '../.claude/skills/local-ci/local-ci.mjs']) {
            readFileSync(join(dir, file), 'utf8').split('\n').forEach((line, i) => {
                const invokes = line.includes("'checkout'");
                const guarded = line.includes('checkoutArgs') || line.includes('--no-recurse-submodules');

                if (invokes && !guarded) {
                    raw.push(`${file}:${i + 1} ${line.trim()}`);
                }
            });
        }

        assert.deepEqual(raw, [], `unguarded checkout call site:\n${raw.join('\n')}`);
    });
});

describe('claim records (wordpress-importer-archive#1140)', () => {
    it('names a ref per slot under refs/slots', () => {
        assert.equal(claimRefName('a'), 'refs/slots/a');
        assert.equal(claimRefName('b'), 'refs/slots/b');
    });

    it('round-trips owner and timestamp', () => {
        const parsed = parseClaimRecord(claimRecord('josh-office', '2026-09-21T00:00:00.000Z'));

        assert.deepEqual(parsed, { owner: 'josh-office', at: '2026-09-21T00:00:00.000Z' });
    });

    // A ref under refs/slots/ this script did not write is somebody else's business. Reporting it
    // as an unreadable claim keeps `release` refusing; reporting it as absent would clear it.
    it('returns null for anything that is not a claim', () => {
        assert.equal(parseClaimRecord(''), null);
        assert.equal(parseClaimRecord(null), null);
        assert.equal(parseClaimRecord('not a claim at all'), null);
        assert.equal(parseClaimRecord('at=2026-01-01\n'), null);
    });
});

describe('mayClearClaim — release refuses a claim it does not own', () => {
    it('allows clearing an unclaimed slot', () => {
        assert.equal(mayClearClaim(null, 'josh-office'), true);
    });

    it('allows the owner to clear their own', () => {
        assert.equal(mayClearClaim({ owner: 'josh-office', at: '' }, 'josh-office'), true);
    });

    it('refuses another machine', () => {
        assert.equal(mayClearClaim({ owner: 'josh-home', at: '' }, 'josh-office'), false);
    });
});

describe('claim — atomic under real concurrency (wordpress-importer-archive#1140)', () => {
    const script = join(fileURLToPath(new URL('.', import.meta.url)), 'worktree-slots.mjs');

    // Build a throwaway superproject whose directory layout matches the slot convention, so the
    // script resolves `a` and `b` the way it does in the real repository.
    function fixture() {
        const root = mkdtempSync(join(tmpdir(), 'wt-claim-'));
        const repo = join(root, 'demo');
        const git = (args, cwd) => spawnSync('git', args, { cwd, encoding: 'utf8' });

        mkdirSync(repo, { recursive: true });
        git(['init', '-q', '-b', 'main'], repo);
        git(['config', 'user.email', 't@t'], repo);
        git(['config', 'user.name', 't'], repo);
        writeFileSync(join(repo, 'f.txt'), 'hi\n');
        git(['add', 'f.txt'], repo);
        git(['commit', '-qm', 'init'], repo);
        git(['worktree', 'add', '-q', '--detach', join(root, 'demo-a'), 'main'], repo);
        git(['worktree', 'add', '-q', '--detach', join(root, 'demo-b'), 'main'], repo);

        return { root, repo };
    }

    const claimAsync = (repo, owner) => new Promise((resolve) => {
        const child = spawn(process.execPath, [script, 'claim', '--owner', owner], { cwd: repo });
        let out = '';

        child.stdout.on('data', (chunk) => { out += chunk; });
        child.on('close', (code) => resolve({ code, slot: out.split('\n')[0] || null }));
    });

    it('gives two simultaneous claimants different slots, never the same one', async () => {
        const { root, repo } = fixture();

        try {
            // Launched without awaiting the first — a run that serializes them would pass while
            // testing nothing, which is why this does not use spawnSync.
            const [one, two] = await Promise.all([claimAsync(repo, 'c1'), claimAsync(repo, 'c2')]);

            assert.equal(one.code, 0);
            assert.equal(two.code, 0);
            assert.notEqual(one.slot, two.slot, 'both claimants took the same slot');
            assert.deepEqual([one.slot, two.slot].sort(), ['a', 'b']);
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });

    it('lets exactly one of two claimants win when only one slot is free', async () => {
        const { root, repo } = fixture();

        try {
            const taken = await claimAsync(repo, 'keeper');
            const other = taken.slot === 'a' ? 'b' : 'a';

            spawnSync('git', ['update-ref', '-d', `refs/slots/${other}`], { cwd: repo });
            spawnSync('git', ['-C', join(root, `demo-${other}`), 'checkout', '-qb', 'occupied'], { cwd: repo });

            const [one, two] = await Promise.all([claimAsync(repo, 'r1'), claimAsync(repo, 'r2')]);
            const winners = [one, two].filter((result) => result.code === 0);

            assert.equal(winners.length, 0, 'no slot was free, so neither may claim');
            assert.deepEqual([one.code, two.code], [3, 3]);
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });
});

describe('claim takes no slot argument (wordpress-importer-archive#1198)', () => {
    it('reads a stray word as positional, and a flag value as not', () => {
        assert.deepEqual(claimPositionals(['b']), ['b']);
        assert.deepEqual(claimPositionals(['--branch', '1198-x']), []);
        assert.deepEqual(claimPositionals(['--owner', 'me', '--branch', 'b', '--at', 'now']), []);
        assert.deepEqual(claimPositionals(['--branch', '1198-x', 'b']), ['b']);
    });

    const script = join(fileURLToPath(new URL('.', import.meta.url)), 'worktree-slots.mjs');

    function fixture() {
        const root = mkdtempSync(join(tmpdir(), 'wt-claim-arg-'));
        const repo = join(root, 'demo');
        const git = (args, cwd) => spawnSync('git', args, { cwd, encoding: 'utf8' });

        mkdirSync(repo, { recursive: true });
        git(['init', '-q', '-b', 'main'], repo);
        git(['config', 'user.email', 't@t'], repo);
        git(['config', 'user.name', 't'], repo);
        writeFileSync(join(repo, 'f.txt'), 'hi\n');
        git(['add', 'f.txt'], repo);
        git(['commit', '-qm', 'init'], repo);
        git(['worktree', 'add', '-q', '--detach', join(root, 'demo-a'), 'main'], repo);
        git(['worktree', 'add', '-q', '--detach', join(root, 'demo-b'), 'main'], repo);

        return { root, repo };
    }

    const claimRefs = (repo) => spawnSync('git', ['for-each-ref', '--format=%(refname)', 'refs/slots/'], { cwd: repo, encoding: 'utf8' }).stdout.trim();

    it('refuses `claim b` with exit 2 and claims nothing, where it used to take A and exit 0', () => {
        const { root, repo } = fixture();

        try {
            const res = spawnSync(process.execPath, [script, 'claim', 'b', '--owner', 'me'], { cwd: repo, encoding: 'utf8' });

            assert.equal(res.status, 2);
            assert.match(res.stderr, /claim takes no slot argument \(got 'b'\)/);
            assert.equal(claimRefs(repo), '', 'no slot was claimed');

            // Control: the same fixture claims normally when run bare, so the refusal above is the
            // argument check and not a broken fixture.
            const bare = spawnSync(process.execPath, [script, 'claim', '--owner', 'me'], { cwd: repo, encoding: 'utf8' });

            assert.equal(bare.status, 0, bare.stderr);
            assert.equal(bare.stdout.split('\n')[0], 'a');
            assert.equal(claimRefs(repo), 'refs/slots/a');
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });

    it('still accepts --branch, whose value is not a slot argument', () => {
        const { root, repo } = fixture();

        try {
            const res = spawnSync(process.execPath, [script, 'claim', '--owner', 'me', '--branch', '1198-x'], { cwd: repo, encoding: 'utf8' });

            // `--branch` goes on to fetch `origin`, which this fixture does not have, so the run
            // fails later for that reason. What is asserted is that it got past the argument check:
            // the branch name was not read as a slot, and it reached the fetch.
            assert.doesNotMatch(res.stderr, /takes no slot argument/);
            assert.match(res.stderr, /fetch origin/, 'control: it reached the fetch, past the argument check');
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });
});

describe('a failed claim --branch gives the slot back (wordpress-importer-archive#1201)', () => {
    const script = join(fileURLToPath(new URL('.', import.meta.url)), 'worktree-slots.mjs');

    function fixture() {
        const root = mkdtempSync(join(tmpdir(), 'wt-claim-rollback-'));
        const repo = join(root, 'demo');
        const git = (args, cwd) => spawnSync('git', args, { cwd, encoding: 'utf8' });

        mkdirSync(repo, { recursive: true });
        git(['init', '-q', '-b', 'main'], repo);
        git(['config', 'user.email', 't@t'], repo);
        git(['config', 'user.name', 't'], repo);
        writeFileSync(join(repo, 'f.txt'), 'hi\n');
        git(['add', 'f.txt'], repo);
        git(['commit', '-qm', 'init'], repo);
        git(['worktree', 'add', '-q', '--detach', join(root, 'demo-a'), 'main'], repo);
        git(['worktree', 'add', '-q', '--detach', join(root, 'demo-b'), 'main'], repo);

        return { root, repo, git };
    }

    const claimRefs = (repo) => spawnSync('git', ['for-each-ref', '--format=%(refname)', 'refs/slots/'], { cwd: repo, encoding: 'utf8' }).stdout.trim();

    it('releases the claim and names the failed step when the branch step fails', () => {
        const { root, repo } = fixture();

        try {
            // No `origin` in this fixture, so the fetch fails: the shape measured on wordpress-importer-archive#1201.
            const res = spawnSync(process.execPath, [script, 'claim', '--owner', 'me', '--branch', 'x'], { cwd: repo, encoding: 'utf8' });

            assert.equal(res.status, 2);
            assert.match(res.stderr, /fetch origin exited/);
            assert.match(res.stderr, /the claim on a was released/);
            assert.equal(claimRefs(repo), '', 'slot a is not left held');

            // Control: the slot really is claimable again, so the ref's absence is a release and
            // not a claim that never happened.
            const bare = spawnSync(process.execPath, [script, 'claim', '--owner', 'me'], { cwd: repo, encoding: 'utf8' });

            assert.equal(bare.status, 0, bare.stderr);
            assert.equal(bare.stdout.split('\n')[0], 'a');
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });

    it('leaves alone a claim some other session wrote in the meantime', () => {
        const { root, repo, git } = fixture();

        try {
            const blob = (text) => spawnSync('git', ['hash-object', '-w', '--stdin'], { cwd: repo, input: text, encoding: 'utf8' }).stdout.trim();
            const mine = blob('owner=me\n');
            const theirs = blob('owner=someone-else\n');

            assert.notEqual(mine, theirs);

            // Another session holds the ref now, not the object this invocation wrote.
            git(['update-ref', 'refs/slots/a', theirs], repo);

            assert.equal(releaseOwnClaim(repo, 'a', mine), false);
            assert.equal(claimRefs(repo), 'refs/slots/a', 'the other claim survives');

            // Control: the same call with the object actually held does delete it.
            assert.equal(releaseOwnClaim(repo, 'a', theirs), true);
            assert.equal(claimRefs(repo), '');
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });
});

describe('slotClaimability — a claim makes a detached slot held (wordpress-importer-archive#1140)', () => {
    const detachedClean = { branch: null };

    it('reads free when detached, clean and unclaimed', () => {
        assert.equal(slotClaimability(detachedClean, '', null), 'free');
    });

    // The window the race lived in: claimed, but not yet branched. Reporting that `free` is what
    // let a second session take a slot the first had already won.
    it('reads held when detached and clean but claimed', () => {
        assert.equal(slotClaimability(detachedClean, '', { owner: 'josh-office', at: '' }), 'held');
    });

    it('still reads held on a branch regardless of any claim', () => {
        assert.equal(slotClaimability({ branch: '1140-x' }, '', null), 'held');
    });

    it('carries through roleFor for a slot, and never overrides primary or ephemeral', () => {
        assert.equal(roleFor('slot', detachedClean, '', { owner: 'x', at: '' }), 'held');
        assert.equal(roleFor('primary', detachedClean, '', { owner: 'x', at: '' }), 'primary');
        assert.equal(roleFor('ephemeral', detachedClean, '', { owner: 'x', at: '' }), 'ephemeral');
    });
});

describe('recordedPatches (#113)', () => {
    // The shape `cweagans/composer-patches` 2.0.0 writes, trimmed to the fields read here.
    const lock = (patches) => JSON.stringify({ _hash: 'x', patches });

    it('groups the recorded patches by package, keeping the url and depth', () => {
        assert.deepEqual(
            recordedPatches(lock({
                'acme/widget': [{ package: 'acme/widget', url: 'patches/one.patch', depth: 1 }, { url: 'patches/two.patch', depth: 2 }],
                'acme/gadget': [{ url: 'patches/three.patch', depth: 1 }],
            })),
            [
                { package: 'acme/widget', patches: [{ url: 'patches/one.patch', depth: 1 }, { url: 'patches/two.patch', depth: 2 }] },
                { package: 'acme/gadget', patches: [{ url: 'patches/three.patch', depth: 1 }] },
            ],
        );
    });

    it('defaults a missing depth to the plugin default of 1', () => {
        assert.deepEqual(recordedPatches(lock({ 'acme/widget': [{ url: 'p.patch' }] })), [{ package: 'acme/widget', patches: [{ url: 'p.patch', depth: 1 }] }]);
    });

    it('leaves out a package whose list is empty', () => {
        assert.deepEqual(recordedPatches(lock({ 'acme/widget': [] })), []);
    });

    it('returns null, not an empty list, for a lock it cannot read', () => {
        // An empty list would certify every package as patched, so each malformed shape must be
        // distinguishable from "no patches".
        assert.equal(recordedPatches('{not json'), null);
        assert.equal(recordedPatches('null'), null);
        assert.equal(recordedPatches(JSON.stringify({ _hash: 'x' })), null);
        assert.equal(recordedPatches(lock({ 'acme/widget': 'patches/one.patch' })), null);
        assert.equal(recordedPatches(lock({ 'acme/widget': [{ depth: 1 }] })), null);
    });
});

describe('unpatchedPackages (#113)', () => {
    const groups = [
        { package: 'acme/widget', patches: [{ url: 'one.patch', depth: 1 }, { url: 'two.patch', depth: 1 }] },
        { package: 'acme/gadget', patches: [{ url: 'three.patch', depth: 1 }] },
    ];

    it('names a package when any one of its patches is missing', () => {
        assert.deepEqual(unpatchedPackages(groups, (name, patch) => patch.url !== 'two.patch'), ['acme/widget']);
    });

    it('names nothing when every patch is applied', () => {
        assert.deepEqual(unpatchedPackages(groups, () => true), []);
    });

    it('names every drifted package, in the lock order', () => {
        assert.deepEqual(unpatchedPackages(groups, () => false), ['acme/widget', 'acme/gadget']);
    });
});

describe('driftReasons — recorded patches (#113)', () => {
    const current = { composerHash: 'lock', stampComposerHash: 'lock' };

    it('names each package missing a recorded patch', () => {
        assert.deepEqual(
            driftReasons({ ...current, unpatched: ['acme/widget', 'acme/gadget'] }),
            ['vendor: recorded patches not applied to acme/widget, acme/gadget'],
        );
    });

    it('reports an unreadable patches.lock.json', () => {
        assert.deepEqual(driftReasons({ ...current, patchesLockUnreadable: true }), ['vendor: patches.lock.json could not be read']);
    });

    it('is not judged for a tree whose install is not this script to keep', () => {
        assert.deepEqual(judgeTree(`${ROOT}-ci`, ROOT, { ...current, unpatched: ['acme/widget'], patchesLockUnreadable: true }).drift, []);
    });
});

describe('patchDrift, against real files (#113)', () => {
    const git = (cwd, ...args) => {
        const out = spawnSync('git', args, { cwd, encoding: 'utf8' });

        assert.equal(out.status, 0, `git ${args.join(' ')} failed: ${out.stderr}`);

        return out.stdout;
    };

    const PATCH = [
        'diff --git a/src/a.txt b/src/a.txt',
        '--- a/src/a.txt',
        '+++ b/src/a.txt',
        '@@ -1 +1 @@',
        '-old',
        '+new',
        '',
    ].join('\n');

    /**
     * A tree shaped like a slot: a git repo ignoring `vendor/`, one patch file, its lock entry,
     * and the patched package installed with `contents` in the file the patch changes.
     */
    const fixture = (contents, extraLock = {}) => {
        const root = mkdtempSync(join(tmpdir(), 'wt-patches-'));
        const tree = join(root, 'wordpress-importer');

        mkdirSync(join(tree, 'patches'), { recursive: true });
        mkdirSync(join(tree, 'vendor', 'acme', 'widget', 'src'), { recursive: true });
        git(tree, 'init', '-q', '.');
        writeFileSync(join(tree, '.gitignore'), '/vendor\n');
        writeFileSync(join(tree, 'patches', 'widget.patch'), PATCH);
        writeFileSync(join(tree, 'vendor', 'acme', 'widget', 'src', 'a.txt'), contents);
        writeFileSync(join(tree, 'patches.lock.json'), JSON.stringify({
            _hash: 'x',
            patches: { 'acme/widget': [{ url: 'patches/widget.patch', depth: 1 }], ...extraLock },
        }));

        return { root, tree };
    };

    const discard = (root) => {
        try {
            rmSync(root, { recursive: true, force: true, maxRetries: 3 });
        } catch {
            // left in the OS temp directory
        }
    };

    it('names a package installed without its recorded patch', () => {
        const { root, tree } = fixture('old\n');

        try {
            assert.deepEqual(patchDrift(tree), { unpatched: ['acme/widget'], patchesLockUnreadable: false });
        } finally {
            discard(root);
        }
    });

    it('names nothing for a package that already carries it', () => {
        const { root, tree } = fixture('new\n');

        try {
            assert.equal(patchApplied(tree, 'acme/widget', { url: 'patches/widget.patch', depth: 1 }), true);
            assert.deepEqual(patchDrift(tree), { unpatched: [], patchesLockUnreadable: false });
        } finally {
            discard(root);
        }
    });

    it('reads a package changed in some other way as unpatched, not as patched', () => {
        // The reverse check must fail on contents that are neither the original nor the patched
        // file, or a hand-edited vendor copy would be certified.
        const { root, tree } = fixture('something else\n');

        try {
            assert.deepEqual(patchDrift(tree).unpatched, ['acme/widget']);
        } finally {
            discard(root);
        }
    });

    it('does not report a recorded package that is not installed', () => {
        const { root, tree } = fixture('new\n', { 'acme/absent': [{ url: 'patches/widget.patch', depth: 1 }] });

        try {
            assert.deepEqual(patchDrift(tree).unpatched, []);
        } finally {
            discard(root);
        }
    });

    it('does not report a patch recorded by URL, which has no file to compare against', () => {
        const { root, tree } = fixture('new\n', { 'acme/widget': [{ url: 'https://example.invalid/widget.patch', depth: 1 }] });

        try {
            assert.deepEqual(patchDrift(tree).unpatched, []);
        } finally {
            discard(root);
        }
    });

    it('reports nothing for a tree with no patches.lock.json', () => {
        const { root, tree } = fixture('old\n');

        try {
            rmSync(join(tree, 'patches.lock.json'));
            assert.deepEqual(patchDrift(tree), { unpatched: [], patchesLockUnreadable: false });
        } finally {
            discard(root);
        }
    });

    it('flags a patches.lock.json it cannot parse', () => {
        const { root, tree } = fixture('new\n');

        try {
            writeFileSync(join(tree, 'patches.lock.json'), '{not json');
            assert.deepEqual(patchDrift(tree), { unpatched: [], patchesLockUnreadable: true });
        } finally {
            discard(root);
        }
    });

    // `inspect()` is not exported, so these are the only tests that fail when the probe is unwired
    // from `status`: every test above still passes with that line removed.
    const status = (cwd) => spawnSync(
        process.execPath,
        [fileURLToPath(new URL('worktree-slots.mjs', import.meta.url)), 'status', '--check'],
        { cwd, encoding: 'utf8' },
    );

    it('makes status --check exit 1 naming the package when the patch is missing', () => {
        const { root, tree } = fixture('old\n');

        try {
            const out = status(tree);

            assert.equal(out.status, 1, out.stdout + out.stderr);
            assert.match(out.stdout, /recorded patches not applied to acme\/widget/);
        } finally {
            discard(root);
        }
    });

    it('lets status --check exit 0 when the patch is applied, so the exit above is not unconditional', () => {
        const { root, tree } = fixture('new\n');

        try {
            const out = status(tree);

            assert.equal(out.status, 0, out.stdout + out.stderr);
            assert.doesNotMatch(out.stdout, /recorded patches/);
        } finally {
            discard(root);
        }
    });
});
