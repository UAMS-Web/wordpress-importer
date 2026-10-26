// Tests for run-pint.mjs (wordpress-importer-archive#1319).
//
// Run: node --test scripts/run-pint.test.mjs
//
// dotDirectoryPaths is the pint job's dot-directory coverage. PHP-CS-Fixer's Finder skips
// dot-directories, so these paths are what the job names explicitly; an enumeration that
// silently returns nothing restores a job that reports success over unread files.
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'

import { dotDirectoryPaths, pintArgv } from './run-pint.mjs'

describe('dotDirectoryPaths', () => {
    it('finds the repository\'s own case', () => {
        assert.deepEqual(
            dotDirectoryPaths(['src/Foo.php', '.cursor/run-herd-mcp.php', '.cursor/run-boost-mcp.php']),
            ['.cursor'],
        )
    })

    it('returns nothing when no file sits under a dot-directory', () => {
        // The empty case must stay empty rather than degrade to a path that lints something unintended.
        assert.deepEqual(dotDirectoryPaths(['src/Foo.php', 'tests/Bar.php']), [])
        assert.deepEqual(dotDirectoryPaths([]), [])
    })

    it('does not treat a dot-FILE as a dot-directory', () => {
        // `.dotfile.php` is a hidden file in a visible directory, which the Finder reads.
        assert.deepEqual(dotDirectoryPaths(['.dotfile.php', 'src/.hidden.php']), [])
    })

    it('handles a nested dot-directory', () => {
        assert.deepEqual(dotDirectoryPaths(['src/.hidden/Nested.php', 'a/b/.deep/c/Thing.php']), ['a/b/.deep', 'src/.hidden'])
    })

    it('stops at the FIRST dot-segment', () => {
        // The outermost dot-directory already covers everything beneath it.
        assert.deepEqual(dotDirectoryPaths(['.a/.b/File.php']), ['.a'])
    })

    it('deduplicates and sorts', () => {
        assert.deepEqual(dotDirectoryPaths(['.z/One.php', '.a/Two.php', '.z/Three.php']), ['.a', '.z'])
    })
})

describe('pintArgv', () => {
    it('lints the whole tree in test mode, then names each dot-directory', () => {
        assert.deepEqual(pintArgv('/bin/php', ['.claude', '.cursor']), ['/bin/php', 'vendor/bin/pint', '--test', '.', '.claude', '.cursor'])
        assert.deepEqual(pintArgv('php', []), ['php', 'vendor/bin/pint', '--test', '.'])
    })
})
