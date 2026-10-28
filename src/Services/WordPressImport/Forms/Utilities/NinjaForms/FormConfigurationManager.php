<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;

use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormConfigurationManagerTrait;

/**
 * Maps Ninja Forms form configuration to Statamic form configuration.
 *
 * This class is responsible for mapping a `.nff` export's settings and its email actions
 * onto the Statamic form config and email-notification config that the Gravity Forms
 * (wordpress-importer-archive#25) and Formidable Forms (wordpress-importer-archive#26) importers already feed.
 *
 * ## Where notifications live, and why that matters
 *
 * Each importer finds notifications somewhere different, and Ninja is a third shape:
 *
 * | Plugin           | Notifications live in                  |
 * | ---------------- | -------------------------------------- |
 * | Gravity Forms    | `notifications[]`                      |
 * | Formidable Forms | `views[]`, filtered by `excerpt`       |
 * | Ninja Forms      | `actions[]`, filtered by `type=email`  |
 *
 * A Ninja `actions[]` array also holds non-email actions — `save`, `successmessage`,
 * `redirect`, `collectpayment` — so the type filter is what separates a notification from
 * a submission behavior.
 *
 * ## Identifiers
 *
 * Ninja merge tags reference a field's **`key`** (`{field:email_1623450939394}`), and
 * top-level fields carry no `id` at all — only fields nested inside a `repeater` do. The
 * trait's id-based `getFieldHandleById()` therefore cannot resolve a Ninja merge tag,
 * which is why `processFieldMergeTags()` here resolves by `key` and recurses into
 * repeater sets itself.
 */
class FormConfigurationManager
{
    /**
     * Shared form configuration engine.
     */
    use FormConfigurationManagerTrait;

    /**
     * Ninja Forms form settings to Statamic form configuration mapping.
     *
     * Read by the shared engine. Keys are read from the export's `settings` object, which
     * `parseFormData()` lifts to the top level.
     *
     * @var array<string, array<string, mixed>>
     */
    protected const FORM_CONFIGURATION_MAPPING = [
        // The form's display title.
        'title' => [
            'statamic_attribute' => 'heading',
        ],

        // Shown when a submission is refused for exceeding the form's submission limit.
        'sub_limit_msg' => [
            'statamic_attribute' => 'limit_entries_message',
        ],

        // Shown when the form is restricted to logged-in users and the visitor is not.
        'not_logged_in_msg' => [
            'statamic_attribute' => 'not_logged_in_message',
        ],
    ];

