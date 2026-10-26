# Timing probe — taxonomy/term existence checks (issue wordpress-importer-archive#46)

The dominant cost the importer controls is the **per-term existence check**. Before
this change, `createTaxonomyTermsFromStoredData()` called `TermFacade::find()` once
per term; each call routes through `TermRepository::query()`, which rebuilds a query
and runs `ensureAssociations()` across **every** taxonomy. The fix preloads all
existing terms for a taxonomy in a single `whereTaxonomy()` pass and tests membership
against an in-memory map.

This is the issue's recommended "cheapest probe": time `find()`-per-term vs one
`whereTaxonomy()` against a real taxonomy. It is **read-only** — it mutates nothing
but the Stache cache (`clear()`/`warm()`), so it is safe to run against a dev app.

## How to run

Save as `probe.php` and run from the host Statamic app dir with the Herd PHP
(`php probe.php`). Point `$appDir` at your app. Adjust `$handles` to taxonomies that
exist in your app (more terms = larger effect).

```php
<?php
use Illuminate\Contracts\Console\Kernel;
use Statamic\Facades\Stache;
use Statamic\Facades\Term;

$appDir = '<path to the uams-statamic checkout>';
require $appDir.'\\vendor\\autoload.php';
$app = require $appDir.'\\bootstrap\\app.php';
$app->make(Kernel::class)->bootstrap();
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);

$ms = fn ($s) => (int) round((microtime(true) - $s) * 1000);
$handles = ['fields_of_study', 'credentials', 'specialties_acgme', 'service_lines'];

Stache::warm(); // steady state: isolate per-pattern overhead from the one-time rebuild

foreach ($handles as $h) {
    $ids = Term::query()->where('taxonomy', $h)->get()->map->id()->all();
    if (! $ids) { continue; }

    $s = microtime(true);
    foreach ($ids as $id) { Term::find($id); }          // old pattern: N finds
    $findMs = $ms($s);

    $s = microtime(true);
    $map = [];
    foreach (Term::whereTaxonomy($h) as $t) { $map[$t->id()] = $t; } // new: 1 query
    foreach ($ids as $id) { $x = isset($map[$id]); }    // + N in-memory lookups
    $preloadMs = $ms($s);

    printf("%-20s terms=%-4d findEachMs=%-6d preloadMs=%-5d speedup=%.1fx\n",
        $h, count($ids), $findMs, $preloadMs, $preloadMs ? $findMs / $preloadMs : 0);
}
```

For a cold-cache variant (closer to the start of an import), call `Stache::clear()`
immediately before each timed block instead of `Stache::warm()` once up front.

## Measured results

Against `uams-statamic` (Herd, file cache; ~40 taxonomies, none attached to
collections, so the associations entry-scan cost is ~0 in this app):

**Warm steady state** (per-pattern overhead, ~15–16 ms per `find()`):

| taxonomy | terms | find()×N | whereTaxonomy()×1 | speedup |
|---|---|---|---|---|
| fields_of_study | 456 | 7570 ms | 109 ms | **69×** |
| credentials | 248 | 3762 ms | 67 ms | **56×** |
| specialties_acgme | 146 | 2200 ms | 45 ms | **49×** |
| service_lines | 46 | 736 ms | 23 ms | **32×** |

**Cold cache** (each block preceded by `Stache::clear()`, so both pay a full rebuild):

| taxonomy | terms | find()×N | whereTaxonomy()×1 | speedup |
|---|---|---|---|---|
| fields_of_study | 456 | 14022 ms | 5949 ms | 2.4× |
| credentials | 248 | 9885 ms | 2894 ms | 3.4× |
| specialties_acgme | 146 | 8936 ms | 4469 ms | 2.0× |
| service_lines | 46 | 6796 ms | 2644 ms | 2.6× |

The per-`find()` overhead is a near-constant ~15–16 ms regardless of taxonomy, so the
storm grows linearly with total term count: a WXR with several thousand terms spends
**minutes** in existence checks alone. The preload collapses that to one query per
taxonomy.

## Note on Part 2 (paused indexing)

Part 2 (`Stache::disableUpdatingIndexes()` around the save loops, guarded by
`StacheIndexUpdateToggle`) was **not** separately benchmarked here: it only skips the
full `Index::update()`/`getItems()` entry-scan, which is ~0 in this app (no taxonomy is
attached to a collection) and which the preload already reduces to one pass. By
mechanism its marginal win is small on top of Part 1; the guard makes leaving it
dormant a no-op. Re-measure on a WXR/app where taxonomies *are* attached to large
collections (where the associations rebuild actually scans entries) to see its effect.
