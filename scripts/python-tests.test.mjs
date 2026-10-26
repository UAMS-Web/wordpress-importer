// Tests for scripts/python-tests.mjs, the driver behind the `python-test` job (wordpress-importer-archive#1155).

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { chmodSync, existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { tmpdir } from 'node:os'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'
import { findPythonTests, isPython3, main } from './python-tests.mjs'
import { which } from './spawn-helpers.mjs'

const REPO_ROOT = fileURLToPath(new URL('..', import.meta.url))

test('findPythonTests finds both suites this repository carries, including the one outside scripts/', () => {
    const found = findPythonTests(REPO_ROOT)

    assert.ok(found.includes('scripts/wxr-parity-diff.test.py'), found.join(', '))
    // The expensive one: a glob rooted at scripts/ would miss it.
    assert.ok(found.includes('.claude/skills/writing-release-notes/gen_release_notes.test.py'), found.join(', '))
})

test('findPythonTests picks up a file added later under either root, and nothing that is not a test', () => {
    const root = mkdtempSync(join(tmpdir(), 'pyt-1155-'))

    try {
        mkdirSync(join(root, 'scripts'))
        mkdirSync(join(root, '.claude', 'skills', 'some-skill', 'deeper'), { recursive: true })
        writeFileSync(join(root, 'scripts', 'new.test.py'), '')
        writeFileSync(join(root, 'scripts', 'helper.py'), '')
        writeFileSync(join(root, '.claude', 'skills', 'some-skill', 'deeper', 'nested.test.py'), '')
        mkdirSync(join(root, 'scripts', 'sub'))
        writeFileSync(join(root, 'scripts', 'sub', 'below.test.py'), '')

        // scripts/ is top-level only, like node-test's scripts/*.test.mjs; skills are recursive.
        assert.deepEqual(findPythonTests(root), [
            '.claude/skills/some-skill/deeper/nested.test.py',
            'scripts/new.test.py',
        ])
    } finally {
        rmSync(root, { recursive: true, force: true })
    }
})

test('isPython3 accepts a Python 3 reply on either stream and refuses everything else', () => {
    assert.equal(isPython3({ status: 0, stdout: 'Python 3.9.6\n' }), true)
    assert.equal(isPython3({ status: 0, stderr: 'Python 3.12.1' }), true)
    // Python 2, and the Windows Store alias, which exists on PATH and runs nothing.
    assert.equal(isPython3({ status: 0, stderr: 'Python 2.7.18' }), false)
    assert.equal(isPython3({ status: 9009, stdout: '' }), false)
    assert.equal(isPython3({ status: null, stdout: '' }), false)
})

test('a run that finds no test files fails rather than passing on nothing', () => {
    const root = mkdtempSync(join(tmpdir(), 'pyt-1155-'))

    try {
        assert.equal(main(root), 1)
    } finally {
        rmSync(root, { recursive: true, force: true })
    }
})

test('a failing Python test fails the job, and a passing one does not', () => {
    const root = mkdtempSync(join(tmpdir(), 'pyt-1155-'))

    try {
        mkdirSync(join(root, 'scripts'))
        writeFileSync(join(root, 'scripts', 'ok.test.py'), 'import sys\nsys.exit(0)\n')
        assert.equal(main(root), 0, 'control: a passing file passes')

        writeFileSync(join(root, 'scripts', 'broken.test.py'), 'import sys\nsys.exit(1)\n')
        assert.equal(main(root), 1)
    } finally {
        rmSync(root, { recursive: true, force: true })
    }
})

test('no Python 3 on PATH is a failure that names the cause, not a skip', () => {
    const root = mkdtempSync(join(tmpdir(), 'pyt-1155-'))
    const savedPath = process.env.PATH
    const savedError = console.error
    const errors = []

    try {
        mkdirSync(join(root, 'scripts'))
        writeFileSync(join(root, 'scripts', 'ok.test.py'), 'import sys\nsys.exit(0)\n')

        process.env.PATH = join(root, 'no-such-dir')
        console.error = (line) => errors.push(String(line))

        assert.equal(main(root), 1)
        assert.match(errors.join('\n'), /no Python 3 interpreter found/)
        assert.match(errors.join('\n'), /1 test file\(s\) did not run/)
    } finally {
        process.env.PATH = savedPath
        console.error = savedError
        rmSync(root, { recursive: true, force: true })
    }
})

test('a run leaves no bytecode cache in the tree, even from a Python that writes one there (wordpress-importer-archive#1207)', { skip: process.platform === 'win32' ? 'the wrapper below is a POSIX shell script' : false }, () => {
    const root = mkdtempSync(join(tmpdir(), 'pyt-1207-'))
    const savedPath = process.env.PATH

    try {
        // A test that imports a sibling module, as the release-notes test imports its generator:
        // importing is what writes bytecode.
        mkdirSync(join(root, 'scripts'))
        writeFileSync(join(root, 'scripts', 'helper_mod.py'), 'VALUE = 1\n')
        writeFileSync(
            join(root, 'scripts', 'imports.test.py'),
            'import sys, os\nsys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))\nimport helper_mod\nsys.exit(0 if helper_mod.VALUE == 1 else 1)\n',
        )

        // A `python3` that writes its cache beside the module, the way a python.org build does.
        // Apple's Command Line Tools Python writes to ~/Library/Caches instead, which is why the
        // defect never showed on macOS; clearing the prefix reproduces the Windows behavior here.
        const real = which('python3')
        assert.ok(real, 'this test needs a python3 on PATH')
        const bin = join(root, 'bin')
        mkdirSync(bin)
        writeFileSync(join(bin, 'python3'), `#!/bin/sh\nexec "${real}" -X pycache_prefix= "$@"\n`)
        chmodSync(join(bin, 'python3'), 0o755)

        const cache = join(root, 'scripts', '__pycache__')

        // Control: the wrapper really does write an in-tree cache when bytecode is not disabled.
        const direct = spawnSync(join(bin, 'python3'), [join(root, 'scripts', 'imports.test.py')], { encoding: 'utf8' })
        assert.equal(direct.status, 0, direct.stderr)
        assert.ok(existsSync(cache), 'control: the wrapper writes __pycache__ beside the module')
        rmSync(cache, { recursive: true, force: true })

        process.env.PATH = `${bin}:${savedPath}`

        assert.equal(main(root), 0)
        assert.equal(existsSync(cache), false, 'the gate run left a __pycache__ behind')
    } finally {
        process.env.PATH = savedPath
        rmSync(root, { recursive: true, force: true })
    }
})

