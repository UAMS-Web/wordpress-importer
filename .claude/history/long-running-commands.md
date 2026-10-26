# History — bound every long-running command, and resolve what you kill

The incident history, measurements and worked examples behind [`long-running-commands.md`](../rules/long-running-commands.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`long-running-commands.md`](../rules/long-running-commands.md); this file is read when a rule is questioned, not on every turn.

## Overview

**Why this is a standing order.** A detached multi-minute run does not sit still. It contends with every run you start next, so the next thing you time comes back slower and you draw the wrong conclusion from it; and it holds the files and caches the next run wants. Four processes were stranded this way in a single session in the sibling app — a prior session's abandoned run, an import test that "timed out" into the background, a stale waiter, and a monitor — and between them they poisoned enough timings to send an afternoon chasing a performance problem that did not exist. Nothing warns you: the tool result says the command ended, and it is telling the truth about the *wrapper*, not about the work.

## How to apply

   Measured on macOS (Darwin 25.6.0), 2026-09-04, with three concurrent sessions live in this repository — each session's long-lived process, resolved with `lsof -a -p <pid> -d cwd`:

   ```
   session   resolved cwd
   -------   -----------------------
   first     …/wordpress-importer     <- the primary
   second    …/wordpress-importer     <- the primary
   third     …/wordpress-importer     <- the primary
   ```

   **Six processes at one path** — each session contributes a wrapper shell and its child, because the wrapper's command line carries the full command and `pgrep -f` matches both. The paths are byte-identical, so the `"$MY_TREE"|"$MY_TREE"/*` arm in step 6 matches all six: **a sweep written exactly as documented kills two other sessions' processes and reports that it touched only its own.**

   This is **not** the prefix trap step 6 already documents. That one is about the primary's path being a string *prefix* of each slot's, and anchoring on the separator fixes it. This is three sessions at the **same** path, where no anchoring helps.

   The three arms of the corrected guard, run with the control asserted visible *before* any result was read — Git Bash on Windows 11, one live competing process:

   | Control process | `MY_TREE` | Result |
   | --- | --- | --- |
   | live, in `-ci` | the `-ci` slot | `ABORTED: 1 competing run`, exit 3, names pid and path |
   | live, in `-ci` | the `-a` slot | clear, exit 0 — correctly does not over-claim a sibling |
   | gone | the `-ci` slot | clear, exit 0 |

   The middle arm is what makes the first meaningful: a guard that aborted on both would be indistinguishable from one that aborts on anything.
