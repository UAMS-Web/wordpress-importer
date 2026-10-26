<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/rules/worktrees.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): core.
-->
<!-- cspell:ignore analyse backgrounded deinit Deinit fatals pasteable pathspec rebuildable reflog -->
# Rule — work from stable, pre-bootstrapped worktree slots (branch work never happens in the primary checkout)

Branch work happens in one of a **fixed, small set of long-lived worktrees** ("slots") that are bootstrapped once and kept current, never in a worktree created for the ticket and thrown away after it. The primary checkout (the repo root of your main clone; its absolute path differs per machine and per platform, so this rule never names one) is the reference tree: it **holds the `main` branch**, current with `origin/main`, it is the diff base, and branch work does not happen there.

**Why slots rather than a worktree per ticket.** Two sessions on one working copy collide by construction: a branch checkout in one swaps the tree out from under the other, one session's `git stash` gets popped by the other, a stray half-finished file rides along in the other's commit. So work is isolated per tree; the unit is a long-lived slot rather than a fresh worktree, because a fresh worktree is not free:

- **It costs a bootstrap** (`composer install` and whatever else the repository's bootstrap needs (see *Provisioning a slot*)) every time, for work that is usually sequential anyway.
- **It re-opens every bootstrap failure mode this file documents**, and those are the expensive part. A `vendor/` junction silently loads the donor worktree's Pest bootstrap; a directory name starting with a digit fatals every test before it runs. Each detour costs far more than the install it was trying to skip.
- **It changes the session's working-directory string**, which is part of the cached prompt prefix, so a never-before-seen path starts every session cold. A fixed set of paths lets that prefix recur. (Secondary: the cache TTL is an hour, so this pays within a working day, not across days. The bootstrap and the failure modes above are the dominant cost.)

A slot is bootstrapped once, so solo work has no cost reason to stay in the primary either.

History: [worktrees.md § Overview](../history/worktrees.md#overview).

## The slots

The set is fixed and small: the primary and two work slots (**A** and **B**). A repository may add one more tree for a specific job. Where the slots sit is decided per repository, because it depends on what scans the directory the primary lives in. A repository with no slots yet sets them up as *Provisioning a slot* describes.

Here the local CI runner also keeps a tree of its own, listed below; see *The CI tree belongs to the runner*.

**Slot names must not begin with a digit**: Pest builds its generated test class's namespace from the file path with separators stripped, so a path segment starting with a digit is an invalid PHP namespace and every test in the tree dies with `Unable to create test case for test file at …` before it runs. Names of the form `<repo>-a` satisfy this by construction; the constraint only bites when you name an *ephemeral* worktree after a ticket number (see below).

Four trees, all **siblings** of each other:

| Tree | Path (relative to the primary) | Role |
| --- | --- | --- |
| Primary | *the main clone itself* | Reference checkout. **Holds the `main` branch**, current with `origin/main`. The diff base. Not a work surface. |
| Slot A | `../wordpress-importer-a` | Ticket or wave work. Take this one first. |
| Slot B | `../wordpress-importer-b` | A second concurrent session, or a second ticket. |
| Local CI | `../wordpress-importer-ci` | Owned by [`scripts/local-ci.mjs`](../../scripts/local-ci.mjs) (`--branch`). **Never hand-drive it**: the runner checks branches in and out of it. |

**They are siblings, not nested under `.claude/worktrees/`, for three reasons**, and the host app's fourth reason does **not** apply here, so do not inherit it. They sit outside the repo, so no ignore rule has to hold for them; a search from the primary cannot descend into them; and it matches the sibling path the local-CI runner already derives. The host app additionally gets each sibling auto-served by Herd at `<dirname>.test`: **this package is never served**. There is no `.test` host, no port, no `APP_URL`, and nothing to visit. A slot here is a place to run `composer`, `pest`, `pint` and `phpstan`, and nothing else.

## Which tree does the work

- **All branch work → a slot.** Claim one, work in it, release it. This holds for a solo session as much as a concurrent one: a session that never touches the primary cannot collide with one that does, and the slot is already bootstrapped so there is no reason to prefer the primary.
- **The primary → `main`, and left there.** It is the tree to diff against. Leaving it on a ticket branch also destroys the oldest drift signal there is: "the primary is on a branch I did not set" no longer means anything if you routinely put it there yourself.

- **Local CI → its own tree, driven only by the runner.** The runner resolves its base from the primary, one more reason the primary stays on `main`. See *The CI tree belongs to the runner* below and the `local-ci` skill.

**Detected churn still means isolate first.** If you observe a slot moving under you: the branch changed, untracked files you did not create appear, `git worktree list` shows a slot on a branch you did not set: treat it as a live second session on that slot and take the other one, or an ephemeral worktree if both are held.

## A slot at rest, and how occupancy is read

**A slot at rest is detached at `origin/main`, with a clean tree: both halves, not one.** Git allows a branch to be checked out in exactly one worktree, so `main` can only ever live in one tree: the primary. Two slots both "on `main`" is not a state that exists; `git worktree add` refuses it outright (`fatal: 'main' is already used by worktree at …`). Detaching sidesteps the constraint entirely and costs nothing, because a slot at rest is not a place work happens.

There is no lock file and there does not need to be one: **the slot's own branch is the occupancy signal.** A slot holding a branch is a slot in use, and `git worktree list` answers that half. But it reports the checked-out ref alone; it says nothing about the index or the working tree, so a slot that is detached and carries **uncommitted work** reads exactly like a genuinely free one to that command. Read both:

```bash
git worktree list
git -C <slot-path> status --porcelain --untracked-files=all
```

A slot is free only when it is detached **and** that second command prints nothing. A slot on a `<N>-<slug>` branch is **held**: by you earlier, or by another session right now.

- **Leave a slot detached when you finish with it**: one left on a merged branch reads as occupied to everyone else.
- **Commit or clear your own work before stepping away, and always run the tree check before taking a slot that looks free.**
- **If you find a slot detached and dirty, leave it and ask the other sessions whose it is: do not clean it up, and do not take it.** Cleaning up looks like the tidy move and is the one that loses somebody's patch.

`scripts/worktree-slots.mjs` reads every tree at once, so start there:

```bash
node scripts/worktree-slots.mjs status     # branch, free/held and drift for every tree
git worktree list                          # the raw form, when you want it
```

**Occupancy here is two signals, and `status` folds them together.** A slot is held when it carries a branch **or** a claim ref, so a slot claimed but not yet branched reads `held` rather than `free`, which is the window the race in *Claiming and releasing a slot* lived in. A slot on a `wave/<theme>` branch is held exactly as one on `<N>-<slug>` is.

## Claiming and releasing a slot

Take **A** first, **B** second. If both are held and you did not hold either, you have two live sessions; do not evict one, take an ephemeral worktree (see *Ephemeral worktrees*).

**Claim a slot by branching from `origin/main`**, never from whatever the slot happens to be sitting on:

```bash
cd ../<repo>-a
git fetch origin
git checkout -b <N>-<slug> origin/main     # or: gh issue develop <N>, then check it out
```

**Here, claim with `claim`, which takes a slot atomically and tells you which it took.** Do not read `status` and act on what it said: that is check-then-act, and two sessions starting together both read `free` and both proceed (#1140).

```bash
node scripts/worktree-slots.mjs claim --branch <N>-<slug>
```

It prints the slot on the first line and the session label on the second, attempts `a` then `b`, and touches no tree until the claim is held. **Exactly two outcomes ask you anything**, and both are exit codes rather than prose to match on:

| Exit | Meaning | What to do |
| --- | --- | --- |
| `0` | claimed | name the session from the second line and work in that slot |
| `4` | no slot is provisioned here | ask the operator before provisioning: it creates worktrees |
| `3` | every slot is held | ask, or take an ephemeral worktree; **never evict a claim** |

Nothing else in the startup sequence prompts. A refresh of an existing slot installs from the committed lockfile and needs no prompt; provisioning creates worktrees first, which is where the operator has drawn the line.

**`claim --branch` already branches from `origin/main`**, which is the part that must not be done by hand from whatever the slot was sitting on. Branching yourself, as above, is still available when you need a name `gh issue develop` produced: run `node scripts/worktree-slots.mjs claim` first (claim only, tree left detached), then branch in the slot it names.

**Releasing a slot is a real step, not a formality.** When a ticket ships, return the slot to rest:

```bash
cd ../<repo>-a
git status --porcelain                     # MUST be empty, see the litter note below
git fetch origin
git checkout --detach origin/main
```

Three things go wrong when this is skipped, and all three are silent:

- **The next ticket branches off the last ticket's tip** instead of current `main`, so its PR carries commits that belong to another ticket and its diff against `main` is wrong. This is the single largest new hazard the slot model introduces, and it has no loud failure: everything builds, the branch is just wrong.
- **Untracked scratch from the previous ticket rides into the next commit.** A slot accretes import XML, generated fixtures, notes. Check before you branch, not after you commit.
- **`git status` is blind to empty directories**, so test litter left by a run killed mid-write does not show up there at all. Look for it directly when a slot has hosted an interrupted run.

**Never delete the branch**: releasing the slot moves the tree off it, and `git checkout` does not touch the branch. The slot itself is never torn down.

The guarded form of the release is the helper:

```bash
node scripts/worktree-slots.mjs release a  # refuses on a dirty tree rather than stranding work
```

**`release` clears the claim as well as detaching the tree, and refuses a claim belonging to another machine**: the holder cannot be asked, and detaching a tree they are mid-run in is exactly what the claim exists to prevent. The granularity is the machine rather than the session: two sessions on one machine hold *different* slots, so each still clears only its own.

**The hand-run status check here is `git status --porcelain --ignore-submodules=all`, and the flag is load-bearing, not boilerplate.** The exports submodule's working copy shows as modified independently of your changes, so a plain `git status --porcelain` in any tree that has ever run a host-app test reports ` M src/Services/WordPressImport/Tests/Exports`. Measured on macOS: the plain form reports it, the `--ignore-submodules=all` form does not. Without the flag a release would be refused on almost every tree.

**A held slot blocks local CI from validating its branch: release before you hand off.** Git allows a branch in one worktree only, and [`scripts/local-ci.mjs`](../../scripts/local-ci.mjs) checks the branch out **by name**. So a slot still sitting on `<N>-<slug>` makes the validating run fail: the runner names the holding tree and points you back here rather than failing with a bare exit code. Since the project flow has the **author flip the PR to ready and a separate session run the gate**, the author's last act on a ticket is to release the slot. The same applies to the primary: it holds `main`, so **local CI cannot validate `main`** while it does.

**A gate can validate a pull request's head WITHOUT taking the author's branch, and it should prefer to.** The constraint above cuts both ways: a validating run that checks out `<N>-<slug>` holds it for the duration, so if that run raises a finding it has just blocked the only session able to act on it. Observed 2026-09-06 gating [#942](https://github.com/UAMS-Web/wordpress-importer/pull/942): the finding was posted, the branch stayed in the CI slot, and the author had to say they were stuck before it was released.

Point a throwaway ref at the same commit and validate that instead. The author's branch is never touched:

```bash
git branch -f ci/<N>-validate <pr-head-sha>          # in the primary; the gate's own ref
node scripts/local-ci.mjs --branch ci/<N>-validate
```

**The runner accepts a local ref with no upstream and says so loudly**, because validating an unpushed commit is exactly what a gate must never do by accident:

```
warning: local ci/<N>-validate has no origin/ci/<N>-validate: validating an UNPUSHED
         commit, which is not what the pull request shows.
```

Two things this deliberately does **not** relax. A branch present both locally and on `origin` but **diverged** is still a hard error: guessing which side to validate is how a run certifies something nobody pushed. And a name that exists **nowhere**, local or remote, is still an error rather than a warning: collapsing those two cases would let a typo validate whatever the slot last held and report it green.

History: [worktrees.md § Claiming and releasing a slot](../history/worktrees.md#claiming-and-releasing-a-slot).

## The CI tree belongs to the runner

The local CI runner creates, reuses and checks out its own tree, always detached at the commit under test, and that tree is **never driven by hand**. Do not branch in it, do not commit from it, and do not treat it as a third slot. Do not provision it either: the runner stands it up itself on its first run and re-bootstraps it on every run afterwards, so hand-building it would only hand the runner a state it overwrites on its next run. No session ever works in it, so it never enters a prompt prefix, and the bootstrap-and-diagnosis cost that makes a hand-built tree expensive: an interactive session running installs, then reading a wall of errors and working out what was missing: is close to free when a script does the same work. The `local-ci` skill owns the runner's flags and its tree.

**Provision `a` and `b` only: the CI slot is the exception, and "once per slot" does not include it.** [`scripts/local-ci.mjs`](../../scripts/local-ci.mjs) stands `../wordpress-importer-ci` up itself on its first `--branch` run: `git worktree add`, then `composer install`, and re-bootstraps it on every run afterwards, without writing `worktree-slots.mjs`'s stamp. Pre-provisioning it therefore buys nothing and contradicts the **never hand-drive it** in the slot table above. Nothing enforces this: `provision ci` is accepted, because `commandProvision` refuses only `primary`. It is a convention the tooling trusts you to keep, and the reason `status` judges that tree by its own kind rather than by a slot's.

## Keeping a slot current: the refresh gate

This is the risk the slot model **introduces**, and it is worse than the one it removes. A fresh worktree fails *loudly*: no `vendor/`, hard error, you notice. A stale slot fails *silently*: the tests run, they pass, and they pass against the wrong dependency versions. The checks invoke the repo-pinned `vendor/bin/{pint,phpstan,pest}`, so a slot whose `vendor/` predates its branch's `composer.lock` produces a green that means nothing. This is not hypothetical: in `uams-statamic` the primary checkout's `vendor/` sat at Pest 4.7.5 while `composer.lock` pinned 5.0.1, and nothing said so.

**So this is a gate, not advice.** Whenever a slot's base moves: you just released it to a newer `main`, or you synced a branch per the [`sync-pr-branch`](sync-pr-branch.md) rule: refresh **before** running any check whose result you intend to trust. The minimum, in any repository, and the whole of it where nothing else is bootstrapped:

```bash
cd ../<repo>-a
composer install                                  # no-op when vendor/ matches composer.lock
npm ci                                            # ONLY if package-lock.json moved, see below
```

- **`composer install` is a no-op when current**, so there is no reason to reason about whether it is needed: just run it. It prints `Nothing to install, update or remove` when the slot was already right.
- **`npm ci`, not `npm install`**: `install` will happily reconcile a drifted tree against the manifest and leave `package-lock.json` modified; `ci` installs exactly the lock. Only needed when the lockfile actually moved.
- Whatever else the repository's bootstrap put in place (submodules, indexes, built assets) has its own refresh step.

This repository's further refresh steps are under its `scripts/worktree-slots.mjs` commands, below.

**The primary needs this as much as any slot does**: the Pest 4.7.5-against-a-5.0.1-lock drift above was found *there*.

**A gate that depends on remembering commands is not a gate**, which is why a repository that carries a tree-checking script makes the script the gate: a check that exits 1 when a tree has drifted works as a shell guard ahead of a long run. What such a check looks at differs per repository, because a guard is only as good as what it looks at. Each tree is judged against its **own kind** (an ephemeral worktree has different expectations from a work slot) because a guard that can never go green is not a guard; it is a line people learn to skip.

Here the script is `scripts/worktree-slots.mjs`: `status --check` is the guard and `refresh` reconciles a tree. It judges the runner-owned CI tree as a kind of its own too. What its check looks at is listed below, and it prints a verdict line as well as its exit code.

**A pipe replaces a guard's exit code with the pager's, so read the status before any pipe.** `… --check 2>&1 | tail -6; echo $?` reports whether `tail` succeeded, which it always does; the guard's `1` is gone and a `0` beside a clean-looking table reads exactly like a pass. On 2026-09-06, in `wordpress-importer`, five false greens were published in one evening by four concurrent sessions on exactly that shape, two of them by sessions that already knew about the trap; one had merged a change about it hours earlier and ran the piped form anyway, and the instances came from both macOS and Windows (Git Bash) sessions: the shape is POSIX pipeline semantics rather than anything platform-specific. Use `>/dev/null 2>&1; echo $?`, or `set -o pipefail`, or capture the status before piping. The exit code is the contract; a verdict line a guard prints is a second channel for the case where the code has already been thrown away, never a replacement.

**A guard shipped inside the artifact it guards cannot report on states that predate the guard, and the reason is structural rather than a defect in it.** The script lives *in the tree it inspects*, so a tree far enough behind is running a copy that predates a check: it reports no drift of that kind, `--check` exits `0`, and that is the value a shell guard reads as permission to proceed. The two kinds of staleness fail differently, and the second is worse:

| What is stale | What happens |
| --- | --- |
| `vendor/` | the check runs, against the wrong dependency versions |
| the tree-checking script itself | the check does not run, and its output does not look truncated: it looks complete |

Here that script is `scripts/worktree-slots.mjs`. The tree *set* is location-independent: the script resolves the primary checkout from the working directory, so any copy judges every tree. The **check set** is not: it comes from whichever file was invoked. `cd <slot> && node scripts/worktree-slots.mjs` runs the slot's copy; `node ../<repo>/scripts/worktree-slots.mjs` runs the primary's. Both enumerate the same trees and can run different checks. **A clean `status --check` run from inside a stale tree is not evidence that the tree is current.**

Anything added to a tree-checking script later (or to any tool that ships in the tree it validates) has the same blind spot at the same place: it is silent exactly where the artifact is most out of date, and silence from it is indistinguishable from health.

**One probe cannot be degraded by any copy's age, because it lives outside the tree:**

```bash
TREE=../<repo>-a
git -C "$TREE" rev-list --count HEAD..origin/main   # 0 = current, N = N commits behind
```

Measured in `wordpress-importer` on macOS (Darwin 25.6.0) against all four trees: it returned `0` for three and `1` for the slot that was genuinely behind. Reach for it when the answer has to be right and you cannot vouch for which copy is executing. It does not fetch (a command that reaches the network on every invocation is one people route around) so a stale remote-tracking ref under-reports; `git fetch origin` before trusting a zero.

**The path is assigned on its own line rather than written `<tree>` inline, and that is deliberate.** An angle-bracket placeholder is this corpus's convention and reads correctly as prose, but in a pasteable fenced recipe it sits where the shell expects a redirection: `<tree>` is an input redirect from a file named `tree`, and `>` before `rev-list` an output redirect to a file named `rev-list`. Pasted with no such file present it aborts harmlessly on the missing input; pasted in a directory that happens to contain a file named `tree`, it runs git on mangled arguments and **creates a file called `rev-list`**. Measured both ways in `wordpress-importer`, macOS and Git Bash on Windows 11, with identical results, and `bash -n` reports the line as syntactically clean either way, so nothing warns a reader who copies it. This is not a call to sweep the other placeholders: `<N>`, `<slug>`, `<branch>` and the rest name no plausible file, so the input redirect fails and the command aborts before any output redirect applies, and inline code inside prose is read as an illustration rather than copied as a command. A placeholder is exposed only when both halves hold: a pasteable fenced recipe and a name that is a plausible file. **Prose has no such protection: every word in it is a valid filename**, so a bare `>` between two words is a redirect wherever it appears, including inside narration that reaches a shell. Measured in the `wordpress-importer` primary on 2026-09-06: four empty files, `ci.yml`, `cspell`, `four,` and `still`, one mtime to the second, from a single line of explanatory text. Write the arrow as `→` (U+2192), which this corpus already uses for version transitions, or quote the whole construction. An ASCII arrow does not help and is the trap it looks like a fix for: measured in `wordpress-importer` on macOS (Darwin 25.6.0), a hyphen followed by the redirect character still redirects, because the hyphen is parsed as an argument and the file is created regardless.

```bash
node scripts/worktree-slots.mjs status          # every tree: branch, free/held, and any drift
node scripts/worktree-slots.mjs refresh a       # reconcile that slot
node scripts/worktree-slots.mjs refresh primary # the clone itself
```

**`refresh` takes `primary` as well as a slot name.** `release` and `provision` deliberately do not: the primary must keep `main` checked out (releasing detaches the tree, which would leave `main` in no worktree at all), and it is the clone rather than a worktree of it.

`status --check` checks two things: the **composer** install stamp, and the exports submodule's revision **where that submodule is initialized**. **It also prints its verdict as the last line of stdout**, so the answer survives an invocation that discards the exit code:

```
DRIFT: 2 trees (wordpress-importer-a, 2 behind origin/main | wordpress-importer-b, vendor stale)
CLEAN: 4 trees
```

The verdict names the trees and their drift, not just the word, because the drift was already visible in the table on 2026-09-06 (a `2 behind origin/main` cell, published in the same message as `check-exit=0`) and was read as informational because the exit code appeared to overrule it. **The exit code is unchanged**, and a scripted caller should keep using it: `0` clean, `1` drift. Under `--json` the line is not printed at all, so that stream stays parseable. A tree detached at a `main` older than #878 carries a copy with no base-position comparison; run from there, `status` enumerates all four trees and reports every one current, including trees that are behind, with nothing to indicate a check was skipped.

**`status` also prints one line that is about the REPOSITORY rather than any tree: how far `origin/main` has run ahead of the newest tag reachable from it.**

```
origin/main is 139 commits ahead of v0.16.0, cut 15d ago: unreachable by anything pinning a tagged range.
```

**It is a report, never a prompt.** It is not part of `--check`'s exit code, and it deliberately does not tell anyone to cut a release: unreleased work is a normal state between the daily releases, a gate that failed on it would be switched off within a day, and **when** to tag belongs to [`cutting-releases`](cutting-releases.md) and to the operator. The line says what is currently unreachable; it does not say that anything is wrong. **Four states, because two of them mean "nothing to report" for opposite reasons.** A repository with no tags has shipped nothing; one level with its newest tag has shipped everything; a probe that could not read has established neither. Each renders as its own sentence, for the reason `baseState` carries a four-state model rather than a number: silence and zero must never be the same output. It does not fetch, so `git fetch` before trusting a zero. A bare `git fetch origin` is enough: measured on git 2.32.0.windows.2, a bare fetch brought all 20 tag objects, matching `--tags` exactly.

Two judgments make a green reachable at all:

- **Each tree is judged against its own kind.** The **CI slot** is runner-owned, so the runner bootstrapping it (without writing this script's stamp) is the expected state rather than drift. An **ephemeral** worktree is nobody's to keep current, so it is reported and not judged. Only a slot and the primary are held to the install.
- **The exports submodule is judged in two kind-independent halves.** An **uninitialized** submodule is *never* drift: it is on demand by design (see the cost under *Provisioning*). An **initialized** one sitting at a revision other than the recorded gitlink **is** drift, in every tree, because the host-app subset renders those fixtures and a slot carrying stale ones runs against the wrong inputs silently.

This is why `refresh` runs `git submodule update` **without** `--init`: an absent submodule stays absent, while an initialized one is reconciled. Initializing it here would pull 1.8 GB into every tree; skipping the reconcile would report drift that `refresh` could not clear.

`refresh` simply runs `composer install` rather than reasoning about whether it is needed. The stamp exists for `status` to judge drift, not to gate the install. It lives at `vendor/.worktree-slot-stamp.json`: already git-ignored, so it can never show up as untracked and break the clean-tree check `release` depends on, and it cannot outlive the `vendor/` it describes.

History: [worktrees.md § Keeping a slot current: the refresh gate](../history/worktrees.md#keeping-a-slot-current--the-refresh-gate).

### Submodules in a linked worktree

**Each linked worktree has its OWN submodule gitdir, so its submodule HEAD moves independently**: measured, not assumed. In `wordpress-importer` on macOS the primary's exports submodule lives at `.git/modules/src/Services/WordPressImport/Tests/Exports` and slot A's at `.git/worktrees/wordpress-importer-a/modules/src/Services/WordPressImport/Tests/Exports`; in `uams-statamic`, on git 2.32.0.windows.2, `content` shows the same shape. Both sit *inside* the primary's `.git`, which is what makes a shared-storage reading look plausible, but they are distinct paths. Confirm on a new machine with `git -C <tree>/<sub> rev-parse --absolute-git-dir`; other git versions do share one `.git/modules/<name>`, so treat this as measured *here* rather than as a general property. So a concurrent session moving a submodule in slot B does **not** move it under slot A's run, and two slots on different submodule revisions is an ordinary state rather than a conflict to sequence around.

**Verifying a submodule's state: the probe that lies.** `rev-parse HEAD` cannot tell you a submodule is *absent*. In an empty submodule directory it does not fail: it walks **up** to the enclosing superproject and answers for that, so an uninitialized submodule reports the *tree's own* HEAD and reads as a revision *mismatch* rather than "not initialized". Measured in a freshly provisioned `wordpress-importer` slot: the empty `Exports/` directory reported `dbd6fa15…`, byte-identical to the slot's own HEAD; in `uams-statamic`, three uninitialized submodules in the local-CI worktree every one reported the commit the superproject was sitting on. Confirm the directory is its own repository first: `git -C <tree>/<sub> rev-parse --show-toplevel` must echo that same path back. `scripts/worktree-slots.mjs` does this check for you, which is the better answer than remembering it.

## Provisioning a slot (one time, and once only)

A slot is created once and kept current by the refresh gate from then on; it is never torn down and recreated per ticket. A repository adopting this model with no slots yet needs the following, and nothing this rule has not already said:

1. **Choose the layout.** Two work slots named after the repository: `<repo>-a` and `<repo>-b`. Make them **siblings** of the primary: they sit outside the repo, so no ignore rule has to hold for them, and a search from the primary cannot descend into them. **If the primary sits in a directory the host application scans**: a WordPress plugin under `wp-content/plugins/`: a plain sibling is a second copy of the plugin on the plugins screen; dot-prefix the slot names (`.<repo>-a`) so the host skips them before opening them, and keep them inside that directory if the test bootstrap locates the host by relative position. Slot names must not begin with a digit (see *The slots*).
2. **Create each slot at rest**: detached, because `main` belongs to the primary:

   ```bash
   git fetch origin
   git worktree add --detach ../<repo>-a origin/main
   ```

3. **Bootstrap it in place.** `composer install`, then `npm ci` where the repository has a `package-lock.json`, then whatever else the repository's checks and its served app need before the slot behaves like the primary: submodules, an `.env`, local databases, built assets. Everything provisioning writes must be git-ignored or a submodule, so none of it can ride a commit: confirm with a clean `git status` afterwards.
4. **Never junction or symlink `vendor/` from another tree to skip `composer install`.** PHP resolves `__DIR__` *through* the link to the real path, so `vendor/bin/pest` reports the **donor** tree . Pest derives its root from that and loads the **donor's** `tests/Pest.php` instead of yours. Your test *files* run; somebody else's bootstrap configures them. The tell is a helper you just added being `Call to undefined function` while pre-existing helpers resolve fine, and a stack trace naming the other tree's vendor path. It cuts both ways: a bootstrap regression passes, and a bootstrap fix appears not to work. A `node_modules/` junction is safe: cspell and the JS tooling do no `__DIR__`-based root detection.
5. **Make `.claude/worktrees/` ignored by the tracked `.gitignore`**, so an ephemeral worktree can never be staged from any checkout (see *Ephemeral worktrees*).

Do not provision the local CI runner's own tree (`<repo>-ci`); the runner stands it up itself (see *The CI tree belongs to the runner*).

Here one command does all of it, and writes the install stamp `status` judges drift from:

```bash
node scripts/worktree-slots.mjs provision b
```

Do this once per slot; the refresh gate above keeps it current from then on. The long form below is what that command runs.

The long form is short, because this is a library and not an app:

```bash
git fetch origin
git worktree add --detach ../wordpress-importer-a origin/main
cd ../wordpress-importer-a
composer install
node scripts/worktree-slots.mjs refresh a    # writes the install stamp, see below
```

**That last line is not optional if you provision by hand.** The stamp is the only record `status` has of what the install was run against, and `provision` writes it as its final step. A hand-run that stops after `composer install` leaves a perfectly good tree that `status` reports as `vendor: no record of a previous install`: drift that is not real, on a guard whose whole value is that its green means something. Either use `provision`, or follow the long form with a `refresh`.

**Measured on macOS (git 2.39.5 Apple Git-154), warm Composer cache: 5 seconds, and the slot occupies 232 MB.** Standing a slot up on macOS is cheap enough not to plan around: do not defer it, and do not background it expecting minutes.

**Measured on Windows (git 2.32.0.windows.2, Git Bash), warm Composer cache: zero downloads, 28 archives extracted from cache: 96 seconds for the first slot and 72 seconds for the second.** The macOS figure does **not** transfer, and the advice inverts with it: on Windows a provision is a minute-or-two job worth backgrounding, not the five seconds that made deferring it pointless. Both still finish well inside the exports submodule's cost below. **The slot occupies 221 MB on Windows**: close enough to the macOS 232 MB that the footprint, unlike the timing, does transfer. Measuring it is the slow part: `du` on one of these trees did not finish inside 150 seconds and needed a second run bounded at 900 to return, which is itself the useful signal: file-tree walks over `vendor/` are slow enough here that a teardown or a size check needs a bound in the minutes, not the seconds.

**The exports submodule is deliberately NOT provisioned.** Initialize it in a slot only when a ticket actually reads a fixture:

```bash
git submodule update --init --depth 1 src/Services/WordPressImport/Tests/Exports
```

- **It is a real network clone per tree, and it is slow.** `--depth 1` still printed `Cloning into '…'` and took **109 seconds**, taking the slot from 232 MB to **2.0 GB**. It is not resolved from the primary's objects: see the gitdir measurement under *Submodules in a linked worktree*. (The host app measured the opposite on macOS for *its* submodules, where nothing fetched. Do not reason from that; this one fetches.)
- **The standalone gate does not need it.** One gate test reads the fixtures: `TaxonomyDestinationTest` globs `Exports/WordPress/*.xml`, and skips visibly when the submodule is not initialized. Five others name the directory only in comments or to exclude it. So an uninitialized slot loses one skipped test and nothing else. Where it IS initialized, `scripts/local-ci.mjs --branch` moves it to the branch's gitlink before the jobs run, because a checkout alone leaves it on the corpus it last held (#1139). The host-app-bound `Feature` suite is what renders the fixtures, and it runs inside `uams-statamic` (see the [`host-app-tests`](../skills/host-app-tests/SKILL.md) skill).

History: [worktrees.md § Provisioning a slot (one time, and once only)](../history/worktrees.md#provisioning-a-slot-one-time-and-once-only).

## A submodule behind its gitlink, and the `git add -A` that commits the revert

**A submodule working tree does not follow `HEAD`.** The slot tooling checks out with `--no-recurse-submodules` (#1142), so this holds even on a machine whose global config sets `submodule.recurse`. After a checkout that crosses a commit moving the exports gitlink, the submodule stays at the old revision and `git status` reports it ` M`. That is the tell, and it reads as the independent churn this file already tells you to ignore.

**`git add -A` stages that gap as an edit, and it is the add nearly every commit here uses.** Committed, it is a silent gitlink revert that the author did not write, that the commit message does not mention, and that no gate job catches: both corpora pass the fixture tests (#1143). A branch touching two PHP files would have merged a revert of #1138, restoring 29 deleted exports and reopening #666.

**[`.githooks/pre-commit`](../../.githooks/pre-commit) now refuses such a commit.** Its second gate compares the staged gitlink with the one `HEAD` records and refuses a difference, naming both revisions. It reads only the index and `HEAD`, so it works with the submodule absent or partially populated. Two cases are let through:

| Case | How it passes |
| --- | --- |
| Concluding a merge that takes the other side's gitlink | allowed when the staged value matches `MERGE_HEAD`'s |
| A deliberate corpus bump | `ALLOW_EXPORTS_GITLINK=1 git commit ...`, which the hook reports as allowed |

**`--no-verify` also passes it, and it passes the CI parity gate too.** For a deliberate bump, prefer the variable, which lifts only the gate it names.

**Either bypass stops at the commit, not at the gate.** The `exports-gitlink` job fails a branch whose gitlink differs from the one at its merge base with `origin/main`, unless a commit on the branch carries the trailer `Exports-Gitlink: bump` (#1195). So a deliberate bump needs both: the variable to get the commit through the hook, and the trailer to get the branch through the gate. It reads trees with `git ls-tree`, so it needs no submodule.

**Recovering from a refusal needs no clone:** `git restore --staged -- src/Services/WordPressImport/Tests/Exports` resets the index entry to `HEAD`'s and leaves the submodule working tree alone. **To keep the submodule out of a broad add in the first place**, exclude it with a pathspec. Both forms were measured in a slot with a real gap, beside a plain `git add -A` that did stage it:

```bash
git add -A -- . ':(exclude)src/Services/WordPressImport/Tests/Exports'
git add -A -- . ':!src/Services/WordPressImport/Tests/Exports'
```

**The per-tree check** compares what the branch wants with what is checked out. Run the second command only after *Submodules in a linked worktree* has confirmed the directory is its own repository, because in an absent submodule it answers for the slot instead:

```bash
git ls-tree HEAD -- src/Services/WordPressImport/Tests/Exports                  # what the branch records
git -C src/Services/WordPressImport/Tests/Exports rev-parse HEAD                # what is checked out
```

History: [worktrees.md § A submodule behind its gitlink, and the `git add -A` that commits the revert](../history/worktrees.md#a-submodule-behind-its-gitlink-and-the-git-add--a-that-commits-the-revert).

## Ephemeral worktrees

Slots cover ordinary work. A throwaway worktree is still right in exactly three cases:

- **Both slots are held** and you need a third tree now.
- **Genuinely file-disjoint parallel fan-out**: independent tickets built at the same time, one tree each, when neither slot is free for the second.
- **Subagents**: the `Agent` tool's `isolation: "worktree"`, which is a different mechanism aimed at a different problem and is unaffected by any of this.

For those, the harness `EnterWorktree` tool creates a worktree under `.claude/worktrees/<name>/` on a fresh branch from `origin/<default>` and switches the session into it. It is gated to fire only on a user request *or a project instruction*: this rule is that instruction. Three caveats:

- **`.claude/worktrees/` must be ignored by the tracked `.gitignore`**, not by a per-machine `.git/info/exclude`, so that "can never be staged by construction" holds on every clone. Confirm with `git check-ignore -v .claude/worktrees/x` if you ever doubt it.
- **Name it `t<N>-<slug>`, not `<N>-<slug>`**, for the digit/namespace reason above. The branch name is unaffected and still comes from `gh issue develop`. Verified in `uams-statamic` against the pinned Pest with a clean negative control: a `tests/Feature/9999Probe/` directory errors while an identical `tests/Feature/t9999Probe/` passes.
- **Prefer `EnterWorktree` over `git worktree add` + entering it.** Entering a worktree the harness did not create is recorded as `"enteredExisting": true` in the session's `worktreeSession` metadata and carries no `originalBranch` / `originalHeadCommit`, so the session shows no worktree marker in Claude Code's session history, and its transcript is filed under the *worktree's* project slug rather than the repo's. Wanting a specific branch name is not a reason to take the manual path: create with `EnterWorktree`, then `git checkout -b <branch>` inside it; the metadata is written on entry, so whatever branch you end up on afterwards does not affect it. Reserve the manual path for what `EnterWorktree` cannot express: adding a worktree for a **pre-existing** branch you must not re-create.

## Committing and pushing needs nothing extra

A fresh worktree can branch, commit, and push immediately, even though `vendor/` is git-ignored and therefore absent from it. A worktree runs whatever hooks the repository configures; in `uams-statamic` and `wordpress-importer` no pre-commit hook runs tests, Pint or PHPStan, and neither has a pre-push hook. Running the checks *does* need a bootstrap, which is what provisioning is for. A push that fails on a missing `vendor/` is an un-bootstrapped *environment*, not a real defect: **bootstrap, don't `--no-verify`**.

There **is** a pre-commit hook carrying two gates, and a worktree does not escape it. [`.githooks/pre-commit`](../../.githooks/pre-commit) blocks a commit that stages **exactly one** of [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) and [`scripts/local-ci.mjs`](../../scripts/local-ci.mjs): hand-maintained mirrors with no shared source of truth, which drift silently when only one is edited. It is a *co-change* gate, not a semantic one: it never compares commands, it only insists the two move together so a human reviews both. It is installed by `git config core.hooksPath .githooks` from `scripts/setup-git-hooks.php`, wired to composer's `post-install-cmd` / `post-update-cmd`. **`core.hooksPath` lives in the shared `.git/config`**, so it is set for every worktree as soon as any checkout has run `composer install`, and because the configured value is relative, each worktree runs its own `.githooks/pre-commit`. Legitimate one-sided edits exist (a typo in a `ci.yml` comment, a runner-only flag); for those, and only those, bypass with `git commit --no-verify`.

**Its second gate refuses a commit that moves the exports gitlink** unless the move is deliberate (#1143). `--no-verify` passes both gates at once, so for a corpus bump use `ALLOW_EXPORTS_GITLINK=1`, which lifts only that one. The hazard it closes, and how to recover from a refusal, are in *A submodule behind its gitlink* above.

**The standalone Pest suites are two, not one**: `composer test` runs `vendor/bin/pest --testsuite=Unit,Integration`. A change that only ever runs `--testsuite=Unit` leaves the `Integration` band (minimal Statamic via Orchestra Testbench) unverified. Bare `vendor/bin/pest` also picks up `tests/Feature/` and errors, so keep the suite scoping.

## File-tool vs. shell-cwd hazards (harness)

These matter **more** with long-lived slots, not less: every slot is always present, always on some branch, and always a plausible destination for a misdirected write.

- **`EnterWorktree` switches the shell cwd, but `Edit`/`Write`/`Read` act on the absolute path you pass.** Keep passing a primary-checkout path and your edits land on the primary, on `main`. Always pass the **slot's** absolute path (in your platform's own form) to the file tools, and `Read` the slot's copy before editing it: read-state is tracked per absolute path. If work lands on the wrong tree: `cp` the changed files where they belonged, then `git -C <wrong-tree> checkout -- <file>` (or delete the stray untracked file) to restore it.
- **The Bash session cwd silently reverts between calls.** Sometimes it prints `Shell cwd was reset to …`; sometimes it is simply back at the primary several calls later with nothing said. A backgrounded command inherits whatever the cwd is at launch. The failure is a **false green**: a verification run meant to exercise your branch instead exercises `main` and passes, proving nothing. Put an explicit `cd` in **every** command, and bake a guard into anything long or backgrounded so the output proves which tree ran:

  ```bash
  cd ../<repo>-a && echo "CWD=$(pwd) HEAD=$(git rev-parse --short HEAD)" && <the real command>
  ```

  With slots this guard matters more than with per-ticket trees, because slots do not differ by name: `<repo>-a` and `<repo>-b` look alike, and only `HEAD` distinguishes them.

  **It is not only the primary, and it is not only runs.** The stale cwd is just as likely to point at the *other slot*, so "did it land on `main`?" is the wrong check; "which tree did it land on?" is the right one. And it corrupts **writes**, not just verification: an in-place shell edit (`sed -i`, a `python` heredoc, `cat >`) with no `cd` of its own silently rewrites the other tree's file, leaving the one you meant to edit untouched and green. Prefer the file tools with **absolute** paths for edits; when a shell edit is genuinely easier, give that command its own `cd`.
- **`git checkout -- <file>` reverts the file to HEAD: it discards *all* uncommitted work on it, not just your last change.** Use it ONLY where the file *should* match HEAD (restoring a tree you wrote to by mistake). **Never** reach for it to "undo" a *temporary* edit on a file that holds uncommitted work you want to keep: e.g. hand-mutation-verifying a template change (revert the fix → expect-red → restore), where the file is template-only (so `composer mutate` cannot help) and the work is not committed yet. Restore the temporary mutation with its **inverse** edit, or `cp` the file aside first. If it happens anyway, a never-committed file is often rebuildable by cross-checking three sources: the still-on-disk **compiled view** where the framework keeps one (Laravel: `storage/framework/views/<hash>.php`, the exact last-rendered state), the session **transcript**'s `Read`/`Edit` results, and **HEAD** for the unchanged scaffolding: then re-verified green before trusting it.
- **The stash stack is one instance of a class this corpus had never named: a SHARED READ-MODIFY-WRITE SURFACE.** Something two actors can read, change and write back, with no locking and no arbitration, so the last writer wins and the loser is never told.

  | Surface | Shared between | Safe operation | Destructive operation |
  | --- | --- | --- | --- |
  | git stash stack | every worktree of one repo | a WIP commit, or `cp` the file aside | `stash push` / bare `pop` |
  | project memory | every session on a machine | append a new file, one fact per file | rewriting a shared file whole |
  | GitHub issue / PR body | everyone, over the network | post a comment | editing the body |

  **Append cannot clobber; rewrite can.** That is mechanical and does not depend on anyone remembering to be careful, which matters, because the losses observed here happened *between sessions that were actively coordinating with each other at the time*.

  **Worktree slots do NOT partition project memory.** `~/.claude/projects/<slug>/memory/` resolves to one directory per repository (keyed to the primary checkout's slug, not to the worktree) so slot A, slot B and any other concurrent session on a machine read and write the same files, and no per-slot directory exists. Isolation that stops at the repo boundary still reads as isolation, which is what makes it dangerous. Observed in `wordpress-importer` on 2026-09-05: two sessions rewrote the same memory file wholesale within a minute; one write landed and the other was lost, recoverable only because a copy had been taken outside the directory. **Never write a memory file whole.** Anchor on exact text you expect to be there, assert it appears exactly once, and replace only that:

  ```python
  old = "<exact text I expect to be there>"
  assert s.count(old) == 1, "anchor moved - someone else edited this"
  s = s.replace(old, new)
  ```

  The assert turns a silent clobber into an aborted edit, and catches the opposite fault too: an anchor appearing twice, where `replace` would hit both. **The protection is in the FORM of the edit, not the care of the editor:** a session that used this pattern seven times in one evening still made one truncating rewrite among them, which would have destroyed a concurrent append just as a whole-file write does.

  **An assert-then-replace guard is necessary and NOT sufficient, and it will be proposed by anyone who has not watched it fail.** Confirming your anchor is still present before writing proves *this* edit lands where intended. It proves nothing about a concurrent edit **elsewhere in the same artifact**, and that is the common case rather than the edge, because two writers usually append to different sections.

  **A guard that assumes it is the only writer detects only the races that start after it does.** A base-hash check compares the remote against your copy immediately before writing, so it catches an edit landing *between* your read and your write. An edit that landed *before* your read is already in your copy and the hashes match honestly. Nothing in the output distinguishes "no race" from "a race I was too late to see", which is worse than no guard, because it licenses confidence.

  **Delete only what you wrote, and confirm it is still what you wrote.** Not a mechanical check, which is why it needs stating: an editor removing a block it authored must verify the block is still its own content, not merely that something sits where it left it. The observed clobber replaced a section whose owner had altered it between the read and the write. The same hazard on a shared *remote* artifact (an issue or pull-request body) belongs to [`filing-defects-across-repos`](filing-defects-across-repos.md); this bullet is the local half.

- **The stash stack belongs to the *repository*, not the worktree, so `git stash pop` in your slot pops whatever another session pushed.** Every linked worktree shares one stack, and two mistakes chain into losing someone else's work. First, `git stash push -- <paths>` **fails outright** if any named path is untracked (`error: pathspec '…' did not match any file(s) known to git`) and stashes **nothing**: easy to miss inside a `&&` chain, or when you only read the last line. Then the paired `pop` applies the entry that was already on top: another session's, or an ancient GitHub Desktop one. **Never use the stash to set work aside or to stage a temporary revert**: prefer a WIP commit, or `cp` the file aside (see the negative-control note in [`adversarial-review`](adversarial-review.md)). If you must stash: `git stash push -u -m "<unique-tag>"`, capture the SHA from `git stash list --format='%H %gs'`, and `git stash apply <sha>`: never bare `pop`.
  - **Recovery, if it happens anyway.** `pop` drops the reflog entry but the commit is merely unreachable, so it can be put back:

    ```bash
    git fsck --unreachable --no-progress | awk '/commit/ {print $3}' |
      while read c; do git log -1 --format="$c %s" "$c"; done | grep -E ' (On|WIP on) '
    git stash store -m "<the original message>" <sha>       # back on top of the stack
    ```

    Then revert the popped files out of your tree: `git restore --staged` + `rm` for its untracked adds, `git checkout --` for tracked ones (safe *here*, per the bullet above, because they legitimately should match HEAD). Confirm with `git stash list` that the stack is the depth it was.

**Line endings are pinned to LF, so a `line_ending` report from Pint is real on every platform.** [`.gitattributes`](../../.gitattributes) has carried `* text=auto eol=lf`, with `*.php text eol=lf` under it, since `d664afb`, and a committed `eol` attribute **outranks `core.autocrlf`**, so the working tree is LF even where git is configured to produce CRLF. Do not route such a report around: it means that file genuinely is CRLF.

Measured with `core.autocrlf=true` on Windows 11 under Git Bash at `13bb024`: 462 `src/` and `tests/` PHP files, every one `i/lf w/lf`. Measured on macOS (git 2.39.5, Apple Git-154) at `3042271`: the **whole tree**: 618 tracked text files at `i/lf w/lf` and **zero** `crlf` entries anywhere, the rest being 9 binaries (`-text`), 10 empty `.gitkeep` files (`i/none`), and the exports submodule gitlink. The precedence reproduces on macOS directly: `git -c core.autocrlf=true checkout-index -f` writes 0 CR bytes.

**The one condition that still produces a CRLF working-tree file is a file whose committed bytes are CRLF**: added before `d664afb` and never renormalized. Adding the attribute does not reach backwards, and no `core.autocrlf` setting rescues it either, because the CRLF lives in the index rather than being applied at checkout. Verified on macOS in a scratch repository; the two halves fail separately, which is the part worth having:

| Step | `git ls-files --eol` |
| --- | --- |
| CRLF file committed, attribute added afterwards | `i/crlf w/crlf`: attribute in force, both halves still CRLF |
| `git add --renormalize .` | `i/lf w/crlf`: **index fixed, working tree untouched** |
| `rm <file> && git checkout -- <file>` | `i/lf w/lf` |

So `git add --renormalize .` alone is not the remedy: it corrects what is committed and leaves the file on disk exactly as Pint found it. **Check the index half before reaching for either fix**: `git ls-files --eol` reports both, and `i/lf w/crlf` is a different fault from `i/crlf w/crlf`. Only the second needs renormalizing; the first is a checkout-time conversion, and a re-checkout alone clears it once the attribute is in force. Nothing in this repo's tracked set is in either state: the whole-tree figure above is the check, and `git ls-files --eol | grep crlf` repeats it in one command. A repository carrying no `eol` attribute has no such protection, which is where `core.autocrlf=true` does silently rewrite LF sources to CRLF and manufacture phantom Pint fixers; the sibling `uamswp-migration-api` recorded that on its own `#152`.

**Confirm with `git ls-files --eol`, never with `grep -c $'\r$'`: that probe's answer depends on the platform.** Both `grep` rows were run against the same two three-line fixtures:

| Probe | CRLF fixture | LF fixture | |
| --- | --- | --- | --- |
| `grep -c $'\r$'`, Git Bash on Windows | 3 | 3 | **degenerate**: reports the line count either way, which reads as "every line is CRLF" |
| `grep -c $'\r$'`, BSD grep on macOS | 3 | 0 | distinguishes correctly |
| `grep -c '$'`, empty-pattern control | 3 | 3 | degenerate on both: the shape the first row gets mistaken for |
| `tr -cd '\r' \| wc -c`, CR bytes | 3 | 0 | answers on both |

History: [worktrees.md § File-tool vs. shell-cwd hazards (harness)](../history/worktrees.md#file-tool-vs-shell-cwd-hazards-harness).

## Rebuilding a slot, and removing an ephemeral worktree

A slot is normally never removed: that is the point. Rebuild one only when its bootstrap is beyond refreshing (a corrupted `vendor/`, a wedged submodule). The removal procedure below is the same one an ephemeral worktree needs.

**`git worktree remove --force` is the normal path and it works, and the old folklore about submodules blocking it is wrong.** Measured directly in `wordpress-importer` (git 2.39.5 Apple Git-154, macOS) on a slot with the exports submodule fully initialized, and in `uams-statamic` (git 2.32.0.windows.2, Windows) with `content` initialized: exit 0, tree deleted, deregistered. The long-standing claim that an initialized submodule makes removal refuse **did not reproduce**, and has been removed rather than softened.

**`git worktree move` is init-gated, not declaration-gated.** With no submodule initialized it succeeds; with any one left it fails `fatal: working trees containing submodules cannot be moved or removed`, exit 128, and the shared error message naming both operations is precisely why the folklore stuck. Deinit every initialized submodule before a move: a repository declaring **four** has to `git submodule deinit -f --all`, because deinit-ing only one leaves three and the move still refuses: exactly the trap hit in `uams-statamic` when it declared four, where deinit-ing only `content` was mistaken for proof that deinit does not help. A repository declaring one needs only that one. So a slot *can* be relocated: deinit, `git worktree move`, re-init if needed. The alternative is an OS-level rename plus `git worktree repair <new-path>` to fix the linkage.

Reach for **`rm -rf <path>` then `git worktree prune`** only when something outside git blocks removal: an OS handle, or untracked scratch you have already triaged. The ways that happens, most of them observed on Windows:

1. **Get every shell's cwd *out* of the tree first.** On Windows a shell whose working directory sits inside it **holds a lock**: `rm -rf` then fails `Device or resource busy` and `git worktree remove` cannot delete it. (Corollary: after a removal, invoke a local CI runner by **absolute path**: a cwd stranded in a deleted tree makes the relative path resolve to the gone copy, and the runner never starts.)
2. **Kill any watcher started in it**: `composer dev` / `npm run dev` (Vite) / `php artisan serve` holds handles the same way.
3. **An OS-level rename can itself be denied** (`Permission denied` from `mv`, `Access to the path … is denied` from `Rename-Item`) while a handle is open on the directory. Observed in `uams-statamic` on Windows, and worth knowing in this order: the `readlink /proc/*/cwd` sweep from [`long-running-commands`](long-running-commands.md) came back **empty**, so an empty sweep does not mean unlocked: it only sees MSYS processes, and `Win32_Process` exposes no working directory at all. **Localize the lock before reacting to it.** Writing a file inside the directory and renaming one of its *subdirectories* both succeeding, while renaming the top level fails, means a single handle on the folder itself: most often a shell that recently `cd`'d there, an editor window rooted on it, or Herd watching it as a parked site. **A top-level lock does not block the delete**, which is what makes this a non-event: `rm -rf` removes the contents regardless and fails only on the final `rmdir`, leaving an empty directory that `git worktree prune` still deregisters. These handles are also often transient: the same directory that refused two rename attempts deleted cleanly, top level included, a few minutes later. So do not go hunting the holder, and do not reboot: delete the contents, prune, and let the tool recreate the worktree under the name you wanted.
4. **Untracked scratch also blocks removal.** *Look before you delete*: a worktree accretes stray files (import XML, generated fixtures); copy anything worth keeping to the scratchpad first.
5. **Delete any junction as its own step, before `rm -rf`**: remove the *link*, never the target: `[System.IO.Directory]::Delete($path, $false)` in PowerShell, or `cmd //c rmdir <path>`. Then confirm the target still exists. A recursive delete that follows a reparse point takes the primary's `node_modules` (or another tree's submodule) with it, and nothing tells you until the next command fails.

The branch is preserved by every path above. **Recovery from a half-delete:** if `rm -rf` removed the `.git` file but stalled on a locked one, `git worktree remove --force` then errors (`'…/.git' does not exist`) and the branch stays pinned to the dead tree (`git worktree list` shows it `prunable`). `git worktree prune --expire=now` drops the stale admin entry and **frees the branch**: enough to reuse it even while leftover files linger; clear those once the lock releases, usually the instant a stranded shell cwd moves off it (step 1).

**Machine-global tool caches outlive a removed tree and record its paths.** Where PHPStan's `tmpDir` is the default, its container/stub cache in the system temp directory (`%TEMP%\phpstan` on Windows, PHP's `sys_get_temp_dir()` elsewhere) pins the absolute phar path of whichever checkout built it, so after a tree is deleted PHPStan can fail from *any* checkout with `"phar://…/<deleted-tree>/vendor/phpstan/phpstan.phar/…" is not a file` (observed in `uams-statamic`). `clear-result-cache` does **not** fix this; it clears a different file. Point the run at a private cache rather than wiping a shared directory a concurrent session may be mid-analysis on: write a throwaway config that `includes:` the repo's `phpstan.neon.dist` and sets `parameters: tmpDir:`, then `phpstan analyse -c <that config>`. There is no `--tmp-dir` flag. A repository whose `phpstan.neon.dist` pins `tmpDir` to a path relative to the config file avoids this entirely: each tree gets its own cache inside itself and no shared system-temp directory is involved; what remains there is ordinary reuse staleness in a long-lived tree, which `clear-result-cache` addresses and deleting the cache directory in that tree is the blunt form of. Stable slots make the machine-global case rare either way: it is triggered by paths *dying*, which is exactly what they stop doing.

Here [`phpstan.neon.dist`](../../phpstan.neon.dist) pins `tmpDir: .phpstan-cache`, so each tree has its own cache; `clear-result-cache` addresses the reuse staleness that remains, and deleting `.phpstan-cache/` in that tree is the blunt form.

## Where it lands / the DRY line

This file is the standing statement on **worktree lifecycle**: the slot model, claiming and releasing, the refresh gate, provisioning, and removal. `AGENTS.md` carries a one-line pointer here; the `EnterWorktree` / `ExitWorktree` *mechanics* live in those tools' own descriptions: don't restate them. It composes with the branch → PR → merge flow in `AGENTS.md` (a slot changes *where* you branch, not *how* you ship), with [`sync-pr-branch`](sync-pr-branch.md) (which owns bringing a *branch* current: this rule owns refreshing the *tree* that branch sits in), and with [`long-running-commands`](long-running-commands.md) (which owns bounding a run and attributing a process to the tree that started it, and not trampling one somebody else owns once isolated).

The `local-ci` skill owns the CI tree entirely, and the chain that runs in it.

It also composes with [`supported-platforms`](supported-platforms.md): every measurement above names the platform and git version it was taken on because that rule requires it, and with the [`host-app-tests`](../skills/host-app-tests/SKILL.md) skill, which claims a slot in the *host* app rather than here.

