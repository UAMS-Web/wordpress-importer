# History — macOS and Windows are both supported; name the platform, write paths repo-relative

The incident history, measurements and worked examples behind [`supported-platforms.md`](../rules/supported-platforms.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`supported-platforms.md`](../rules/supported-platforms.md); this file is read when a rule is questioned, not on every turn.

## Overview

**Why this is a standing order.** This repo has already paid for the assumption twice, in both directions. The evidence behind [#506](https://github.com/UAMS-Web/wordpress-importer-archive/issues/506)'s corrections to the [`worktrees`](../rules/worktrees.md) rule was gathered on a **Windows** checkout, under a `GitHub Repos` directory on a lettered drive; the slot measurements in that same rule were taken on **macOS**, under a home directory. Neither machine's paths resolve on the other, and until [#597](https://github.com/UAMS-Web/wordpress-importer-archive/issues/597) the `worktrees` rule opened by naming that Windows path as *the* primary checkout — a sentence that was simply false on the machine most recently doing the work.

(This file deliberately describes those paths rather than reproducing them, so a repo-wide sweep for absolute machine paths stays clean and the one rule that forbids them is not its own only hit.)

The subtler half is the unqualified claim. [`long-running-commands`](../rules/long-running-commands.md) records the case in full: a `bound()` idiom reasoned from "macOS ships no `timeout(1)`" shipped as general advice and turned out to be a **silent no-op under Git Bash**, where Perl has no real `exec` and the pending alarm dies with the emulated one. A 10s bound on a 30s sleep ran the full 31s, and a soak band "bounded" at 5400s ran 6670s. Nothing errored. The advice was correct on the platform it was derived from and dangerous on the other, and nothing in its wording said which was which.

## The two obligations

   This is why the [`worktrees`](../rules/worktrees.md) rule labels every figure it quotes — the 5s provisioning time, the 109s submodule clone, the `git worktree remove --force` result — with its git version and platform, and explicitly flags the Windows figures as outstanding.
