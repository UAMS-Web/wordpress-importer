<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders;

use Illuminate\Console\Command;

/**
 * The Formidable Forms sourcing seam (wordpress-importer-archive#811).
 *
 * **This is the one slice of #695 where the two sources genuinely differ in format.**
 * Gravity serves `GFAPI::get_form()` output — the same array its export wraps — and Ninja
 * serves the shape a `.nff` holds plus the id it lacks, so both slices could assert the raw
 * sources agree. Formidable cannot: its committed export is XML and the endpoint serves a
 * bespoke JSON shape, so the API payload is a real translation of the file rather than a
 * re-wrapping of it. The equivalence those two slices rest on is asserted here on the
 * MAPPED output — the blueprint and notifications — not on the payloads.
 *
 * The third constructor argument is therefore the Formidable-specific XML loader, not the
 * shared `JsonFileLoader`: the file path still reads XML exactly as before, and only the
 * API path carries the translation. The translation itself does not live here — it belongs
 * to the API payload's own handling so the file path is untouched — see wordpress-importer-archive#811 for where it
 * lands and why the mapping is never taught about the JSON shape.
 */
class FormidableFormsLoader extends MigrationApiFormLoader
{
    /**
     * Create the Formidable Forms loader.
     */
    public function __construct()
    {
        parent::__construct(
            'FormidableForms',
            'formidable',
            new FormidableXmlFileLoader('FormidableForms')
        );
    }

    /**
     * Translate one API-served Formidable form into the shape the XML loader hands the mapping.
     *
     * Three steps: re-serialize the options-family members the endpoint delivers already
     * parsed, translate `actions[]` into `views[]`, and report what the endpoint withheld.
     *
     * **The first is a correctness fix, not a shape nicety, and it fails silently without it.**
     * Formidable's endpoint returns `options` (form level) and each field's `options` and
     * `field_options` as parsed structures — the raw-not-curated convention (D-046) — while
     * the XML export stores them as the serialized strings the mapping expects. Every guard
     * that reads them keys on `is_string()`: `FormConfigurationManager::parseFormData()`
     * skips a non-string `options`, and `FieldtypeMapping::extractConditionalLogicData()`
     * plus `isFieldOptionNotSet()` treat a parsed `field_options` as absent. So an
     * untranslated payload imports GREEN while dropping every field's conditional logic and
     * reading every field option as unset — the same silent-skip class as wordpress-importer-archive#1106.
     *
     * Re-encoding to a JSON string hands the mapping the shape it already handles, on its
     * tested path, without teaching it about JSON (criterion 2). The endpoint's parsed value
     * is the correct one — the XML string carried an extra level of escaping (wordpress-importer-archive#808) — so the
     * round-trip removes a corruption rather than reintroducing one.
     *
     * @param  array<int|string, mixed>  $form  One form as `/forms/formidable` served it
     * @param  Command  $command  Command instance, for reporting what the endpoint withheld
     * @return array<int|string, mixed> The form with its options-family members serialized
     */
    protected function translateApiForm(
        array $form,
        Command $command
    ): array {
        // Serialize the form-level options member.
        $form = $this->serializeMembers(
            $form,
            ['options']
        );

        // Serialize each field's options and field_options members.
        if (isset($form['fields']) && \is_array($form['fields'])) {
            $form['fields'] = \array_map(
                fn (mixed $field): mixed => \is_array($field)
                    ? $this->serializeMembers(
                        $field,
                        ['options', 'field_options']
                    )
                    : $field,
                $form['fields']
            );
        }

        // Translate the endpoint's actions[] into the views[] the mapping consumes (wordpress-importer-archive#811,
        // uamswp-migration-api-archive#284). The endpoint serves form actions as `actions[]`; the
        // Formidable mapping reads `views[]` — the `frm_form_actions` posts the XML loader
        // attaches (wordpress-importer-archive#37). This maps one to the other, into the SAME shape the XML loader
        // produces, so both sourcing paths hand the mapping identical views — including
        // `views => []` on a form with no actions, which the XML loader seeds on every form.
        $actions = isset($form['actions']) && \is_array($form['actions'])
            ? \array_values(\array_filter(
                $form['actions'],
                \is_array(...)
            ))
            : [];

        $form['views'] = \array_map(
            $this->actionToView(...),
            $actions
        );
        unset($form['actions']);

        // Report what the endpoint withheld rather than drop it silently (wordpress-importer-archive#811, criterion 4).
        // An action's `denied` counters are the endpoint's own record of settings it refused
        // to serve; a view translated without them imports green with those settings absent,
        // and nothing downstream can tell a withheld setting from one that was never set.
        $withheld = $this->withheldActionSettings($actions);

        if ($withheld > 0) {
            $command->warn(\sprintf(
                '  Formidable form %s: %d action setting(s) withheld by the endpoint denylist are not carried into the import.',
                // %s renders every scalar exactly as the cast does.
                // @pest-mutate-ignore: RemoveStringCast
                \is_scalar($form['id'] ?? null) ? (string) $form['id'] : '?',
                $withheld
            ));
        }

        return $form;
    }