    /**
     * Ninja Forms email action to Statamic email notification mapping.
     *
     * Read by the shared engine's buildEmailConfiguration(). Keys are read from a single
     * `actions[]` entry whose `type` is `email`.
     *
     * Two Ninja keys have no Statamic analog in the vocabulary the sibling importers
     * established and are deliberately absent: `email_format` (`html` on every production
     * action) and `email_message_plain` (empty on every production action). Mapping either
     * would invent a target rather than fill one.
     *
     * @var array<string, array<string, mixed>>
     */
    protected const FORM_CONFIGURATION_ACTION_MAPPING_EMAIL = [
        // The action's own identifier. Null on some production actions.
        'key' => [
            'statamic_attribute' => 'id',
        ],

        // The notification's name, as shown in the Ninja Forms admin.
        'label' => [
            'statamic_attribute' => 'name',
        ],

        // ---
        // Recipients. Each may carry merge tags — `{field:email_…}` for a submitter
        // address, `{wp:admin_email}` for the site administrator — so each is translated
        // and comma-split, matching the sibling importers.
        // ---
        'to' => [
            'statamic_attribute' => 'to',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],
        'cc' => [
            'statamic_attribute' => 'cc',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],
        'bcc' => [
            'statamic_attribute' => 'bcc',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // Sender. Ninja spells these `from_address` / `from_name`.
        'from_address' => [
            'statamic_attribute' => 'from',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],
        'from_name' => [
            'statamic_attribute' => 'from_name',
            'value_translation' => [
                // Empty on several production actions; fall back to the site name rather
                // than sending with a blank sender name, matching Gravity Forms.
                '#empty' => '{{ site:name }}',
            ],
        ],
        'reply_to' => [
            'statamic_attribute' => 'reply_to',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // Content.
        'email_subject' => [
            'statamic_attribute' => 'subject',
            'translate_field_references' => true,
        ],
        'email_message' => [
            'statamic_attribute' => 'message',
            'translate_field_references' => true,
        ],

        // ---
        // Whether the notification sends. Ninja uses `active`, which is `1`/`0` in the
        // exports — and it is load-bearing: on the Love Lights form four of six actions
        // are switched off, so importing them all as enabled would send mail the live
        // form does not.
        // ---
        'active' => [
            'statamic_attribute' => 'enabled',
        ],
    ];

    /**
     * Configure a Statamic form's email settings from a Ninja Forms export.
     *
     * The plugin-agnostic entry point the form-creation coordinator calls. All of the
     * Ninja-specific behaviour reaches the shared engine through the abstract hooks
     * implemented below, so this method only names the plugin.
     *
     * @param  Form  $form  The Statamic form being configured
     * @param  array<int|string, mixed>  $ninjaFormsData  The decoded `.nff` export
     * @param  string  $siteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  The asset importer for any referenced media
     * @return bool Whether the settings were configured
     */
    public function configureFormEmailSettings(
        Form $form,
        array $ninjaFormsData,
        string $siteHandle,
        $assetImporter
    ): bool {
        // Submission storage is a first-class Form property rather than form data, so the
        // mapping DSL cannot express it and the engine's hooks do not reach it. Applied
        // before the engine runs, so its save persists this too.
        $this->mapSubmissionStorage(
            $form,
            $ninjaFormsData
        );

        // Payment configuration is recorded, never applied — Statamic has no gateway.
        $this->carryPaymentConfiguration(
            $form,
            $ninjaFormsData
        );

        return $this->configureFormSettingsInternal(
            $form,
            $ninjaFormsData,
            'Ninja Forms',
            $siteHandle,
            $assetImporter
        );
    }

    /**
     * Prepare a `.nff` export for the shared engine.
     *
     * The engine reads FORM_CONFIGURATION_MAPPING keys off the top level, but a `.nff`
     * nests them under `settings`. Lifting them keeps the mapping keys readable as the
     * plugin's own setting names rather than dotted paths.
     *
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @return array<int|string, mixed> The prepared form data
     */
    protected function parseFormData(
        array $formData
    ): array {
        $settings = $formData['settings'] ?? null;

        // Nothing to lift; hand the export back untouched.
        if (! \is_array($settings)) {
            return $formData;
        }

        // Settings first, so an explicit top-level key still wins.
        return \array_merge($settings, $formData);
    }

    /**
     * Determine whether the export carries any email notifications.
     *
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @return bool True when at least one `actions[]` entry is an email action
     */
    protected function hasNotifications(
        array $formData
    ): bool {
        return $this->emailActions($formData) !== [];
    }

    /**
     * Collect the export's email actions.
     *
     * `actions[]` also holds `save`, `successmessage`, `redirect` and `collectpayment`
     * entries, so the type filter is what distinguishes a notification.
     *
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @return list<array<int|string, mixed>> The email actions, in export order
     */
    protected function emailActions(
        array $formData
    ): array {
        $actions = $formData['actions'] ?? null;

        // A form with no actions has no notifications.
        if (! \is_array($actions)) {
            return [];
        }

        $emailActions = [];

        foreach ($actions as $action) {
            if (! \is_array($action)) {
                continue;
            }

            // Only `email` actions are notifications.
            if (($action['type'] ?? null) === 'email') {
                $emailActions[] = $action;
            }
        }

        return $emailActions;
    }

    /**
     * Map the export's email actions to Statamic email configurations.
     *
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @param  string  $siteHandle  The Statamic site handle
     * @param  mixed  $assetImporter  Asset importer instance, when available
     * @return array<int|string, mixed> The Statamic email configurations
     */
    protected function mapNotificationsToEmailConfigurations(
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): array {
        $formFields = isset($formData['fields']) && \is_array($formData['fields'])
            ? $formData['fields']
            : [];

        $emailConfigurations = [];

        // Build one Statamic email configuration per email action.
        foreach ($this->emailActions($formData) as $action) {
            $emailConfig = $this->buildEmailConfiguration(
                $action,
                $formData,
                $siteHandle,
                // The engine's parameter is typed; the abstract's is not, so narrow here
                // rather than widening the shared signature for one caller.
                $assetImporter instanceof AssetImporter ? $assetImporter : null,
                $formFields
            );

            // Skip an action that produced nothing mappable.
            if ($emailConfig !== []) {
                $emailConfigurations[] = $emailConfig;
            }
        }

        return $emailConfigurations;
    }

    /**
     * Translate Ninja Forms field merge tags into Statamic field references.
     *
     * Ninja references a field by its **`key`** — `{field:email_1623450939394}` — where
     * the sibling plugins reference a numeric id. Top-level Ninja fields carry no `id` at
     * all, so the trait's id-based resolution finds nothing; this walks the export's own
     * fields by key instead, recursing into `repeater` sets because six of the 23 field
     * types used in production appear only there.
     *
     * @param  string  $value  The value possibly containing merge tags
     * @param  array<int|string, mixed>  $formFields  The export's fields array
     * @return string The value with field references translated
     */
    protected function processFieldMergeTags(
        string $value,
        array $formFields
    ): string {
        // Nothing to translate. Without this return the pattern below matches nothing and
        // hands the value back unchanged, so it only saves the work.
        if (! \str_contains($value, '{field:')) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return $value;
        }

        $handles = $this->fieldHandlesByKey($formFields);

        $result = \preg_replace_callback(
            '/\{field:([A-Za-z0-9_\-]+)\}/',
            static function (array $matches) use ($handles): string {
                $handle = $handles[$matches[1]] ?? null;

                // ---
                // An unresolved tag is left verbatim rather than blanked. The corpus
                // contains references to deleted fields — `{calc:Total}`'s second operand
                // names a field that exists in neither payment form — and silently
                // emptying those would hide the breakage instead of surfacing it.
                // ---
                return \is_string($handle) ? '{{ '.$handle.' }}' : $matches[0];
            },
            $value
        );

        return \is_string($result) ? $result : $value;
    }

    /**
     * Build a field key to Statamic handle map, recursing into repeater sets.
     *
     * @param  array<int|string, mixed>  $formFields  The export's fields array
     * @return array<string, string> Field key => Statamic handle
     */
    protected function fieldHandlesByKey(
        array $formFields
    ): array {
        $handles = [];

        foreach ($formFields as $field) {
            if (! \is_array($field)) {
                continue;
            }

            $key = $field['key'] ?? null;

            // The mutant admits '' and shuts out its own placeholder string. A merge tag
            // names at least one character from [A-Za-z0-9_-], so it can read neither, and
            // the check changes no translation.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            if (\is_string($key) && $key !== '') {
                // ---
                // Derived from the KEY, identically to
                // NinjaForms\FormBlueprintCreator::generateFieldHandle(). The two must
                // agree exactly: a merge tag resolving to a handle the blueprint did not
                // create points at a field that does not exist, and nothing would report
                // it — the notification would simply render an empty value.
                // ---
                $handles[$key] = $this->ninjaFieldHandle($key);
            }

            // A repeater nests its own fields, and merge tags can name them. Added key by
            // key rather than with array_merge(), which renumbers the int keys PHP makes
            // of all-digit field keys such as '10'.
            if (($field['type'] ?? null) === 'repeater' && \is_array($field['fields'] ?? null)) {
                foreach ($this->fieldHandlesByKey($field['fields']) as $nestedKey => $nestedHandle) {
                    $handles[$nestedKey] = $nestedHandle;
                }
            }
        }

        return $handles;
    }

