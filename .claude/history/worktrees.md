# History — work from stable, pre-bootstrapped worktree slots (never share the primary checkout)

The incident history, measurements and worked examples behind [`worktrees.md`](../rules/worktrees.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`worktrees.md`](../rules/worktrees.md); this file is read when a rule is questioned, not on every turn.

## Overview

**Why slots rather than a worktree per ticket.** The isolation itself is not in question: two sessions on one working copy collide by construction — a branch checkout in one swaps the tree out from under the other, one session's `git stash` gets popped by the other, a stray half-finished file rides along in the other's commit. What changed is the *unit* of isolation, because a fresh worktree is not free:

- **It costs a bootstrap** — a `composer install` into a 208 MB `vendor/` tree — every time, for work that is usually sequential anyway.
- **It re-opens every bootstrap failure mode this file documents**, and those are the expensive part. A `vendor/` symlinked from another tree silently loads the *donor's* Pest bootstrap; a directory name starting with a digit fatals every test before it runs. Each detour costs far more than the install it was trying to skip.
- **It changes the session's working-directory string**, which is part of the cached prompt prefix — so a never-before-seen path starts every session cold. A fixed set of paths lets that prefix recur. (Secondary: the cache TTL is an hour, so this pays within a working day, not across days. The bootstrap and the failure modes above are the dominant cost.)

A slot is bootstrapped once, so the exception that used to justify staying in the primary for solo work — "don't pay the worktree cost" — no longer exists. There is nothing left to pay.

## Claiming and releasing a slot

Three things go wrong when this is skipped, and all three are silent:

Creating that ref is the gate operator's own git write, in the primary, on a ref nobody else owns. The runner only ever *reads* refs — it does not create or move one, least of all an author's.

## Keeping a slot current — the refresh gate

**The verdict names the trees and their drift, not just the word.** The drift was already visible in the table on that evening — a `2 behind origin/main` cell, published in the same message as `check-exit=0` — and it was read as informational because the exit code appeared to overrule it. A bare `DRIFT` would be one more signal to reconcile against the same wrong one.

This exists because [`cutting-releases`](../rules/cutting-releases.md) makes a missing tag load-bearing: `uams-statamic` pins this package by a tagged caret range, so merged work is unreachable by the app until a tag exists. That gap is invisible from inside a session by construction — `main` is current, the tree is clean, the gate is green, and every per-tree signal reads at rest while the shipped surface is months of work behind. Measured on 2026-09-06, the distance was **139 commits over 15 days**, and a method renamed in that window was diagnosed in the host app as a scope bug rather than a version gap, with a wrong fix pushed before the cause was found.

## Provisioning a slot (one time, and once only)

Two measured reasons it is on demand rather than standard (macOS, same git):

## A submodule behind its gitlink, and the `git add -A` that commits the revert

**Reproduced in a real slot, 2026-09-25, Windows 11, Git Bash, git 2.32.0.windows.2**, with the submodule initialized from the primary's objects (`--reference`, then `--dissociate`, so no network clone and no lasting dependency):

```
1  slot detached at 9bd1b6a, the commit before wordpress-importer-archive#1138      submodule checked out at cb818222c
2  checkout --no-recurse-submodules to a branch on main   branch wants 2dc5f67a9; submodule still cb818222c
                                                          git status:  M src/Services/WordPressImport/Tests/Exports
3  a real edit, then git add -A                           staged: the edit AND
                                                            -Subproject commit 2dc5f67a9...
                                                            +Subproject commit cb818222c...
4  git commit, new hook                                   refused, rc 1, both revisions named; HEAD unmoved
5  git restore --staged -- <exports path>, commit         the edit alone; gitlink stays 2dc5f67a9
6  control: same staging, committed under main's hook     rc 0, and the commit records cb818222c over its
                                                          parent's 2dc5f67a9 -- the silent revert
```

The control is what makes step 4 a finding rather than an assumption: without the gate the same staging commits cleanly and records the revert. **Not measured on macOS.** The mechanism is git's, not the platform's, but the reproduction ran on one machine.

## File-tool vs. shell-cwd hazards (harness)

  The macOS rows are measured here; the Git Bash rows come from the report on [#979](https://github.com/UAMS-Web/wordpress-importer-archive/issues/979). **A probe that confirms the documentation on one platform and cannot see on the other is how the claim it replaced went unchallenged** — anyone re-checking reaches for `grep` first, and under Git Bash it agrees with whatever they already believed.
