// Tests for node-modules-drift.mjs: whether an installed node_modules/ matches package-lock.json
// (wordpress-importer-archive#1335). Run with `node --test "scripts/*.test.mjs"`.

import test from 'node:test'
import assert from 'node:assert/strict'
import { nodeModulesDrift } from './node-modules-drift.mjs'

/**
 * A lockfile in npm's v3 shape, with a root entry and the given packages.
 *
 * @param  object  packages  the node_modules/ entries
 * @return string
 */
function lockfile(packages) {
    return JSON.stringify({
        name: 'probe',
        lockfileVersion: 3,
        packages: { '': { name: 'probe' }, ...packages },
    })
}

const cspell = (version, integrity = `sha512-${version}`) => ({ version, integrity })

test('a tree installed from the lockfile matches it', () => {
    const lock = lockfile({ 'node_modules/cspell': cspell('10.3.6') })
    // npm's own record has no root entry, which must not count as a difference.
    const hidden = JSON.stringify({ name: 'probe', lockfileVersion: 3, packages: { 'node_modules/cspell': cspell('10.3.6') } })

    assert.equal(nodeModulesDrift(lock, hidden), null)
})

test('a lockfile that moved cspell is drift, naming both versions', () => {
    // The wordpress-importer-archive#1331 case: the tree held 10.0.1 while the pull request pinned 10.3.6.
    const lock = lockfile({ 'node_modules/cspell': cspell('10.3.6') })
    const hidden = lockfile({ 'node_modules/cspell': cspell('10.0.1') })

    assert.equal(nodeModulesDrift(lock, hidden), 'node_modules/cspell is 10.0.1, the lockfile pins 10.3.6')
})

test('the same version with a different integrity is drift', () => {
    const lock = lockfile({ 'node_modules/cspell': cspell('10.3.6', 'sha512-new') })
    const hidden = lockfile({ 'node_modules/cspell': cspell('10.3.6', 'sha512-old') })

    assert.notEqual(nodeModulesDrift(lock, hidden), null)
})

test('a package the lockfile pins and the tree lacks is drift', () => {
    const lock = lockfile({ 'node_modules/cspell': cspell('10.3.6'), 'node_modules/chalk': cspell('5.0.0') })
    const hidden = lockfile({ 'node_modules/cspell': cspell('10.3.6') })

    assert.equal(nodeModulesDrift(lock, hidden), 'node_modules/chalk is not installed')
})

test('an optional package the platform skipped is not drift', () => {
    // npm leaves out an optional package that does not fit the platform; counting it would
    // reinstall on every run.
    const lock = lockfile({ 'node_modules/cspell': cspell('10.3.6'), 'node_modules/fsevents': { ...cspell('2.3.3'), optional: true } })
    const hidden = lockfile({ 'node_modules/cspell': cspell('10.3.6') })

    assert.equal(nodeModulesDrift(lock, hidden), null)
})

test('a missing or unreadable record is drift, never a match', () => {
    const lock = lockfile({ 'node_modules/cspell': cspell('10.3.6') })

    assert.equal(nodeModulesDrift(lock, null), 'node_modules/.package-lock.json is missing')
    assert.notEqual(nodeModulesDrift(lock, '{not json'), null)
    assert.notEqual(nodeModulesDrift(null, lockfile({})), null)
    assert.notEqual(nodeModulesDrift(lock, JSON.stringify({ lockfileVersion: 3 })), null)
})
