<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests\Support;

use FilesystemIterator;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Finds private and protected methods declared in a trait that nothing reaches.
 *
 * Tokenizes rather than matching text, for the reason the sibling guard in
 * {@see ContentSubmoduleState}'s band
 * gives: this repo documents what it removes, so a docblock naming a helper
 * would read as a caller and keep dead code alive forever. `token_get_all()`
 * drops comments, so prose about a method never counts as use of it.
 *
 * **Scoped to traits, which is the exact gap PHPStan leaves.** Measured on this
 * repo at `level: max`: an uncalled private method in a `final class` is
 * reported as `method.unused`, and so is one that only calls itself. Move the
 * identical pair into a trait a class composes and PHPStan reports **No
 * errors** — it cannot know a trait's private method is unused, because a trait
 * may be composed anywhere. Everything this repo has accumulated sits in that
 * gap: wordpress-importer-archive#740's nineteen members and wordpress-importer-archive#747's twentieth were all declared in
 * `WordPressImportTestTrait`. Staying inside it is deliberate; a guard that also
 * judged class methods would be a second opinion on a question PHPStan already
 * answers.
 *
 * **What it can get wrong, in both directions.** An earlier draft claimed every
 * limit here under-reports. That was wrong — an adversarial review found three
 * false positives it had argued were impossible — so each direction is now
 * stated and tested rather than asserted in bulk.
 *
 * *Under-reports, which is the safe direction:*
 *
 * - **A name shared with an unrelated symbol reads as a reference**, keeping a
 *   dead method in the live set.
 *
 * *Would over-report, so each is handled and pinned by a test:*
 *
 * - **Case.** PHP method names are case-insensitive: `$this->CASELIVE()` calls
 *   `caseLive()`. A byte-exact compare reported that live method as dead.
 * - **Names used as strings.** `[$this, 'helper']`, `$this->{'helper'}()`, a
 *   provider name and a reflection lookup are `T_CONSTANT_ENCAPSED_STRING`, and
 *   count as references.
 * - **Magic methods.** `__construct`, `__clone` and their siblings are invoked
 *   by the engine and named nowhere, so they are skipped.
 * - **Framework overrides.** `protected` methods are judged too (wordpress-importer-archive#1407): the
 *   Feature helpers became `protected` when the trait moved onto a real base
 *   class (wordpress-importer-archive#1031), and a guard limited to `private` stopped seeing them. A
 *   protected method can override one the framework calls by name, such as
 *   `setUp()`, and so be reached with no caller in this tree. The rule is
 *   therefore: **a protected method whose name is also declared on a
 *   test-case base class in {@see self::FRAMEWORK_BASES} is an override, and is
 *   skipped.** Private methods are not polymorphic, so the rule never applies to
 *   them. The protected overrides in test doubles (subclasses of importer
 *   classes in `tests/Unit` and `tests/Integration`) sit in classes, not traits,
 *   so the trait scope already leaves them out.
 * - **Cycles.** Two dead helpers calling only each other kept each other alive
 *   under a survivor count. Liveness is therefore computed as **reachability**:
 *   a method is live when something outside every guarded trait method body names it,
 *   and liveness propagates from there. A cycle nothing reaches falls out whole.
 */
final class PrivateMethodUsage
{
    /**
     * Test-case base classes whose methods a trait's protected method may override.
     *
     * A base class that is not installed is skipped rather than failing the guard.
     *
     * @var list<class-string>
     */
    private const FRAMEWORK_BASES = [
        PHPUnitTestCase::class,
        LaravelTestCase::class,
    ];

    /**
     * Read every PHP file under a directory.
     *
     * @param  string  $root  Directory to walk
     * @return array<string, string> Absolute path to source
     */
    public static function readTree(string $root): array
    {
        $sources = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());

            if ($contents !== false) {
                $sources[$file->getPathname()] = $contents;
            }
        }

        \ksort($sources);

        return $sources;
    }

    /**
     * The private and protected trait methods nothing reaches.
     *
     * @param  array<string, string>  $sources  Path to source, as from {@see self::readTree()}
     * @return list<array{file: string, name: string}> Orphans, in path then declaration order
     */
    public static function orphans(array $sources): array
    {
        /** @var list<array{file: string, name: string, nameIndex: int, bodyStart: int, bodyEnd: int}> $declarations */
        $declarations = [];
        $tokensByFile = [];

        foreach ($sources as $path => $source) {
            $tokens = \token_get_all($source);
            $tokensByFile[$path] = $tokens;

            foreach (self::declarationsIn($tokens, self::traitSpans($tokens)) as $declaration) {
                $declarations[] = ['file' => $path] + $declaration;
            }
        }

        // Index every token that could name a declared method, once. Rescanning the
        // whole corpus per declaration made the guard 17x slower than the gate
        // needs it to be; this is the same answer for a fraction of the work.
        $occurrences = self::indexOccurrences($tokensByFile, $declarations);

        // Seed liveness from root code: anything not inside a guarded trait method body.
        $live = [];

        foreach ($declarations as $index => $declaration) {
            if (self::referencedFrom($occurrences, $declaration, $declarations, null) > 0) {
                $live[$index] = true;
            }
        }

        // Then propagate: what a live body names is live too. Repeat to a fixed
        // point, so a chain of helpers stays up and a cycle nothing reaches does not.
        do {
            $grew = false;

            foreach ($declarations as $index => $declaration) {
                if (isset($live[$index])) {
                    continue;
                }

                foreach (\array_keys($live) as $liveIndex) {
                    if (self::referencedFrom($occurrences, $declaration, $declarations, $liveIndex) > 0) {
                        $live[$index] = true;
                        $grew = true;

                        break;
                    }
                }
            }
        } while ($grew);

        $orphans = [];

        foreach ($declarations as $index => $declaration) {
            if (! isset($live[$index])) {
                $orphans[] = ['file' => $declaration['file'], 'name' => $declaration['name']];
            }
        }

        return $orphans;
    }

    /**
     * Every `private` or `protected function` declared inside a trait body.
     *
     * A protected method that overrides a framework base-class method is left out,
     * per the rule in the class docblock.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens  A tokenized file
     * @param  list<array{0: int, 1: int}>  $traitSpans  Brace spans of each trait body
     * @return list<array{name: string, nameIndex: int, bodyStart: int, bodyEnd: int}> Declarations
     */
    private static function declarationsIn(array $tokens, array $traitSpans): array
    {
        $declarations = [];

        foreach ($tokens as $index => $token) {
            if (! \is_array($token) || ! \in_array($token[0], [\T_PRIVATE, \T_PROTECTED], true)) {
                continue;
            }

            $isProtected = $token[0] === \T_PROTECTED;

            $cursor = self::skipTrivia($tokens, $index + 1);

            if (\is_array($tokens[$cursor] ?? null) && $tokens[$cursor][0] === \T_STATIC) {
                $cursor = self::skipTrivia($tokens, $cursor + 1);
            }

            // A private *property* has no T_FUNCTION and drops out here.
            if (! \is_array($tokens[$cursor] ?? null) || $tokens[$cursor][0] !== \T_FUNCTION) {
                continue;
            }

            $nameIndex = self::skipTrivia($tokens, $cursor + 1);
            $name = $tokens[$nameIndex] ?? null;

            if (! \is_array($name) || $name[0] !== \T_STRING) {
                continue;
            }

            if (! self::within($nameIndex, $traitSpans)) {
                continue;
            }

            // Magic methods are invoked by the engine and named nowhere.
            if (\str_starts_with($name[1], '__')) {
                continue;
            }

            // A protected framework override is called by name from outside this tree.
            if ($isProtected && isset(self::frameworkMethods()[\strtolower($name[1])])) {
                continue;
            }

            [$bodyStart, $bodyEnd] = self::bodySpan($tokens, $nameIndex);

            $declarations[] = [
                'name' => $name[1],
                'nameIndex' => $nameIndex,
                'bodyStart' => $bodyStart,
                'bodyEnd' => $bodyEnd,
            ];
        }

        return $declarations;
    }

    /**
     * Lowercased names of every method declared on the framework base classes.
     *
     * @return array<string, true> Method name to true
     */
    private static function frameworkMethods(): array
    {
        $methods = [];

        foreach (self::FRAMEWORK_BASES as $base) {
            if (! \class_exists($base)) {
                continue;
            }

            // Reflection, not get_class_methods(): called from outside the class
            // that returns public methods only, and setUp() is protected.
            foreach ((new ReflectionClass($base))->getMethods() as $method) {
                $methods[\strtolower($method->getName())] = true;
            }
        }

        return $methods;
    }

    /**
     * Every token that could name one of the declared methods, keyed by name.
     *
     * @param  array<string, array<int, array{0: int, 1: string, 2: int}|string>>  $tokensByFile  Every tokenized file
     * @param  list<array{file: string, name: string, nameIndex: int, bodyStart: int, bodyEnd: int}>  $declarations  Every declaration found
     * @return array<string, list<array{file: string, index: int}>> Lowercased name to its occurrences
     */
    private static function indexOccurrences(array $tokensByFile, array $declarations): array
    {
        $wanted = [];

        foreach ($declarations as $declaration) {
            $wanted[\strtolower($declaration['name'])] = true;
        }

        $occurrences = [];

        foreach ($tokensByFile as $path => $tokens) {
            foreach ($tokens as $index => $token) {
                if (! \is_array($token)) {
                    continue;
                }

                if ($token[0] === \T_STRING) {
                    $key = \strtolower($token[1]);
                } elseif ($token[0] === \T_CONSTANT_ENCAPSED_STRING) {
                    $key = \strtolower(\trim($token[1], "'\""));
                } else {
                    continue;
                }

                if (isset($wanted[$key])) {
                    $occurrences[$key][] = ['file' => $path, 'index' => $index];
                }
            }
        }

        return $occurrences;
    }

    /**
     * How often a declaration is named from a given scope.
     *
     * @param  array<string, list<array{file: string, index: int}>>  $occurrences  Name index from {@see self::indexOccurrences()}
     * @param  array{file: string, name: string, nameIndex: int, bodyStart: int, bodyEnd: int}  $declaration  The method being judged
     * @param  list<array{file: string, name: string, nameIndex: int, bodyStart: int, bodyEnd: int}>  $declarations  Every declaration found
     * @param  int|null  $scope  Index of the body to look in, or null for everything outside all of them
     * @return int Matching references
     */
    private static function referencedFrom(
        array $occurrences,
        array $declaration,
        array $declarations,
        ?int $scope
    ): int {
        $count = 0;

        foreach ($occurrences[\strtolower($declaration['name'])] ?? [] as $occurrence) {
            $path = $occurrence['file'];
            $index = $occurrence['index'];

            if ($path === $declaration['file'] && $index === $declaration['nameIndex']) {
                continue;
            }

            if ($scope === null) {
                if (! self::insideAnyBody($path, $index, $declarations)) {
                    $count++;
                }

                continue;
            }

            if (self::insideBody($path, $index, $declarations[$scope])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether a token sits inside one declaration's body.
     *
     * @param  string  $path  File the token is in
     * @param  int  $index  Token index
     * @param  array{file: string, name: string, nameIndex: int, bodyStart: int, bodyEnd: int}  $body  The declaration
     * @return bool True when it is inside
     */
    private static function insideBody(string $path, int $index, array $body): bool
    {
        return $body['file'] === $path
            && $index > $body['bodyStart']
            && $index < $body['bodyEnd'];
    }

    /**
     * Whether a token sits inside any guarded trait method body.
     *
     * @param  string  $path  File the token is in
     * @param  int  $index  Token index
     * @param  list<array{file: string, name: string, nameIndex: int, bodyStart: int, bodyEnd: int}>  $declarations  Every declaration found
     * @return bool True when it is inside one
     */
    private static function insideAnyBody(string $path, int $index, array $declarations): bool
    {
        foreach ($declarations as $body) {
            if (self::insideBody($path, $index, $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a token opens a curly block.
     *
     * `{` is a plain string token in ordinary code, but PHP emits `T_CURLY_OPEN`
     * for the brace in `"{$interpolated}"` and `T_DOLLAR_OPEN_CURLY_BRACES` for
     * `"${name}"`, while the *closing* brace of both is a plain `}`. Counting only
     * the string form therefore misses opens and never misses closes, so depth
     * walks off the end of the first interpolated string — this trait's span
     * closed 13,553 tokens early before this existed.
     *
     * @param  array{0: int, 1: string, 2: int}|string  $token  One token
     * @return bool True when the token opens a block
     */
    private static function opensBlock(array|string $token): bool
    {
        if ($token === '{') {
            return true;
        }

        return \is_array($token)
            && \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true);
    }

    /**
     * Brace spans of every `trait` body in a token stream.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens  A tokenized file
     * @return list<array{0: int, 1: int}> Opening and closing brace indices
     */
    private static function traitSpans(array $tokens): array
    {
        $spans = [];

        foreach ($tokens as $index => $token) {
            if (! \is_array($token) || $token[0] !== \T_TRAIT) {
                continue;
            }

            $depth = 0;
            $start = null;

            for ($cursor = $index; $cursor < \count($tokens); $cursor++) {
                $current = $tokens[$cursor];

                if ($current === ';' && $depth === 0) {
                    break;
                }

                if (self::opensBlock($current)) {
                    $depth++;

                    if ($depth === 1) {
                        $start = $cursor;
                    }
                }

                if ($current === '}') {
                    $depth--;

                    if ($depth === 0 && $start !== null) {
                        $spans[] = [$start, $cursor];

                        break;
                    }
                }
            }
        }

        return $spans;
    }

    /**
     * Whether a token index falls inside any of the given spans.
     *
     * @param  int  $index  Token index to test
     * @param  list<array{0: int, 1: int}>  $spans  Spans to test against
     * @return bool True when the index is inside one
     */
    private static function within(int $index, array $spans): bool
    {
        foreach ($spans as $span) {
            if ($index > $span[0] && $index < $span[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Advance past whitespace, comments and attributes.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens  A tokenized file
     * @param  int  $index  Where to start
     * @return int The first meaningful token at or after $index
     */
    private static function skipTrivia(array $tokens, int $index): int
    {
        while (
            \is_array($tokens[$index] ?? null)
            && \in_array($tokens[$index][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_ATTRIBUTE], true)
        ) {
            $index++;
        }

        return $index;
    }

    /**
     * Token indices spanning a method's body, so its own references can be scoped.
     *
     * An abstract or interface method has no body; both ends come back equal,
     * which makes every "inside this body" test vacuously false.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens  A tokenized file
     * @param  int  $nameIndex  Index of the method-name token
     * @return array{0: int, 1: int} The opening and closing brace indices
     */
    private static function bodySpan(array $tokens, int $nameIndex): array
    {
        $depth = 0;
        $start = null;

        for ($index = $nameIndex; $index < \count($tokens); $index++) {
            $token = $tokens[$index];

            if ($token === ';' && $depth === 0) {
                return [$nameIndex, $nameIndex];
            }

            if (self::opensBlock($token)) {
                $depth++;

                if ($depth === 1) {
                    $start = $index;
                }
            }

            if ($token === '}') {
                $depth--;

                if ($depth === 0 && $start !== null) {
                    return [$start, $index];
                }
            }
        }

        return [$nameIndex, $nameIndex];
    }
}
