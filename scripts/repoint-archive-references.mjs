#!/usr/bin/env node
/**
 * Re-point references to this repository's history at `wordpress-importer-archive` (#1427).
 *
 * This repository is renamed `UAMS-Web/wordpress-importer-archive` and a new repository
 * takes the old name (#1299). GitHub's rename redirect stops when the new repository is
 * created, so from then on a link to `UAMS-Web/wordpress-importer` resolves against the
 * new repository, where this one's closed issues, pull requests and commits do not exist.
 *
 * Two modes:
 *
 *   files   [--write]                 tracked files in this checkout
 *   bodies  --repo <owner/name> [--apply]   issue and pull-request bodies and comments
 *
 * Both print what they would change and change nothing without `--write` or `--apply`.
 *
 * ## What is re-pointed
 *
 * - `github.com/UAMS-Web/wordpress-importer/{pull,issues,commit,compare}/...`
 * - `github.com/UAMS-Web/wordpress-importer/{blob,tree}/<ref>/...` at any ref but `main`
 * - `UAMS-Web/wordpress-importer#N`, and the bare `wordpress-importer#N`
 * - in tracked files only, a bare `#N` that names this repository's history (below)
 *
 * The repository root, `blob/main` and `tree/main` are left alone: they mean the package
 * going forward, and the new repository's first commit carries the same tree.
 *
 * ## Which bare `#N` names this repository's history
 *
 * A bare `#N` in a tracked file is rewritten to `wordpress-importer-archive#N` when all of
 * these hold, and is reported as left otherwise:
 *
 * - `N` is at most the highest issue or pull-request number here, so it can name one;
 * - `N` is not an open issue, which moves to the new repository in #1300 and is rewritten
 *   there to its new number;
 * - the line names no other repository, where the number may well be that repository's;
 * - it is not the text of a Markdown link (`[#N](...)`), whose URL carries the target.
 *
 * In a body or comment a bare `#N` already resolves against the repository the body is
 * in, which is the archive after the rename, so the `bodies` mode leaves it alone.
 *
 * Commit messages cannot be edited and are out of reach of both modes.
 */

import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'

const OWNER = 'UAMS-Web'
const OLD = 'wordpress-importer'
const NEW = 'wordpress-importer-archive'

// Tracked paths the files mode leaves alone: the exports gitlink, and this script and its
// test, whose references are the rules' own examples
const SKIPPED = [
    'src/Services/WordPressImport/Tests/Exports',
    'scripts/repoint-archive-references.',
]

// The old name, not followed by anything that would make it a different repository
const NAME = `${OLD}(?![-\\w])`

/**
 * The rewrite rules shared by both modes, each a pattern and its replacement.
 */
export const RULES = [
    {
        cls: 'url',
        re: new RegExp(`(github\\.com/${OWNER}/)${NAME}(/(?:pull|issues|commit|compare)/)`, 'g'),
        to: `$1${NEW}$2`,
    },
    {
        cls: 'url_ref',
        re: new RegExp(`(github\\.com/${OWNER}/)${NAME}(/(?:blob|tree)/)(?!main(?:[/)\\s#]|$))`, 'g'),
        to: `$1${NEW}$2`,
    },
    {
        cls: 'qualified',
        re: new RegExp(`(?<![\\w./-])(${OWNER}/)${NAME}#(?=\\d)`, 'g'),
        to: `$1${NEW}#`,
    },
    {
        cls: 'short',
        re: new RegExp(`(?<![\\w./-])${NAME}#(?=\\d)`, 'g'),
        to: `${NEW}#`,
    },
]

// A bare reference: not part of a word, a numeric character reference or a link target
const BARE = /(?<![\w&-]|\]\()#(\d{2,4})\b(?!;)/g

