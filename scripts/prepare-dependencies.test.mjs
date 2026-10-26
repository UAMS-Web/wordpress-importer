// Tests for prepare-dependencies.mjs (wordpress-importer-archive#1319).
//
// Run: node --test scripts/prepare-dependencies.test.mjs
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import { SLOT_MARKER } from '../.claude/skills/local-ci/local-ci.mjs'
import { composerDrift, main } from './prepare-dependencies.mjs'

describe('composerDrift', () => {
    const lock = {
        packages: [{ name: 'a/one', version: '1.0.0', dist: { reference: 'r1' } }],
        'packages-dev': [{ name: 'b/two', version: '2.0.0', dist: { reference: 'r2' } }],
    }

    it('is empty when vendor/ holds every locked package at its version and reference', () => {
        assert.deepEqual(composerDrift(lock, { packages: [...lock.packages, ...lock['packages-dev']] }), [])
    })

    it('names a package at another version, and one at the same version but another reference', () => {
        const installed = {
            packages: [
                { name: 'a/one', version: '0.9.0', dist: { reference: 'r0' } },
                { name: 'b/two', version: '2.0.0', dist: { reference: 'other' } },
            ],
        }

        assert.deepEqual(composerDrift(lock, installed), ['a/one: 1.0.0 (installed 0.9.0)', 'b/two: 2.0.0 (installed 2.0.0)'])
    })

    it('reports every locked package when installed.json is absent', () => {
        assert.equal(composerDrift(lock, null).length, 2)
    })
})

describe('main', () => {
    /** A tree with a lockfile pair that matches, so only the seam under test can fail. */
    function matchingTree() {
        const root = mkdtempSync(join(tmpdir(), 'prep-main-'))
        const pkg = { name: 'a/one', version: '1.0.0', dist: { reference: 'r1' } }
        writeFileSync(join(root, 'composer.lock'), JSON.stringify({ packages: [pkg] }))
        mkdirSync(join(root, 'vendor', 'composer'), { recursive: true })
        writeFileSync(join(root, 'vendor', 'composer', 'installed.json'), JSON.stringify({ packages: [pkg] }))
        const npmLock = JSON.stringify({ packages: { '': {}, 'node_modules/cspell': { version: '1.0.0', integrity: 'x' } } })
        writeFileSync(join(root, 'package-lock.json'), npmLock)
        mkdirSync(join(root, 'node_modules', 'cspell'), { recursive: true })
        writeFileSync(join(root, 'node_modules', '.package-lock.json'), npmLock)

        return root
    }

    it('installs nothing outside the runner\'s slot, and passes a tree that matches', () => {
        const root = matchingTree()
        const calls = []

        try {
            const status = main(root, { slot: () => false, exec: (...args) => calls.push(args) })

            assert.equal(status, 0)
            assert.deepEqual(calls, [], 'a developer\'s tree was installed into')
        } finally {
            rmSync(root, { recursive: true, force: true })
        }
    })

    it('fails outside the runner\'s slot on drift, still installing nothing', () => {
        const root = matchingTree()
        const calls = []

        try {
            writeFileSync(join(root, 'vendor', 'composer', 'installed.json'), JSON.stringify({ packages: [] }))

            const status = main(root, { slot: () => false, exec: (...args) => calls.push(args) })

            assert.equal(status, 1)
            assert.deepEqual(calls, [])
        } finally {
            rmSync(root, { recursive: true, force: true })
        }
    })

    it('in the runner\'s slot runs composer install, skips npm ci on a matching tree', () => {
        const root = matchingTree()
        const calls = []

        try {
            const status = main(root, {
                slot: () => true,
                exec: (cmd, args) => {
                    calls.push([cmd, args[0]])

                    return 0
                },
            })

            assert.equal(status, 0)
            assert.deepEqual(calls, [['composer', 'install']])
        } finally {
            rmSync(root, { recursive: true, force: true })
        }
    })

    it('in the runner\'s slot runs npm ci when node_modules/ differs from the lockfile', () => {
        const root = matchingTree()
        const calls = []

        try {
            rmSync(join(root, 'node_modules', '.package-lock.json'))

            main(root, {
                slot: () => true,
                exec: (cmd, args) => {
                    calls.push([cmd, args[0]])

                    return 0
                },
            })

            assert.deepEqual(calls, [['composer', 'install'], ['npm', 'ci']])
        } finally {
            rmSync(root, { recursive: true, force: true })
        }
    })

    it('tells the runner\'s slot from any other tree by the runner\'s own marker, with no seam injected', () => {
        const root = matchingTree()
        const calls = []
        const exec = (cmd, args) => {
            calls.push([cmd, args[0]])

            return 0
        }

        try {
            const init = spawnSync('git', ['init', '-q', root], { encoding: 'utf8' })
            assert.equal(init.status, 0, init.stderr)

            // Without the marker the tree is a developer's: nothing is installed.
            assert.equal(main(root, { exec }), 0)
            assert.deepEqual(calls, [])

            // With it, the same tree is the runner's slot, and composer install runs.
            writeFileSync(join(root, '.git', SLOT_MARKER), '')
            assert.equal(main(root, { exec }), 0)
            assert.deepEqual(calls, [['composer', 'install']])
        } finally {
            rmSync(root, { recursive: true, force: true })
        }
    })

    it('in the runner\'s slot fails on a failed install', () => {
        const root = matchingTree()

        try {
            assert.equal(main(root, { slot: () => true, exec: () => 1 }), 1)
        } finally {
            rmSync(root, { recursive: true, force: true })
        }
    })
})
