#!/usr/bin/env node
// The shared local CI runner, with this repository's slot (wordpress-importer-archive#1319).
//
// The shared runner (`.claude/skills/local-ci/local-ci.mjs`, configured by `local-ci.json`) does
// the work; this only places its `--branch` slot and translates the private runner's flags.
//
// Left to itself the runner puts the slot at `<primary>-ci`. That path already holds the tree
// the private runner this replaces created, which carries no `local-ci-slot` marker, so the
// shared runner refuses it rather than move a tree it did not make. The shared runner's slot is
// therefore `<primary>-local-ci`, beside the PRIMARY checkout, so a run started from slot A,
// slot B or an ephemeral worktree resolves to the same one. `scripts/worktree-slots.mjs` reads
// that name as runner-owned (`LOCAL_CI_SLOT`).
//
// Usage: node scripts/ci-local.mjs [shared runner options]
import { spawnSync } from 'node:child_process'
import { existsSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { primaryRootOf } from '../.claude/skills/local-ci/local-ci.mjs'
import { LOCAL_CI_SLOT, slotDirName } from './worktree-slots.mjs'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')

/** The job that installs, or reports drift, before anything else runs. */
export const DEPENDENCIES_JOB = 'dependencies'

/**
 * The private runner's flags the shared runner has no counterpart for, and what to do instead.
 * Refused rather than dropped: `--fresh` dropped silently reads as though it rebuilt something.
 */
export const RETIRED_FLAGS = {
    '--fresh': 'the shared runner reuses its slot; to rebuild it, remove it with `git worktree remove --force <slot>` and the next run recreates it',
    '--skip-install': 'the `dependencies` job installs only what the slot lacks, and every run includes it',
}

/**
 * The slot path for a primary checkout.
 *
 * @param  {string}  primaryRoot
 * @return {string}
 */
export function slotPath(primaryRoot) {
    return join(dirname(primaryRoot), slotDirName(primaryRoot, LOCAL_CI_SLOT))
}

/**
 * A `--jobs` list with the `dependencies` job first, added when it is missing.
 *
 * The shared runner ignores `always` once `--jobs` names a subset, so without this a
 * `--branch --jobs pest` run in a reused slot would install nothing and pass against whatever
 * `vendor/` the slot held last. Outside the runner's slot the job installs nothing and fails on
 * drift, which is the same stale-tree green caught in a developer's checkout.
 *
 * @param  {string}  list  the comma-separated value given to `--jobs`
 * @return {string}
 */
export function withDependencies(list) {
    const names = list.split(',').map((name) => name.trim()).filter(Boolean)

    return names.includes(DEPENDENCIES_JOB) ? list : [DEPENDENCIES_JOB, ...names].join(',')
}

/**
 * The shared runner's arguments for this invocation's, with the slot beside `primaryRoot`.
 *
 * `--jobs all` becomes `--all`, and `-b` becomes `--branch`: the private runner took both, and
 * the shared runner would refuse them. Any other `--jobs` list gains the `dependencies` job.
 *
 * @param  {string[]}  args
 * @param  {string}    primaryRoot
 * @return {string[]}
 */
export function runnerArgs(args, primaryRoot) {
    for (const flag of Object.keys(RETIRED_FLAGS)) {
        if (args.includes(flag)) {
            throw new Error(`${flag} is not a flag of the shared runner: ${RETIRED_FLAGS[flag]}.`)
        }
    }

    const out = []

    for (let i = 0; i < args.length; i++) {
        if ((args[i] === '--jobs' || args[i] === '-j') && args[i + 1] === 'all') {
            out.push('--all')
            i++
        } else if ((args[i] === '--jobs' || args[i] === '-j') && args[i + 1] !== undefined) {
            out.push(args[i], withDependencies(args[i + 1]))
            i++
        } else if (args[i] === '-b') {
            out.push('--branch')
        } else {
            out.push(args[i])
        }
    }

    if (out.includes('--branch') && !out.includes('--worktree-path')) {
        out.push('--worktree-path', slotPath(primaryRoot))
    }

    return out
}

/**
 * The arguments of a `dependencies`-only run to make first, or `null` when none is needed.
 *
 * The shared runner refuses a selection with `vendor/bin` jobs before any job runs when the slot
 * has no `vendor/autoload.php`, so on a fresh slot the `dependencies` job that would install it
 * never gets to. A run of that job alone passes the check, stands the slot up and installs; the
 * full selection then follows. This bridges until UAMS-Web/uams-claude-skills#276 lets the runner
 * do it itself. Only a `--branch` run needs it: in place, the job installs nothing anyway.
 *
 * @param  {string[]}                  out     the shared runner's arguments, from `runnerArgs`
 * @param  {(path: string) => boolean} exists
 * @return {string[]|null}
 */
export function bootstrapArgs(out, exists = existsSync) {
    const branch = out.indexOf('--branch')
    const slot = out.indexOf('--worktree-path')

    if (branch === -1 || slot === -1 || out.includes('--dry-run') || out.includes('--help')) {
        return null
    }

    if (exists(join(out[slot + 1], 'vendor', 'autoload.php'))) {
        return null
    }

    // Only the slot and the ref carry over: a verdict comment or commit status belongs to the full run.
    return ['--branch', out[branch + 1], '--worktree-path', out[slot + 1], '--jobs', DEPENDENCIES_JOB]
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    let args

    try {
        args = runnerArgs(process.argv.slice(2), primaryRootOf(root))
    } catch (error) {
        console.error(error.message)
        process.exit(1)
    }

    const runner = join(root, '.claude', 'skills', 'local-ci', 'local-ci.mjs')
    const first = bootstrapArgs(args)
    let r = { status: 0, signal: null }

    if (first) {
        console.log(`The slot has no vendor/autoload.php, so the dependencies job runs alone first (until UAMS-Web/uams-claude-skills#276).`)
        r = spawnSync(process.execPath, [runner, ...first], { stdio: 'inherit' })
    }

    // A failed install is the answer: the full run would only be refused for the same missing vendor/.
    if (r.status === 0) {
        r = spawnSync(process.execPath, [runner, ...args], { stdio: 'inherit' })
    }

    // A runner killed by a signal has no status; report it the way a shell would, never as a pass.
    process.exitCode = r.status ?? (r.signal ? 128 + ({ SIGHUP: 1, SIGINT: 2, SIGTERM: 15 }[r.signal] ?? 0) : 1)
}
