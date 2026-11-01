// Unit tests for watch-github.mjs.
// Run: node --test scripts/watch-github.test.mjs
//
// Uses the Node built-in test runner rather than vitest, matching the other scripts/*.test.mjs files:
// this package has no package.json and no JS dependency tree.
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'

import { classifyIssue, isPush, main, parseArgs, pollAll, watchingEvent } from './watch-github.mjs'

// The issue watcher polls by `updated_at` watermark, so every poll returns a superset of what it
// should announce: anything touched since the mark, including label edits and comments. These
// assert the three outcomes that superset has to collapse to — opened, closed, nothing — and the
// boundary conditions that decide between them.

const SINCE = '2026-07-29T00:00:00Z'

const issue = (overrides) => ({
    state: 'open',
    createdAt: '2026-01-01T00:00:00Z',
    closedAt: null,
    stateReason: null,
    ...overrides,
})

describe('classifyIssue', () => {
    it('announces an issue created after the watermark as opened', () => {
        assert.deepEqual(
            classifyIssue(issue({ createdAt: '2026-07-30T09:00:00Z' }), SINCE),
            { kind: 'ISSUE', transition: 'opened' },
        )
    })

    it('announces an issue closed after the watermark as gone', () => {
        assert.deepEqual(
            classifyIssue(issue({ state: 'closed', closedAt: '2026-07-30T09:00:00Z', stateReason: 'completed' }), SINCE),
            { kind: 'ISSUE-GONE', transition: 'completed' },
        )
    })

    it('distinguishes a not-planned closure from a completed one', () => {
        assert.deepEqual(
            classifyIssue(issue({ state: 'closed', closedAt: '2026-07-30T09:00:00Z', stateReason: 'not_planned' }), SINCE),
            { kind: 'ISSUE-GONE', transition: 'not planned' },
        )
    })

    // The watermark query returns everything *updated*, so an untouched-but-commented issue is the
    // common case. Announcing it would be a false event on every poll it appears in.
    it('ignores an old open issue that merely changed', () => {
        assert.equal(classifyIssue(issue({}), SINCE), null)
    })

    // A closure predating the watermark was already announced in an earlier window.
    it('ignores a closure older than the watermark', () => {
        assert.equal(
            classifyIssue(issue({ state: 'closed', closedAt: '2026-01-02T00:00:00Z', stateReason: 'completed' }), SINCE),
            null,
        )
    })

    // Opened and closed inside one interval: the closure is the actionable state, and an "opened"
    // that is already stale helps no consumer.
    it('reports an issue opened and closed within one interval as closed only', () => {
        const sameWindow = issue({
            state: 'closed',
            createdAt: '2026-07-30T09:00:00Z',
            closedAt: '2026-07-30T09:30:00Z',
            stateReason: 'completed',
        })

        assert.deepEqual(classifyIssue(sameWindow, SINCE), { kind: 'ISSUE-GONE', transition: 'completed' })
    })

    // GitHub's `since` filter is inclusive, so an issue sitting exactly on the mark comes back.
    it('treats a timestamp exactly on the watermark as in-window', () => {
        assert.deepEqual(
            classifyIssue(issue({ createdAt: SINCE }), SINCE),
            { kind: 'ISSUE', transition: 'opened' },
        )
    })

    // A closed issue with no `closed_at` must not fall through to a comparison against null.
    it('does not announce a closed issue that carries no closure timestamp', () => {
        assert.equal(classifyIssue(issue({ state: 'closed', closedAt: null }), SINCE), null)
    })
})

