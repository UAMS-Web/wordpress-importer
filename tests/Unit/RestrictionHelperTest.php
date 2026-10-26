<?php

declare(strict_types=1);

/**
 * Tests that `RestrictionHelper` reads a WordPress role restriction in any of
 * the shapes the exports store it (an array, a JSON string, a comma list),
 * recognizes a guest-only restriction, and writes the restriction fields and
 * the roles note onto an entry (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/RestrictionHelperTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\RestrictionHelper;

it('formats the roles as a quoted list for the import notes', function (mixed $role, string $expected): void {
    expect(RestrictionHelper::formatRolesForNotes($role))->toBe($expected);
})->with([
    'an array' => [['admin', 'editor'], 'restriction_user_roles: "admin", "editor"'],
    'a JSON array' => ['["admin","editor"]', 'restriction_user_roles: "admin", "editor"'],
    'a JSON object, read by its values' => ['{"a":"x"}', 'restriction_user_roles: "x"'],
    'a comma list, trimmed, with empties dropped' => ['admin, editor ,', 'restriction_user_roles: "admin", "editor"'],
    'JSON that is not an array, read as a list' => ['5', 'restriction_user_roles: "5"'],
    'a numeric role' => [[7], 'restriction_user_roles: "7"'],
    'a quote inside a role' => [['say "hi"'], 'restriction_user_roles: "say \"hi\""'],
    'the string zero, which is a role and not nothing' => ['0', 'restriction_user_roles: "0"'],
]);

it('leaves an empty item for a role that is neither a string nor a number', function (): void {
    // Characterization: a non-scalar role is not dropped, it renders as nothing
    expect(RestrictionHelper::formatRolesForNotes(['admin', ['nested'], '']))->toBe('restriction_user_roles: "admin", ');
});

it('formats no roles as nothing', function (mixed $role): void {
    expect(RestrictionHelper::formatRolesForNotes($role))->toBeNull();
})->with([
    'null' => [null],
    'an empty string' => [''],
    'false' => [false],
    'an empty array' => [[]],
    'an empty JSON array' => ['[]'],
    'only empty roles' => [['', null, false, []]],
    'a JSON array of empty roles' => ['[""]'],
]);

it('drops a bare scalar role that is not a string', function (mixed $role): void {
    // Characterization: an integer inside an array formats, but a bare integer
    // or boolean is neither an array nor a string, so it yields no roles
    expect(RestrictionHelper::formatRolesForNotes($role))->toBeNull();
})->with([
    'an integer' => [42],
    'true' => [true],
]);

it('recognizes a guest-only restriction', function (mixed $role, bool $expected): void {
    expect(RestrictionHelper::isGuestOnlyRole($role))->toBe($expected);
})->with([
    'no restriction at all' => [null, true],
    'an empty string' => ['', true],
    'false' => [false, true],
    'an empty array' => [[], true],
    'an array of guest' => [['guest'], true],
    'an array of guest and another' => [['guest', 'admin'], false],
    'an array of another role' => [['admin'], false],
    'a JSON array of guest' => ['["guest"]', true],
    'a padded JSON array of guest' => [' ["guest"] ', true],
    'a JSON array of guest and another' => ['["guest","x"]', false],
    'a JSON array of another role' => ['["admin"]', false],
    'single-quoted, which is not JSON' => ["['guest']", true],
    'single-quoted and padded' => [" ['guest'] ", true],
    'a bare word' => ['guest', false],
    'a number' => [5, false],
    'an array holding true, not the word guest' => [[true], false],
    'a JSON object whose one value is guest' => ['{"a":"guest"}', true],
    'a JSON array holding true' => ['[true]', false],
    'a JSON string, which is not a list' => ['"guest"', false],
]);

it('marks the entry as restricted by user role and records the roles in its notes', function (): void {
    $entry = ['title' => 'Members area'];

    RestrictionHelper::applyRestrictionFields($entry, ['admin']);

    expect($entry)->toBe([
        'title' => 'Members area',
        'restriction_category' => ['user_roles'],
        'restriction_user_roles' => null,
        'import_wordpress_notes' => ['restriction_user_roles: "admin"'],
    ]);
});

it('appends the roles note to notes already on the entry', function (): void {
    $entry = ['import_wordpress_notes' => ['earlier note']];

    RestrictionHelper::applyRestrictionFields($entry, '["editor"]');

    expect($entry['import_wordpress_notes'])->toBe(['earlier note', 'restriction_user_roles: "editor"']);
});

it('replaces notes that are not a list before appending', function (): void {
    $entry = ['import_wordpress_notes' => 'a string'];

    RestrictionHelper::applyRestrictionFields($entry, ['editor']);

    expect($entry['import_wordpress_notes'])->toBe(['restriction_user_roles: "editor"']);
});

it('marks the entry as restricted but adds no note when there are no roles', function (): void {
    $entry = ['restriction_user_roles' => ['old']];

    RestrictionHelper::applyRestrictionFields($entry, null);

    expect($entry)->toBe([
        'restriction_user_roles' => null,
        'restriction_category' => ['user_roles'],
    ]);
});
