# History — keep session-coordination plumbing out of GitHub artifacts and documentation

The incident history, measurements and worked examples behind [`coordination-plumbing-stays-out-of-artifacts.md`](../rules/coordination-plumbing-stays-out-of-artifacts.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`coordination-plumbing-stays-out-of-artifacts.md`](../rules/coordination-plumbing-stays-out-of-artifacts.md); this file is read when a rule is questioned, not on every turn.

## Why this is a standing order

The failure is not hypothetical and it is not obvious while writing. `UAMS-Web/wordpress-importer-archive#849` was filed with a machine alias in its measurement line, three session labels in its evidence table, and a consequence argued entirely through liveness vocabulary — a chain from "a swept process stops publishing" to "its owner reads as stale" to "a takeover then looks justified." Every step was true, and none of it was checkable by anyone reading the tracker.

**The rewrite is the argument for this rule.** The same defect, restated as "three long-running background processes, each started by a different concurrent session and distinguishable only by their command-line arguments," kept every process id, path, date and platform, and lost nothing. Its consequence was re-argued from harms the repo already documents — a killed run destroys what it was going to emit, a benchmark taken beside the wreckage is not a benchmark, an interrupted correctness run returns failures indistinguishable from regressions. That version is **stronger**, because it stands on things the reader can check.
