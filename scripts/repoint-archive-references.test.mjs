import { test } from 'node:test'
import assert from 'node:assert/strict'
import { rewriteBare, rewriteLinks } from './repoint-archive-references.mjs'

const HISTORY = { max: 1431, open: new Set([1300]) }

test('history links move to the archive', () => {
    const counts = {}
    const out = rewriteLinks(
        [
            'https://github.com/UAMS-Web/wordpress-importer/pull/705',
            'https://github.com/UAMS-Web/wordpress-importer/issues/5#issuecomment-1',
            'https://github.com/UAMS-Web/wordpress-importer/commit/abc123',
            'https://github.com/UAMS-Web/wordpress-importer/blob/1420-branch/patches.json',
            'UAMS-Web/wordpress-importer#849 and wordpress-importer#12',
        ].join('\n'),
        counts,
    )
    assert.equal(out, [
        'https://github.com/UAMS-Web/wordpress-importer-archive/pull/705',
        'https://github.com/UAMS-Web/wordpress-importer-archive/issues/5#issuecomment-1',
        'https://github.com/UAMS-Web/wordpress-importer-archive/commit/abc123',
        'https://github.com/UAMS-Web/wordpress-importer-archive/blob/1420-branch/patches.json',
        'UAMS-Web/wordpress-importer-archive#849 and wordpress-importer-archive#12',
    ].join('\n'))
    assert.deepEqual(counts, { url: 3, url_ref: 1, qualified: 1, short: 1 })
})

test('links that follow the package stay, as do other repositories', () => {
    const kept = [
        'https://github.com/UAMS-Web/wordpress-importer',
        'https://github.com/UAMS-Web/wordpress-importer/blob/main/README.md',
        'https://github.com/UAMS-Web/wordpress-importer/tree/main/src',
        'UAMS-Web/wordpress-importer-exports#3',
        'UAMS-Web/wordpress-importer-archive#4',
        'uams-web/wordpress-importer',
    ].join('\n')
    assert.equal(rewriteLinks(kept), kept)
})

test('a bare reference to this repository\'s history is qualified', () => {
    assert.deepEqual(rewriteBare('see #464 and (#624/#628).', HISTORY), {
        line: 'see wordpress-importer-archive#464 and (wordpress-importer-archive#624/wordpress-importer-archive#628).',
        rewritten: 3,
    })
})

test('a bare reference is left, with its reason, when it may not be this repository\'s', () => {
    const cases = [
        ['an open issue moves: #1300', 'open issue, moves in #1300'],
        ['above the range: #2834', 'above this repository\'s numbers'],
        ['link text: [#808](https://example.test/808)', 'link text'],
        ['UAMS-Web/uams-statamic#2151 and #842', 'line names another repository'],
    ]
    for (const [line, why] of cases) {
        const left = []
        assert.deepEqual(rewriteBare(line, HISTORY, left), { line, rewritten: 0 }, line)
        assert.equal(left.at(-1).why, why, line)
    }
})

test('a numeric character reference, a word and an anchor are not references', () => {
    for (const line of ['Jerry&amp;#039;s', 'Jerry&#039;s', 'abc#123', 'see [the part](#12)']) {
        assert.deepEqual(rewriteBare(line, HISTORY), { line, rewritten: 0 }, line)
    }
})
