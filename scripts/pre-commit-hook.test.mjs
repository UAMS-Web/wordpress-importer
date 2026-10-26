// Tests for .githooks/pre-commit. Run: node --test scripts/pre-commit-hook.test.mjs
//
// Each case drives a REAL `git commit` in a throwaway repository whose core.hooksPath points at a
// copy of the committed hook, so git itself invokes the hook exactly as it does in a slot: its own
// `sh` and its own environment.
//
// The hook carries one gate, CI parity. Its exports gitlink gate (wordpress-importer-archive#1143) went with the submodule
// (wordpress-importer-archive#1426).
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const HOOK = join(dirname(fileURLToPath(import.meta.url)), '..', '.githooks', 'pre-commit');
let root;
let emptyGlobal;

/**
 * Run git in a directory with the machine's own configuration shut out.
 *
 * A developer's global config can change what a commit does (submodule.recurse, a hooksPath of
 * their own, commit signing), so every run reads an empty global file and no system file.
 */
function git(cwd, args, extraEnv = {}) {
    return spawnSync('git', args, {
        cwd,
        encoding: 'utf8',
        env: {
            ...process.env,
            GIT_CONFIG_GLOBAL: emptyGlobal,
            GIT_CONFIG_NOSYSTEM: '1',
            ...extraEnv,
        },
    });
}

/** Run git and fail the test with its output if it does not exit 0. */
function ok(cwd, args, extraEnv = {}) {
    const out = git(cwd, args, extraEnv);
    assert.equal(out.status, 0, `git ${args.join(' ')} failed:\n${out.stdout}\n${out.stderr}`);

    return out;
}

/** A fresh repository with the hook installed and one baseline commit. */
function repo(name) {
    const dir = join(root, name);
    const hooks = join(dir, '.test-hooks');
    mkdirSync(hooks, { recursive: true });
    copyFileSync(HOOK, join(hooks, 'pre-commit'));

    ok(dir, ['init', '-q', '-b', 'main']);
    ok(dir, ['config', 'user.email', 'hook-test@example.invalid']);
    ok(dir, ['config', 'user.name', 'Hook Test']);
    ok(dir, ['config', 'core.hooksPath', hooks]);
    ok(dir, ['config', 'commit.gpgsign', 'false']);

    writeFileSync(join(dir, 'f.txt'), 'base\n');
    ok(dir, ['add', 'f.txt']);
    ok(dir, ['commit', '-q', '-m', 'base']);

    return dir;
}

describe('.githooks/pre-commit: the CI parity gate', () => {
    before(() => {
        root = mkdtempSync(join(tmpdir(), 'pre-commit-hook-'));
        emptyGlobal = join(root, 'empty-global.gitconfig');
        writeFileSync(emptyGlobal, '');
    });

    after(() => {
        rmSync(root, { recursive: true, force: true, maxRetries: 3 });
    });

    it('lets an ordinary commit through', () => {
        const dir = repo('ordinary');
        writeFileSync(join(dir, 'f.txt'), 'edited\n');
        ok(dir, ['add', 'f.txt']);

        const out = git(dir, ['commit', '-q', '-m', 'edit']);

        assert.equal(out.status, 0, out.stderr);
    });

    it('refuses a commit that stages ci.yml alone', () => {
        const dir = repo('parity');
        mkdirSync(join(dir, '.github', 'workflows'), { recursive: true });
        writeFileSync(join(dir, '.github', 'workflows', 'ci.yml'), 'name: ci\n');
        ok(dir, ['add', '.github/workflows/ci.yml']);

        const out = git(dir, ['commit', '-q', '-m', 'one side only']);

        assert.notEqual(out.status, 0, 'a one-sided CI edit got through');
        assert.match(out.stderr, /CI parity gate/);
    });

    it('pairs ci.yml with local-ci.json, the shared runner\'s job list (wordpress-importer-archive#1319)', () => {
        const dir = repo('parity-pair');
        mkdirSync(join(dir, '.github', 'workflows'), { recursive: true });

        // One side only, from the other end: the job list without the workflow.
        writeFileSync(join(dir, 'local-ci.json'), '{}\n');
        ok(dir, ['add', 'local-ci.json']);
        const alone = git(dir, ['commit', '-q', '-m', 'job list only']);
        assert.notEqual(alone.status, 0, 'a one-sided job-list edit got through');
        assert.match(alone.stderr, /local-ci\.json/);

        // Both together: the control that shows the refusal above is about the pairing.
        writeFileSync(join(dir, '.github', 'workflows', 'ci.yml'), 'name: ci\n');
        ok(dir, ['add', '.github/workflows/ci.yml']);
        const both = git(dir, ['commit', '-q', '-m', 'both sides']);
        assert.equal(both.status, 0, both.stderr);
    });
});