    /**
     * Derive a Statamic handle from a Ninja Forms field key.
     *
     * Deliberately duplicates NinjaForms\FormBlueprintCreator::generateFieldHandle()
     * rather than importing it: the two classes have no shared base, and a merge tag that
     * resolves differently from the blueprint would fail silently. A test pins them
     * against each other so the duplication cannot drift unnoticed.
     *
     * @param  string  $key  The Ninja Forms field key
     * @return string The Statamic field handle
     */
    protected function ninjaFieldHandle(
        string $key
    ): string {
        $handle = \strtolower(
            \preg_replace('/[^A-Za-z0-9]+/', '_', $key) ?? $key
        );

        return \trim($handle, '_');
    }

    /**
     * Get the field name used for handle conversion.
     *
     * @param  array<int|string, mixed>  $field  The Ninja Forms field data
     * @return string The field label
     */
    protected function getFieldNameFromField(
        array $field
    ): string {
        $label = $field['label'] ?? null;

        return \is_string($label) ? $label : '';
    }

    /**
     * Get the Statamic variable mappings for Ninja Forms system merge tags.
     *
     * Covers every non-field token shape the production corpus actually contains.
     * Enumerating every brace token across the four exports yields exactly four shapes:
     * `{field:…}` (20 occurrences, resolved by processFieldMergeTags() rather than here),
     * `{fields_table}` (18), `{wp:…}` (11) and `{calc:…}` (4). There is no `{system:…}`.
     *
     * @return array<string, string> Ninja Forms tag => Statamic replacement
     */
    protected function getVariableMappings(): array
    {
        return [
            // ---
            // The submitted-field table. Ninja writes it into an email body as
            // `{fields_table}`, and it is the single most common token in the corpus after
            // field references — it is on EVERY email action of all four production forms.
            //
            // Left unmapped it survives to Statamic verbatim, so the notification arrives
            // with the literal text `{fields_table}` where the submission should be: the
            // entire body content of every imported notification, lost without an error.
            //
            // Both sibling importers map their equivalent to the same Statamic tag —
            // Gravity Forms from `{all_fields}`, Formidable Forms from `[default-message]`.
            // ---
            '{fields_table}' => '{{ all_fields }}',

            // Used as an admin-notification recipient on every production form.
            '{wp:admin_email}' => '{{ config:mail:from:address }}',
            '{wp:user_email}' => '{{ user:email }}',
            '{wp:site_title}' => '{{ site:name }}',
            '{wp:site_url}' => '{{ config:app:url }}',
        ];
    }

