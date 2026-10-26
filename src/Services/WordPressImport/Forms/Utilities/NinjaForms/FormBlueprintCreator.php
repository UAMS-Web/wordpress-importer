<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormBlueprintCreatorTrait;

/**
 * Creates Statamic form blueprints from Ninja Forms exports.
 *
 * This class is responsible for creating and saving blueprints for forms
 * imported from Ninja Forms `.nff` exports.
 *
 * The shared FormBlueprintCreatorTrait carries the blueprint assembly; this class
 * supplies the four Ninja-specific readers the trait declares abstract, and resolves its
 * mapping class automatically from this namespace.
 */
class FormBlueprintCreator
{
    /**
     * Shared blueprint creation engine.
     *
     * `generateFieldHandle` is aliased so this class can override it and still reach the
     * trait's implementation for the nested fields that do carry an `id` — a trait method
     * is not reachable through `parent::`.
     */
    use FormBlueprintCreatorTrait {
        generateFieldHandle as protected traitGenerateFieldHandle;
    }

    /**
     * Get the field name to use for handle generation.
     *
     * Ninja Forms carries both a `key` and a `label`. The `label` is what an editor sees
     * and what the sibling importers key on, so display names derive from it.
     *
     * The handle itself does NOT — see generateFieldHandle() below, which overrides the
     * trait because Ninja top-level fields carry no `id` at all.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string The field name for handle generation
     */
    protected function getFieldNameForHandle(
        array $field
    ): string {
        $label = $field['label'] ?? null;

        return \is_string($label) ? $label : '';
    }

    /**
     * Generate a stable Statamic handle for a Ninja Forms field.
     *
     * The trait's implementation composes `slug(label) . '_' . $field['id']`, and falls
     * back to `'field_' . $type . '_' . Str::random(8)` when there is no `id`.
     *
     * **Ninja top-level fields carry no `id`** — only fields nested inside a `repeater`
     * do. So the inherited implementation reaches the random fallback for every top-level
     * field, which is wrong twice over: the handle differs on every run, so re-importing
     * a form produces a different blueprint; and no merge tag can ever resolve to it,
     * because `processFieldMergeTags()` derives its target from the same field data and
     * would have to guess the same random string.
     *
     * Ninja's `key` is the field's real identity — unique within the form and stable
     * across exports — so it is the handle. It carries a creation-timestamp suffix
     * (`email_1623450939394`), which is not pretty, but a readable-but-unstable handle is
     * worse than an ugly stable one, and labels alone are not unique: a form can carry
     * two "Email" fields in different repeater sets.
     *
     * @param  array<int|string, mixed>  $field  The Ninja Forms field data
     * @return string The Statamic field handle
     */
    protected function generateFieldHandle(
        array $field
    ): string {
        $key = $field['key'] ?? null;

        // Nested repeater fields do carry an `id`, and anything without a usable key
        // falls back to the trait so the existing behavior still applies.
        if (! \is_string($key) || \trim($key) === '') {
            return $this->traitGenerateFieldHandle($field);
        }

        // Normalize to snake_case without disturbing the numeric suffix.
        $handle = \strtolower(
            \preg_replace('/[^A-Za-z0-9]+/', '_', $key) ?? $key
        );

        return \trim($handle, '_');
    }

    /**
     * Get the field name to use for display name generation.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string The field name for display
     */
    protected function getFieldNameForDisplay(
        array $field
    ): string {
        $label = $field['label'] ?? null;

        return \is_string($label) ? $label : '';
    }

    /**
     * Get the name of the attribute that indicates if a field is required.
     *
     * Ninja Forms uses a lowercase `required` where Gravity Forms uses `isRequired`. Its
     * value is `1` or `false` across the production exports, which the trait's truthiness
     * check already handles.
     *
     * @return string The attribute name
     */
    protected function getRequiredAttributeName(): string
    {
        return 'required';
    }

    /**
     * Get mapped field attributes using the Ninja Forms mapping class.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for conditional logic mapping
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     * @param  ?string  $siteHandle  The Statamic site handle for placeholder replacement
     * @return array<int|string, mixed> The mapped Statamic field attributes
     */
    protected function getMappedFieldAttributes(
        array $field,
        string $pluginName,
        array $allFields = [],
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null,
        ?string $siteHandle = null
    ): array {
        // The declarative half: everything expressible as a per-attribute translation
        // lives in FieldtypeMapping::$fieldAttributeMapping and is applied by the engine.
        $mappedAttributes = FieldtypeMapping::mapFieldAttributes(
            $field,
            $allFields,
            null,
            $siteHandle
        );

        // The imperative half: a choice list needs restructuring, not translating, so the
        // mapping DSL cannot express it. The engine's own options support parses a
        // `field_options` JSON string, which is a Formidable Forms shape a Ninja field
        // does not have.
        $options = $this->mapNinjaFormsOptions($field);

        // A field with no choice list contributes nothing rather than an empty `options`.
        if ($options !== []) {
            $mappedAttributes['options'] = $options;
        }

        return $mappedAttributes;
    }

