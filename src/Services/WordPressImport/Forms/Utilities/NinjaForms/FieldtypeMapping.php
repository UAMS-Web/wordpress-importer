<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FieldtypeMappingTrait;

/**
 * Maps Ninja Forms field types to Statamic fieldtypes.
 *
 * This class is responsible for:
 *
 * - Declaring the Ninja Forms -> Statamic fieldtype mapping.
 * - Delegating resolution to the shared FieldtypeMappingTrait engine.
 *
 * ## Which types are covered
 *
 * The 23 field types the four production exports actually use, established by walking
 * every `.nff` in the corpus **recursively**. Six of them — `textbox`, `listradio`,
 * `listcheckbox`, `checkbox`, `date`, `zip` — appear ONLY inside `repeater` sets, so a
 * top-level walk reports 17 and silently misses `textbox`, which is the second most
 * common type overall. Anything Ninja Forms supports but no production form uses is
 * deliberately absent; the trait raises an unmapped-fieldtype warning, which is the
 * signal to extend this map with the case that prompted it.
 *
 * ## Composite address fields are mapped individually, by choice
 *
 * Ninja Forms models a postal address as four sibling fields — `address`, `city`,
 * `liststate`, `zip` — where Statamic has one composite `address` fieldtype. They are
 * mapped one-to-one here rather than consolidated, because consolidation is a decision
 * about the target blueprint's shape and not a fieldtype mapping. Mapping `address` to
 * the composite would also silently drop the sibling values.
 */
class FieldtypeMapping
{
    /**
     * Shared fieldtype mapping engine.
     */
    use FieldtypeMappingTrait;

    /**
     * Ninja Forms field type to Statamic fieldtype mapping.
     *
     * Keys are the `type` value on a Ninja Forms field; `false` suppresses a field.
     *
     * @var array<string, array<string, mixed>>
     */
    protected static array $mapping = [
        // --- Text and identity -------------------------------------------------------
        'textbox' => ['default' => 'text'],
        'firstname' => ['default' => 'text'],
        'lastname' => ['default' => 'text'],
        'email' => ['default' => 'email'],
        'phone' => ['default' => 'phone'],

        // --- Postal address, mapped per field; see the class docblock ----------------
        'address' => ['default' => 'text'],
        'city' => ['default' => 'text'],
        'zip' => ['default' => 'text'],
        'liststate' => ['default' => 'select'],

        // --- Numeric and temporal ----------------------------------------------------
        'number' => ['default' => 'float'],
        'date' => ['default' => 'date'],

        // --- Choice ------------------------------------------------------------------
        'listselect' => ['default' => 'select'],
        'listradio' => ['default' => 'radio'],
        'listcheckbox' => ['default' => 'checkboxes'],

        // ---
        // A Ninja Forms `checkbox` is a SINGLE boolean box, not a set of options — its
        // multi-option sibling is `listcheckbox` above. `toggle` is the honest target;
        // `checkboxes` would model one boolean as a one-item option list.
        // ---
        'checkbox' => ['default' => 'toggle'],

        // --- Structural --------------------------------------------------------------
        'html' => ['default' => 'html'],

        // A divider. `subsection` is the nearest structural fieldtype the sibling maps
        // already target; it carries the visual break without inventing a heading.
        'hr' => ['default' => 'subsection'],

        // Repeating fieldsets. This is the whole body of the waiver form — the
        // highest-volume Ninja Form at UAMS — so it is the load-bearing entry here.
        'repeater' => ['default' => 'replicator'],

        // ---
        // Statamic forms render their own submit control, so importing one would produce
        // a duplicate button. `false` suppresses the field rather than leaving it
        // unmapped, which would otherwise raise a warning on every single form.
        // ---
        'submit' => ['default' => false],
    ];