    /**
     * Resolve a subfield handle from a dotted field identifier.
     *
     * Ninja Forms does not address subfields this way — a repeater's children are
     * referenced by their own `key`, which `processFieldMergeTags()` already resolves,
     * and the dotted `17.1` ids that appear on nested fields are never used in a merge
     * tag. Returning null lets the caller fall through to key resolution.
     *
     * @param  string  $subfieldId  The dotted subfield identifier
     * @param  array<int|string, mixed>  $formFields  The export's fields array
     * @return string|null Always null; see above
     */
    protected function getSubfieldHandleById(
        string $subfieldId,
        array $formFields
    ): ?string {
        return null;
    }

    /**
     * Resolve a subfield handle from the fieldtype mapping.
     *
     * Null for the same reason as getSubfieldHandleById(): Ninja addresses nested fields
     * by key, not by a composite subfield label.
     *
     * @param  string  $fieldType  The parent field type
     * @param  string  $subfieldLabel  The subfield label
     * @param  array<int|string, mixed>  $subfieldMapping  The subfield mapping
     * @return string|null Always null; see above
     */
    protected function getSubfieldHandleFromMapping(
        string $fieldType,
        string $subfieldLabel,
        array $subfieldMapping
    ): ?string {
        return null;
    }

    /**
     * Map confirmation fields from the export.
     *
     * Ninja models confirmations as **actions** rather than form settings — a
     * `successmessage` or `redirect` entry in `actions[]` — where Gravity Forms uses a
     * `confirmations[]` collection and Formidable Forms uses views. The engine's hook is
     * per-form, so the actions are read here and mapped onto the same Statamic attributes
     * the Gravity Forms importer writes: `confirmation_type`, `confirmation_message` and
     * `confirmation_redirect_url`.
     *
     * Only an **active** action is mapped. `active` is load-bearing across this corpus —
     * Love Lights has its success message switched off and its redirect on, which is the
     * whole of that form's post-submit behaviour.
     *
     * A redirect takes precedence over a message when both are active, matching Ninja's
     * own behaviour: the redirect leaves the page, so any message never renders.
     *
     * @param  Form  $form  The Statamic form being configured
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @param  string  $siteHandle  The Statamic site handle
     * @param  mixed  $assetImporter  Asset importer instance, when available
     */
    protected function mapConfirmationFields(
        Form $form,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        $redirect = $this->firstActiveActionOfType($formData, 'redirect');
        $message = $this->firstActiveActionOfType($formData, 'successmessage');

        $confirmation = [];

        if ($redirect !== null) {
            // The redirect leaves the page, so it supersedes any success message.
            $url = $redirect['redirect_url'] ?? null;

            if (\is_string($url) && \trim($url) !== '') {
                $confirmation['confirmation_type'] = 'redirect';
                $confirmation['confirmation_redirect_url'] = \trim($url);
            }
        }

        if ($confirmation === [] && $message !== null) {
            $body = $message['message'] ?? null;

            if (\is_string($body) && \trim($body) !== '') {
                $confirmation['confirmation_type'] = 'message';
                $confirmation['confirmation_message'] = $this->translateFieldReferences(
                    $body,
                    $this->formFieldsFrom($formData)
                );
            }
        }

        // A form with neither an active redirect nor an active message contributes
        // nothing, rather than writing an empty confirmation block onto every form.
        if ($confirmation === []) {
            return;
        }

        $form->merge($confirmation);
    }

