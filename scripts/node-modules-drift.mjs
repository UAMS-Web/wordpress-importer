// node-modules-drift.mjs: does a tree's installed node_modules/ match its package-lock.json?
//
// Shared by scripts/prepare-dependencies.mjs (the CI tree) and scripts/worktree-slots.mjs (the
// slots), which both skip `npm ci` when node_modules/ is already there. Presence alone was the old
// test, and it let a pull request that moved `cspell` be validated with the version a tree last
// installed (wordpress-importer-archive#1335). A module of its own because the private runner that first used it imported
// worktree-slots.mjs, and an import the other way would have made a cycle.

/**
 * Why the installed `node_modules/` differs from `package-lock.json`, or null when it matches.
 *
 * npm records what it installed in `node_modules/.package-lock.json`, in the same `packages` shape
 * the lockfile uses, so the two can be compared without running npm (wordpress-importer-archive#1335). Every package the
 * lockfile pins under `node_modules/` must be installed at the same version and integrity.
 * Packages marked `optional` are left out: npm skips one that does not fit the platform, and
 * counting that as drift would reinstall on every run. A record that is missing or unreadable is
 * drift, because nothing then says what is installed.
 *
 * @param  ?string  lockText    the contents of package-lock.json, or null when it could not be read
 * @param  ?string  hiddenText  the contents of node_modules/.package-lock.json, or null when absent
 * @return ?string              the first difference found, or null when the tree matches
 */
export function nodeModulesDrift(lockText, hiddenText) {
    if (hiddenText === null) {
        return 'node_modules/.package-lock.json is missing'
    }

    let lock
    let hidden
    try {
        lock = JSON.parse(lockText ?? '').packages
        hidden = JSON.parse(hiddenText).packages
    } catch {
        return 'package-lock.json or node_modules/.package-lock.json could not be parsed'
    }

    if (!lock || typeof lock !== 'object' || !hidden || typeof hidden !== 'object') {
        return 'a lockfile has no packages map'
    }

    for (const [key, entry] of Object.entries(lock)) {
        if (!key.startsWith('node_modules/') || entry?.optional) {
            continue
        }

        const installed = hidden[key]
        if (!installed) {
            return `${key} is not installed`
        }

        if (installed.version !== entry.version || installed.integrity !== entry.integrity) {
            return `${key} is ${installed.version ?? 'unknown'}, the lockfile pins ${entry.version ?? 'unknown'}`
        }
    }

    return null
}
