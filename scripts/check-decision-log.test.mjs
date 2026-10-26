import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { decisionLogFindings, formatFindings } from './check-decision-log.mjs'

const REPO_ROOT = join(import.meta.dirname, '..')

/**
 * A minimal well-formed log: a preamble, then two entries separated by a blank
 * line. Every red case below is this document with one thing done to it, so a
 * failure names the mutation rather than the fixture.
 */
const CLEAN = [
    '# Decisions',
    '',
    'Preamble prose.',
    '',
    '## First decision',
    '',
    'Body of the first.',
    '',
    '## Second decision',
    '',
    'Body of the second.',
    '',
].join('\n')

test('the clean fixture has no findings, so a red below is the mutation and not the shape', () => {
    assert.deepEqual(decisionLogFindings(CLEAN), [])
})

test('a heading fused to the entry above it is caught — the separator union drops', () => {
    // Exactly what `merge=union` does: it keeps both sides and loses the blank
    // line between them. Cosmetic once, and compounding at every append.
    const fused = CLEAN.replace('Body of the first.\n\n## Second', 'Body of the first.\n## Second')

    assert.notEqual(fused, CLEAN, 'the mutation must have applied, or this test proves nothing')

    const findings = decisionLogFindings(fused)

    assert.equal(findings.length, 1, formatFindings(findings, 'fused'))
    assert.equal(findings[0].kind, 'no-blank-line-before-heading')
})

test('the same decision recorded twice by two branches is caught', () => {
    const duplicated = CLEAN.replace('## Second decision', '## First decision')

    assert.notEqual(duplicated, CLEAN, 'the mutation must have applied')

    const findings = decisionLogFindings(duplicated)

    assert.equal(findings.length, 1, formatFindings(findings, 'duplicated'))
    assert.equal(findings[0].kind, 'duplicate-heading')
    assert.match(findings[0].detail, /also at line 5/)
})

test('a heading with nothing under it is caught — line-level interleaving', () => {
    const hollow = CLEAN.replace('Body of the first.', '')

    assert.notEqual(hollow, CLEAN, 'the mutation must have applied')

    const kinds = decisionLogFindings(hollow).map((f) => f.kind)

    assert.ok(kinds.includes('empty-section'), `expected empty-section, got ${kinds.join(', ')}`)
})

test('the first heading is exempt, because the document preamble precedes it', () => {
    // Without this the guard reds on every well-formed log, which is the shape of
    // a check nobody can leave switched on.
    const findings = decisionLogFindings('## Only decision\n\nBody.\n')

    assert.deepEqual(findings, [])
})

test('the line numbers are 1-indexed, so they match an editor and a CI log', () => {
    const fused = CLEAN.replace('Body of the first.\n\n## Second', 'Body of the first.\n## Second')

    assert.equal(decisionLogFindings(fused)[0].line, 8)
})

test('this repository docs/DECISIONS.md passes the guard', () => {
    // The wiring. `node-test` runs every scripts/*.test.mjs, so the real log is
    // checked on every gate run rather than by a script someone remembers to
    // invoke. If a union merge breaks the structure, this is what goes red.
    const source = readFileSync(join(REPO_ROOT, 'docs', 'DECISIONS.md'), 'utf8')
    const findings = decisionLogFindings(source)

    assert.deepEqual(findings, [], `\n${formatFindings(findings, 'docs/DECISIONS.md')}`)
})
