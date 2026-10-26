// Tests for run-spell.mjs (wordpress-importer-archive#1319).
//
// Run: node --test scripts/run-spell.test.mjs
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { cspellBin, main, SPELL_ARGS, spellFileCount, spellFloorRefusal } from './run-spell.mjs'

const ROOT = join(fileURLToPath(new URL('.', import.meta.url)), '..')
const ZERO_FILE_SUMMARY = 'CSpell: Files checked: 0, Issues found: 0 in 0 files.'
const REAL_SUMMARY = 'CSpell: Files checked: 62, Issues found: 0 in 0 files.'

describe('the floor', () => {
    it('reads the count rather than matching the digit', () => {
        assert.equal(spellFileCount(REAL_SUMMARY), 62)
        assert.equal(spellFileCount(ZERO_FILE_SUMMARY), 0)
        // The guard must not be satisfiable by a count that merely contains a zero.
        assert.equal(spellFileCount('CSpell: Files checked: 10, Issues found: 0 in 0 files.'), 10)
        assert.equal(spellFileCount('no summary here'), null)
    })

    it('refuses a zero-file run and passes a real one', () => {
        assert.match(spellFloorRefusal(ZERO_FILE_SUMMARY), /checked 0 files/)
        assert.equal(spellFloorRefusal(REAL_SUMMARY), null)
        assert.equal(spellFloorRefusal('CSpell: Files checked: 10, Issues found: 0 in 0 files.'), null)
    })

    it('refuses an absent summary rather than reading it as a pass', () => {
        assert.match(spellFloorRefusal(''), /no "Files checked" summary/)
        assert.match(spellFloorRefusal('cspell exploded'), /no "Files checked" summary/)
    })
})

describe('the corpus', () => {
    it('is the one ci.yml checks, glob for glob', () => {
        const ci = readFileSync(join(ROOT, '.github', 'workflows', 'ci.yml'), 'utf8')
        const line = ci.split('\n').find((l) => l.includes('npx cspell'))
        const globs = [...line.matchAll(/"([^"]+)"/g)].map((m) => m[1])

        assert.ok(globs.length > 0, 'no globs read from ci.yml')
        assert.deepEqual(SPELL_ARGS.filter((arg) => !arg.startsWith('--')), globs)
        assert.ok(SPELL_ARGS.includes('--dot'), 'without --dot the globs skip .claude/')
    })
})

describe('main', () => {
    it('refuses a real cspell run that checks nothing, and passes the real corpus', (t) => {
        // Executes cspell rather than asserting on argv: a test that reads the source for a
        // flag stays green when the flag is deleted from the only place it acts.
        if (!existsSync(cspellBin(ROOT))) {
            t.skip('node_modules/cspell absent: run `npm ci` to exercise this')

            return
        }

        assert.equal(main(ROOT, ['--no-progress', '--no-must-find-files', '--dot', 'nonexistent/**/*.md']), 1)
        assert.equal(main(ROOT, ['--no-progress', '--no-must-find-files', '--dot', 'README.md']), 0)
    })

    it('fails, rather than skipping, when cspell is not installed', () => {
        assert.equal(main(join(ROOT, 'nonexistent-tree')), 1)
    })
})
