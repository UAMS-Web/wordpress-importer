<?php

declare(strict_types=1);

/*
 * Shared fixtures-directory resolution for the notification-audit dumps
 * (GravityFormsNotificationAuditTest, FormidableNotificationAuditTest) and for
 * the Ninja Forms per-form import tests.
 *
 * Both audits ship with a minimal synthetic corpus committed under
 * tests/Fixtures/form-audit/, which is what they run against by default. That
 * default is package-relative, so the audits carry their own fixtures wherever
 * the package is installed and the host app needs no wiring to run them.
 *
 * The host app may still repoint either audit at a different corpus through
 * config testing.gf_audit_fixtures_dir / testing.frm_audit_fixtures_dir (backed
 * by the GF_AUDIT_FIXTURES_DIR / FRM_AUDIT_FIXTURES_DIR env vars in the host's
 * .env). That knob is host-side by construction — this package has no .env of
 * its own — and it is read through config() rather than env() so it survives a
 * cached config. An unset or non-string value falls back to the committed set.
 */

/**
 * Resolve the directory holding the Gravity Forms JSON export fixtures.
 *
 * @return string Absolute path to the Gravity Forms fixtures directory.
 */
function formAuditGravityFormsFixturesDir(): string
{
    return formAuditFixturesDir(
        'testing.gf_audit_fixtures_dir',
        'gravity-forms'
    );
}

/**
 * Resolve the directory holding the Formidable Forms XML export fixtures.
 *
 * @return string Absolute path to the Formidable Forms fixtures directory.
 */
function formAuditFormidableFixturesDir(): string
{
    return formAuditFixturesDir(
        'testing.frm_audit_fixtures_dir',
        'formidable'
    );
}

/**
 * Resolve the directory holding the Ninja Forms `.nff` export fixtures.
 *
 * The committed Ninja corpus is derived from the four production exports rather than
 * hand-written: each fixture is a real export with its eight staff email addresses
 * replaced, its per-option `settingModel` scaffolding dropped from all but the first
 * option of each list, and any list longer than eight entries truncated. Structure —
 * fieldtypes, keys, repeater nesting, action wiring — is untouched, so the fixtures
 * exercise the real shapes at a fraction of the 716 KB the originals occupy.
 *
 * The first option keeps its `settingModel` deliberately. That blob holds Ninja's
 * default template choice list (`One` / `Two` / `Three`), and sourcing choices from it
 * yields a blueprint of plausible placeholder options with no error raised — so the
 * corpus has to carry the trap for these tests to prove anything about surviving it.
 *
 * Point NF_AUDIT_FIXTURES_DIR at the `NinjaForms` directory of a local
 * wordpress-importer-exports checkout to run against the full untrimmed corpus.
 *
 * @return string Absolute path to the Ninja Forms fixtures directory.
 */
function formAuditNinjaFormsFixturesDir(): string
{
    return formAuditFixturesDir(
        'testing.nf_audit_fixtures_dir',
        'ninja-forms'
    );
}

/**
 * Resolve a form-audit fixtures directory, preferring the host-side override.
 *
 * @param  string  $configKey  Dotted config key carrying the host-side override.
 * @param  string  $committedSubdirectory  Sub-directory of the committed corpus.
 * @return string Absolute path to the resolved fixtures directory.
 */
function formAuditFixturesDir(string $configKey, string $committedSubdirectory): string
{
    // Read the host-side override, which is absent unless a developer sets it
    $configured = config($configKey);

    // Prefer the override whenever the host actually supplies one
    if (\is_string($configured) && $configured !== '') {
        return $configured;
    }

    // Fall back to the synthetic corpus committed alongside these tests
    return \dirname(__DIR__).'/Fixtures/form-audit/'.$committedSubdirectory;
}
