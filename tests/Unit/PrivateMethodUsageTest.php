<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Tests\Support\PrivateMethodUsage;
use UAMSWeb\WordPressImporter\Tests\Unit\UncalledPrivateHelperGuardTest;

/**
 * Pins both directions of the trait-orphan detector behind
 * {@see UncalledPrivateHelperGuardTest} (wordpress-importer-archive#748).
 *
 * Every case here is one an adversarial review raised. Three were **false
 * positives the class's own docblock had argued were impossible** — a live
 * method reported dead because the call used different letter case, because it
 * was reached through `[$this, 'name']`, or because it was magic — and one was a
 * pair of dead helpers holding each other up. A guard that flags live code gets
 * deleted rather than fixed, so the over-reporting direction is tested first.
 *
 * `orphans()` takes source keyed by path, so these are strings rather than
 * fixture files on disk.
 *
 * @command  php vendor/bin/pest tests/Unit/PrivateMethodUsageTest.php
 */

/**
 * Names reported as orphans for one snippet of trait source.
 *
 * @param  string  $body  Trait body source
 * @return list<string> The reported method names
 */
function privateMethodUsageOrphans(string $body): array
{
    $source = "<?php\ntrait FixtureTrait\n{\n".$body."\n}\n";

    return \array_map(
        static fn (array $orphan): string => $orphan['name'],
        PrivateMethodUsage::orphans(['/fixture/Fixture.php' => $source])
    );
}

it('reports a private trait method nothing names', function (): void {
    expect(privateMethodUsageOrphans('    private function dead(): string { return "x"; }'))
        ->toBe(['dead']);
});

it('reports one that only calls itself, because recursion is not use', function (): void {
    expect(privateMethodUsageOrphans(
        '    private function selfOnly(int $n): int { return $n > 0 ? $this->selfOnly($n - 1) : 0; }'
    ))->toBe(['selfOnly']);
});

it('reports a cycle of dead helpers that hold each other up', function (): void {
    // Counting survivors leaves both alive forever: each is named from the
    // other's body. Reachability from live code is what makes the pair fall out.
    $orphans = privateMethodUsageOrphans(
        '    private function ping(int $n): int { return $n > 0 ? $this->pong($n - 1) : 0; }'."\n"
        .'    private function pong(int $n): int { return $n > 0 ? $this->ping($n - 1) : 0; }'
    );

    expect($orphans)->toBe(['ping', 'pong']);
});

it('keeps a method called with different letter case', function (): void {
    // PHP method names are case-insensitive, so this is a real call. A byte-exact
    // comparison reported it dead — a live method failing the gate.
    expect(privateMethodUsageOrphans(
        '    private function caseLive(): string { return "x"; }'."\n"
        .'    public function uses(): string { return $this->CASELIVE(); }'
    ))->toBe([]);
});

it('keeps a method reached only through a callable array', function (): void {
    expect(privateMethodUsageOrphans(
        '    private function mapper(string $s): string { return $s; }'."\n"
        .'    public function uses(array $in): array { return array_map([$this, "mapper"], $in); }'
    ))->toBe([]);
});

it('keeps a method named only as a string', function (): void {
    expect(privateMethodUsageOrphans(
        '    private function dynamic(): string { return "x"; }'."\n"
        .'    public function uses(): string { $n = "dynamic"; return $this->{$n}(); }'
    ))->toBe([]);
});

it('never reports a magic method, which the engine invokes by name nowhere', function (): void {
    expect(privateMethodUsageOrphans('    private function __clone(): void {}'))->toBe([]);
});

it('keeps a chain of helpers that a live method enters', function (): void {
    expect(privateMethodUsageOrphans(
        '    private function first(): string { return $this->second(); }'."\n"
        .'    private function second(): string { return "x"; }'."\n"
        .'    public function uses(): string { return $this->first(); }'
    ))->toBe([]);
});

it('ignores private methods declared in a class rather than a trait', function (): void {
    // PHPStan reports these as `method.unused`; a second opinion would only ever
    // disagree with it.
    $source = "<?php\nfinal class Fixture\n{\n    private function dead(): string { return 'x'; }\n}\n";

    expect(PrivateMethodUsage::orphans(['/fixture/Fixture.php' => $source]))->toBe([]);
});

it('is not fooled by a brace inside an interpolated string', function (): void {
    // `{` in "{$x}" tokenizes as T_CURLY_OPEN but closes with a plain `}`, so
    // counting only the string form walks depth off the end of the trait and the
    // guard silently reports nothing at all.
    $orphans = privateMethodUsageOrphans(
        '    public function noisy(string $x): string { return "a {$x} b {$x} c"; }'."\n"
        .'    private function dead(): string { return "x"; }'
    );

    expect($orphans)->toBe(['dead']);
});

it('reports a protected trait method nothing names', function (): void {
    // The Feature helpers are protected since wordpress-importer-archive#1031, so a private-only guard
    // could not see one lose its last caller (wordpress-importer-archive#1407).
    expect(privateMethodUsageOrphans('    protected function deadHelper(): string { return "x"; }'))
        ->toBe(['deadHelper']);
});

it('reports a private helper reached only through a dead protected one', function (): void {
    // Before wordpress-importer-archive#1407 a protected body counted as live root code, so the private
    // helper it called stayed green with the protected caller itself orphaned.
    expect(privateMethodUsageOrphans(
        '    protected function deadCaller(): string { return $this->inner(); }'."\n"
        .'    private function inner(): string { return "x"; }'
    ))->toBe(['deadCaller', 'inner']);
});

it('keeps a protected trait method a live method calls', function (): void {
    expect(privateMethodUsageOrphans(
        '    protected function helper(): string { return "x"; }'."\n"
        .'    public function uses(): string { return $this->helper(); }'
    ))->toBe([]);
});

it('never reports a protected override of a test-case base-class method', function (): void {
    // `setUp()` is declared on PHPUnit's TestCase and called by the framework,
    // so a trait's protected `setUp()` has no caller here and is still live.
    expect(privateMethodUsageOrphans('    protected function setUp(): void {}'))->toBe([]);
});

it('still reports a private method that shares a framework method name', function (): void {
    // Private methods are not polymorphic, so the override rule does not apply.
    expect(privateMethodUsageOrphans('    private function setUp(): void {}'))->toBe(['setUp']);
});