    /**
     * Count the action settings the endpoint's `denied` counters say it withheld.
     *
     * The shape mirrors what `WxrMaterializer` reads off the options payload: `by_name` and
     * `by_shape` are summed rather than `total` trusted, so a payload that carries the parts
     * and not the sum still counts. A missing or malformed `denied` counts as nothing withheld
     * — the same liberal reading the rest of the translation takes of the payload.
     *
     * @param  list<array<int|string, mixed>>  $actions  The `actions[]` entries as served
     * @return int The number of settings withheld across every action
     */
    private function withheldActionSettings(
        array $actions
    ): int {
        $withheld = 0;

        // Sum the two counters on each action, treating anything non-numeric as zero.
        foreach ($actions as $action) {
            $denied = $action['denied'] ?? null;

            if (! \is_array($denied)) {
                continue;
            }

            $byName = \is_numeric($denied['by_name'] ?? null) ? (int) $denied['by_name'] : 0;
            $byShape = \is_numeric($denied['by_shape'] ?? null) ? (int) $denied['by_shape'] : 0;
            $withheld += $byName + $byShape;
        }

        return $withheld;
    }

    /**
     * Convert one API `actions[]` entry into the `views[]` shape the XML loader produces.
     *
     * **This deliberately reproduces the XML view shape rather than an improved one.** wordpress-importer-archive#811
     * is sourcing parity: the two paths must hand the mapping identical views, so the API
     * path is translated to what `FormidableXmlFileLoader` emits for a `frm_form_actions`
     * post — `excerpt`, `content`, `title`, `post_name`, `status`, `post_id` — not to a shape
     * that would make the mapping behave differently on an API-sourced run.
     *
     * Two specifics carry the parity:
     *
     * - `settings` is re-serialized to `content` as a JSON string, because the mapping
     *   json_decodes `view['content']` behind an `is_string()` guard — the same silent-skip
     *   the field members have. A parsed settings map would be dropped.
     * - `name` is the endpoint's `post_name` (a slug like `1_email_1`), and it is emitted as
     *   `post_name`, NOT `name`, matching the export. The slug is not a title, and the
     *   mapping's view-title resolution reads neither key.
     * - `title` carries the action's human title (`Send Email`), under the key the export
     *   uses. `FormConfigurationManager::resolveViewTitle()` reads it, so both paths label a
     *   view by its name rather than its type (wordpress-importer-archive#1121).
     *
     * @param  array<int|string, mixed>  $action  One `actions[]` entry as the endpoint served it
     * @return array<int|string, mixed> The view in the XML loader's shape
     */
    private function actionToView(
        array $action
    ): array {
        // Copy the scalar members under the keys the XML loader emits for a view.
        $view = [
            'excerpt' => $action['type'] ?? '',
            'title' => $action['title'] ?? '',
            'post_name' => $action['name'] ?? '',
            'status' => $action['status'] ?? '',
            'post_id' => $action['id'] ?? '',
        ];

        // The settings map is re-serialized to the JSON string the mapping's is_string guard
        // then decodes, exactly as the options-family field members are. A settings member
        // the endpoint already serves as a string passes through unchanged, for the same
        // idempotence `serializeMembers()` keeps — dropping it would lose the whole action.
        if (isset($action['settings']) && \is_array($action['settings'])) {
            $view['content'] = \json_encode($action['settings']);
        } elseif (isset($action['settings']) && \is_string($action['settings'])) {
            $view['content'] = $action['settings'];
        }

        return $view;
    }

    /**
     * JSON-encode the named members where they arrive as arrays, leaving strings untouched.
     *
     * A member the endpoint already serves as a string (or omits) is passed through, so the
     * translation is idempotent and safe against a future endpoint that serializes some
     * members itself.
     *
     * @param  array<int|string, mixed>  $data  The form or field array
     * @param  list<string>  $members  The member keys to serialize when they are arrays
     * @return array<int|string, mixed> The array with those members serialized
     */
    private function serializeMembers(
        array $data,
        array $members
    ): array {
        foreach ($members as $member) {
            if (isset($data[$member]) && \is_array($data[$member])) {
                $data[$member] = \json_encode($data[$member]);
            }
        }

        return $data;
    }
}