    /**
     * Ninja Forms field attribute to Statamic field attribute mapping.
     *
     * Scoped to what the production corpus actually carries. Enumerating every field of
     * all four exports yields 53 distinct attribute keys, of which **18 are never
     * populated on any field** and most of the rest are Ninja admin-drawer state with no
     * Statamic meaning — `editActive`, `drawerDisabled`, `idAttribute` (always the
     * literal `"id"`), `objectType`/`objectDomain` (always `"Field"`/`"fields"`),
     * `manual_key`, `repeaterField`. Mapping those would add surface without adding
     * behaviour, so this maps the attributes that carry meaning and documents the
     * judgement calls below rather than leaving them silent.
     *
     * Deliberately NOT mapped, and why:
     *
     * - `input_limit_type` and `input_limit_msg` are set on 19 fields, but
     *   **`input_limit` itself is never populated anywhere in the corpus.** The type and
     *   message are Ninja's defaults sitting behind an unset limit, so importing a
     *   "Character(s) left" counter with no limit behind it would invent a constraint the
     *   live form does not enforce.
     * - `label_pos` (59 fields) positions the label above/left/inside the input. Statamic
     *   renders labels from the blueprint and offers no equivalent.
     * - `personally_identifiable` (24 fields) is a Ninja data-retention flag with no
     *   Statamic counterpart. It is a real signal and worth carrying one day, but it
     *   belongs to a retention feature that does not exist yet.
     * - `custom_name_attribute` (30 fields) sets the raw HTML `name`; Statamic derives
     *   that from the handle, so honouring it would break form submission.
     * - `mask` is live on exactly one field — a credit-card expiry (`99/9999`) — which is
     *   #631 territory and blocked on `uams-statamic#295`.
     * - `processing_label` (4 fields) labels the submit button mid-submit, and `submit`
     *   maps to `false` in the fieldtype map, so the field never reaches a blueprint.
     *
     * @var array<int|string, array<int|string, mixed>>
     */
    protected static array $fieldAttributeMapping = [
        // Description Text
        // --------------------------------------------------------------------------------
        // Fieldtypes: all
        //
        // Ninja's per-field help text, authored in a rich-text drawer, so the value is
        // HTML rather than plain text. Statamic's `instructions` renders Markdown and
        // passes HTML through, so it carries across without conversion.
        //
        // It is frequently present but empty — 33 fields declare the key and only 7 hold
        // anything, and the empty ones hold `<p><br></p>` rather than `''`. That string is
        // what an emptied rich-text drawer saves, so it is treated as empty rather than
        // imported as a blank paragraph.
        // --------------------------------------------------------------------------------
        'desc_text' => [
            'statamic_attribute' => [
                'default' => 'instructions',
            ],
            'value_translation' => [
                '<p><br></p>' => '',
                '<p></p>' => '',
            ],
        ],

        // Default Value
        // --------------------------------------------------------------------------------
        // Fieldtypes: all, but it carries two different meanings.
        //
        // On an `html` field the value IS the field's content — the whole point of the
        // fieldtype — and the corpus uses it that way (`<h2>Miracle Star Buyer
        // Information</h2>`). On an input field it is the pre-filled value. Both land on
        // Statamic's `default`, and `html` maps to a `section`/`html` fieldtype whose
        // display comes from the same key, so one mapping serves both.
        //
        // Ninja also supports merge tags in a default (`{wp:user_email}` and friends).
        // None appear in the corpus, and there is no Statamic analog for a dynamic
        // default, so they are left verbatim rather than resolved to a wrong value — a
        // visible `{wp:...}` in the CP is a better failure than a silently wrong default.
        // --------------------------------------------------------------------------------
        'default' => [
            'statamic_attribute' => [
                'default' => 'default',
            ],
        ],

        // Number Step
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - number (maps to Statamic `float`)
        //
        // The increment of the number input's stepper. Every corpus instance is `1` — the
        // T-Shirt reservation's per-size quantity fields — but the attribute is genuinely
        // meaningful and cheap to carry.
        //
        // `num_min` and `num_max` are deliberately absent from this mapping: both are
        // declared on all 6 number fields and populated on none, so there is no range to
        // import. Mapping them would write `min: ''` into every number field's blueprint.
        // --------------------------------------------------------------------------------
        'num_step' => [
            'statamic_attribute' => [
                'default' => 'step',
            ],
        ],

        // Repeater: Add-Another Button Label
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - repeater (maps to Statamic `replicator`)
        //
        // Labels the button that adds another set. The waiver form's is "Add Another
        // Participant Waiver", which is exactly the kind of wording an editor would have
        // to re-type by hand if it were dropped.
        // --------------------------------------------------------------------------------
        'button_text' => [
            'statamic_attribute' => [
                'default' => 'button_label',
            ],
        ],

        // Checkbox: Checked / Unchecked Values and Default State
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - checkbox (maps to Statamic `toggle`)
        //
        // Ninja stores a checkbox's two submitted values as free text (`Checked` /
        // `Unchecked` in the corpus) and its initial state as the string `checked` or
        // `unchecked`. Statamic's `toggle` submits a boolean and takes a boolean
        // `default`, so only the initial state translates; the value labels have no
        // Statamic counterpart and are dropped rather than mapped to something that
        // would change what a submission contains.
        // --------------------------------------------------------------------------------
        'default_value' => [
            'statamic_attribute' => [
                'default' => 'default',
            ],
            'exclude_fieldtypes' => [
                // On non-checkbox fieldtypes `default` above already carries the value;
                // letting this entry apply too would overwrite it.
                'textbox',
                'email',
                'phone',
                'number',
                'address',
                'city',
                'zip',
                'firstname',
                'lastname',
                'html',
            ],
            'value_translation' => [
                'checked' => true,
                'unchecked' => false,
            ],
        ],

        // Date: Mode, Format and Time Granularity
        // --------------------------------------------------------------------------------
        // Fieldtypes:
        //   - date (maps to Statamic `date`)
        //
        // `date_mode` selects date-only, time-only or both; the corpus's single date field
        // is `date_only`. Statamic expresses the same choice as a `time_enabled` boolean,
        // so the two time-bearing modes both enable it.
        //
        // `date_format` is a moment.js format string (`MM/DD/YYYY`), which is NOT PHP's
        // date format — Statamic wants a PHP format, and the two share letters with
        // different meanings (`D` is day-of-month in PHP and day-of-week in moment). The
        // translation below covers the formats the corpus actually uses; an unrecognised
        // format is left for the engine to pass through rather than mistranslated, since a
        // wrong format silently renders wrong dates.
        // --------------------------------------------------------------------------------
        'date_mode' => [
            'statamic_attribute' => [
                'default' => 'time_enabled',
            ],
            'value_translation' => [
                'date_only' => false,
                'date_and_time' => true,
                'time_only' => true,
            ],
        ],
        'date_format' => [
            'statamic_attribute' => [
                'default' => 'format',
            ],
            'value_translation' => [
                'MM/DD/YYYY' => 'm/d/Y',
                'DD/MM/YYYY' => 'd/m/Y',
                'YYYY-MM-DD' => 'Y-m-d',
            ],
        ],
        'minute_increment' => [
            'statamic_attribute' => [
                'default' => 'time_step',
            ],
        ],
    ];