    /**
     * Map the `save` action onto Statamic's submission storage.
     *
     * Ninja stores a submission only when a `save` action is present and active — the
     * T-Shirt Reservation form has none at all, and Love Lights' is switched off. Statamic
     * expresses the same choice as `Form::store()`.
     *
     * Only an **explicit** `save` action moves the setting. A form with no `save` action
     * is left at Statamic's default rather than having storage silently disabled: reading
     * absence as "do not store" would risk discarding submissions on a form whose export
     * simply predates the action, and losing a submission is worse than storing one that
     * WordPress did not.
     *
     * @param  Form  $form  The Statamic form being configured
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     */
    protected function mapSubmissionStorage(
        Form $form,
        array $formData
    ): void {
        $save = $this->firstActionOfType($formData, 'save');

        // No save action at all: leave Statamic's default untouched. See the docblock.
        if ($save === null) {
            return;
        }

        $form->store($this->isActionActive($save));
    }

    /**
     * Carry an active payment action's configuration onto the form for manual setup.
     *
     * Statamic has no payment infrastructure, so nothing here is *applied* — this records
     * what WordPress does today so it is not lost, for whoever configures payment later.
     * The Statamic-side decision is `UAMS-Web/uams-statamic#48`; the implementation is
     * `wordpress-importer-archive#295`. Neither is needed to preserve the configuration, which is why this does not
     * wait on them.
     *
     * Only an **active** action is carried. Love Lights' `collectpayment` is switched off
     * *and* references a gateway whose plugin is not installed on its subsite — it is dead
     * configuration inherited by duplicating Miracle Stars, and recording it would invent
     * a payment form that does not exist.
     *
     * The field map is recorded as **observed current state, not specification**. It is
     * partly broken on the live form, and the record says so per row rather than
     * presenting it as a target to reimplement.
     *
     * @param  Form  $form  The Statamic form being configured
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     */
    protected function carryPaymentConfiguration(
        Form $form,
        array $formData
    ): void {
        $action = $this->firstActiveActionOfType($formData, 'collectpayment');

        // No active payment action: the overwhelming majority of forms.
        if ($action === null) {
            return;
        }

        $fields = $this->formFieldsFrom($formData);

        $carried = [
            'plugin' => 'Ninja Forms',
            'status' => 'Recorded from the WordPress form. Not configured in Statamic — see UAMS-Web/uams-statamic#48.',
            'gateway' => \is_scalar($action['payment_gateways'] ?? null)
                ? (string) $action['payment_gateways']
                : null,
            'amount_source' => \is_scalar($action['payment_total'] ?? null)
                ? (string) $action['payment_total']
                : null,
            'amount_source_type' => \is_scalar($action['payment_total_type'] ?? null)
                ? (string) $action['payment_total_type']
                : null,
            'price_table' => $this->observedPriceTable($fields),
            'observed_field_map' => $this->observedFieldMap($action, $fields),
        ];

        $form->merge(['wordpress_payment_import' => $carried]);
    }

