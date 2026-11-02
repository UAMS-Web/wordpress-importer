// Tests for ci-local.mjs and the job list it runs (wordpress-importer-archive#1319).
//
// Run: node --test scripts/ci-local.test.mjs
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, realpathSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { parseConfig, primaryRootOf } from '../.claude/skills/local-ci/local-ci.mjs'
import { bootstrapArgs, runnerArgs, slotPath } from './ci-local.mjs'
import { treeKind } from './worktree-slots.mjs'

const ROOT = join(fileURLToPath(new URL('.', import.meta.url)), '..')

describe('runnerArgs', () => {
    it('places a --branch slot beside the primary, under a name worktree-slots reads as runner-owned', () => {
        const out = runnerArgs(['--branch', 'x'], '/p/wordpress-importer')

        assert.deepEqual(out, ['--branch', 'x', '--worktree-path', join('/p', 'wordpress-importer-local-ci')])
        assert.equal(treeKind(out[3], '/p/wordpress-importer'), 'ci')
    })

    it('does not reuse the old runner\'s slot, which the shared runner refuses for want of its marker', () => {
        assert.notEqual(slotPath('/p/wordpress-importer'), join('/p', 'wordpress-importer-ci'))
    })

    it('leaves an in-place run alone, since the runner refuses --worktree-path without --branch', () => {
        assert.deepEqual(runnerArgs(['--dry-run'], '/p/repo'), ['--dry-run'])
    })

    it('keeps a --worktree-path the caller chose', () => {
        assert.deepEqual(runnerArgs(['--branch', 'x', '--worktree-path', '/elsewhere'], '/p/repo'), ['--branch', 'x', '--worktree-path', '/elsewhere'])
    })

    it('translates the private runner\'s -b and --jobs all', () => {
        assert.deepEqual(runnerArgs(['-b', 'x', '--jobs', 'all'], '/p/repo').slice(0, 3), ['--branch', 'x', '--all'])
        assert.deepEqual(runnerArgs(['--jobs', 'pint,spell'], '/p/repo'), ['--jobs', 'dependencies,pint,spell'])
    })

    it('adds the dependencies job to every --jobs subset, since the runner ignores always there', () => {
        const out = runnerArgs(['--branch', 'x', '-j', 'pest,phpstan'], '/p/repo')

        assert.deepEqual(out.slice(0, 4), ['--branch', 'x', '-j', 'dependencies,pest,phpstan'])
        assert.deepEqual(runnerArgs(['--jobs', 'pest,dependencies'], '/p/repo'), ['--jobs', 'pest,dependencies'])
    })

    it('refuses the private runner\'s --fresh and --skip-install, naming what to do instead', () => {
        assert.throws(() => runnerArgs(['--branch', 'x', '--fresh'], '/p/repo'), /--fresh.*git worktree remove/)
        assert.throws(() => runnerArgs(['--skip-install'], '/p/repo'), /--skip-install.*dependencies/)
    })

    it('puts the slot beside the primary when invoked from a linked worktree', () => {
        // `.native` expands a Windows short name such as RUNNER~1, as git does.
        const dir = realpathSync.native(mkdtempSync(join(tmpdir(), 'ci-local-')))
        const git = (cwd, ...args) => {
            const r = spawnSync('git', ['-C', cwd, ...args], { encoding: 'utf8' })
            assert.equal(r.status, 0, r.stderr)
        }

        try {
            const primary = join(dir, 'repo')
            git(dir, 'init', '-q', 'repo')
            git(primary, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'x')
            const linked = join(dir, 'repo-a')
            git(primary, 'worktree', 'add', '-q', '--detach', linked)

            // The control: from the linked worktree itself the slot would be `repo-a-local-ci`.
            assert.notEqual(slotPath(linked), slotPath(primary))
            assert.deepEqual(runnerArgs(['--branch', 'x'], primaryRootOf(linked)), ['--branch', 'x', '--worktree-path', slotPath(primary)])
        } finally {
            rmSync(dir, { recursive: true, force: true })
        }
    })
})

