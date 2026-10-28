<?php

declare(strict_types=1);

use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Imports the real Gravity Forms user-registration forms and asserts their
 * `username` and `password` fields keep their semantics (`wordpress-importer-archive#301`).
 *
 * `UAMS-Web/uams-statamic-archive#312` added the Statamic `username` fieldtype and brought
 * `password` to public-form parity; `wordpress-importer-archive#300` pointed the importer's mapping at them.
 * Both were verified at the unit level — `GravityFormsBlueprintMappingTest` asserts
 * `getStatamicFieldtype('username') === 'username'` — but **nothing imported a real
 * export.** `GravityFormsBlueprintImportTest` builds synthetic forms (ids `123`,
 * `456`, `789`, `321`) and never reads these sites' files, so no test asserted that a
 * real User Registration form survives a blueprint import with its field semantics
 * intact. That gap is what this file closes.
 *
 * **The fixtures are the real forms, carried whole.** Every field is preserved rather
 * than trimmed to the two under test, because the acceptance criterion is that *no
 * other field regresses* — a fixture cut down to `username` and `password` could not
 * detect that. Between them the six forms carry nine `username`/`password` instances
 * and seven other field types: `consent`, `email`, `text`, `captcha`, `name`, `radio`
 * and `select`.
 *
 * **This is NOT host-bound, and used to say it was.** The docblock previously read
 * *"host-bound because blueprint creation is — `createBlueprintWithFields()` saves
 * through Statamic's `Blueprint` facade, which the minimal Statamic band does not
 * stand up"*. That was true when written and stopped being true with wordpress-importer-archive#827, which
 * points `BlueprintRepository` at a per-process directory, so a saved blueprint
 * round-trips inside the minimal band. Measured before the move: 12 passed, 66
 * assertions, under `StatamicTestCase`.
 *
 * **The move is the point, not the wording.** `tests/Feature/` runs nowhere in this
 * package's gate — not in `composer test`, not in `ci.yml`, not under PHPStan — so a
 * justification that had expired was holding these assertions outside it. The file
 * never called a `WordPressImportTestTrait` method and needed no database, so nothing
 * else bound it to the host (wordpress-importer-archive#844).
 *
 * **These assertions are mapping-regression cover, and did not used to be.** The second
 * test built its expectation by calling `FieldtypeMapping` — the class it was checking —
 * so a mapping change moved both sides of the comparison together and the test could not
 * fail. Measured then: `username -> text` was caught here only because the *other* test
 * counts literal occurrences, while `consent -> text` was caught by the rest of the gate
 * and not by this file at all. The expectation is stated literally in
 * `gfUserRegistrationExpectedTypes()` now, so repointing any mapping these forms use
 * fails here (wordpress-importer-archive#847).
 *
 * @command  php vendor/bin/pest tests/Integration/GravityFormsUserRegistrationImportTest.php
 */

/**
 * Directory holding the extracted user-registration fixtures.
 */
const GF_USERREG_FIXTURES = __DIR__.'/../Fixtures/form-audit/gravity-forms';

/**
 * Every form in the corpus carrying a `username` or `password` field.
 *
 * Measured across all 161 committed Gravity Forms exports: these six forms on four
 * sites are the only ones with either type, and they hold nine instances between
 * them. The counts are stated literally rather than derived, so that repointing the
 * mapping at `text` fails here rather than quietly agreeing with itself.
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: int}>
 */
function gfUserRegistrationForms(): array
{
    return [
        // fixture file, form key, title, expected username count, expected password count
        'agec form 11 — User Registration - First Responder Dementia Training' => [
            'user-registration-agec.json', '0', 'User Registration - First Responder Dementia Training', 1, 1,
        ],
        'patientslearn form 10 — Registration' => [
            'user-registration-patientslearn.json', '0', 'Registration', 0, 1,
        ],
        'patientslearn form 12 — Profile Update' => [
            'user-registration-patientslearn.json', '1', 'Profile Update', 0, 1,
        ],
        'patientslearn form 13 — User and Password' => [
            'user-registration-patientslearn.json', '2', 'User and Password', 1, 1,
        ],
        'caregiving/springdale form 24 — TestForm' => [
            'user-registration-caregiving-springdale.json', '0', 'TestForm', 1, 1,
        ],
        'caregiving/teacher form 1 — User Registration' => [
            'user-registration-caregiving-teacher.json', '0', 'User Registration', 0, 1,
        ],
    ];
}

/**
 * The Statamic fieldtypes each fixture form is expected to produce.
 *
 * Keyed by `<fixture file>:<form key>`, and sorted to match `gfUserRegistrationTypes()`.
 *
 * **Written out rather than computed from `FieldtypeMapping`, deliberately.** Deriving
 * them from the mapping made the comparison tautological: repointing a mapping changed
 * the expectation and the observation together, so no mapping regression could fail this
 * file (wordpress-importer-archive#847).
 *
 * Every Gravity Forms type these six forms carry happens to map to a Statamic type of
 * the same name, so the lists read like the raw exports — that is a property of this
 * corpus, not an identity the test assumes. `captcha` is the one type that resolves to
 * `null` and contributes no blueprint field, which is wordpress-importer-archive#815's guard.
 *
 * @return array<string, list<string>>
 */
function gfUserRegistrationExpectedTypes(): array
{
    return [
        // Two separate `text` fields, hence the repeat
        'user-registration-agec.json:0' => ['consent', 'email', 'password', 'text', 'text', 'username'],
        // Carries a `captcha`, which is skipped and so appears nowhere below
        'user-registration-patientslearn.json:0' => ['email', 'name', 'password'],
        'user-registration-patientslearn.json:1' => ['email', 'name', 'password'],
        'user-registration-patientslearn.json:2' => ['password', 'username'],
        'user-registration-caregiving-springdale.json:0' => ['password', 'radio', 'username'],
        'user-registration-caregiving-teacher.json:0' => ['email', 'name', 'password', 'select', 'text'],
    ];
}

/**
 * Read one form out of a fixture export.
 *
 * @param  string  $fixture  The fixture file name
 * @param  string  $formKey  The numeric-string key the form sits under
 * @return array<string, mixed> The Gravity Forms form definition
 */
function gfUserRegistrationForm(
    string $fixture,
    string $formKey
): array {
    $raw = file_get_contents(GF_USERREG_FIXTURES.'/'.$fixture);

    expect($raw)->not->toBeFalse();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> $form */
    $form = $decoded[$formKey];

    return $form;
}

/**
 * Import a form's fields and return the resulting blueprint.
 *
 * @param  array<string, mixed>  $form  The Gravity Forms form definition
 * @param  string  $handle  A blueprint handle unique to the calling test
 * @return Blueprint The saved blueprint
 */
function gfUserRegistrationImport(
    array $form,
    string $handle
): Blueprint {
    $creator = new FormBlueprintCreator;

    /** @var array<int|string, mixed> $fields */
    $fields = $form['fields'] ?? [];

    $title = $form['title'] ?? '';
    $formId = $form['id'] ?? '';

    $created = $creator->createBlueprintWithFields(
        $handle,
        \is_scalar($title) ? (string) $title : '',
        $fields,
        'gravity forms',
        \is_scalar($formId) ? (string) $formId : ''
    );

    expect($created)->toBeTrue();

    $blueprint = $creator->getBlueprint($handle);

    // Assert before returning, so a failed lookup fails here rather than as a
    // confusing null-property error inside whichever test called this
    expect($blueprint)->toBeInstanceOf(Blueprint::class);
    \assert($blueprint instanceof Blueprint);

    return $blueprint;
}

/**
 * The Statamic fieldtype of every field in a blueprint.
 *
 * @param  Blueprint  $blueprint  The blueprint to read
 * @return list<string> The fieldtypes, sorted
 */
function gfUserRegistrationTypes(
    Blueprint $blueprint
): array {
    $types = [];

    foreach ($blueprint->fields()->all() as $field) {
        if (! $field instanceof Field) {
            continue;
        }

        // `Field::type()` carries no return type upstream
        $type = $field->type();

        $types[] = \is_string($type) ? $type : '';
    }

    sort($types);

    return $types;
}

afterEach(function (): void {
    // Remove every blueprint these tests created, however they ended
    $creator = new FormBlueprintCreator;

    foreach (array_keys(gfUserRegistrationForms()) as $index => $_) {
        $creator->deleteBlueprint('zz_gf_userreg_probe_'.$index);
    }
});

// ---
// The ticket's core claim: a real `username` field lands as the `username`
// fieldtype rather than the `text` it silently became before wordpress-importer-archive#300, and every
// real `password` field lands as `password`.
// ---
it('lands username and password fieldtypes from the real exports', function (
    string $fixture,
    string $formKey,
    string $title,
    int $expectedUsernames,
    int $expectedPasswords
): void {
    $form = gfUserRegistrationForm($fixture, $formKey);

    expect($form['title'])->toBe($title);

    $index = array_search(
        [$fixture, $formKey, $title, $expectedUsernames, $expectedPasswords],
        array_values(gfUserRegistrationForms()),
        true
    );

    $types = gfUserRegistrationTypes(
        gfUserRegistrationImport($form, 'zz_gf_userreg_probe_'.$index)
    );

    expect(count(array_keys($types, 'username', true)))->toBe($expectedUsernames);
    expect(count(array_keys($types, 'password', true)))->toBe($expectedPasswords);
})->with(gfUserRegistrationForms());

// ---
// The no-regression half. The oracle is a literal list per form rather than the
// mapping's own output, so this fails when a mapping these forms use is repointed
// — including for the six other mapped field types they carry.
//
// **This derived its expectation from `FieldtypeMapping` until wordpress-importer-archive#847.** Both sides
// of the comparison moved together, so it could not detect a mapping regression;
// it pinned the creator's plumbing while its name claimed more.
//
// **It asserted containment rather than equality until wordpress-importer-archive#815 landed**, because a
// field type the mapping declares `'default' => null` — an unsupported type such
// as `captcha` — was skipped by the mapping and still emitted by the blueprint
// pipeline as a `text` field. `patientslearn` form 10 carries a captcha and
// gained a spurious free-text input from it, so the sets could not match. Rather
// than encode `captcha => text` as expected, the assertion was weakened and the
// defect tracked separately.
//
// wordpress-importer-archive#815 fixed the guard, so equality holds and is asserted. That makes this the
// regression test for both tickets at once: a captcha reappearing in the
// blueprint fails here, as does any mapped field going missing.
// ---
it('lands exactly the fieldtypes these real forms are known to produce', function (
    string $fixture,
    string $formKey,
    string $title,
    int $expectedUsernames,
    int $expectedPasswords
): void {
    $form = gfUserRegistrationForm($fixture, $formKey);

    $index = array_search(
        [$fixture, $formKey, $title, $expectedUsernames, $expectedPasswords],
        array_values(gfUserRegistrationForms()),
        true
    );

    $blueprint = gfUserRegistrationImport($form, 'zz_gf_userreg_probe_'.$index);

    $stated = gfUserRegistrationExpectedTypes();

    $key = $fixture.':'.$formKey;

    // A dataset row carrying no stated expectation fails here, rather than
    // silently comparing the blueprint against nothing
    expect($stated)->toHaveKey($key);

    /** @var list<string> $expected */
    $expected = $stated[$key];

    // The blueprint carries exactly the stated fieldtypes — no more, no fewer
    expect(gfUserRegistrationTypes($blueprint))->toBe($expected);

    // The comparison above is only meaningful against a non-empty list
    expect($expected)->not->toBeEmpty();
})->with(gfUserRegistrationForms());