describe('parseArgs', () => {
    it('watches pull requests across the two default repos when given no flags', () => {
        const options = parseArgs([])

        assert.equal(options.prs, true)
        assert.equal(options.issues, false)
        assert.equal(options.repos.length, 2)
    })

    it('turns pull-request watching off with --no-prs', () => {
        assert.equal(parseArgs(['--no-prs', '--issues']).prs, false)
    })

    it('accepts --no-prs alongside --issue-repos, which implies --issues', () => {
        const options = parseArgs(['--no-prs', '--issue-repos', 'UAMS-Web/aether-wordpress-importer'])

        assert.equal(options.prs, false)
        assert.equal(options.issues, true)
        assert.deepEqual(options.issueRepos, ['UAMS-Web/aether-wordpress-importer'])
    })

    it('resolves each feed to the repositories it will poll', () => {
        assert.deepEqual(parseArgs(['--no-prs', '--issues']).prRepos, [])
        assert.equal(parseArgs(['--issues']).issueRepos.length, 2)
        assert.deepEqual(parseArgs([]).issueRepos, [])
    })

    // Both feeds empty polls nothing at all, which from the outside is indistinguishable from a
    // watcher whose repos are simply quiet.
    it('rejects --no-prs on its own, which would watch nothing', () => {
        assert.throws(() => parseArgs(['--no-prs']), /nothing to watch/)
    })

    // The guard has to test the resolved feeds, not the flags: a blank or comma-only value filters
    // to an empty list while the flag implying it still reads as on.
    for (const argv of [
        ['--repos', ''],
        ['--repos', ',,'],
        ['--no-prs', '--issue-repos', ''],
        ['--repos', '', '--issue-repos', ''],
    ]) {
        it(`rejects ${JSON.stringify(argv)}, whose repository lists all resolve empty`, () => {
            assert.throws(() => parseArgs(argv), /nothing to watch/i)
        })
    }
})

/**
 * A stand-in for the `gh` child process that records the REST path of every request handed to it.
 * `--gh` cannot be pointed at a Node script cross-platform, so the invoker is injected instead.
 */
const recordRequests = () => {
    const requests = []

    return {
        requests,
        exec: async (gh, args) => {
            requests.push(args.find((argument) => argument.startsWith('repos/')) ?? args.join(' '))

            return { stdout: '' }
        },
    }
}

const matching = (requests, fragment) => requests.filter((request) => request.includes(fragment))

// Whether a feed is really off is a question about the requests the watcher makes, not about the
// flags it parsed — so these drive the seed pass and then `pollAll`, which is verbatim what every
// interval of the steady-state loop runs.
describe('feed selection', () => {
    it('never requests the pulls endpoint when run issues-only', async () => {
        const { requests, exec } = recordRequests()

        await main(['--once', '--no-prs', '--issue-repos', 'UAMS-Web/aether-wordpress-importer'], { exec })

        // The seed pass only sets the issue watermark, so a correct issues-only run asks nothing.
        assert.deepEqual(requests, [])

        requests.length = 0
        await pollAll()

        assert.deepEqual(matching(requests, '/pulls'), [])
        assert.equal(matching(requests, '/issues').length, 1)
    })

    it('keeps polling pull requests across the two default repos when run bare', async () => {
        const { requests, exec } = recordRequests()

        await main(['--once'], { exec })

        assert.equal(matching(requests, '/pulls').length, 2)
        assert.deepEqual(matching(requests, '/issues'), [])

        requests.length = 0
        await pollAll()

        assert.equal(matching(requests, '/pulls').length, 2)
        assert.deepEqual(matching(requests, '/issues'), [])
    })

    it('polls each feed on its own repo list when both are live', async () => {
        const { requests, exec } = recordRequests()

        await main([
            '--once',
            '--repos', 'UAMS-Web/aether-wordpress-importer,UAMS-Web/uams-statamic',
            '--issue-repos', 'UAMS-Web/aether-wordpress-importer',
        ], { exec })

        requests.length = 0
        await pollAll()

        assert.equal(matching(requests, '/pulls').length, 2)
        assert.equal(matching(requests, '/issues').length, 1)
    })
})

