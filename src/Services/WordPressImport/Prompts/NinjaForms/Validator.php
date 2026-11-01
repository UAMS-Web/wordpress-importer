<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\NinjaForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginValidator;

/**
 * Validates Ninja Forms export file structure.
 *
 * This class extends BaseFormPluginValidator to provide Ninja Forms-specific
 * validation functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * validation operations.
 */
class Validator extends BaseFormPluginValidator
{
    /**
     * Create a new Validator instance.
     */
    public function __construct()
    {
        parent::__construct('NinjaForms');
    }

    /**
     * Validate the exported data, accepting a single form or a list of them.
     *
     * A `.nff` export is **one form per file**, serialized as a bare object with
     * `settings`, `fields` and `actions` keys — unlike a Gravity Forms export, which is a
     * list of forms. The parent iterates its argument as a list, which for a `.nff` would
     * walk `settings`/`fields`/`actions` and find no form at all. So the envelope is
     * tested first, and the list form is still accepted in case an operator concatenates
     * several exports.
     *
     * @param  mixed  $forms  The forms data to validate
     * @return bool True if valid, false otherwise
     */
    public function validate(
        mixed $forms
    ): bool {
        // A single .nff export: the decoded object IS the form.
        if ($this->validateFormStructure($forms)) {
            return true;
        }

        // Otherwise fall back to the parent's list behavior.
        return parent::validate($forms);
    }

    /**
     * Validate the structure of a single Ninja Forms form.
     *
     * Checks for the `settings` and `fields` keys every `.nff` export carries. Note that
     * there is deliberately **no form-ID check**: a `.nff` contains no `id` or `form_id`
     * anywhere, and `settings.embed_form` — the only place a form ID appears — is an empty
     * string on two of the four production exports. There is nothing to match an ID
     * against, so structure and title are what can honestly be verified here.
     *
     * @param  mixed  $form  The form data to validate
     * @return bool True if the form structure is valid, false otherwise
     */
    protected function validateFormStructure(
        mixed $form
    ): bool {
        // Must be an array carrying the two keys every export has.
        if (! \is_array($form) || ! isset($form['settings']) || ! isset($form['fields'])) {
            return false;
        }

        // Both must themselves be arrays; a scalar under either key is not an export.
        return \is_array($form['settings']) && \is_array($form['fields']);
    }

    /**
     * Read the form's display title from a validated export.
     *
     * The operator has to confirm that the file they supplied is the form the importer
     * detected, and the title is the only human-readable identifier a `.nff` carries.
     *
     * @param  mixed  $form  The validated form data
     * @return string|null The form title, or null when absent
     */
    public function formTitle(
        mixed $form
    ): ?string {
        // Narrowed inline rather than through validateFormStructure(): a helper returning
        // bool tells static analysis nothing about the shape of what was passed in.
        if (! \is_array($form)) {
            return null;
        }

        $settings = $form['settings'] ?? null;

        if (! \is_array($settings)) {
            return null;
        }

        $title = $settings['title'] ?? null;

        return \is_string($title) && $title !== '' ? $title : null;
    }

    /**
     * Read the form ID a `.nff` advertises, when it advertises one at all.
     *
     * The ID appears only inside `settings.embed_form`, as the shortcode an editor would
     * paste — `[ninja_form id='2']`. It is an empty string on two of the four production
     * exports, so a null return is an ordinary outcome and not an error.
     *
     * @param  mixed  $form  The validated form data
     * @return int|null The advertised form ID, or null when the export does not carry one
     */
    public function advertisedFormId(
        mixed $form
    ): ?int {
        // Narrowed inline, for the same reason as formTitle().
        if (! \is_array($form)) {
            return null;
        }

        $settings = $form['settings'] ?? null;

        if (! \is_array($settings)) {
            return null;
        }

        $embed = $settings['embed_form'] ?? null;

        // Absent or empty on half the corpus; that is expected, not a failure. An empty
        // string fails the match below, so it needs no test of its own.
        if (! \is_string($embed)) {
            return null;
        }

        // Quotes are optional in the shortcode the plugin writes.
        if (\preg_match('/\[ninja_form\s+id=["\']?([0-9]+)["\']?/', $embed, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
