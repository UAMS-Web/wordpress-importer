<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;

/**
 * Manages Ninja Forms data storage and retrieval.
 *
 * This class extends BaseDataManager to provide Ninja Forms-specific
 * data management functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * data management operations.
 *
 * Note that a Ninja Forms export holds **one form per file**, unlike the Gravity Forms
 * and Formidable Forms exports which each carry a list. The store is keyed by form ID so
 * a run that detects several embeds can hold every operator-supplied export at once.
 */
class DataManager extends BaseDataManager
{
    /**
     * Create a new DataManager instance.
     */
    public function __construct()
    {
        parent::__construct('NinjaForms');
    }

    /**
     * Set the loaded Ninja Forms data.
     *
     * @param  array<int|string, mixed>|null  $forms  The loaded Ninja Forms exports, keyed by form ID
     */
    public function setNinjaFormsData(
        ?array $forms
    ): void {
        $this->setFormsData($forms);
    }

    /**
     * Get the loaded Ninja Forms data.
     *
     * @return array<int|string, mixed>|null The loaded Ninja Forms exports, keyed by form ID
     */
    public function getNinjaFormsData(): ?array
    {
        return $this->getFormsData();
    }

    /**
     * Clear the Ninja Forms data.
     *
     * Useful for resetting between different import sessions.
     */
    public function clearNinjaFormsData(): void
    {
        $this->clearFormsData();
    }

    /**
     * Store one form's export against the form ID it was supplied for.
     *
     * The `.nff` file cannot identify itself for most of the production corpus — no
     * key anywhere in it names the FORM, and `settings.embed_form` is unusable on
     * **4 of the 7** exports in the corpus, in two shapes: the key is absent on two
     * and an empty string on two. So the form ID here is the one detection surfaced
     * and the operator answered the prompt for, not one read out of the file.
     *
     * A key named `id` DOES occur — ten of them on 3 of the 7 exports, all at
     * `fields[].fields[].id`, identifying the sub-parts of a repeater in
     * `<field>.<part>` form (`17.1`, `17.2`). The export carrying those is the form
     * `embed_form` calls `3`, so they are field-scoped and none of them answers
     * which form the file holds. A probe for `id` alone finds them and reads as a
     * usable identifier.
     *
     * A consumer tempted to read it anyway must guard with `empty()`, which is the
     * only one of the obvious three that rejects both shapes. Measured on PHP 8.4:
     *
     * | shape        | `isset()` | `array_key_exists()` | `empty()` |
     * | ------------ | --------- | -------------------- | --------- |
     * | key absent   | false     | false                | true      |
     * | empty string | **true**  | **true**             | true      |
     * | populated    | true      | true                 | false     |
     *
     * So an `isset()` or `array_key_exists()` guard passes the empty-string export
     * through as though it carried an ID, and the failure is silent. Where the key IS
     * populated it reads `[ninja_form id='N']`, the only durable identifier a `.nff`
     * offers — the `_NN_` in the export filename is the form's position in the export
     * run, and the next re-export renumbers it. See UAMS-Web/wordpress-importer-archive#1097.
     *
     * The export is normalized on the way in, because the shared creation engine was
     * built around the Gravity Forms and Formidable Forms shape — a flat form array
     * carrying its own `id` and `title`. A `.nff` satisfies neither, and both gaps fail
     * silently rather than loudly:
     *
     * - **No `id`.** The availability filter in FormPluginCoordinator reads exactly that
     *   key off each stored form to decide which referenced IDs it can import, so an
     *   unstamped export filters every Ninja Forms ID out and imports nothing.
     * - **No top-level `title`.** FormCreator::getFormTitle() reads the title attribute
     *   off the top level and returns null when it is missing, and createForm() returns
     *   null on a null title — so the form is skipped with no error.
     *
     * Lifting `settings` to the top level fixes the second and any sibling setting the
     * engine reads the same way. The original `settings` array is left in place, and the
     * export's own keys win the merge, so nothing a `.nff` actually carries is displaced.
     *
     * @param  int|string  $formId  The detected form ID this export was supplied for
     * @param  array<int|string, mixed>  $form  The decoded `.nff` export
     */
    public function addNinjaForm(
        int|string $formId,
        array $form
    ): void {
        // Preserve any exports already supplied in this run.
        $forms = $this->getFormsData() ?? [];

        $settings = $form['settings'] ?? null;

        // Lift the settings envelope so the shared engine can read `title` and friends.
        if (\is_array($settings)) {
            $form = \array_merge($settings, $form);
        }

        // Stamp the binding ID so the export can be attributed downstream.
        $form['id'] = $formId;

        $forms[$formId] = $form;

        $this->setFormsData($forms);
    }

    /**
     * Get the export supplied for a given form ID.
     *
     * @param  int|string  $formId  The detected form ID
     * @return array<int|string, mixed>|null The decoded export, or null when none was supplied
     */
    public function getNinjaForm(
        int|string $formId
    ): ?array {
        // Reads as null both when nothing has been supplied yet in this run and when
        // nothing was supplied for this form.
        $form = $this->getFormsData()[$formId] ?? null;

        return \is_array($form) ? $form : null;
    }
}
