#!/usr/bin/env node
// The `spell` job in local-ci.json: cspell over the prose corpus, with a floor (wordpress-importer-archive#1319).
//
// The corpus is wordpress-importer-archive#922's, widened by wordpress-importer-archive#980 and wordpress-importer-archive#1223: the Markdown that loads into every session
// plus `docs/` and the README. `src/` stays out, because it spends the dictionary on WordPress
// field names for no prose coverage. package.json's `spell` script is a different, broader
// corpus and is not the gate. ci.yml's `spell` job runs the same globs and the same floor.
//
// cspell runs through Node against its own entry point rather than through `npx`: on Windows a
// bare `npx` cannot be spawned without a shell, and a POSIX shell would expand the globs below
// before cspell saw them, so the job would check a different corpus than ci.yml does.
//
// Usage: node scripts/run-spell.mjs
import { spawnSync } from 'node:child_process'
import { existsSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * The cspell arguments, globs last. `--dot` is load-bearing: without it the globs skip
 * dot-directories, so `.claude/` is never read and the job reports a clean pass over nothing.
 */
export const SPELL_ARGS = [
    '--no-progress',
    '--no-must-find-files',
    '--dot',
    '.claude/**/*.md',
    'docs/**/*.md',
    'README.md',
    'AGENTS.md',
    'CLAUDE.md',
    'tests/CLAUDE.md',
    '.github/**/*.md',
]

/**
 * cspell's own file count, parsed from its summary line, or null when the line is absent.
 *
 * The line reads `CSpell: Files checked: 62, Issues found: 0 in 0 files.`, measured on cspell
 * 10.0.1, macOS (Darwin 25.6.0). The count is captured rather than string-matched so
 * `Files checked: 10` cannot satisfy a test for zero.
 *
 * @param  {?string}  output
 * @return {?number}
 */
export function spellFileCount(output) {
    const m = /Files checked:\s*(\d+)/.exec(output ?? '')

    return m ? Number(m[1]) : null
}

/**
 * Why a spell run that exited 0 must still be refused, or null when it is a genuine pass.
 *
 * `--no-must-find-files` is kept deliberately, so one non-matching glob does not fail the run,
 * but it also suppresses the only signal separating *read the corpus and found nothing* from
 * *read nothing at all*. Measured on cspell 10.0.1: with the flag a zero-file run exits 0;
 * without it, 1. This restores the floor without losing the tolerance. An absent summary line
 * is refused too: a shape change must not read as a pass.
 *
 * @param  {?string}  output
 * @return {?string}
 */
export function spellFloorRefusal(output) {
    const n = spellFileCount(output)

    if (n === null) {
        return 'cspell printed no "Files checked" summary: output shape changed, so a pass cannot be trusted'
    }

    if (n === 0) {
        return 'cspell checked 0 files: the corpus globs matched nothing, so a pass means nothing'
    }

    return null
}

/**
 * The cspell entry point inside a tree's node_modules/.
 *
 * @param  {string}  root
 * @return {string}
 */
export function cspellBin(root) {
    return join(root, 'node_modules', 'cspell', 'bin.mjs')
}

/**
 * Run cspell over the corpus and apply the floor. Returns the exit status.
 *
 * @param  {string}    root
 * @param  {string[]}  args  the cspell arguments; the corpus unless a test passes another
 * @return {number}
 */
export function main(root = process.cwd(), args = SPELL_ARGS) {
    const bin = cspellBin(root)

    // Fail rather than skip: a spell job that skips when its tool is missing reports nothing
    // about prose nobody checked. The `dependencies` job installs it in the runner's slot.
    if (!existsSync(bin)) {
        console.error('node_modules/cspell is missing. Run `npm ci` here, then run again.')

        return 1
    }

    const res = spawnSync(process.execPath, [bin, ...args], { cwd: root, encoding: 'utf8' })
    const output = `${res.stdout ?? ''}${res.stderr ?? ''}`
    process.stdout.write(output)

    if (res.error) {
        console.error(`could not launch cspell: ${res.error.message}`)

        return 1
    }

    if (res.status !== 0) {
        return res.status ?? 1
    }

    const refusal = spellFloorRefusal(output)

    if (refusal !== null) {
        console.error(refusal)

        return 1
    }

    return 0
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    process.exitCode = main()
}
