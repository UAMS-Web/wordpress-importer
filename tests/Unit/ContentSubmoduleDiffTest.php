<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Tests\Support\ContentSubmoduleChange;
use UAMSWeb\WordPressImporter\Tests\Support\ContentSubmoduleDiff;
use UAMSWeb\WordPressImporter\Tests\Support\ContentSubmoduleState;

/**
 * Build a state from `XY path` pairs, joined the way `git status -z` writes them.
 *
 * @param  list<string>  $fields  Raw NUL-separated fields, in git's order
 * @param  string|null  $head  The revision those paths differ from
 */
function submoduleState(array $fields, ?string $head = 'a1b2c3d'): ContentSubmoduleState
{
    // git terminates every field with a NUL, including the last
    return ContentSubmoduleState::fromPorcelain(
        $fields === [] ? '' : \implode("\0", $fields)."\0",
        $head
    );
}

// ---
// Parsing — the `-z` form, which exists so paths need no unquoting.
// ---

it('parses one untracked path', function (): void {
    $state = submoduleState(['?? collections/posts/imported.md']);

    expect($state->changes)->toHaveCount(1);
    expect($state->changes[0]->status)->toBe('??');
    expect($state->changes[0]->path)->toBe('collections/posts/imported.md');
    expect($state->changes[0]->origin)->toBeNull();
});

it('parses a path containing a space without quoting it', function (): void {
    // The whole reason for `-z`: porcelain v1 would render this as
    // `"collections/posts/two words.md"`, quotes included.
    $state = submoduleState(['?? collections/posts/two words.md']);

    expect($state->changes)->toHaveCount(1);
    expect($state->changes[0]->path)->toBe('collections/posts/two words.md');
});

it('consumes a rename origin instead of reading it as its own entry', function (): void {
    // git writes `XY <new>` then a SECOND field holding `<old>`. Read naively,
    // that second field becomes an entry whose path has two characters sliced
    // off the front — the parse bug this pairing exists to prevent.
    $state = submoduleState([
        'R  collections/posts/new-slug.md',
        'collections/posts/old-slug.md',
        '?? collections/posts/stray.md',
    ]);

    expect($state->changes)->toHaveCount(2);
    expect($state->changes[0]->status)->toBe('R ');
    expect($state->changes[0]->path)->toBe('collections/posts/new-slug.md');
    expect($state->changes[0]->origin)->toBe('collections/posts/old-slug.md');

    // The entry after the rename is still parsed correctly, not shifted by one
    expect($state->changes[1]->path)->toBe('collections/posts/stray.md');
    expect($state->changes[1]->origin)->toBeNull();
});

it('consumes a copy origin as well as a rename origin', function (): void {
    $state = submoduleState(['C  trees/collections/copy.yaml', 'trees/collections/default.yaml']);

    expect($state->changes)->toHaveCount(1);
    expect($state->changes[0]->origin)->toBe('trees/collections/default.yaml');
});

it('skips a field too short to be an entry rather than slicing a path out of it', function (): void {
    $state = submoduleState(['??', '?? collections/posts/real.md']);

    expect($state->changes)->toHaveCount(1);
    expect($state->changes[0]->path)->toBe('collections/posts/real.md');
});

it('parses empty porcelain as a clean tree', function (): void {
    expect(submoduleState([])->changes)->toBe([]);
});

// ---
// Classification — what the run did, versus what it inherited.
// ---

it('charges the run for a path that was not dirty before it', function (): void {
    $diff = ContentSubmoduleDiff::between(
        submoduleState([]),
        submoduleState(['?? collections/posts/imported.md'])
    );

    expect($diff->isClean())->toBeFalse();
    expect($diff->introduced)->toHaveCount(1);
    expect($diff->introduced[0]->path)->toBe('collections/posts/imported.md');
    expect($diff->preexisting)->toBe([]);
});

it('does not charge the run for a path that arrived dirty', function (): void {
    $dirty = ['?? collections/posts/stranded.md'];

    $diff = ContentSubmoduleDiff::between(submoduleState($dirty), submoduleState($dirty));

    expect($diff->isClean())->toBeTrue();
    expect($diff->introduced)->toBe([]);
    expect($diff->preexisting)->toHaveCount(1);
    expect($diff->preexisting[0]->path)->toBe('collections/posts/stranded.md');
});

