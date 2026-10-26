# History — sync every PR branch from `origin/main` before the gating run

The incident history, measurements and worked examples behind [`sync-pr-branch.md`](../rules/sync-pr-branch.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`sync-pr-branch.md`](../rules/sync-pr-branch.md); this file is read when a rule is questioned, not on every turn.

## Overview

**Why this is a standing order.** A branch left behind `origin/main` is validated against stale code. When it touches a surface a since-merged change also touched — e.g. a file reformatted by an `src/`-wide Pint/PHPStan sweep, or a parser a sibling ticket refactored — it can merge to a combined state that was **never tested together**: semantic drift, or un-formatted files slipping past the gate. The failure is silent — the branch's own suite stays green because it never saw the interacting change. Observed in the sibling app (`uams-statamic` PR #1073): a branch sat **43 commits behind `origin/main`**, missing an app-wide reformatting sweep that had touched the same surface, while the concurrent wave PRs were current (0 behind).