describe('bootstrapArgs', () => {
    const slot = '/w/wordpress-importer-local-ci'
    const full = ['--branch', 'x', '--all', '--comment', '--worktree-path', slot]
    const missing = () => false
    const present = () => true

    it('runs the dependencies job alone first when the slot has no vendor/autoload.php', () => {
        assert.deepEqual(bootstrapArgs(full, missing), ['--branch', 'x', '--worktree-path', slot, '--jobs', 'dependencies'])
    })

    it('asks about the slot\'s own autoloader, not the invoking tree\'s', () => {
        const asked = []
        bootstrapArgs(full, (path) => asked.push(path) && false)
        assert.deepEqual(asked, [join(slot, 'vendor', 'autoload.php')])
    })

    it('adds nothing once the slot has vendor/', () => {
        assert.equal(bootstrapArgs(full, present), null)
    })

    it('adds nothing to an in-place run or a dry run', () => {
        assert.equal(bootstrapArgs(['--all'], missing), null)
        assert.equal(bootstrapArgs([...full, '--dry-run'], missing), null)
    })
})

describe('local-ci.json against ci.yml', () => {
    const config = parseConfig(readFileSync(join(ROOT, 'local-ci.json'), 'utf8'))
    const ci = readFileSync(join(ROOT, '.github', 'workflows', 'ci.yml'), 'utf8')
    const ciJobs = ci.slice(ci.indexOf('\njobs:')).split('\n').map((line) => /^ {2}([a-z][\w-]*):$/.exec(line)?.[1]).filter(Boolean)
    const LOCAL_ONLY = ['dependencies', 'synced-files', 'handed-back-workflow']
    // The aggregate the ruleset requires as `CI` (#163). It runs no command of its own, so it
    // has no local counterpart.
    const AGGREGATE = 'ci'
    const chained = ciJobs.filter((job) => job !== AGGREGATE)

    it('reads ci.yml\'s jobs, so an empty comparison cannot pass', () => {
        assert.ok(ciJobs.length >= 8, `read ${ciJobs.length} ci.yml jobs`)
    })

    it('runs every job ci.yml gates, and nothing else but the declared local-only jobs', () => {
        const names = config.jobs.map((job) => job.name)

        assert.deepEqual(names.filter((name) => !LOCAL_ONLY.includes(name)).sort(), [...chained].sort())
        assert.deepEqual(names.filter((name) => LOCAL_ONLY.includes(name)).sort(), [...LOCAL_ONLY].sort())
    })

    it('selects every job on every run, as the private runner did', () => {
        assert.deepEqual(config.jobs.filter((job) => job.always !== true).map((job) => job.name), [])
    })

    it('runs the synced-files check and the handed-back drift check', () => {
        const run = Object.fromEntries(config.jobs.map((job) => [job.name, job.run]))

        assert.equal(run['synced-files'], 'node .claude/skills/synced-files/scripts/check-synced-files.mjs')
        assert.equal(run['handed-back-workflow'], 'node .claude/skills/handed-back-label/scripts/check-handed-back-workflow.mjs')
    })

    it('runs each mirrored command as ci.yml does', () => {
        const run = Object.fromEntries(config.jobs.map((job) => [job.name, job.run]))
        const ciLines = ci.split('\n').map((line) => line.trim())

        for (const name of ['validate', 'rector', 'pest', 'node-test', 'python-test']) {
            // A whole line, not a substring: `--testsuite=Unit` is a prefix of ci.yml's
            // `--testsuite=Unit,Integration`, so `includes` would pass a narrowed command.
            // `--shard` is the one difference a shard's command may carry (#376).
            const mirrored = ciLines.map((line) => line.replace(/ --shard=\S+$/, ''))
            assert.ok(mirrored.includes(`run: ${run[name]}`), `${name}: ci.yml does not run \`${run[name]}\``)
        }
    })

    // A shard left out of the matrix drops its tests with every check still green, so this
    // is the failure nothing else would report (#376).
    it('runs every Pest shard on the matrix', () => {
        const own = ci.slice(ci.indexOf('\n  pest:\n') + 1).split(/\n {2}[a-z][\w-]*:\n/)[0]
        const total = Number(/--shard=\$\{\{ matrix\.shard \}\}\/(\d+)$/m.exec(own)?.[1])
        const shards = (/^ {8}shard: \[([\d, ]+)\]$/m.exec(own)?.[1] ?? '').split(', ').map(Number)

        assert.ok(total >= 2, `read a shard total of ${total}`)
        assert.deepEqual(shards, Array.from({ length: total }, (_, index) => index + 1))
        assert.match(own, new RegExp(`^ {4}name: Pest shard \\$\\{\\{ matrix\\.shard \\}\\}/${total} `, 'm'))
    })

    describe('the CI aggregate the ruleset requires', () => {
        // The job's own text, from its `ci:` line to the next job or the end of the file.
        const start = ci.indexOf(`\n  ${AGGREGATE}:\n`)
        const rest = ci.slice(start + 1)
        const next = rest.slice(1).search(/\n {2}[a-z][\w-]*:\n/)
        const body = next === -1 ? rest : rest.slice(0, next + 1)
        const needs = (/^ {4}needs:\n((?: {6}- \S+\n?)+)/m.exec(body)?.[1] ?? '').match(/(?<=^ {6}- )\S+/gm) ?? []

        it('is the last job, named CI, so the ruleset context does not move', () => {
            assert.notEqual(start, -1, `ci.yml has no \`${AGGREGATE}\` job`)
            assert.equal(ciJobs.at(-1), AGGREGATE)
            assert.match(body, /^ {4}name: CI$/m)
        })

        it('needs every other job, so a job left off the list is required by nothing', () => {
            assert.ok(chained.length >= 8, `read ${chained.length} chained jobs`)
            assert.deepEqual([...needs].sort(), [...chained].sort())
        })

        it('runs whatever the others did, and is not gated on RUN_REMOTE_CI, which would let it skip and pass', () => {
            assert.match(body, /^ {4}if: always\(\)$/m)
            assert.doesNotMatch(body, /RUN_REMOTE_CI/)

            for (const job of chained) {
                const own = ci.slice(ci.indexOf(`\n  ${job}:\n`) + 1).split(/\n {2}[a-z][\w-]*:\n/)[0]
                assert.match(own, /^ {4}if: vars\.RUN_REMOTE_CI != 'false'$/m, `${job} is not gated`)
            }
        })

        // The Windows leg (#186). A matrix keeps one `needs:` entry per job, so this is what
        // fails when a job stops running on Windows: nothing in `needs:` would change.
        it('runs every job on both operating systems, each leg reporting', () => {
            assert.ok(chained.length >= 8, `read ${chained.length} chained jobs`)

            for (const job of chained) {
                const own = ci.slice(ci.indexOf(`\n  ${job}:\n`) + 1).split(/\n {2}[a-z][\w-]*:\n/)[0]

                assert.match(own, /^ {4}runs-on: \$\{\{ matrix\.os \}\}$/m, `${job} does not run on the matrix`)
                assert.match(own, /^ {8}os: \[ubuntu-latest, windows-latest\]$/m, `${job} does not list both operating systems`)
                assert.match(own, /^ {6}fail-fast: false$/m, `${job} lets one leg cancel the other`)
                assert.doesNotMatch(own, /^\s*(?:exclude|include):/m, `${job} narrows its matrix`)
                assert.match(own, /^ {4}name: .*\(\$\{\{ matrix\.os \}\}\)$/m, `${job}'s checks do not name their operating system`)
            }
        })

        it('tolerates no failure, on either operating system', () => {
            assert.doesNotMatch(ci.slice(ci.indexOf('\njobs:')), /^[\s-]*continue-on-error:/m)
        })

        it('runs every step in bash, which a Windows runner does not default to', () => {
            assert.match(ci, /^defaults:\n {2}run:\n {4}shell: bash$/m)
        })

        // The step's script, run as the runner would with RESULTS set as `join(needs.*.result, ' ')` sets it.
        const script = (/^ {8}run: \|\n((?: {10}.*\n?)+)/m.exec(body)?.[1] ?? '').replace(/^ {10}/gm, '')
        const run = (results) => spawnSync('bash', ['-e', '-c', script], { encoding: 'utf8', env: { ...process.env, RESULTS: results } })

        it('passes only when every needed job succeeded', () => {
            assert.ok(script.includes('RESULTS'), 'read no script from the CI step')
            assert.equal(run('success success success').status, 0)
        })

        for (const result of ['failure', 'skipped', 'cancelled']) {
            it(`fails when a needed job reports ${result}`, () => {
                const r = run(`success ${result} success`)

                assert.equal(r.status, 1)
                assert.match(r.stdout, new RegExp(`A needed job reported ${result}`))
            })
        }
    })
})
