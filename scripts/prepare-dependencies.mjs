#!/usr/bin/env node
// The `dependencies` job in local-ci.json: keep vendor/ and node_modules/ in line with the
// commit being validated (wordpress-importer-archive#1319).
//
// The shared runner installs nothing. Its slot is reused from run to run, so without this a
// pull request that moves composer.lock is validated against the dependencies the slot held
// last, and passes against the wrong versions. `.claude/rules/worktrees.md` calls that stale
// tree worse than a missing install, because nothing about it is red. The private runner this
// replaces did the same before every `--branch` run.
//
// Where it runs decides what it does:
//
//   - In a slot the shared runner created (its `local-ci-slot` marker is in the slot's git
//     directory), it brings the tree in line: `composer install`, which is a no-op when
//     vendor/ already matches; `npm ci` only when node_modules/ differs from
//     package-lock.json, because `ci` deletes and rebuilds the tree. That tree is the runner's
//     own and is never hand-edited.
//   - Anywhere else (a developer's checkout, a work slot), it installs NOTHING. Installing
//     into someone's working tree is their decision, so it reports what is out of date,
//     names the command, and fails.
//
// Usage: node scripts/prepare-dependencies.mjs
import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { isRunnerSlot } from '../.claude/skills/local-ci/local-ci.mjs'
import { nodeModulesDrift } from './node-modules-drift.mjs'
import { needsShell, which, winQuote } from './spawn-helpers.mjs'

/**
 * Read a file, or null when it cannot be read.
 *
 * @param  {string}  file
 * @return {?string}
 */
function readOrNull(file) {
    try {
        return readFileSync(file, 'utf8')
    } catch {
        return null
    }
}

/**
 * Read a JSON file, or null when it is absent or not JSON.
 *
 * @param  {string}  file
 * @return {?object}
 */
function readJson(file) {
    try {
        return JSON.parse(readFileSync(file, 'utf8'))
    } catch {
        return null
    }
}

/**
 * Spawn a tool, resolving Windows `.bat`/`.cmd` shims through a shell the way spawn-helpers says.
 *
 * @param  {string}    file
 * @param  {string[]}  args
 * @param  {string}    cwd
 * @return {number}
 */
function tool(file, args, cwd) {
    const exe = which(file) ?? file
    const shell = needsShell(exe)
    const res = spawnSync(
        shell ? winQuote(exe) : exe,
        shell ? args.map(winQuote) : args,
        { cwd, shell, encoding: 'utf8', stdio: 'inherit' },
    )

    if (res.error) {
        console.error(`${file} could not be started: ${res.error.message}`)
    }

    return res.status ?? 1
}

/**
 * The packages composer.lock pins that vendor/ does not hold at that version and reference.
 * An absent or unreadable installed.json reports every locked package.
 *
 * @param  {?object}  lock       composer.lock, parsed
 * @param  {?object}  installed  vendor/composer/installed.json, parsed
 * @return {string[]}            one `name: wanted (installed)` line per mismatch
 */
export function composerDrift(lock, installed) {
    const pinned = [...(lock?.packages ?? []), ...(lock?.['packages-dev'] ?? [])]
    const have = new Map()

    for (const pkg of installed?.packages ?? []) {
        have.set(pkg.name, pkg)
    }

    const drift = []

    for (const pkg of pinned) {
        const got = have.get(pkg.name)
        const wantRef = pkg.dist?.reference ?? pkg.source?.reference ?? null
        const gotRef = got?.dist?.reference ?? got?.source?.reference ?? null

        if (!got) {
            drift.push(`${pkg.name}: ${pkg.version} (not installed)`)
        } else if (got.version !== pkg.version || wantRef !== gotRef) {
            drift.push(`${pkg.name}: ${pkg.version} (installed ${got.version})`)
        }
    }

    return drift
}

/**
 * The cspell version node_modules/ holds, for the log line that says which cspell checks the prose.
 *
 * @param  {string}  root
 * @return {string}
 */
function cspellVersion(root) {
    return readJson(join(root, 'node_modules', 'cspell', 'package.json'))?.version ?? 'unknown'
}

/**
 * Bring the runner's own slot in line with the commit. Returns the exit status.
 *
 * @param  {string}    root
 * @param  {Function}  exec  spawns a tool; returns its exit status
 * @return {number}
 */
function prepareSlot(root, exec) {
    console.log('Runner slot: installing from composer.lock and package-lock.json.')

    // No-op when vendor/ already matches, so there is nothing to decide first.
    if (exec('composer', ['install', '--no-interaction', '--no-progress'], root) !== 0) {
        console.error('composer install failed.')

        return 1
    }

    // Say which happened: a skipped install and a fresh one otherwise look the same (wordpress-importer-archive#1335).
    const drift = existsSync(join(root, 'node_modules', 'cspell'))
        ? nodeModulesDrift(readOrNull(join(root, 'package-lock.json')), readOrNull(join(root, 'node_modules', '.package-lock.json')))
        : 'node_modules/cspell is missing'

    if (drift === null) {
        console.log('node_modules: matches package-lock.json; npm ci skipped')
    } else {
        console.log(`node_modules: ${drift}; running npm ci`)

        if (exec('npm', ['ci', '--no-audit', '--no-fund'], root) !== 0) {
            console.error('npm ci failed.')

            return 1
        }
    }

    console.log(`cspell ${cspellVersion(root)} checks the prose`)

    return 0
}

/**
 * Report what is out of date in a tree that is not the runner's slot. Returns the exit status.
 *
 * @param  {string}  root
 * @return {number}
 */
function reportDrift(root) {
    const composer = composerDrift(readJson(join(root, 'composer.lock')), readJson(join(root, 'vendor', 'composer', 'installed.json')))
    const npm = nodeModulesDrift(readOrNull(join(root, 'package-lock.json')), readOrNull(join(root, 'node_modules', '.package-lock.json')))
    const lines = [
        ...composer.map((line) => `  composer  ${line}`),
        ...(npm === null ? [] : [`  npm       ${npm}`]),
    ]

    if (lines.length === 0) {
        console.log(`vendor/ and node_modules/ match the commit (cspell ${cspellVersion(root)}). Not a runner slot, so nothing was installed.`)

        return 0
    }

    console.error('This tree is not the runner\'s slot, so nothing was installed, and it does not match the commit:')

    for (const line of lines) {
        console.error(line)
    }

    const remedies = [
        ...(composer.length ? ['`composer install`'] : []),
        ...(npm === null ? [] : ['`npm ci`']),
    ]
    console.error(`Run ${remedies.join(' and ')} here (or \`node scripts/worktree-slots.mjs refresh <slot>\`), then run again.`)

    return 1
}

/**
 * Prepare or check `root`, depending on whether it is the runner's slot.
 *
 * @param  {string}  root
 * @param  {object}  seams  injectable for tests
 * @return {number}  the exit status
 */
export function main(root = process.cwd(), { slot = isRunnerSlot, exec = tool } = {}) {
    return slot(root) ? prepareSlot(root, exec) : reportDrift(root)
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    process.exitCode = main()
}