    /**
     * Build a Statamic options map from a Ninja Forms field's choice list.
     *
     * Ninja stores choices as a list of option objects carrying `label`, `value`, `order`,
     * `selected` and `calc`. Statamic wants a flat `value => label` map, ordered.
     *
     * **Read `options`, never `settingModel`.** Each option also carries a `settingModel`
     * blob of admin-UI scaffolding whose own `value` key holds Ninja's *default template*
     * choice list — literally `One` / `Two` / `Three`. Reading that instead of the option
     * itself yields a blueprint full of plausible-looking placeholder choices with no
     * error anywhere, which is why this method takes only the four keys it needs rather
     * than merging the option wholesale.
     *
     * A leading prompt option is kept, not stripped. Both `liststate` fields in the corpus
     * open with `- Select State -` as a real option whose value equals its label, which is
     * how Ninja renders an unselected state dropdown. Dropping it would change the rendered
     * form; keeping it reproduces WordPress exactly, and the field's own `required` rule is
     * what stops it being submitted — the same thing that stopped it there.
     *
     * Two values are deliberately dropped. `calc` assigns a per-option number used by
     * Ninja's calculation fields — the Miracle Stars select prices every option at `4` —
     * and Statamic has no calculation concept to receive it; it is tracked with the rest
     * of the calculation work rather than smuggled into an option label. `selected` marks
     * the initially-chosen option, which belongs on the field's `default` rather than in
     * the options map, and is applied by the caller through the `default` mapping.
     *
     * @param  array<int|string, mixed>  $field  The Ninja Forms field data
     * @return array<string, string> The Statamic options map, value => label
     */
    private function mapNinjaFormsOptions(
        array $field
    ): array {
        $rawOptions = $field['options'] ?? null;

        // Most fieldtypes carry no choice list at all.
        if (! \is_array($rawOptions) || $rawOptions === []) {
            return [];
        }

        $ordered = [];

        foreach ($rawOptions as $index => $rawOption) {
            if (! \is_array($rawOption)) {
                continue;
            }

            $label = $rawOption['label'] ?? null;
            $value = $rawOption['value'] ?? null;

            // A choice with no label cannot be rendered; skip rather than emit a blank.
            if (! \is_scalar($label) || (string) $label === '') {
                continue;
            }

            $label = (string) $label;

            // Ninja allows an empty value with a populated label, in which case the label
            // is what the form submits.
            $value = \is_scalar($value) && (string) $value !== ''
                ? (string) $value
                : $label;

            // `order` is authoritative and is a string in some exports; fall back to the
            // array position so an export missing it keeps its natural order.
            $order = $rawOption['order'] ?? null;
            // `order` is read only by the sort below, and `<=>` compares a digit string
            // with an int numerically, so the cast changes no ordering.
            // @pest-mutate-ignore: RemoveIntegerCast
            $order = \is_scalar($order) && \ctype_digit((string) $order)
                ? (int) $order
                : $index;

            $ordered[] = [
                'order' => $order,
                'value' => $value,
                'label' => $label,
            ];
        }

        // Sort by the export's own ordering before flattening to the Statamic map.
        \usort(
            $ordered,
            static fn (array $a, array $b): int => $a['order'] <=> $b['order']
        );

        $options = [];

        foreach ($ordered as $option) {
            // A later duplicate value would silently replace an earlier one; keeping the
            // first matches the order the editor sees in the Ninja admin.
            if (\array_key_exists($option['value'], $options)) {
                continue;
            }

            $options[$option['value']] = $option['label'];
        }

        return $options;
    }

    /**
     * Get mapped subfield attributes for a Ninja Forms composite field.
     *
     * Ninja Forms nests a `repeater`'s inner fields under **`fields`**, where Gravity
     * Forms nests a composite's parts under `inputs`. That difference is why this cannot
     * be inherited: reading `inputs` here would find nothing, and every subfield of the
     * waiver form — which is one repeater and nothing else — would be silently dropped.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @param  array<int|string, mixed>  $allFields  Array of all form fields
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     * @return array<int|string, mixed> The mapped Statamic subfield attributes
     */
    protected function getMappedSubfieldAttributes(
        array $field,
        string $pluginName,
        array $allFields = [],
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): array {
        // Only a repeater carries nested fields; everything else is flat.
        if (! isset($field['fields']) || ! \is_array($field['fields']) || $field['fields'] === []) {
            return [];
        }

        $combinedSubfieldAttributes = [];

        // Process each nested field individually, mirroring the sibling implementations.
        foreach ($field['fields'] as $subfieldData) {
            if (! \is_array($subfieldData)) {
                continue;
            }

            $fieldType = $field['type'] ?? '';

            $subfieldAttributes = FieldtypeMapping::mapSubfieldAttributes(
                \is_string($fieldType) ? $fieldType : '',
                $subfieldData,
                $field,
                $pluginName,
                $allFields
            );

            // Merge this subfield's attributes into the combined result.
            if ($subfieldAttributes !== []) {
                $combinedSubfieldAttributes = \array_merge(
                    $combinedSubfieldAttributes,
                    $subfieldAttributes
                );
            }
        }

        return $combinedSubfieldAttributes;
    }
}
