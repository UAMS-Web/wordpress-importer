#!/usr/bin/env node
// The `pint` job in local-ci.json: lint the whole tree, dot-directories included (wordpress-importer-archive#1319).
//
// PHP-CS-Fixer's Finder skips dot-directories, and Pint exposes no switch to re-include them,
// so a bare `vendor/bin/pint --test` exits 0 over `.cursor/*.php` without opening it. Naming a
// path explicitly defeats the Finder. The paths are enumerated from git rather than written
// into local-ci.json, so a dot-directory added later is covered without anyone remembering to
// add it. ci.yml's `pint` job does the same enumeration in shell.
//
// Runs Pint under the PHP the shared runner resolved for the run (`LOCAL_CI_PHP`), and under
// `php` on PATH when there is none, which is the case for `--jobs pint` alone: the runner
// resolves PHP only when a selected job's command starts with `php` or `vendor/bin/`.
//
// Usage: node scripts/run-pint.mjs
import { spawnSync } from 'node:child_process'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { needsShell, which, winQuote } from './spawn-helpers.mjs'

/**
 * The dot-directories holding tracked PHP, as paths to hand Pint explicitly.
 *
 * Measured on macOS, Pint 1.30.3, no `pint.json`, against a file known to be dirty:
 *
 *     vendor/bin/pint --test              exit 0   <- blind
 *     vendor/bin/pint --test .            exit 0   <- still blind
 *     vendor/bin/pint --test . .cursor    exit 1   <- sees it
 *
 * Returns the path up to and including the FIRST dot-segment, so a nested `src/.foo/x.php`
 * yields `src/.foo` rather than `src`. Pure, so the enumeration is tested without git.
 *
 * @param  {string[]}  files  repo-relative paths, as `git ls-files` prints them
 * @return {string[]}         sorted, deduplicated directory paths; empty when none qualify
 */
export function dotDirectoryPaths(files) {
    const dirs = new Set()

    for (const file of files) {
        const parts = file.split('/')

        // The basename cannot itself be the directory, so stop one short.
        for (let i = 0; i < parts.length - 1; i++) {
            if (parts[i].startsWith('.')) {
                dirs.add(parts.slice(0, i + 1).join('/'))
                break
            }
        }
    }

    return [...dirs].sort()
}

/**
 * The argv to run Pint with: the PHP binary first, then the tool and its paths.
 *
 * @param  {string}    php   the PHP binary to run Pint under
 * @param  {string[]}  dirs  the dot-directories to name explicitly
 * @return {string[]}
 */
export function pintArgv(php, dirs) {
    return [php, 'vendor/bin/pint', '--test', '.', ...dirs]
}

/**
 * Enumerate, then lint. Returns the exit status.
 *
 * A git failure yields no paths, which would silently restore the blind spot, so it fails the
 * job instead. That mirrors the spell floor: the one outcome a green must never cover is a
 * check that examined nothing.
 *
 * @param  {string}  root
 * @param  {object}  env
 * @return {number}
 */
export function main(root = process.cwd(), env = process.env) {
    const listed = spawnSync('git', ['-C', root, 'ls-files', '--', '*.php'], { encoding: 'utf8' })

    if (listed.status !== 0) {
        console.error(`could not enumerate tracked PHP in ${root}: pint would silently skip dot-directories`)

        return 1
    }

    const dirs = dotDirectoryPaths(listed.stdout.split('\n').filter(Boolean))
    // Say what was covered: the defect this replaces was invisible because a green reported
    // nothing about its own scope. ci.yml echoes the same line.
    console.log(`dot-directories linted explicitly: ${dirs.join(' ') || '(none)'}`)

    const php = env.LOCAL_CI_PHP || which('php') || 'php'
    const [exe, ...args] = pintArgv(php, dirs)
    const shell = needsShell(exe)
    const res = spawnSync(shell ? winQuote(exe) : exe, shell ? args.map(winQuote) : args, { cwd: root, shell, stdio: 'inherit' })

    if (res.error) {
        console.error(`could not launch ${exe}: ${res.error.message}`)
    }

    return res.status ?? 1
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    process.exitCode = main()
}
