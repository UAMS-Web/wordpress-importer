#!/usr/bin/env node
/**
 * Structural guard for `docs/DECISIONS.md`, paired with the `merge=union` driver
 * that `.gitattributes` applies to that file.
 *
 * Union resolves a concurrent append without a conflict by keeping both sides,
 * ours then theirs — **on a local merge whose checkout carries the attribute**.
 * That bound is not incidental: git reads `.gitattributes` from the tree of the
 * checkout performing the merge, so the driver applies when merging on `main`
 * and not when syncing `main` into a branch cut before the attribute landed, and
 * GitHub's own server-side merge does not honour it at all. Measured in three
 * arms, control included, in `docs/DECISIONS.md` (wordpress-importer-archive#1091).
 *
 * This guard, by contrast, runs wherever it is invoked — so it checks the result
 * of a union merge that happened, not one that was assumed to have happened.
 * It does not understand the document it is merging, so the three things union
 * can break are structural. This checks exactly those.
 *
 * ## Why this is NOT `uams-statamic`'s `check-decision-log-order.mjs`
 *
 * That sibling keys on `^## (\d{4}-\d{2}-\d{2})` and enforces date order, which
 * is right for a log whose entries are dated. This log's are not: 47 of its 52
 * headings are claim-shaped (`## Versioning and releases`), and the 5 dated ones
 * are a six-day experiment — 2026-09-04 to 2026-09-10 — that the repository
 * stopped. Every entry written since is undated.
 *
 * So the sibling's check does not need porting here; it would have nothing to
 * check. Pointed at this file it matches 5 headings of 52 and passes silently on
 * the rest, which is a guard that cannot see rather than a guard that is green.
 *
 * And this log has no sort key at all — it is append-ordered — so union's
 * ours-then-theirs is correct either way and there is no ordering failure mode
 * to guard. What is left is structure.
 */

const HEADING = '## '

/**
 * Every structural finding in a decision log, as `{ line, kind, detail }`.
 *
 * `line` is 1-indexed, to match what an editor and a CI log will show.
 */
export function decisionLogFindings(source) {
    const lines = source.split('\n')
    const headings = []

    for (let i = 0; i < lines.length; i++) {
        if (lines[i].startsWith(HEADING)) {
            headings.push({ index: i, text: lines[i] })
        }
    }

    const findings = []
    const seen = new Map()

    for (let n = 0; n < headings.length; n++) {
        const { index, text } = headings[n]

        // Two branches recording the same decision. Union keeps both, and the
        // duplicate is the only trace that it happened.
        if (seen.has(text)) {
            findings.push({
                line: index + 1,
                kind: 'duplicate-heading',
                detail: `also at line ${seen.get(text) + 1}: ${text}`,
            })
        } else {
            seen.set(text, index)
        }

        // The separator union drops. Cosmetic in isolation and load-bearing in
        // aggregate: every future append removes one more, and the headings
        // gradually fuse to the entry above them.
        //
        // The first heading is exempt — the document's own preamble precedes it.
        if (n > 0 && lines[index - 1].trim() !== '') {
            findings.push({
                line: index + 1,
                kind: 'no-blank-line-before-heading',
                detail: text,
            })
        }

        // Union merges line by line, so two branches touching one region can
        // interleave into a heading with nothing under it.
        const end = n + 1 < headings.length ? headings[n + 1].index : lines.length
        if (lines.slice(index + 1, end).join('\n').trim() === '') {
            findings.push({ line: index + 1, kind: 'empty-section', detail: text })
        }
    }

    return findings
}

/**
 * The findings rendered for a terminal, one per line.
 */
export function formatFindings(findings, path) {
    return findings
        .map((f) => `  ${path}:${f.line}  ${f.kind}  ${f.detail}`)
        .join('\n')
}