// A line naming another repository, whose bare numbers may be that repository's
const OTHER_REPO = new RegExp(
    [
        'uams-statamic', 'uamswp-', 'uams-claude-skills', 'claude-skills', 'uams-2020', 'uams-2016',
        'People-Showcase', 'Find-a-Doc', 'wxr-cleanup', 'statamic-form-settings', 'roll-call',
        'business-card-portal', 'pestphp', 'statamic/cms', 'larastan', 'phpstan/', 'cweagans',
        `${OLD}-exports`, `(?<![\\w-])[\\w.-]+/[\\w.-]+#\\d`,
    ].join('|'),
    'i',
)

/**
 * Rewrite one text with the shared rules, counting each class's matches.
 *
 * @param {string} text  the text to rewrite
 * @param {Record<string, number>} counts  per-class counts, added to in place
 * @returns {string} the rewritten text
 */
export function rewriteLinks(text, counts = {}) {
    let out = text
    for (const { cls, re, to } of RULES) {
        const hits = out.match(re)
        if (hits) {
            counts[cls] = (counts[cls] ?? 0) + hits.length
            out = out.replace(re, to)
        }
    }

    return out
}

/**
 * Rewrite the bare `#N` references on one line that name this repository's history.
 *
 * @param {string} line  the line to rewrite
 * @param {{max: number, open: Set<number>}} history  the highest number here and the open issues
 * @param {Array<{n: number, why: string}>} left  every bare reference left, added to in place
 * @returns {{line: string, rewritten: number}} the line and how many references it changed
 */
export function rewriteBare(line, history, left = []) {
    const otherRepo = OTHER_REPO.test(line.replace(new RegExp(`${OWNER}/${NAME}`, 'g'), ''))
    let rewritten = 0
    const out = line.replace(BARE, (match, digits, offset) => {
        const n = Number(digits)
        const linkText = line[offset - 1] === '[' && line.startsWith(`${match}](`, offset)
        let why = null
        if (n > history.max) {
            why = 'above this repository\'s numbers'
        } else if (history.open.has(n)) {
            why = 'open issue, moves in #1300'
        } else if (linkText) {
            why = 'link text'
        } else if (otherRepo) {
            why = 'line names another repository'
        }
        if (why) {
            left.push({ n, why })

            return match
        }
        rewritten++

        return `${NEW}${match}`
    })

    return { line: out, rewritten }
}

/**
 * Run `gh` and return its standard output.
 *
 * @param {string[]} args  the arguments to `gh`
 * @param {string} [input]  standard input
 * @returns {string} the output
 */
function gh(args, input) {
    return execFileSync('gh', args, { encoding: 'utf8', input, maxBuffer: 1 << 28 })
}

/**
 * Read every page of a REST list endpoint, walking `page=N` until a short page.
 *
 * @param {string} path  the endpoint, without a query string's page parameters
 * @returns {Array<object>} every item
 */
function pages(path) {
    const sep = path.includes('?') ? '&' : '?'
    const all = []
    for (let page = 1; ; page++) {
        const items = JSON.parse(gh(['api', `${path}${sep}per_page=100&page=${page}`]))
        all.push(...items)
        if (items.length < 100) {
            return all
        }
    }
}

/**
 * The highest issue or pull-request number here, and the open issues.
 *
 * @returns {{max: number, open: Set<number>}} the history bounds
 */
function readHistory() {
    const newest = JSON.parse(gh(['api', `repos/${OWNER}/${OLD}/issues?state=all&per_page=1`]))
    const open = pages(`repos/${OWNER}/${OLD}/issues?state=open`)
        .filter((issue) => !issue.pull_request)
        .map((issue) => issue.number)

    return { max: newest[0].number, open: new Set(open) }
}

/**
 * Rewrite this checkout's tracked files, skipping files the shared sync manages.
 *
 * @param {boolean} write  whether to write the files
 */
