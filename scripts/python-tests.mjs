#!/usr/bin/env node
//
// python-tests.mjs — run every `*.test.py` file this repository carries (wordpress-importer-archive#1155).
//
// Invoked by the `python-test` job in local-ci.json and .github/workflows/ci.yml.
//
// **Neither obvious mechanism reaches the files.** A `*.test.py` name is not a valid Python module
// name, so `unittest` discovery never imports one; each file says so in its own docstring and
// is run as a script. And one of the two lives under `.claude/skills/`, outside `scripts/`, so a
// shell glob rooted at `scripts/` misses the file whose failure is most expensive: the
// release-notes generator's encoding test (wordpress-importer-archive#527). This finds them by walking both roots, so a
// file added later under either one runs without editing the job.
//
// **Every way this can fail to test anything is a failure, never a skip.** No interpreter, and
// no files found, both exit non-zero with the cause named, because a job that tested nothing
// and reported PASS is the gap this job exists to close.
//
import { existsSync, readdirSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { join, relative, sep } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { needsShell, which, winQuote } from './spawn-helpers.mjs'

/**
 * The directories searched, relative to the repository root. `scripts/` is searched at its top
 * level only, matching the `node-test` job's `scripts/*.test.mjs`. `.claude/skills/` is searched
 * recursively, because each skill keeps its files in its own directory.
 */
export const PYTHON_TEST_ROOTS = [
    { dir: 'scripts', recursive: false },
    { dir: join('.claude', 'skills'), recursive: true },
]

/**
 * Every `*.test.py` file under the searched roots, as sorted forward-slash paths relative to `root`.
 *
 * @param  string  root  the repository root
 * @return string[]
 */
export function findPythonTests(root) {
    const found = []

    for (const { dir, recursive } of PYTHON_TEST_ROOTS) {
        const base = join(root, dir)

        if (!existsSync(base)) {
            continue
        }

        for (const entry of readdirSync(base, { recursive, withFileTypes: true })) {
            if (!entry.isFile() || !entry.name.endsWith('.test.py')) {
                continue
            }

            // `parentPath` on current Node; `path` on the older releases that shipped `recursive`
            const parent = entry.parentPath ?? entry.path ?? base
            found.push(relative(root, join(parent, entry.name)).split(sep).join('/'))
        }
    }

    return found.sort()
}

/**
 * The interpreter candidates, in order: the command, the leading arguments it needs, and the name
 * to report.
 *
 * `py -3` is the Windows launcher. `python3` is tried first everywhere, and on Windows it can be
 * the Microsoft Store alias, which exists on `PATH` but installs rather than runs. So a candidate
 * counts only when `--version` actually reports Python 3.
 */
export const PYTHON_CANDIDATES = [
    { command: 'python3', args: [] },
    { command: 'python', args: [] },
    { command: 'py', args: ['-3'] },
]

/**
 * Whether a `--version` reply names a Python 3 interpreter.
 *
 * Python 2 printed its version on stderr and 3 on stdout, so both are read.
 *
 * @param  {{status: ?number, stdout?: string, stderr?: string}}  reply
 * @return boolean
 */
export function isPython3(reply) {
    return reply.status === 0 && /^Python 3\./.test(`${reply.stdout ?? ''}${reply.stderr ?? ''}`.trim())
}

/**
 * Spawn a resolved executable, routing Windows shims through a shell the way spawn-helpers says.
 *
 * @param  string    exe
 * @param  string[]  args
 * @param  object    options
 * @return object
 */
function spawn(exe, args, options) {
    const shell = needsShell(exe)

    return spawnSync(
        shell ? winQuote(exe) : exe,
        shell ? args.map(winQuote) : args,
        { ...options, shell },
    )
}

/**
 * The first candidate that is a working Python 3, or null.
 *
 * @return {{exe: string, args: string[], version: string}|null}
 */
function resolvePython() {
    for (const candidate of PYTHON_CANDIDATES) {
        const exe = which(candidate.command)

        if (exe === null) {
            continue
        }

        const reply = spawn(exe, [...candidate.args, '--version'], { encoding: 'utf8' })

        if (isPython3(reply)) {
            return { exe, args: candidate.args, version: `${reply.stdout ?? ''}${reply.stderr ?? ''}`.trim() }
        }
    }

    return null
}

/**
 * Run every Python test file and return the process exit code.
 *
 * @param  string  root  the repository root
 * @return number
 */
export function main(root) {
    const files = findPythonTests(root)

    if (files.length === 0) {
        console.error(`python-test: found 0 *.test.py files under ${PYTHON_TEST_ROOTS.map((r) => r.dir).join(' and ')} — refusing to report a pass for a run that tested nothing.`)

        return 1
    }

    const python = resolvePython()

    if (python === null) {
        console.error(
            `python-test: no Python 3 interpreter found (tried ${PYTHON_CANDIDATES.map((c) => [c.command, ...c.args].join(' ')).join(', ')}). `
            + `${files.length} test file(s) did not run. Install Python 3 or put it on PATH; this is a failure, not a skip.`,
        )

        return 1
    }

    console.log(`python-test: ${python.version} (${python.exe}), ${files.length} file(s)`)

    const failed = []

    for (const file of files) {
        console.log(`\n--- ${file}`)

        const res = spawn(python.exe, [...python.args, file], {
            cwd: root,
            stdio: 'inherit',
            // No bytecode cache, so a gate run leaves the tree as it found it (wordpress-importer-archive#1207). A
            // python.org build writes `__pycache__/` beside the imported module, and an untracked
            // file there is staged by a broad `git add` and makes `worktree-slots release` refuse
            // the slot. Apple's Command Line Tools Python writes under
            // `~/Library/Caches/com.apple.python` instead, which is why macOS never showed it.
            env: { ...process.env, PYTHONDONTWRITEBYTECODE: '1' },
        })

        if (res.status !== 0) {
            failed.push(`${file} (exit ${res.status ?? 'none'}${res.error ? `, ${res.error.message}` : ''})`)
        }
    }

    console.log(`\npython-test: ${files.length - failed.length} of ${files.length} file(s) passed`)

    if (failed.length > 0) {
        console.error(`python-test: failed — ${failed.join('; ')}`)

        return 1
    }

    return 0
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
    process.exit(main(fileURLToPath(new URL('..', import.meta.url))))
}