describe('watchingEvent', () => {
    const seed = async (argv) => {
        const { exec } = recordRequests()

        await main(['--once', ...argv], { exec })
    }

    it('reports the bare default as pull requests on two repos, issues off', async () => {
        await seed([])

        assert.deepEqual(watchingEvent(60), {
            subject: 'PRs on 2 repos, issues off',
            transition: 'every 60s',
            detail: 'open PRs at start — wordpress-importer:0, wordpress-importer-exports:0',
        })
    })

    it('names an issues-only watcher, with no open-PR count to report', async () => {
        await seed(['--no-prs', '--issue-repos', 'UAMS-Web/aether-wordpress-importer'])

        assert.deepEqual(watchingEvent(30), {
            subject: 'PRs off, issues on 1 repo',
            transition: 'every 30s',
            detail: 'issues on wordpress-importer',
        })
    })

    // The single-repo watcher is common enough that `1 repos` reads as a bug in the watcher itself.
    it('pluralizes a one-repo feed as `repo` and a many-repo feed as `repos`', async () => {
        await seed(['--repos', 'UAMS-Web/aether-wordpress-importer,UAMS-Web/uams-statamic', '--issue-repos', 'UAMS-Web/aether-wordpress-importer'])

        assert.equal(watchingEvent(60).subject, 'PRs on 2 repos, issues on 1 repo')
        assert.equal(
            watchingEvent(60).detail,
            'open PRs at start — wordpress-importer:0, uams-statamic:0; issues on wordpress-importer',
        )
    })
})


// isPush decides whether an open pull request's head moved between polls. Every case below is
// about what must NOT fire: a false positive announces a push that did not happen, and on the
// first poll after an upgrade it would announce one for every open pull request at once.
describe('isPush', () => {
    const at = (sha, draft = false) => ({ sha, draft })

    it('fires when the head of a non-draft pull request moves', () => {
        assert.equal(isPush(at('aaaaaaaaa'), at('bbbbbbbbb')), true)
    })

    it('does not fire when the head is unchanged', () => {
        assert.equal(isPush(at('aaaaaaaaa'), at('aaaaaaaaa')), false)
    })

    it('does not fire when either side carries no SHA', () => {
        // `undefined` is a projection recorded before this field existed; `null` is a poll that
        // could not read one. Treating either as changed is the first-poll-after-upgrade storm.
        assert.equal(isPush(at(undefined), at('bbbbbbbbb')), false)
        assert.equal(isPush(at('aaaaaaaaa'), at(undefined)), false)
        assert.equal(isPush(at(null), at('bbbbbbbbb')), false)
        assert.equal(isPush(at('aaaaaaaaa'), at(null)), false)
    })

    it('does not fire when the whole projection is missing on either side', () => {
        // `before` is undefined for a pull request seen for the first time, which the caller
        // reports as opened rather than pushed.
        assert.equal(isPush(undefined, at('bbbbbbbbb')), false)
        assert.equal(isPush(at('aaaaaaaaa'), undefined), false)
    })

    it('does not fire while either side is a draft', () => {
        // A draft is not in the queue, so a push to one has nothing to re-enter.
        assert.equal(isPush(at('aaaaaaaaa', true), at('bbbbbbbbb', false)), false)
        assert.equal(isPush(at('aaaaaaaaa', false), at('bbbbbbbbb', true)), false)
        assert.equal(isPush(at('aaaaaaaaa', true), at('bbbbbbbbb', true)), false)
    })

    it('yields to a draft flip that carries a push with it', () => {
        // The flip is the larger fact and is already reported by the caller; reporting both
        // would put one pull request in the queue twice for a single change.
        assert.equal(isPush(at('aaaaaaaaa', true), at('bbbbbbbbb', false)), false)
    })

    it('treats an empty-string SHA as absent rather than as a value', () => {
        assert.equal(isPush(at(''), at('bbbbbbbbb')), false)
        assert.equal(isPush(at('aaaaaaaaa'), at('')), false)
    })
})