function files(write) {
    const root = execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim()
    const synced = new Set(JSON.parse(readFileSync(join(root, '.claude/sync-manifest.jsonc'), 'utf8')).files)
    const tracked = execFileSync('git', ['-C', root, 'ls-files', '-z'], { encoding: 'utf8', maxBuffer: 1 << 28 })
        .split('\0')
        .filter((f) => f && !synced.has(f) && !SKIPPED.some((prefix) => f.startsWith(prefix)))
    const history = readHistory()
    const counts = {}
    const left = []
    let changed = 0
    for (const file of tracked) {
        const path = join(root, file)
        let text
        try {
            text = readFileSync(path, 'utf8')
        } catch {
            continue
        }
        if (text.includes('\0')) {
            continue
        }
        const out = rewriteLinks(text, counts)
            .split('\n')
            .map((line, i) => {
                const fileLeft = []
                const result = rewriteBare(line, history, fileLeft)
                counts.bare = (counts.bare ?? 0) + result.rewritten
                left.push(...fileLeft.map((l) => ({ ...l, at: `${file}:${i + 1}` })))

                return result.line
            })
            .join('\n')
        if (out !== text) {
            changed++
            if (write) {
                writeFileSync(path, out)
            }
        }
    }
    console.log(`history: highest number ${history.max}, ${history.open.size} open issues`)
    console.log(`files scanned: ${tracked.length} (synced files skipped: ${synced.size})`)
    console.log(`files ${write ? 'changed' : 'to change'}: ${changed}`)
    console.log(`rewritten by class: ${JSON.stringify(counts)}`)
    const byWhy = {}
    for (const l of left) {
        byWhy[l.why] = (byWhy[l.why] ?? 0) + 1
    }
    console.log(`bare references left by reason: ${JSON.stringify(byWhy)}`)
    for (const l of left.filter((x) => x.why === 'line names another repository')) {
        console.log(`  left #${l.n} at ${l.at}`)
    }
}

/**
 * Rewrite a repository's issue and pull-request bodies and comments.
 *
 * Each body is read again immediately before it is written, and the write is skipped
 * when that read no longer matches what was rewritten, so a concurrent edit is never lost.
 *
 * @param {string} repo  the repository, as owner/name
 * @param {boolean} apply  whether to write the edits
 */
function bodies(repo, apply) {
    const issues = pages(`repos/${repo}/issues?state=all`)
    const comments = pages(`repos/${repo}/issues/comments`)
    const reviewComments = pages(`repos/${repo}/pulls/comments`)
    console.log(`read: ${issues.length} issues and pull requests, ${comments.length} comments, ${reviewComments.length} review comments`)
    const targets = [
        ...issues.map((i) => ({ url: `repos/${repo}/issues/${i.number}`, body: i.body })),
        ...comments.map((c) => ({ url: `repos/${repo}/issues/comments/${c.id}`, body: c.body })),
        ...reviewComments.map((c) => ({ url: `repos/${repo}/pulls/comments/${c.id}`, body: c.body })),
    ]
    const counts = {}
    let edits = 0
    for (const target of targets) {
        if (!target.body) {
            continue
        }
        const out = rewriteLinks(target.body, counts)
        if (out === target.body) {
            continue
        }
        edits++
        console.log(`edit ${target.url}`)
        for (const [i, line] of target.body.split('\n').entries()) {
            const next = out.split('\n')[i]
            if (next !== line) {
                console.log(`  - ${line}\n  + ${next}`)
            }
        }
        if (!apply) {
            continue
        }
        const current = JSON.parse(gh(['api', target.url])).body
        if (current !== target.body) {
            console.log('  skipped: the body changed since it was read')
            continue
        }
        gh(['api', '-X', 'PATCH', target.url, '--input', '-'], JSON.stringify({ body: out }))
    }
    console.log(`${apply ? 'edited' : 'to edit'}: ${edits}; rewritten by class: ${JSON.stringify(counts)}`)
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const [mode, ...rest] = process.argv.slice(2)
    if (mode === 'files') {
        files(rest.includes('--write'))
    } else if (mode === 'bodies' && rest.includes('--repo')) {
        bodies(rest[rest.indexOf('--repo') + 1], rest.includes('--apply'))
    } else {
        console.error('usage: repoint-archive-references.mjs files [--write] | bodies --repo <owner/name> [--apply]')
        process.exit(2)
    }
}
