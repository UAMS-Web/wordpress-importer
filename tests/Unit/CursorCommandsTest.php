<?php

declare(strict_types=1);

/**
 * Pins that the Cursor commands point only at guidance that exists (wordpress-importer-archive#1276).
 *
 * `.cursor/rules/` was retired by wordpress-importer-archive#1275, and two commands were written to apply rule files there.
 * They now apply sections of the `.claude/` skills instead, so a command that names a retired rule
 * file, or a `.claude/` path or skill section that no longer exists, would send a Cursor agent to
 * nothing. A pure file read, so it runs on the bare test case.
 *
 * @command  php vendor/bin/pest tests/Unit/CursorCommandsTest.php
 */

/**
 * Every Cursor command file, keyed by its path relative to the repository root.
 *
 * @return array<string, string> The commands' contents
 */
function cursorCommands(): array
{
    $root = dirname(__DIR__, 2);
    $commands = [];

    $paths = glob($root.'/.cursor/commands/*.md');

    foreach ($paths === false ? [] : $paths as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('%s could not be read.', $path));
        }

        $commands[substr($path, strlen($root) + 1)] = $contents;
    }

    return $commands;
}

it('finds the command files it checks', function (): void {
    expect(cursorCommands())->toHaveKeys([
        '.cursor/commands/apply-arrow-functions.md',
        '.cursor/commands/apply-php-type-hints.md',
    ]);
});

it('requires each standards command to name a skill and a section of it', function (string $command): void {
    $contents = cursorCommands()['.cursor/commands/'.$command.'.md'] ?? null;

    expect($contents)->toBeString();

    if (! is_string($contents)) {
        return;
    }

    expect(preg_match_all('#\.claude/[A-Za-z0-9_./-]+\.md#', $contents))->toBeGreaterThan(0, sprintf('%s names no .claude/ path.', $command))
        ->and(preg_match_all('/"([^"]+)"/', $contents))->toBeGreaterThan(0, sprintf('%s names no skill section.', $command));
})->with(['apply-arrow-functions', 'apply-php-type-hints']);

it('names no retired .cursor/rules file', function (): void {
    foreach (cursorCommands() as $path => $contents) {
        expect(str_contains($contents, '.cursor/rules/'))->toBeFalse(sprintf('%s names a retired .cursor/rules file.', $path));
    }
});

it('points only at .claude paths that exist', function (): void {
    $root = dirname(__DIR__, 2);

    foreach (cursorCommands() as $path => $contents) {
        preg_match_all('#\.claude/[A-Za-z0-9_./-]+\.md#', $contents, $matches);

        foreach ($matches[0] as $target) {
            expect(is_file($root.'/'.$target))->toBeTrue(sprintf('%s names %s, which does not exist.', $path, $target));
        }
    }
});

it('names skill sections that exist in the skill', function (): void {
    $skill = file_get_contents(dirname(__DIR__, 2).'/.claude/skills/php-coding-standards/SKILL.md');

    if ($skill === false) {
        throw new RuntimeException('The php-coding-standards skill could not be read.');
    }

    foreach (cursorCommands() as $path => $contents) {
        if (! str_contains($contents, 'php-coding-standards')) {
            continue;
        }

        preg_match_all('/"([^"]+)"/', $contents, $matches);

        expect($matches[1])->not->toBeEmpty($path);

        foreach ($matches[1] as $section) {
            expect($skill)->toMatch('/^## \d+\. '.preg_quote($section, '/').'\b/m', sprintf('%s names section "%s".', $path, $section));
        }
    }
});
