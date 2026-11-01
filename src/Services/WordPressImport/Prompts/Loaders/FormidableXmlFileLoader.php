<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders;

use SimpleXMLElement;

/**
 * Formidable Forms-specific XML loader.
 *
 * Extends the base {@see XmlFileLoader} to additionally extract the
 * `<view>` (`frm_form_actions`) sibling elements that carry a form's
 * email notifications and on-submit confirmations. The base loader parses
 * only `<form>`/`<field>` nodes, so every form arrived with no `views` key
 * and `FormConfigurationManager` early-returned at its `views` guards —
 * meaning zero notifications/confirmations imported for any Formidable form
 * (issue wordpress-importer-archive#37).
 *
 * Views are siblings of forms (not nested) and are keyed to their parent
 * form by `<menu_order>` == the form `id`. This subclass leaves the base
 * loader untouched so Gravity Forms and other importers are unaffected.
 */
class FormidableXmlFileLoader extends XmlFileLoader
{
    /**
     * Convert XML structure to array format, attaching `<view>` actions.
     *
     * Builds the base forms array (with `fields`), then walks the top-level
     * `<view>` siblings and appends each `frm_form_actions` view to its
     * parent form by `<menu_order>`. Every form is seeded with an empty
     * `views` array, so action-less forms get `views => []` (no regression).
     *
     * @param  SimpleXMLElement  $xml  The parsed XML element
     * @return array<int|string, mixed> The converted forms array with attached views
     */
    protected function convertXmlToArray(
        SimpleXMLElement $xml
    ): array {
        // Build the base forms (id, fields, etc.) with the base loader's parser.
        $forms = $this->parseForms($xml);

        // Seed every form with an empty views list and map id => list index
        // so views can be attached in a single pass below.
        $indexByFormId = [];
        foreach ($forms as $index => $form) {
            $forms[$index]['views'] = [];
            $indexByFormId[$form['id']] = $index;
        }

        // Attach each action view to its parent form via <menu_order>.
        foreach ($xml->view as $view) {
            // Skip any non-action view defensively.
            if ((string) $view->post_type !== 'frm_form_actions') {
                continue;
            }

            // Capture every child node generically so the mapper sees all
            // keys (excerpt, content, post_title, status, post_id, ...).
            $viewData = [];
            foreach ($view->children() as $child) {
                $viewData[$child->getName()] = (string) $child;
            }

            // Attach the view to its parent form when that form exists.
            $formIndex = $indexByFormId[(string) $view->menu_order] ?? null;

            if ($formIndex === null) {
                continue;
            }

            $forms[$formIndex]['views'][] = $viewData;
        }

        return $forms;
    }
}