    /**
     * Record the per-option price values the amount is actually computed from.
     *
     * The amount equation reads like arithmetic over two fields, but the prices live in
     * the **options** — each option's `calc` is its price, and the option's `value` is the
     * quantity ($4 per star, $5 per light). Those `calc` values are dropped by the choice-list
     * mapper, documented there, because Statamic has nowhere to put them — so without this
     * record the amount rule cannot be reconstructed from the imported form at all.
     *
     * @param  array<int|string, mixed>  $fields  The export's fields array
     * @return array<string, array<string, string>> Field key => option value => price
     */
    private function observedPriceTable(
        array $fields
    ): array {
        $table = [];

        foreach ($fields as $field) {
            if (! \is_array($field)) {
                continue;
            }

            $key = $field['key'] ?? null;
            $options = $field['options'] ?? null;

            if (! \is_string($key) || ! \is_array($options)) {
                continue;
            }

            $prices = [];

            foreach ($options as $option) {
                if (! \is_array($option)) {
                    continue;
                }

                $calc = $option['calc'] ?? null;
                $value = $option['value'] ?? null;

                // Only options that actually carry a price are of interest.
                if (! \is_scalar($calc) || (string) $calc === '' || ! \is_scalar($value)) {
                    continue;
                }

                $prices[(string) $value] = (string) $calc;
            }

            if ($prices !== []) {
                $table[$key] = $prices;
            }
        }

        return $table;
    }

    /**
     * Record the gateway field map, flagging references that resolve to nothing.
     *
     * Each row targets a gateway field and sources it from free text containing merge
     * tags. Ninja leaves an unresolvable tag in an action setting **literal** — only
     * calculation equations scrub them — so a dangling reference is transmitted verbatim
     * to the gateway rather than as an empty value. Flagging it per row keeps the record
     * honest about which rows are trustworthy.
     *
     * @param  array<int|string, mixed>  $action  The payment action
     * @param  array<int|string, mixed>  $fields  The export's fields array
     * @return array<int, array<string, mixed>> One entry per populated map row
     */
    private function observedFieldMap(
        array $action,
        array $fields
    ): array {
        $rows = $action['nfelavon_field_map'] ?? null;

        if (! \is_array($rows)) {
            return [];
        }

        $known = $this->fieldKeysIn($fields);
        $map = [];

        foreach ($rows as $row) {
            if (! \is_array($row)) {
                continue;
            }

            $target = $row['field_map'] ?? null;
            $source = $row['form_field'] ?? null;

            // Ninja itself skips a row with either half blank, so neither is recorded.
            if (
                ! \is_string($target) || \trim($target) === ''
                || ! \is_string($source) || \trim($source) === ''
            ) {
                continue;
            }

            $unresolved = [];

            // With no match there is nothing to scan, so `>= 0` and `> -1` behave alike.
            // @pest-mutate-ignore: GreaterToGreaterOrEqual, DecrementInteger
            if (\preg_match_all('/\{field:([^}]+)\}/', $source, $matches) > 0) {
                foreach ($matches[1] as $referenced) {
                    if (! \in_array($referenced, $known, true)) {
                        $unresolved[] = $referenced;
                    }
                }
            }

            $map[] = [
                'gateway_field' => $target,
                'source' => $source,
                'unresolved_references' => $unresolved,
            ];
        }

        return $map;
    }