it('separates an inherited path from one the run added in the same tree', function (): void {
    $diff = ContentSubmoduleDiff::between(
        submoduleState(['?? collections/posts/stranded.md']),
        submoduleState(['?? collections/posts/stranded.md', '?? collections/posts/imported.md'])
    );

    expect($diff->isClean())->toBeFalse();
    expect(\array_map(fn (ContentSubmoduleChange $c): string => $c->path, $diff->introduced))
        ->toBe(['collections/posts/imported.md']);
    expect(\array_map(fn (ContentSubmoduleChange $c): string => $c->path, $diff->preexisting))
        ->toBe(['collections/posts/stranded.md']);
});

it('treats a path whose status changed under the run as still inherited', function (): void {
    // Identity is the path, not the status: the question is whether this path
    // was already dirty when the test started, and it was.
    $diff = ContentSubmoduleDiff::between(
        submoduleState([' M collections/posts/existing.md']),
        submoduleState(['MM collections/posts/existing.md'])
    );

    expect($diff->isClean())->toBeTrue();
    expect($diff->preexisting)->toHaveCount(1);
});

it('reports a clean run as clean', function (): void {
    $diff = ContentSubmoduleDiff::between(submoduleState([]), submoduleState([]));

    expect($diff->isClean())->toBeTrue();
    expect($diff->failureMessage('/host/content'))->toBe('');
    expect($diff->warnings('/host/content'))->toBe([]);
});

// ---
// The revision, which a status probe alone is blind to.
// ---

it('fails a run that moved the submodule to another revision, even with no dirty path', function (): void {
    $diff = ContentSubmoduleDiff::between(
        submoduleState([], 'aaaaaaa'),
        submoduleState([], 'bbbbbbb')
    );

    expect($diff->headMoved())->toBeTrue();
    expect($diff->isClean())->toBeFalse();
    expect($diff->introduced)->toBe([]);
});

it('does not call an unreadable revision a move', function (): void {
    // A probe that failed is an environment problem, not a test that checked
    // something out; blaming the run for it would make the guard cry wolf.
    expect(ContentSubmoduleDiff::between(submoduleState([], null), submoduleState([], 'bbbbbbb'))->headMoved())
        ->toBeFalse();
    expect(ContentSubmoduleDiff::between(submoduleState([], 'aaaaaaa'), submoduleState([], null))->headMoved())
        ->toBeFalse();
    expect(ContentSubmoduleDiff::between(submoduleState([], null), submoduleState([], null))->headMoved())
        ->toBeFalse();
});

it('does not call an unchanged revision a move', function (): void {
    expect(ContentSubmoduleDiff::between(submoduleState([], 'aaaaaaa'), submoduleState([], 'aaaaaaa'))->headMoved())
        ->toBeFalse();
});

// ---
// The report — a failure that does not name the path and the fix is a failure
// the reader has to re-derive.
// ---

it('names every introduced path and what its status implies', function (): void {
    $diff = ContentSubmoduleDiff::between(
        submoduleState([]),
        submoduleState(['?? collections/posts/imported.md', ' M trees/collections/default.yaml'])
    );

    $message = $diff->failureMessage('/host/content');

    expect($message)->toContain('collections/posts/imported.md');
    expect($message)->toContain('trees/collections/default.yaml');
    expect($message)->toContain('an import a test did not clean up');
    expect($message)->toContain('the test rewrote a file the host owns');
    expect($message)->toContain('(2 paths)');
});

it('names the restore command that matches what is actually wrong', function (): void {
    // `checkout -- .` cannot remove an untracked file and `clean -fd` cannot
    // restore a modified one, so a message naming only one leaves the reader
    // with a command that does not finish the job.
    $untracked = ContentSubmoduleDiff::between(submoduleState([]), submoduleState(['?? a.md']))
        ->failureMessage('/host/content');

    expect($untracked)->toContain('git -C /host/content clean -fd');
    expect($untracked)->not->toContain('checkout -- .');

    $tracked = ContentSubmoduleDiff::between(submoduleState([]), submoduleState([' M a.md']))
        ->failureMessage('/host/content');

    expect($tracked)->toContain('git -C /host/content checkout -- .');
    expect($tracked)->not->toContain('clean -fd');

    $both = ContentSubmoduleDiff::between(submoduleState([]), submoduleState(['?? a.md', ' M b.md']))
        ->failureMessage('/host/content');

    expect($both)->toContain('git -C /host/content checkout -- .');
    expect($both)->toContain('git -C /host/content clean -fd');
});