    /**
     * Ninja Forms field option to Statamic field option mapping.
     *
     * Empty, and correctly so — not merely deferred. The shared engine only consults this
     * map after parsing a `field_options` JSON string off the field, which is a Formidable
     * Forms shape. A Ninja Forms field is flat: every setting sits directly on the field
     * object, so it is $fieldAttributeMapping above that carries all of them.
     *
     * @var array<int|string, array<int|string, mixed>>
     */
    protected static array $fieldAttributeOptionsMapping = [];

    /**
     * Ninja Forms subfield to Statamic subfield mapping.
     *
     * Empty for now. This is where a `repeater`\'s inner fields will map when blueprint
     * creation lands — the waiver form\'s entire body is one repeater, so this will not
     * stay empty for long.
     *
     * @var array<int|string, array<int|string, mixed>>
     */
    protected static array $subfieldMapping = [];

    /**
     * Get the field attribute mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array for field attributes
     */
    public static function getFieldAttributeMapping(): array
    {
        return static::$fieldAttributeMapping;
    }

    /**
     * Get the field options mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array for field options
     */
    public static function getFieldOptionsMapping(): array
    {
        return static::$fieldAttributeOptionsMapping;
    }

    /**
     * Extract conditional logic data from Ninja Forms field data.
     *
     * Always null, and that is the correct answer rather than a stub. Ninja Forms models
     * conditional logic at the **form** level: `settings.conditions` is a list of rules,
     * each with `when` clauses naming a source field key and `then` clauses naming a
     * target and a trigger such as `show_field`. A field carries no conditional
     * configuration of its own, so there is nothing to extract from one.
     *
     * Verified against the production exports: Miracle Stars and Love Lights each carry
     * two form-level conditions, and the waiver carries conditional logic on three of its
     * four *actions* — again not on fields.
     *
     * Importing those form-level rules is a separate concern from fieldtype mapping and
     * belongs with form configuration; the engine calls this hook per field, which is the
     * wrong granularity for the Ninja model.
     *
     * @param  array<int|string, mixed>  $fieldData  The Ninja Forms field data
     * @return array<int|string, mixed>|null Always null; see above
     */
    protected static function extractConditionalLogicData(
        array $fieldData
    ): ?array {
        return null;
    }

    /**
     * Get the Statamic fieldtype for a given Ninja Forms field.
     *
     * The four `creditcard*` types are deliberately absent: they belong to #631, which
     * waits on the Statamic Elavon analog, and only one production form uses them. Until
     * then the trait's unmapped-fieldtype warning is the correct outcome for those.
     *
     * @param  string|array<int|string, mixed>  $ninjaFormsField  The field type
     *                                                            or full Ninja Forms field data
     * @return string|null|false Statamic fieldtype, null if not mapped, or
     *                           false if suppressed
     */
    public static function getStatamicFieldtype(
        string|array $ninjaFormsField
    ): string|null|false {
        return static::resolveFieldtypeFromMapping(
            static::$mapping,
            $ninjaFormsField
        );
    }

    /**
     * Get the mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array
     */
    public static function getMapping(): array
    {
        return static::$mapping;
    }
}