    /**
     * Collect every field key in the export, including those nested in repeaters.
     *
     * @param  array<int|string, mixed>  $fields  The export's fields array
     * @return array<int, string> The field keys
     */
    private function fieldKeysIn(
        array $fields
    ): array {
        $keys = [];

        foreach ($fields as $field) {
            if (! \is_array($field)) {
                continue;
            }

            $key = $field['key'] ?? null;

            // A reference is compared strictly as a string, so a key that is not a string
            // and is admitted here could never match one.
            // @pest-mutate-ignore: BooleanAndToBooleanOr
            if (\is_string($key) && $key !== '') {
                $keys[] = $key;
            }

            $nested = $field['fields'] ?? null;

            if (\is_array($nested)) {
                $keys = \array_merge($keys, $this->fieldKeysIn($nested));
            }
        }

        return $keys;
    }

    /**
     * Find the first action of a type, whether or not it is active.
     *
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @param  string  $type  The Ninja Forms action type
     * @return array<int|string, mixed>|null The action, or null when absent
     */
    private function firstActionOfType(
        array $formData,
        string $type
    ): ?array {
        $actions = $formData['actions'] ?? null;

        if (! \is_array($actions)) {
            return null;
        }

        foreach ($actions as $action) {
            if (\is_array($action) && ($action['type'] ?? null) === $type) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Find the first active action of a type.
     *
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @param  string  $type  The Ninja Forms action type
     * @return array<int|string, mixed>|null The action, or null when absent or inactive
     */
    private function firstActiveActionOfType(
        array $formData,
        string $type
    ): ?array {
        $actions = $formData['actions'] ?? null;

        if (! \is_array($actions)) {
            return null;
        }

        foreach ($actions as $action) {
            if (
                \is_array($action)
                && ($action['type'] ?? null) === $type
                && $this->isActionActive($action)
            ) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Whether a Ninja Forms action is switched on.
     *
     * `active` is `'1'` / `'0'` in the exports. A missing key is treated as active, which
     * is how Ninja itself behaves for an action saved before the toggle existed.
     *
     * @param  array<int|string, mixed>  $action  The Ninja Forms action
     * @return bool Whether the action is active
     */
    private function isActionActive(
        array $action
    ): bool {
        $active = $action['active'] ?? null;

        if ($active === null) {
            // null is not among the values below, so the final test also returns true.
            // @pest-mutate-ignore: RemoveEarlyReturn
            return true;
        }

        return ! \in_array($active, ['0', 0, false, ''], true);
    }

    /**
     * Read the export's fields array for merge-tag resolution.
     *
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @return array<int|string, mixed> The fields array, or an empty array
     */
    private function formFieldsFrom(
        array $formData
    ): array {
        $fields = $formData['fields'] ?? null;

        return \is_array($fields) ? $fields : [];
    }

    /**
     * Process a single confirmation view.
     *
     * Not reached: Ninja exports carry no confirmation *views*, which are a Formidable
     * Forms construct.
     *
     * @param  array<int|string, mixed>  $processedViewData  The processed view data
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @param  string  $siteHandle  The Statamic site handle
     * @param  mixed  $assetImporter  Asset importer instance, when available
     * @param  bool  $isAdditional  Whether this is an additional confirmation
     * @return array<int|string, mixed>|null Always null; see above
     */
    protected function processSingleConfirmationView(
        array $processedViewData,
        array $formData,
        string $siteHandle,
        $assetImporter = null,
        bool $isAdditional = false
    ): ?array {
        return null;
    }

    /**
     * Map plugin-specific form fields.
     *
     * Everything a `.nff` carries that maps cleanly is declared in
     * FORM_CONFIGURATION_MAPPING, so there is no residue needing bespoke handling.
     *
     * @param  array<int|string, mixed>  $currentFormData  The Statamic form config being built
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @param  string  $siteHandle  The Statamic site handle
     * @param  mixed  $assetImporter  Asset importer instance, when available
     */
    protected function mapPluginSpecificFields(
        array &$currentFormData,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        // Intentionally empty — see the docblock above.
    }

    /**
     * Process a plugin-specific field.
     *
     * @param  string  $statamicKey  The Statamic attribute key
     * @param  mixed  $value  The value being mapped
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @param  string  $siteHandle  The Statamic site handle
     * @param  mixed  $assetImporter  Asset importer instance, when available
     * @return mixed The value unchanged; Ninja needs no bespoke per-key handling
     */
    protected function processPluginSpecificField(
        string $statamicKey,
        $value,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): mixed {
        return $value;
    }

    /**
     * Process complex format mappings in a value.
     *
     * Gravity Forms uses this for its quiz, survey and date format tokens. Ninja Forms
     * has no equivalent token vocabulary, so the value passes through unchanged.
     *
     * @param  string  $value  The value to process
     * @return string The value, unchanged
     */
    protected function processComplexFormatMappings(
        string $value
    ): string {
        return $value;
    }

    /**
     * Process a date/time field.
     *
     * Ninja Forms has a single `date` field with no separate time component and no
     * combination construct, so there is nothing to assemble.
     *
     * @param  string  $statamicKey  The Statamic attribute key
     * @param  mixed  $value  The value being mapped
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @return string|null Always null; see above
     */
    protected function processDateTimeField(
        string $statamicKey,
        $value,
        array $formData
    ): ?string {
        return null;
    }

    /**
     * Process a date/time combination.
     *
     * Null for the same reason as processDateTimeField().
     *
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration
     * @param  array<int|string, mixed>  $formData  The decoded `.nff` export
     * @return string|null Always null; see above
     */
    protected function processDatetimeCombination(
        array $mappingConfig,
        array $formData
    ): ?string {
        return null;
    }

    /**
     * Convert conditional routing rules to the Statamic format.
     *
     * Ninja Forms has no routing construct. Gravity Forms routes a notification to
     * different recipients by rule; Ninja instead gates a whole action with its
     * `conditions` object, which is a different shape and belongs with conditional-logic
     * import rather than recipient routing.
     *
     * @param  array<int|string, mixed>  $routingRules  The routing rules
     * @param  array<int|string, mixed>  $formFields  The export's fields array
     * @return array<int|string, mixed> Always empty; see above
     */
    protected function convertRoutingRulesToStatamicFormat(
        array $routingRules,
        array $formFields
    ): array {
        return [];
    }

    /**
     * Convert a routing operator to the Statamic format.
     *
     * Unreachable while convertRoutingRulesToStatamicFormat() returns nothing; the
     * operator is passed through so the method is honest rather than throwing.
     *
     * @param  string  $operator  The plugin's operator
     * @return string The operator, unchanged
     */
    protected function convertRoutingOperatorToStatamicFormat(
        string $operator
    ): string {
        return $operator;
    }

    /**
     * Translate every Ninja Forms reference in a string to its Statamic equivalent.
     *
     * Covers both vocabularies a `.nff` uses: `{field:<key>}` field references, and the
     * `{wp:…}` system tags. Applied by the engine wherever a mapping entry sets
     * `translate_field_references`.
     *
     * @param  string  $text  The text to translate
     * @param  array<int|string, mixed>  $allFields  The export's fields array
     * @return string The translated text
     */
    protected function translateFieldReferences(
        string $text,
        array $allFields = []
    ): string {
        // Field references first, resolved by key against the export's own fields.
        $translated = $this->processFieldMergeTags(
            $text,
            $allFields
        );

        // Then the system tags, which need no field context.
        // str_replace() pairs the two arrays by position, not by key, so re-indexing the
        // replacements changes nothing.
        // @pest-mutate-ignore: UnwrapArrayValues
        return \str_replace(
            \array_keys($this->getVariableMappings()),
            \array_values($this->getVariableMappings()),
            $translated
        );
    }
}