it('names both revisions and the checkout that undoes a moved submodule', function (): void {
    $message = ContentSubmoduleDiff::between(
        submoduleState([], 'aaaaaaa'),
        submoduleState([], 'bbbbbbb')
    )->failureMessage('/host/content');

    expect($message)->toContain('from aaaaaaa to bbbbbbb');
    expect($message)->toContain('git -C /host/content checkout aaaaaaa');
});

it('warns about an inherited path without failing on it', function (): void {
    $dirty = ['?? collections/posts/stranded.md'];
    $diff = ContentSubmoduleDiff::between(submoduleState($dirty), submoduleState($dirty));

    $warnings = $diff->warnings('/host/content');

    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('arrived dirty (1 path)');
    expect($warnings[0])->toContain('collections/posts/stranded.md');
    expect($warnings[0])->toContain('not attributed to this run');
    expect($warnings[0])->toContain('git -C /host/content clean -fd');

    // The warning is the whole output — nothing failed
    expect($diff->failureMessage('/host/content'))->toBe('');
});

// ---
// The status vocabulary — a failure names the wrong thing if these drift.
// ---

it('names what each status class means for the run', function (): void {
    // Every branch asserted: an unasserted branch can return anything, and the
    // deletion branch is the one that reports a test destroying real content.
    expect((new ContentSubmoduleChange('??', 'a.md'))->describe())
        ->toContain('an import a test did not clean up');
    expect((new ContentSubmoduleChange(' D', 'a.md'))->describe())
        ->toContain('the test deleted real content');
    expect((new ContentSubmoduleChange('D ', 'a.md'))->describe())
        ->toContain('the test deleted real content');
    expect((new ContentSubmoduleChange(' M', 'a.md'))->describe())
        ->toContain('the test rewrote a file the host owns');
    expect((new ContentSubmoduleChange(' T', 'a.md'))->describe())
        ->toContain('the test rewrote a file the host owns');

    // The fallback, for a status matching none of the above
    expect((new ContentSubmoduleChange('UU', 'a.md'))->describe())
        ->toBe('differs from the submodule\'s HEAD');
});

it('reports a deletion as a deletion even when it is also staged', function (): void {
    // `D ` puts the D in the INDEX field. A branch testing only the worktree
    // field would fall through to the generic sentence for a staged deletion —
    // the most serious case there is.
    expect((new ContentSubmoduleChange('D ', 'a.md'))->describe())
        ->not->toBe('differs from the submodule\'s HEAD');
});

// ---
// Staged changes, which the worktree restore alone cannot undo.
// ---

it('clears the index before restoring the worktree when a change is staged', function (): void {
    // `checkout -- .` restores from the INDEX, not from HEAD: against a staged
    // addition it exits 0 and changes nothing, and against a staged deletion it
    // fails outright. Either way the guard would report the same dirt forever.
    $message = ContentSubmoduleDiff::between(submoduleState([]), submoduleState(['A  staged.md']))
        ->failureMessage('/host/content');

    expect($message)->toContain('git -C /host/content restore --staged -- .');

    // Order matters — clearing the index after the checkout fixes nothing
    expect(\strpos($message, 'restore --staged'))
        ->toBeLessThan((int) \strpos($message, 'checkout -- .'));
});

it('does not suggest clearing the index when nothing is staged', function (): void {
    $message = ContentSubmoduleDiff::between(submoduleState([]), submoduleState([' M worktree.md']))
        ->failureMessage('/host/content');

    expect($message)->toContain('git -C /host/content checkout -- .');
    expect($message)->not->toContain('restore --staged');
});

it('renders a rename with the path it came from', function (): void {
    $diff = ContentSubmoduleDiff::between(
        submoduleState([]),
        submoduleState(['R  collections/posts/new.md', 'collections/posts/old.md'])
    );

    expect($diff->failureMessage('/host/content'))
        ->toContain('R  collections/posts/new.md (from collections/posts/old.md)');
});
