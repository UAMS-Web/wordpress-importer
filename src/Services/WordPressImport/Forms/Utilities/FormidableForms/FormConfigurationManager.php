<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormConfigurationManagerTrait;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Manages form configuration mapping from Formidable Forms to Statamic.
 *
 * This class is responsible for mapping Formidable Forms form configuration
 * data (notifications, etc.) to Statamic form settings.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * form configuration mapping operations.
 *
 * --------------------------------------------------------------------------------
 * Notification import audit (2026-06-03, issue UAMS-Web/wordpress-importer-archive#26)
 * --------------------------------------------------------------------------------
 * Audited against the 13-file Formidable Forms export corpus
 * (`FormidableForms/` in `UAMS-Web/wordpress-importer-exports`): 69 forms, 56 of them carrying notification
 * or confirmation actions, across 88 `email` and 15 `on_submit` frm_form_actions
 * (plus 1 `wppost` and 1 `api` action that are not notifications).
 *
 * Mapping coverage (this class) is sound. Every email/confirmation content field
 * key present in the corpus is mapped, intentionally dropped, or benign; plain
 * system merge tags (`[admin_email]`, `[sitename]`, `[form_name]`,
 * `[default-message]`) and numeric field-id references (`[3]`) resolve cleanly;
 * confirmation message/redirect/page actions and `from`/`reply_to` splitting all
 * round-trip. Verified by a real-import pass over all 56 forms.
 *
 * The audit surfaced gaps that mostly sit OUTSIDE this class's mapping arrays
 * (tracked as follow-up issues):
 *   - Notification/confirmation import is unreachable in production
 *     (UAMS-Web/wordpress-importer-archive#37):
 *     Prompts\Loaders\XmlFileLoader::convertXmlToArray() parses <form>/<field>
 *     but never extracts the <view> (frm_form_actions) siblings, so
 *     $formData['views'] is always empty and every method here early-returns.
 *   - mapNotificationsToEmailConfigurations() reads
 *     FORM_CONFIGURATION_VIEW_MAPPING[$excerpt] without the `?? null` guard the
 *     sibling methods (mapViewFields, mapConfirmationFields) use, so a `wppost`
 *     or `api` action raises "Undefined array key". Two corpus forms trip this.
 *     (UAMS-Web/wordpress-importer-archive#40)
 *   - Email-action conditional logic (`conditions`) was dropped
 *     (UAMS-Web/wordpress-importer-archive#38, FIXED):
 *     processConditionalLogic() ran only in the processEmailView() path whose
 *     output is overwritten, so the 22 corpus email actions carrying send-rules
 *     all imported as send-to-everyone. The authoritative
 *     mapNotificationsToEmailConfigurations() path now calls it too, so the
 *     rules survive Form::save(). The confirmation CL path was already wired.
 *   - Merge-tag resolution gaps (UAMS-Web/wordpress-importer-archive#39, FIXED):
 *     field-KEY references (`[provider_email_address]`) now resolve through a
 *     last-resort `field_key` lookup in translateFieldReferences(), after the id
 *     and label lookups so nothing that already resolved changes; Formidable
 *     conditional shortcodes (`[if x]...[/if x]`) have their wrappers stripped,
 *     keeping the inner reference; and the parameterized
 *     `[default-message exclude_fields="..."]` maps to the same
 *     `{{ all_fields }}` base tag as the bare form, dropping the per-field
 *     exclusion Statamic has no analog for.
 *
 * Intentionally dropped (no Statamic analog, no ticket filed): `inc_user_info`,
 * `attach_csv`, `plain_text`, `email_attachment_id`, `event` (always
 * `["create"]` in the corpus), and `menu_order`.
 *
 * Full audit artifacts: see issue UAMS-Web/wordpress-importer-archive#26.
 * --------------------------------------------------------------------------------
 */
class FormConfigurationManager
{
    /**
     * Use shared functionality for form configuration management.
     */
    use FormConfigurationManagerTrait;

    /**
     * Mapping from Formidable Forms form configuration fields to Statamic form fields.
     *
     * Key: Formidable Forms form configuration field name (supports dot notation for
     * nested objects)
     * Value: Statamic form field name (null to exclude from mapping)
     *
     * Note: Email configuration fields (notifications) are mapped separately
     * using the FORM_CONFIGURATION_ACTION_MAPPING_EMAIL array.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected const FORM_CONFIGURATION_MAPPING = [
        // Form ID
        // --------------------------------------------------------------------------------
        // Value type: integer
        //
        // Used for generating form handle, etc., but not directly mapped to the form
        // configuration.
        // --------------------------------------------------------------------------------
        'id' => [
            'statamic_attribute' => null,
        ],

        // Form Key
        // --------------------------------------------------------------------------------
        // Value type: string
        //
        // Not used for generating anything and not directly mapped to the form
        // configuration.
        // --------------------------------------------------------------------------------
        'form_key' => [
            'statamic_attribute' => null,
        ],

        // Form Title
        // --------------------------------------------------------------------------------
        // Value type: string
        // --------------------------------------------------------------------------------
        'name' => [
            'statamic_attribute' => [
                'multiple' => [
                    'title' => 'title',
                    'heading' => 'heading',
                ],
            ],
        ],

        // Form Description
        // --------------------------------------------------------------------------------
        // Value type: string
        // --------------------------------------------------------------------------------
        'description' => [
            'statamic_attribute' => 'description',
            'strip_html' => true,
        ],

        // Form Creation Date
        // --------------------------------------------------------------------------------
        // Value type: string (datetime)
        //
        // Not used for generating anything and not directly mapped to the form
        // configuration.
        // --------------------------------------------------------------------------------
        'created_at' => [
            'statamic_attribute' => null,
        ],

        // Limit form visibility
        // --------------------------------------------------------------------------------
        // Value type: integer string (boolean)
        //
        // Not being used in any active UAMS forms.
        //
        // Not used for generating anything and not directly mapped to the form
        // configuration.
        // --------------------------------------------------------------------------------
        'logged_in' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // is_template
        // --------------------------------------------------------------------------------
        // Value type: integer (boolean)
        //
        // Not being used in any active UAMS forms.
        //
        // Not used for generating anything and not directly mapped to the form
        // configuration.
        // --------------------------------------------------------------------------------
        'is_template' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Allow front-end editing of entries (Form Permissions)
        // --------------------------------------------------------------------------------
        // Value type: integer (boolean)
        //
        // Not being used in any active UAMS forms.
        //
        // Not used for generating anything and not directly mapped to the form
        // configuration.
        // --------------------------------------------------------------------------------
        'editable' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // options
        // --------------------------------------------------------------------------------
        // Mapped separately by FORM_CONFIGURATION_MAPPING_OPTIONS
        // --------------------------------------------------------------------------------
        'options' => [
            'statamic_attribute' => null,
        ],

        // status
        // --------------------------------------------------------------------------------
        // Value type: string (enum)
        //   - "published"
        //
        // All instances of this attribute in UAMS forms have a value of "published".
        //
        // Not used for generating anything and not directly mapped to the form
        // configuration.
        // --------------------------------------------------------------------------------
        'status' => [
            'statamic_attribute' => null,
        ],

        // Parent Form ID
        // --------------------------------------------------------------------------------
        // This is only used by forms that are subforms of another form (i.e., they are
        // embedded in another form, they are the contents of a repeater, etc.).
        // --------------------------------------------------------------------------------
        'parent_form_id' => [
            'statamic_attribute' => null,
        ],
    ];

    protected const FORM_CONFIGURATION_MAPPING_OPTIONS = [

        // Show the form title
        // --------------------------------------------------------------------------------
        // Value type: integer string
        //
        // This will not be mapped to the form configuration since this decision will be
        // made at the page level.
        // --------------------------------------------------------------------------------
        'show_title' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Show the form description
        // --------------------------------------------------------------------------------
        // Value type: integer string
        //
        // This will not be mapped to the form configuration since this decision will be
        // made at the page level.
        // --------------------------------------------------------------------------------
        'show_description' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // on_submit_migrated
        // --------------------------------------------------------------------------------
        // Included in the settings UI as an input[type="hidden"] element.
        //
        // Value type: integer string
        //
        // This will not be mapped to the form configuration.
        // --------------------------------------------------------------------------------
        'on_submit_migrated' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Do not store entries submitted from this form
        // --------------------------------------------------------------------------------
        // Value type: integer string
        // --------------------------------------------------------------------------------
        'no_save' => [
            'statamic_attribute' => 'store',
            'as_boolean' => true,
            'invert_boolean' => true,
        ],

        // Use Honeypot to check entries for spam
        // --------------------------------------------------------------------------------
        // Include an invisible field in your form to trick bots. Setting to strict will
        // catch more spam but issues with autocomplete may prevent real people from
        // submitting on some browsers.
        //
        // Value type: string (enum)
        //   - "off" (Off)
        //   - "basic" (Basic)
        //   - "strict" (Strict)
        //
        // This will not be mapped to the form configuration as honeypot is enabled by
        // default.
        // --------------------------------------------------------------------------------
        'honeypot' => [
            'statamic_attribute' => null,
        ],

        // Check entries for spam using JavaScript
        // --------------------------------------------------------------------------------
        // Generate unique tokens for validating form submissions. Uploaded files will
        // also be validated for spam.
        //
        // Value type: integer string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'antispam' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Load and save form builder page with AJAX
        // --------------------------------------------------------------------------------
        // Recommended for long forms.
        //
        // Value type: integer string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'ajax_load' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Submit this form with AJAX
        // --------------------------------------------------------------------------------
        // Submit the form without refreshing the page.
        //
        // Value type: integer string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'ajax_submit' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Validate this form with javascript
        // --------------------------------------------------------------------------------
        // Required fields, email format, and number format can be checked instantly in
        // your browser. You may want to turn this option off if you have any
        // customizations to remove validation messages on certain fields.
        //
        // Value type: integer string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'js_validate' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // custom_style
        // --------------------------------------------------------------------------------
        // Included in the settings UI as an input[type="hidden"] element.
        //
        // Value type: integer string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'custom_style' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // rootline_titles_on
        // --------------------------------------------------------------------------------
        // Included in the settings UI as an input[type="hidden"] element.
        //
        // Value type: integer string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'rootline_titles_on' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Rootline numbers off
        // --------------------------------------------------------------------------------
        // Included in the settings UI as an input[type="hidden"] element.
        //
        // Value type: integer string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'rootline_numbers_off' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Rootline lines off
        // --------------------------------------------------------------------------------
        // Included in the settings UI as an input[type="hidden"] element.
        //
        // Value type: integer string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'rootline_lines_off' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Submit conditions
        // --------------------------------------------------------------------------------
        // The UI for this attribute is on the submit button in the form builder.
        //
        // Mapped separately by FORM_CONFIGURATION_MAPPING_OPTIONS_SUBMIT_CONDITIONS
        // --------------------------------------------------------------------------------
        'submit_conditions' => [
            'statamic_attribute' => null,
        ],

        // Limit form visibility to (roles)
        // --------------------------------------------------------------------------------
        // Used in concert with `logged_in`.
        //
        // Value type: array<string(enum)>
        //   - "" (Logged-in Users)
        //   - "administrator" (Administrator)
        //   - "editor" (Editor)
        //   - "author" (Author)
        //   - "contributor" (Contributor)
        //   - "subscriber" (Subscriber)
        //   - "newsletterglue_manager" (Newsletter Glue Manager)
        //
        // There is no analog to this in Statamic.
        //
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'logged_in_role' => [
            'statamic_attribute' => null,
        ],

        // Limit number of entries
        // --------------------------------------------------------------------------------
        // There is no analog to this in Statamic.
        //
        // Value type: integer string (boolean)
        //
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'single_entry' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Limit number of entries to one per (roles)
        // --------------------------------------------------------------------------------
        // Used in concert with `single_entry`.
        //
        // Value type: array<string(enum)>
        //   - "user" (Logged-in User)
        //   - "ip" (IP Address)
        //   - "cookie" (Saved Cookie)
        //   - "email" (Email Address)
        //
        // There is no analog to this in Statamic.
        //
        // `single_entry` is not being used in any active UAMS forms, so
        // `single_entry_type` is not being used in any active forms by extension, despite
        // this attribute having a value in some active UAMS forms.
        // --------------------------------------------------------------------------------
        // Value type: array
        // --------------------------------------------------------------------------------
        'single_entry_type' => [
            'statamic_attribute' => null,
        ],

        // Cookie Expiration (Form Permissions)
        // --------------------------------------------------------------------------------
        // Used in concert with `single_entry` and `single_entry_type` to limit the number
        // of entries that can be submitted.
        //
        // Value type: integer string
        //
        // There is no analog to this in Statamic.
        //
        // `single_entry` is not being used in any active UAMS forms, so
        // `cookie_expiration` is not being used in any active forms by extension, despite
        // this attribute having a value in some active UAMS forms.
        // --------------------------------------------------------------------------------
        'cookie_expiration' => [
            'statamic_attribute' => null,
            'as_integer' => true,
        ],

        // Unique email ID
        // --------------------------------------------------------------------------------
        // Included in the settings UI as an input[type="hidden"] element.
        //
        // Value type: string
        //
        // Likely used in concert with `single_entry` and `single_entry_type` to limit the
        // number of entries that can be submitted.
        //
        // `single_entry` is not being used in any active UAMS forms, so
        // `unique_email_id` is not being used in any active forms by extension, despite
        // this attribute having a value in some active UAMS forms.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'unique_email_id' => [
            'statamic_attribute' => null,
        ],

        // Protect all files uploaded in this form
        // --------------------------------------------------------------------------------
        // Value type: integer string
        //
        // This is not being used in any active UAMS forms.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'protect_files' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Role required to access file
        // --------------------------------------------------------------------------------
        // Used in concert with `protect_files`.
        //
        // Value type: array<string(enum)>
        //   - "" (Logged-in Users)
        //   - "administrator" (Administrator)
        //   - "editor" (Editor)
        //   - "author" (Author)
        //   - "contributor" (Contributor)
        //   - "subscriber" (Subscriber)
        //   - "newsletterglue_manager" (Newsletter Glue Manager)
        //
        // This is not being used in any active UAMS forms.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'protect_files_role' => [
            'statamic_attribute' => null,
        ],

        // Prevent search engines from indexing uploads
        // --------------------------------------------------------------------------------
        // This feature requires that file protection is turned on.
        //
        // Value type: integer string
        //
        // This is not being used in any active UAMS forms.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'noindex_files' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Copy this form to other blogs when Formidable Forms is activated
        // --------------------------------------------------------------------------------
        // Value type: integer string
        //
        // This is not being used in any active UAMS forms.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'copy' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Role required to edit one's own entries
        // --------------------------------------------------------------------------------
        // Used in concert with `editable`.
        //
        // Value type: array<string(enum)>
        //   - "" (Logged-in Users)
        //   - "administrator" (Administrator)
        //   - "editor" (Editor)
        //   - "author" (Author)
        //   - "contributor" (Contributor)
        //   - "subscriber" (Subscriber)
        //   - "newsletterglue_manager" (Newsletter Glue Manager)
        //
        // This is not being used in any active UAMS forms.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'editable_role' => [
            'statamic_attribute' => null,
        ],

        // Role required to edit other users' entries
        // --------------------------------------------------------------------------------
        // Used in concert with `editable`.
        //
        // Value type: array<string(enum)>
        //   - "" (Logged-in Users)
        //   - "administrator" (Administrator)
        //   - "editor" (Editor)
        //   - "author" (Author)
        //   - "contributor" (Contributor)
        //   - "subscriber" (Subscriber)
        //   - "newsletterglue_manager" (Newsletter Glue Manager)
        //
        // This is not being used in any active UAMS forms.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'open_editable_role' => [
            'statamic_attribute' => null,
        ],

        // Allow saving drafts
        // --------------------------------------------------------------------------------
        // Value type: integer string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'save_draft' => [
            'statamic_attribute' => null,
            'as_boolean' => true,
        ],

        // Allow saving drafts by (roles)
        // --------------------------------------------------------------------------------
        // Used in concert with `save_draft`.
        //
        // Value type: array<string(enum)>
        //   - "" (Logged-in Users)
        //   - "logged-out" (Logged-out Users)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'edit_draft_role' => [
            'statamic_attribute' => null,
        ],

        // Saved Draft Message
        // --------------------------------------------------------------------------------
        // Value type: string
        //
        // This is not being used in any active UAMS forms.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'draft_msg' => [
            'statamic_attribute' => null,
            'translate_field_references' => true,
        ],

        // Save Draft Text
        // --------------------------------------------------------------------------------
        // Value type: string
        //
        // This is not being used in any active UAMS forms.
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'draft_label' => [
            'statamic_attribute' => null,
        ],

        // Form Status (Form Scheduling)
        // --------------------------------------------------------------------------------
        // Value type: string (enum)
        //   - "" / not set (Open)
        //   - "closed" (Closed)
        //   - "schedule" (Schedule)
        //   - "limit" (Limit Entries)
        //   - "schedule-limit" (Schedule and Limit Entries)
        //
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'open_status' => [
            'statamic_attribute' => null,
        ],

        // Open Form On (Form Scheduling)
        // --------------------------------------------------------------------------------
        // Used in concert with `open_status` set to "schedule" or "schedule-limit".
        //
        // Value type: string (datetime)
        //
        // `open_status` is not being used in any active UAMS forms, so `open_date` is not
        // being used in any active forms by extension, despite this attribute having a
        // value in some active UAMS forms.
        // --------------------------------------------------------------------------------
        'open_date' => [
            'statamic_attribute' => null,
        ],

        // Close Form On (Form Scheduling)
        // --------------------------------------------------------------------------------
        // Used in concert with `open_status` set to "schedule" or "schedule-limit".
        //
        // Value type: string (datetime)
        //
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'close_date' => [
            'statamic_attribute' => null,
        ],

        // Entry Limit (Form Scheduling)
        // --------------------------------------------------------------------------------
        // Close the form after a specific number of entries have been received.
        //
        // Used in concert with `open_status` set to "limit" or "schedule-limit".
        //
        // Value type: integer string
        //
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'max_entries' => [
            'statamic_attribute' => null,
            'as_integer' => true,
        ],

        // Form Closed Message
        // --------------------------------------------------------------------------------
        // This message is shown when a form is closed for new entries.
        //
        // It may need to have HTML elements stripped out before being stored in Statamic.
        //
        // Used in concert with `open_status` set to a non-empty value.
        //
        // Value type: string (HTML)
        //
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'closed_msg' => [
            'statamic_attribute' => null,
        ],

        // Form Classes (Customize HTML)
        // --------------------------------------------------------------------------------
        // Value type: string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'form_class' => [
            'statamic_attribute' => null,
        ],

        // Before Fields (Customize HTML)
        // --------------------------------------------------------------------------------
        // Value type: string (HTML)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'before_html' => [
            'statamic_attribute' => null,
            'translate_field_references' => true,
        ],

        // Submit Button (Customize HTML)
        // --------------------------------------------------------------------------------
        // Value type: string (HTML)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'submit_html' => [
            'statamic_attribute' => null,
            'translate_field_references' => true,
        ],

        // Message on submit (Actions & Notifications)
        // --------------------------------------------------------------------------------
        // Appears to be a repeat of the top-most confirmation/email notification message
        // from the UI.
        //
        // Value type: string
        //
        // This type of information will be mapped separately by
        // FORM_CONFIGURATION_VIEW_MAPPING, FORM_CONFIGURATION_ACTION_MAPPING, etc.
        // --------------------------------------------------------------------------------
        'success_msg' => [
            'statamic_attribute' => null,
        ],

        // Show the form with the confirmation message (Actions & Notifications)
        // --------------------------------------------------------------------------------
        // Appears to be a repeat of the field from the top-most confirmation/email
        // notification from the UI.
        //
        // Value type: boolean
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'show_form' => [
            'statamic_attribute' => null,
        ],

        // Edit message
        // --------------------------------------------------------------------------------
        // Value type: string
        //
        // This is not being used in any active UAMS forms.
        // --------------------------------------------------------------------------------
        'edit_msg' => [
            'statamic_attribute' => null,
        ],
    ];

    protected const FORM_CONFIGURATION_MAPPING_OPTIONS_SUBMIT_CONDITIONS = [
        'show_hide' => [
            'statamic_attribute' => null,
        ],
        'any_all' => [
            'statamic_attribute' => null,
        ],
        'hide_field_cond' => [
            'statamic_attribute' => null,
        ],
    ];

    /**
     * Determine whether to map the <view> element to email notification or
     * confirmation base on the 'excerpt' attribute value.
     */
    protected const FORM_CONFIGURATION_VIEW_MAPPING = [
        'on_submit' => 'confirmation',
        'email' => 'email',
    ];

    /**
     * Mapping from Formidable Forms confirmation fields to Statamic confirmation
     * fields.
     *
     * Key: Formidable Forms confirmation field name
     * Value: Statamic confirmation field name (null to exclude from mapping)
     *
     * Note: This mapping is used for both default and additional confirmations.
     * The actual target path is determined by the confirmation type
     * (default vs additional).
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected const FORM_CONFIGURATION_ACTION_MAPPING = [
        // Action Name
        // --------------------------------------------------------------------------------
        // Value type: string
        // --------------------------------------------------------------------------------
        'title' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'excerpt' => [
                        'on_submit' => 'confirmation_name',
                        'email' => 'name',
                    ],
                ],
            ],
        ],

        // Action Status
        // --------------------------------------------------------------------------------
        // Value type: string (enum): "publish" or "draft"
        // --------------------------------------------------------------------------------
        'status' => [
            'statamic_attribute' => 'enabled',
            'value_translation' => [
                'publish' => true,
                'draft' => false,
            ],
        ],

        // content
        // --------------------------------------------------------------------------------
        // Mapped separately by FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION_CONTENT
        //
        // Value type: CDATA JSON array
        // --------------------------------------------------------------------------------
        'content' => [
            'statamic_attribute' => null,
        ],

        // Action Type
        // --------------------------------------------------------------------------------
        // Used to map the <view> element to email notification or confirmation
        //
        // Value type: CDATA string
        // --------------------------------------------------------------------------------
        'excerpt' => [
            'statamic_attribute' => null,
        ],

        // Form ID
        // --------------------------------------------------------------------------------
        // Form ID used to connect the <view> element to the specific <form> element
        //
        // Value type: integer
        // --------------------------------------------------------------------------------
        'menu_order' => [
            'statamic_attribute' => null,
        ],

        // comment_status
        // --------------------------------------------------------------------------------
        // Form ID used to connect the <view> element to the specific <form> element
        //
        // Value type: string enum: "open" or "closed"
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'comment_status' => [
            'statamic_attribute' => null,
        ],

        // ping_status
        // --------------------------------------------------------------------------------
        // Value type: string (enum): "open" or "closed"
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'ping_status' => [
            'statamic_attribute' => null,
        ],

        // post_author
        // --------------------------------------------------------------------------------
        // Username of the user who created the confirmation
        //
        // Value type: string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'post_author' => [
            'statamic_attribute' => null,
        ],

        // post_date
        // --------------------------------------------------------------------------------
        // Date and time the confirmation was created
        //
        // Value type: string (datetime)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'post_date' => [
            'statamic_attribute' => null,
        ],

        // post_date_gmt
        // --------------------------------------------------------------------------------
        // Date and time (in GMT) the confirmation was created
        //
        // Value type: string (datetime)
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'post_date_gmt' => [
            'statamic_attribute' => null,
        ],

        // post_id
        // --------------------------------------------------------------------------------
        // ID of the confirmation
        //
        // Value type: integer
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'post_id' => [
            'statamic_attribute' => null,
        ],

        // post_name
        // --------------------------------------------------------------------------------
        // Slug of the confirmation ("<menu_order>_<excerpt>_<post_id>")
        //
        // Value type: string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'post_name' => [
            'statamic_attribute' => 'id',
        ],

        // post_type
        // --------------------------------------------------------------------------------
        // "frm_form_actions"
        //
        // Value type: string
        //
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'post_type' => [
            'statamic_attribute' => null,
        ],

        // description
        // --------------------------------------------------------------------------------
        // Value type: string
        //
        // Unused attribute. Always empty.
        // --------------------------------------------------------------------------------
        'description' => [
            'statamic_attribute' => null,
        ],

        // is_sticky
        // --------------------------------------------------------------------------------
        // Value type: integer boolean
        //
        // Unused attribute. Always `0`.
        // --------------------------------------------------------------------------------
        'is_sticky' => [
            'statamic_attribute' => null,
        ],

        // link
        // --------------------------------------------------------------------------------
        // Value type: string
        //
        // Unused attribute. Always empty.
        // --------------------------------------------------------------------------------
        'link' => [
            'statamic_attribute' => null,
        ],

        // post_parent
        // --------------------------------------------------------------------------------
        // Value type: integer
        //
        // Unused attribute. Always `0`.
        // --------------------------------------------------------------------------------
        'post_parent' => [
            'statamic_attribute' => null,
        ],

        // post_password
        // --------------------------------------------------------------------------------
        // Value type: string (CDATA)
        //
        // Unused attribute. Always `<![CDATA[]]>`.
        // --------------------------------------------------------------------------------
        'post_password' => [
            'statamic_attribute' => null,
        ],
    ];

    protected const FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION = self::FORM_CONFIGURATION_ACTION_MAPPING;

    protected const FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION_CONTENT = [
        'success_action' => [
            'statamic_attribute' => 'confirmation_type',
            'value_translation' => [
                'message' => 'message',
                'redirect' => 'redirect',
                'page' => 'replace',
            ],
        ],
        'success_msg' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'success_action' => [
                        'message' => 'confirmation_message',
                        'redirect' => null,
                        'page' => null,
                    ],
                ],
            ],
            'as_bard' => true,
            'translate_field_references' => true,
        ],
        'success_url' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'success_action' => [
                        'message' => null,
                        'redirect' => 'confirmation_redirect_url',
                        'page' => null,
                    ],
                ],
            ],
        ],
        'open_in_new_tab' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'success_action' => [
                        'message' => null,
                        'redirect' => 'confirmation_redirect_target',
                        'page' => null,
                    ],
                ],
            ],
        ],
        'redirect_delay' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'success_action' => [
                        'message' => null,
                        'redirect' => 'confirmation_redirect_delay_query',
                        'page' => null,
                    ],
                ],
            ],
            'as_boolean' => true,
        ],
        'redirect_delay_time' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'success_action' => [
                        'message' => null,
                        'redirect' => 'confirmation_redirect_delay_time',
                        'page' => null,
                    ],
                ],
            ],
        ],
        'redirect_delay_msg' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'success_action' => [
                        'message' => null,
                        'redirect' => 'confirmation_redirect_message',
                        'page' => null,
                    ],
                ],
            ],
            'as_bard' => true,
            'translate_field_references' => true,
        ],
        'success_page_id' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'success_action' => [
                        'message' => null,
                        'redirect' => null,
                        'page' => 'confirmation_replace_entry',
                    ],
                ],
            ],
            'translate_page_references' => true,
        ],
        // Attributes with no analogs in Statamic
        'event' => [
            'statamic_attribute' => null,
        ],
    ];

    protected const FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION_CONTENT_CONDITIONS = [
        'send_stop' => [
            'statamic_attribute' => null,
        ],
        'any_all' => [
            'statamic_attribute' => null,
        ],
        'hide_field' => [
            'statamic_attribute' => null,
        ],
        'hide_field_cond' => [
            'statamic_attribute' => null,
        ],
        'hide_opt' => [
            'statamic_attribute' => null,
        ],
    ];

    /**
     * Mapping from Formidable Forms notification fields to Statamic email fields.
     *
     * Key: Formidable Forms notification field name
     * Value: Statamic email field name (null to exclude from mapping)
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected const FORM_CONFIGURATION_ACTION_MAPPING_EMAIL = self::FORM_CONFIGURATION_ACTION_MAPPING;

    protected const FORM_CONFIGURATION_ACTION_MAPPING_EMAIL_CONTENT = [
        // BCC
        // --------------------------------------------------------------------------------
        // Add BCC addresses separated by a ",".
        //
        // FORMAT: Name <name@email.com> or name@email.com.
        //
        // Value type: string
        // --------------------------------------------------------------------------------
        'bcc' => [
            'statamic_attribute' => 'bcc',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // CC
        // --------------------------------------------------------------------------------
        // Add CC addresses separated by a ",".
        //
        // FORMAT: Name <name@email.com> or name@email.com.
        //
        // Value type: string
        // --------------------------------------------------------------------------------
        'cc' => [
            'statamic_attribute' => 'cc',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // Message
        // --------------------------------------------------------------------------------
        // Value type: string
        // --------------------------------------------------------------------------------
        'email_message' => [
            'statamic_attribute' => 'message',
            'translate_field_references' => true,
            'as_bard' => true,
        ],

        // Subject
        // --------------------------------------------------------------------------------
        // If you leave the subject blank, the default will be used:
        // "<form title> Form submitted on <site title>"
        //
        // Value type: string
        // --------------------------------------------------------------------------------
        'email_subject' => [
            'statamic_attribute' => 'subject',
            'translate_field_references' => true,
            'trim' => true,
        ],

        // To
        // --------------------------------------------------------------------------------
        // Add one or more recipient addresses separated by a ",".
        //
        // FORMAT: Name <name@email.com> or name@email.com.
        //
        // [admin_email] is the address set in WP General Settings.
        //
        // Value type: string
        // --------------------------------------------------------------------------------
        'email_to' => [
            'statamic_attribute' => 'to',
            'translate_field_references' => true,
            'statamic_other_field_value' => [
                'to_type' => 'email',
                'cc_type' => 'email',
                'bcc_type' => 'email',
                'reply_to_type' => 'email',
            ],
            'trim_comma_separated_values' => true,
        ],

        // From
        // --------------------------------------------------------------------------------
        // Enter the name and/or email address of the sender.
        //
        // FORMAT: John Bates <john@example.com> or john@example.com.
        //
        // Value type: string
        // --------------------------------------------------------------------------------
        'from' => [
            'statamic_attribute' => [
                'multiple' => [
                    [
                        'statamic_attribute' => 'from',
                        'value_substitution_regex' => [
                            '/[^>,]*<([^>]*)>/' => '$1', // Extract the email address(es) from the from field
                        ],
                        'translate_field_references' => true,
                        'trim_comma_separated_values' => true,
                    ],
                    [
                        'statamic_attribute' => 'from_name',
                        'value_substitution_regex' => [
                            '/([^<,\s][^<,]*?)(?:\s*<\S+?>\s*)(?=(?:,\s*[^<,\s][^<,]*?\s*<\S+?>|$))/' => '$1', // Extract the from name(s) from the from field
                        ],
                        'translate_field_references' => true,
                    ],
                ],
            ],
        ],

        // Reply To
        // --------------------------------------------------------------------------------
        // If you would like a different reply to address than the "from" address, add a
        // single address here.
        //
        // FORMAT: Name <name@email.com> or name@email.com.
        //
        // Value type: string
        // --------------------------------------------------------------------------------
        'reply_to' => [
            'statamic_attribute' => 'reply_to',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // Trigger this action when...
        // --------------------------------------------------------------------------------
        // There is no analog to this in Statamic.
        //
        // Value type: array of string (enum):
        //   - "create" (Entry is created)
        //   - "draft" (Draft is saved)
        //   - Additional values may exist but are not documented
        // --------------------------------------------------------------------------------
        'event' => [
            'statamic_attribute' => null,
        ],

        // Append IP Address, Browser, and Referring URL to message
        // --------------------------------------------------------------------------------
        // This attribute IS BEING USED in active UAMS forms.
        //
        // Value type: integer string (boolean)
        // --------------------------------------------------------------------------------
        'inc_user_info' => [
            'statamic_attribute' => null,
        ],

        // Send Emails in Plain Text
        // --------------------------------------------------------------------------------
        // This attribute is not used in any active UAMS forms.
        //
        // Value type: integer string (boolean)
        // --------------------------------------------------------------------------------
        'plain_text' => [
            'statamic_attribute' => null,
        ],

        // Attach CSV export of entry to email
        // --------------------------------------------------------------------------------
        // This attribute IS BEING USED in active UAMS forms.
        //
        // Value type: integer string (boolean)
        // --------------------------------------------------------------------------------
        'attach_csv' => [
            'statamic_attribute' => null,
        ],

        // Add Attachment
        // --------------------------------------------------------------------------------
        // Used in concert with `attach_csv` toggled on.
        //
        // This attribute is not used in any active UAMS forms.
        //
        // Value type: integer string
        // --------------------------------------------------------------------------------
        'email_attachment_id' => [
            'statamic_attribute' => null,
        ],

        // Run this action for...
        // --------------------------------------------------------------------------------
        // Choose between the main entry or for embedded forms, repeaters, etc.
        //
        // This attribute is not used in any active UAMS forms.
        //
        // Value type: string
        // --------------------------------------------------------------------------------
        'child_form' => [
            'statamic_attribute' => null,
        ],
    ];

    protected const FORM_CONFIGURATION_ACTION_MAPPING_EMAIL_CONTENT_CONDITIONS = [
        'send_stop' => [
            'statamic_attribute' => null,
        ],
        'any_all' => [
            'statamic_attribute' => null,
        ],
        'hide_field' => [
            'statamic_attribute' => null,
        ],
        'hide_field_cond' => [
            'statamic_attribute' => null,
        ],
        'hide_opt' => [
            'statamic_attribute' => null,
        ],
    ];

    /**
     * Configure a Statamic form with settings from Formidable Forms data.
     *
     * Maps Formidable Forms notifications to Statamic email configurations
     * and form configuration fields, then applies them to the provided form.
     *
     * @param  Form  $form  The Statamic form to configure
     * @param  array<int|string, mixed>  $formidableFormsData  The Formidable Forms form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @return bool True if configuration was successful, false otherwise
     */
    public function configureFormEmailSettings(
        Form $form,
        array $formidableFormsData,
        string $siteHandle,
        AssetImporter $assetImporter
    ): bool {
        return $this->configureFormSettingsInternal(
            $form,
            $formidableFormsData,
            'Formidable Forms',
            $siteHandle,
            $assetImporter
        );
    }

    /**
     * Process confirmation views and map them to Statamic confirmation settings.
     *
     * The first confirmation view becomes the default confirmation, and all others
     * become additional confirmations.
     *
     * @param  array<int|string, mixed>  &$currentFormData  The current form data (passed by reference)
     * @param  list<array<int|string, mixed>>  $confirmationViews  Array of confirmation view data
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    private function processConfirmationViews(
        array &$currentFormData,
        array $confirmationViews,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        // Initialize arrays for default and additional confirmations
        $defaultConfirmation = null;
        $additionalConfirmations = [];

        // Process each confirmation view
        foreach ($confirmationViews as $index => $view) {
            $formIdentifier = $this->formIdentifierForLogging($formData);

            $viewTitle = self::resolveViewTitle($view, 'Default');

            // Set form config context so log messages identify this confirmation
            $this->setFormConfigContextForLogging([
                'form_id' => $formIdentifier,
                'source' => 'confirmation',
                'source_name' => $viewTitle,
                'plugin' => 'Formidable Forms',
            ]);

            // Wrap processing in try/finally so form config context is always cleared
            try {
                // ---
                // Parse content JSON if it exists
                // ---

                // Initialize content array for parsed view content
                $content = [];

                // Decode view content from JSON when present
                if (
                    isset($view['content'])
                    && \is_string($view['content'])
                ) {
                    // Parse view content JSON into array
                    $decodedContent = \json_decode(
                        $view['content'],
                        true
                    );
                    $content = \is_array($decodedContent) ? $decodedContent : [];
                }

                // Merge view data with content for processing
                $processedViewData = [...$view, ...$content];

                // ---
                // The first confirmation view becomes the default confirmation
                // ---

                // First confirmation view is the default confirmation
                if ($index === 0) {
                    // Map default confirmation from this view
                    $defaultConfirmation = $this->processSingleConfirmationView(
                        $processedViewData,
                        $formData,
                        $siteHandle,
                        $assetImporter,
                        false
                    );
                } else {
                    // ---
                    // All other confirmation views become additional confirmations
                    // ---

                    // Map additional confirmation from this view
                    $additionalConfirmation = $this->processSingleConfirmationView(
                        $processedViewData,
                        $formData,
                        $siteHandle,
                        $assetImporter,
                        true
                    );

                    // Append it; an additional confirmation always carries at least its type
                    $additionalConfirmations[] = $additionalConfirmation;
                }
            } finally {
                // Clear form config context so logs do not attribute later processing
                $this->clearFormConfigContextForLogging();
            }
        }

        // Set the default confirmation if it exists
        if ($defaultConfirmation !== null) {
            $currentFormData['confirmation_settings_default'] = $defaultConfirmation;
        }

        // Set the additional confirmations if they exist
        if ($additionalConfirmations !== []) {
            $currentFormData['confirmation_additional'] = $additionalConfirmations;
        }

        // Clean up variables to free up memory
        unset(
            $confirmationViews,
            $formData,
            $siteHandle,
            $assetImporter,
            $defaultConfirmation,
            $additionalConfirmations,
            $view,
            $content,
            $processedViewData,
            $index
        );
    }

    /**
     * Process an email view and map it to Statamic email settings.
     *
     * @param  array<int|string, mixed>  &$currentFormData  The current form data (passed by reference)
     * @param  array<int|string, mixed>  $view  The view data
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    private function processEmailView(
        array &$currentFormData,
        array $view,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        $currentFormData['email'] ??= [];

        // Parse content JSON if it exists
        $content = [];
        if (isset($view['content']) && \is_string($view['content'])) {
            $decodedContent = \json_decode(
                $view['content'],
                true
            );
            $content = \is_array($decodedContent) ? $decodedContent : [];
        }

        // Merge view data with content for processing
        $processedViewData = [...$view, ...$content];

        // Create email configuration
        $emailConfig = [];

        // Map the view-level fields (title, status, post_name)
        $this->processMappingConfigurations(
            'FORM_CONFIGURATION_ACTION_MAPPING_EMAIL',
            $processedViewData,
            $formData,
            $siteHandle,
            $assetImporter,
            function (string $statamicKey, $value) use (&$emailConfig): void {
                $emailConfig[$statamicKey] = $value;
            }
        );

        // Map the email content fields (recipients, subject, message), as notifications do
        $formFields = isset($formData['fields']) && \is_array($formData['fields'])
            ? $formData['fields']
            : [];
        $emailConfig = [
            ...$emailConfig,
            ...$this->processEmailContentMappings(
                $processedViewData,
                $formData,
                $siteHandle,
                $assetImporter,
                $formFields
            ),
        ];

        $emailConfig['id'] ??= Str::random(8);

        // Process conditional logic if present.
        //
        // This whole configuration is transient: it lands on
        // currentFormData['email'], which applyEmailConfigurationsToForm() replaces
        // wholesale via Form::email() before Form::save() persists anything. The
        // authoritative copy is built in mapNotificationsToEmailConfigurations(),
        // which carries its own processConditionalLogic() call. Kept rather than
        // dropped because the conditional logic is no more transient here than the
        // rest of this configuration, and the notification audit compares the two
        // copies. wordpress-importer-archive#38
        $this->processConditionalLogic(
            $processedViewData,
            $formData,
            $emailConfig,
            'conditional_logic'
        );

        // Set default values for required Statamic fields
        $emailConfig = $this->setDefaultEmailConfigurationValues($emailConfig);

        // Add the email configuration to the form data
        if (! \is_array($currentFormData['email'])) {
            $currentFormData['email'] = [];
        }

        $currentFormData['email'][] = $emailConfig;

        // Clean up variables to free up memory
        unset(
            $view,
            $formData,
            $siteHandle,
            $assetImporter,
            $content,
            $processedViewData,
            $emailConfig,
            $formFields
        );
    }

    /**
     * Process field merge tags and convert them to Statamic submission field tags.
     *
     * Formidable Forms doesn't use field merge tags in the same way as Gravity Forms.
     * This method is implemented as a stub to satisfy the abstract method requirement.
     *
     * @param  string  $value  The value to process
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @return string The processed value with field merge tags converted
     */
    protected function processFieldMergeTags(
        string $value,
        array $formFields
    ): string {
        // Formidable Forms doesn't use field merge tags like Gravity Forms
        // Return the value unchanged
        return $value;
    }

    /**
     * Get the field name/label from a field array (Formidable Forms specific).
     *
     * @param  array<int|string, mixed>  $field  The field array
     * @return string The field name/label
     */
    protected function getFieldNameFromField(
        array $field
    ): string {
        // Formidable Forms uses 'name' property for field names
        return isset($field['name']) && \is_scalar($field['name'])
            ? (string) $field['name']
            : '';
    }

    /**
     * Get the subfield handle for a given subfield ID from the form fields array.
     *
     * Formidable Forms doesn't use subfields in the same way as Gravity Forms.
     * This method is implemented as a stub to satisfy the abstract method requirement.
     *
     * @param  string  $subfieldId  The subfield ID
     * @param  array<int|string, mixed>  $formFields  Array of form fields
     * @return ?string The subfield handle or null if not found
     */
    protected function getSubfieldHandleById(
        string $subfieldId,
        array $formFields
    ): ?string {
        // Formidable Forms doesn't use subfields like Gravity Forms
        return null;
    }

    /**
     * Get the subfield handle from the mapping based on field type and subfield label.
     *
     * Formidable Forms doesn't use subfields in the same way as Gravity Forms.
     * This method is implemented as a stub to satisfy the abstract method requirement.
     *
     * @param  string  $fieldType  The field type
     * @param  string  $subfieldLabel  The subfield label
     * @param  array<int|string, mixed>  $subfieldMapping  The subfield mapping configuration
     * @return ?string The subfield handle or null if not found
     */
    protected function getSubfieldHandleFromMapping(
        string $fieldType,
        string $subfieldLabel,
        array $subfieldMapping
    ): ?string {
        // Formidable Forms doesn't use subfields like Gravity Forms
        return null;
    }

    /**
     * Convert routing rules to Statamic format.
     *
     * Formidable Forms doesn't use routing rules in the same way as Gravity Forms.
     * This method is implemented as a stub to satisfy the abstract method requirement.
     *
     * @param  array<int|string, mixed>  $routingRules  Array of routing rules
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @return array<int|string, mixed> Array of Statamic routing rules
     */
    protected function convertRoutingRulesToStatamicFormat(
        array $routingRules,
        array $formFields
    ): array {
        // Formidable Forms doesn't use routing rules like Gravity Forms
        return [];
    }

    /**
     * Convert routing operator to Statamic format.
     *
     * Formidable Forms doesn't use routing operators in the same way as Gravity
     * Forms. This method is implemented as a stub to satisfy the abstract method
     * requirement.
     *
     * @param  string  $operator  The routing operator
     * @return string The Statamic operator
     */
    protected function convertRoutingOperatorToStatamicFormat(
        string $operator
    ): string {
        // Formidable Forms doesn't use routing operators like Gravity Forms
        return $operator;
    }

    /**
     * Parse and prepare form data for mapping (plugin-specific).
     *
     * @param  array<int|string, mixed>  $formData  The raw form data from the plugin
     * @return array<int|string, mixed> The processed form data ready for mapping
     */
    protected function parseFormData(
        array $formData
    ): array {
        // Parse options JSON if it exists and merge with form data for extraction
        $processedFormData = $formData;
        if (isset($formData['options']) && \is_string($formData['options'])) {
            $options = \json_decode(
                $formData['options'],
                true
            );
            if (\is_array($options)) {
                $processedFormData = [...$formData, ...$options];
            }
        }

        return $processedFormData;
    }

    /**
     * Map plugin-specific fields that aren't covered by standard mappings.
     *
     * @param  array<int|string, mixed>  &$currentFormData  The current form data (passed by reference)
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    protected function mapPluginSpecificFields(
        array &$currentFormData,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        // Map the form options (Formidable Forms keeps them in a JSON `options` value, which
        // parseFormData() spreads into the form data)
        $this->processMappingConfigurations(
            'FORM_CONFIGURATION_MAPPING_OPTIONS',
            $this->parseFormData($formData),
            $formData,
            $siteHandle,
            $assetImporter,
            function (string $statamicKey, $value) use (&$currentFormData): void {
                // Storing submissions is Statamic's default, so only a form that turns it off
                // needs to say so
                if ($statamicKey === 'store' && $value === true) {
                    return;
                }

                $this->assignNestedFieldToFormData(
                    $currentFormData,
                    $statamicKey,
                    $value
                );
            }
        );
    }

    /**
     * Map confirmation fields from Formidable Forms data to Statamic form settings.
     *
     * Maps confirmation fields from all views in the views array to Statamic
     * confirmation configuration fields. Handles both default and additional
     * confirmations with proper nested group structure.
     *
     * @param  Form  $form  The Statamic form to configure
     * @param  array<int|string, mixed>  $formData  The Formidable Forms form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    protected function mapConfirmationFields(
        Form $form,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        $viewMapping = self::FORM_CONFIGURATION_VIEW_MAPPING;

        // Check if views exist and have at least one item
        if (! isset($formData['views']) || ! \is_array($formData['views']) || $formData['views'] === []) {
            return;
        }

        // Get the current form data. A Statamic form's data is always a collection; the check
        // narrows the untyped accessor for PHPStan.
        $currentData = $form->data();
        // @pest-mutate-ignore: InstanceOfToTrue
        $currentFormData = $currentData instanceof Collection ? $currentData->all() : [];

        // Separate views by action type
        $confirmationViews = [];
        $emailViews = [];

        foreach ($formData['views'] as $view) {
            if (! \is_array($view)) {
                continue;
            }

            // A view's excerpt names its action type in FORM_CONFIGURATION_VIEW_MAPPING; a view
            // with any other excerpt is skipped
            $excerpt = $view['excerpt'] ?? null;
            $actionType = \is_string($excerpt) ? ($viewMapping[$excerpt] ?? null) : null;

            // Group views by action type
            if ($actionType === 'confirmation') {
                $confirmationViews[] = $view;
            } elseif ($actionType === 'email') {
                $emailViews[] = $view;
            }
        }

        // Process confirmation views
        if ($confirmationViews !== []) {
            $this->processConfirmationViews(
                $currentFormData,
                $confirmationViews,
                $formData,
                $siteHandle,
                $assetImporter
            );
        }

        // Process email views
        foreach ($emailViews as $view) {
            $this->processEmailView(
                $currentFormData,
                $view,
                $formData,
                $siteHandle,
                $assetImporter
            );
        }

        // Apply the mapped view data to the form
        $form->merge($currentFormData);

        // Clean up variables to free up memory
        unset(
            $formData,
            $siteHandle,
            $assetImporter,
            $currentFormData,
            $view,
            $excerpt,
            $actionType
        );
    }

    /**
     * Process datetime fields for Formidable Forms.
     *
     * Formidable Forms doesn't have the same datetime field structure as Gravity
     * Forms. This method returns null to indicate no special datetime processing is
     * needed.
     *
     * @param  string  $statamicKey  The Statamic field handle
     * @param  mixed  $value  The original value
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @return ?string Always returns null for Formidable Forms
     */
    protected function processDateTimeField(
        string $statamicKey,
        $value,
        array $formData
    ): ?string {
        // Formidable Forms doesn't have the same datetime field structure as Gravity Forms
        // Return null to indicate no special datetime processing is needed
        return null;
    }

    /**
     * Process datetime combination fields for Formidable Forms.
     *
     * Formidable Forms doesn't have datetime combination fields like Gravity Forms.
     * This method returns null to indicate no special datetime combination processing
     * is needed.
     *
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration with datetime_combination
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @return ?string Always returns null for Formidable Forms
     */
    protected function processDatetimeCombination(
        array $mappingConfig,
        array $formData
    ): ?string {
        // Formidable Forms doesn't have datetime combination fields
        // Return null to indicate no special datetime combination processing is needed
        return null;
    }

    /**
     * Process Formidable Forms specific field types that require special handling.
     *
     * @param  string  $statamicKey  The Statamic field handle
     * @param  mixed  $value  The original value
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @return ?mixed The processed value or null if no special processing was needed
     */
    protected function processPluginSpecificField(
        string $statamicKey,
        $value,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): mixed {
        // Formidable Forms doesn't have specific field types that require special handling
        // Return null to indicate no special processing was needed
        return null;
    }

    /**
     * Process complex format mappings that require regex patterns.
     *
     * Formidable Forms doesn't have complex format mappings like Gravity Forms.
     * This method returns the value unchanged to satisfy the abstract method
     * requirement.
     *
     * @param  string  $value  The value to process
     * @return string The value unchanged
     */
    protected function processComplexFormatMappings(
        string $value
    ): string {
        // `[default-message]` is an exact-match entry in getVariableMappings(), so a
        // parameterized form such as
        // `[default-message exclude_fields="541,542,543"]` survived literally in the
        // Bard message body. Map any parameterized form to the same base tag; the
        // Statamic `all_fields` tag has no per-field exclusion, so the parameters are
        // dropped rather than translated. wordpress-importer-archive#39
        $mapped = \preg_replace(
            '/\[default-message\s[^\]]*\]/',
            '{{ all_fields }}',
            $value
        );

        return $mapped ?? $value;
    }

    /**
     * Get Formidable Forms specific variable mappings for merge tag processing.
     *
     * @return array<string, string> Array mapping Formidable Forms variables to Statamic variables
     */
    protected function getVariableMappings(): array
    {
        return [
            '[admin_email]' => '{{ admin_email }}',
            '[default-email]' => '{{ admin_email }}',
            '[default-from-email]' => '{{ admin_email }}',
            '[default-message]' => '{{ all_fields }}',
            '[form_name]' => '{{ form:title }}',
            '[sitename]' => '{{ site:name }}',
        ];
    }

    /**
     * Check if the form has views (Formidable Forms specific).
     *
     * @param  array<int|string, mixed>  $formData  The Formidable Forms form data
     * @return bool True if the form has views, false otherwise
     */
    protected function hasNotifications(
        array $formData
    ): bool {
        return isset($formData['views']) && \is_array($formData['views']) && $formData['views'] !== [];
    }

    /**
     * Map Formidable Forms views to Statamic email configurations.
     *
     * @param  array<int|string, mixed>  $formData  The Formidable Forms form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @return array<int|string, mixed> Array of Statamic email configurations
     */
    protected function mapNotificationsToEmailConfigurations(
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): array {
        $views = $formData['views'] ?? [];
        if (! \is_array($views)) {
            return [];
        }

        $formFields = isset($formData['fields']) && \is_array($formData['fields'])
            ? $formData['fields']
            : [];
        $emailConfigurations = [];

        $viewMapping = self::FORM_CONFIGURATION_VIEW_MAPPING;

        // Filter views to only include email views
        $emailViews = [];
        foreach ($views as $view) {
            if (! \is_array($view)) {
                continue;
            }

            $excerpt = $view['excerpt'] ?? null;
            if (\is_string($excerpt) && ($viewMapping[$excerpt] ?? null) === 'email') {
                $emailViews[] = $view;
            }
        }

        // Process each email view and map it to Statamic format
        foreach ($emailViews as $view) {
            $formIdentifier = $this->formIdentifierForLogging($formData);

            $viewTitle = self::resolveViewTitle($view, 'Unnamed');

            // Set form config context so log messages identify this email view
            $this->setFormConfigContextForLogging([
                'form_id' => $formIdentifier,
                'source' => 'view',
                'source_name' => $viewTitle,
                'plugin' => 'Formidable Forms',
            ]);

            // Wrap processing in try/finally so form config context is always cleared
            try {
                // ---
                // Parse content JSON if it exists
                // ---

                // Initialize content array for parsed view content
                $content = [];

                // Decode view content from JSON when present
                if (
                    isset($view['content'])
                    && \is_string($view['content'])
                ) {
                    // Parse view content JSON into array
                    $decodedContent = \json_decode(
                        $view['content'],
                        true
                    );
                    $content = \is_array($decodedContent) ? $decodedContent : [];
                }

                // Merge view data with content for processing
                $processedViewData = [...$view, ...$content];

                // Build the base email configuration using common logic
                $emailConfig = $this->buildEmailConfiguration(
                    $processedViewData,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Process content mappings (Formidable Forms specific)
                $contentMappings = $this->processEmailContentMappings(
                    $processedViewData,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Merge content mappings with email configuration
                $emailConfig = [...$emailConfig, ...$contentMappings];

                // ---
                // Attach the action's send conditions.
                //
                // This is the authoritative email path: what it returns is handed to
                // applyEmailConfigurationsToForm(), which calls Form::email() and is
                // what Form::save() persists. processEmailView() also computes the
                // conditional logic, but onto the transient currentFormData['email']
                // that this path then overwrites, so that copy never reached disk and
                // every routed notification imported as send-to-everyone. wordpress-importer-archive#38
                // ---
                $this->processConditionalLogic(
                    $processedViewData,
                    $formData,
                    $emailConfig,
                    'conditional_logic'
                );

                // Add the email configuration to the array
                $emailConfigurations[] = $emailConfig;
            } finally {
                // Clear form config context so logs do not attribute later processing
                $this->clearFormConfigContextForLogging();
            }
        }

        // Clean up variables to free up memory
        unset(
            $views,
            $formFields,
            $formData,
            $siteHandle,
            $assetImporter,
            $emailViews,
            $view,
            $content,
            $processedViewData,
            $emailConfig,
            $contentMappings
        );

        return $emailConfigurations;
    }

    /**
     * Drop Formidable conditional shortcodes, body included.
     *
     * Only removes a `[if X ...]...[/if X]` pair whose reference resolves to a field
     * on this form, so HTML conditional comments and ordinary bracketed prose are
     * left alone. Each dropped block is warned about, since it may carry an optional
     * recipient. wordpress-importer-archive#39
     *
     * @param  string  $text  The text that may contain conditional shortcodes
     * @param  array<int|string, mixed>  $allFields  All fields on the form
     * @return string The text with any resolvable conditional blocks removed
     */
    protected function dropConditionalShortcodes(
        string $text,
        array $allFields
    ): string {
        $result = \preg_replace_callback(
            '/\\[if\\s+([A-Za-z0-9_\\-]+)[^\\]]*\\](.*?)\\[\\/if\\s+\\1\\s*\\]/s',
            function (array $matches) use ($allFields): string {
                $reference = $matches[1];

                // Leave anything that is not a field reference untouched — an Outlook
                // conditional comment reads as `[if gte mso 9]` and must survive.
                if (! $this->isKnownFieldReference($reference, $allFields)) {
                    return $matches[0];
                }

                ImportWarningManager::addWarning(
                    'forms',
                    'dropped_conditional_shortcodes',
                    [
                        'reference' => $reference,
                        'dropped' => $matches[0],
                        'reason' => 'Formidable conditional shortcodes have no Statamic '
                            .'equivalent; the guarded content is dropped so a blank field '
                            .'cannot leave a dangling separator.',
                    ]
                );

                return '';
            },
            $text
        );

        return $result ?? $text;
    }

    /**
     * Whether a bracket reference names a field on this form.
     *
     * Matches the same three lookups translateFieldReferences() uses: numeric id,
     * label, and field key.
     *
     * @param  array<int|string, mixed>  $allFields  All fields on the form
     */
    private function isKnownFieldReference(
        string $reference,
        array $allFields
    ): bool {
        foreach ($allFields as $field) {
            if (! \is_array($field)) {
                continue;
            }

            foreach (['id', 'name', 'field_key'] as $key) {
                $candidate = $field[$key] ?? null;
                if (\is_scalar($candidate) && (string) $candidate === $reference) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Translate field references in string values to Statamic format.
     *
     * Formidable Forms uses [field_name] syntax (e.g., [field_name], [admin_email])
     * and converts them to Statamic submission field tags.
     *
     * @param  string  $text  The text containing field references
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return string The text with field references translated to Statamic format
     */
    protected function translateFieldReferences(
        string $text,
        array $allFields = []
    ): string {
        // Drop Formidable conditional shortcodes before resolving references.
        //
        // `[provider_email][if editor_email not_equal=""],[editor_email][/if editor_email]`
        // has no Statamic equivalent in a `to` / `cc` / `reply_to` value, and the
        // reference pattern below never matched the wrappers, so they used to ship
        // literally.
        //
        // The WHOLE block goes, body included. Stripping only the wrappers would
        // leave behind whatever separator lived inside them — the corpus keeps the
        // comma there — making the optional recipient unconditional. A blank optional
        // field would then render `provider@uams.edu,`, and Statamic's
        // `Email::addresses()` explodes on the comma and hands Symfony an empty
        // address, which throws RfcComplianceException and the notification never
        // sends. Formidable emitted neither the recipient nor the separator, so
        // dropping both matches the blank-field behavior the guard existed for. The
        // populated case loses an optional recipient, which is recorded as a warning
        // rather than left silent.
        //
        // Matched as a PAIR, and only when the reference names a real field on this
        // form. An unanchored `[if ...]` pattern also eats Outlook conditional
        // comments (`<!--[if gte mso 9]>`) and ordinary bracketed prose, silently
        // deleting content and breaking the comment. wordpress-importer-archive#39
        $text = $this->dropConditionalShortcodes(
            $text,
            $allFields
        );

        // Pattern to match Formidable Forms field references: [field_name] or [329]
        $pattern = '/\[([^\]]+)\]/';

        $translatedText = preg_replace_callback(
            $pattern,
            function ($matches) use ($allFields): string {
                $fullMatch = $matches[0];
                $reference = $matches[1];

                // Check if it's a numeric field ID
                if (\is_numeric($reference)) {
                    // Find the field by ID
                    foreach ($allFields as $field) {
                        if (! \is_array($field)) {
                            continue;
                        }

                        if (isset($field['id']) && \is_scalar($field['id']) && (string) $field['id'] === $reference) {
                            // Get the field name/label
                            $fieldName = isset($field['name']) && \is_scalar($field['name'])
                                ? (string) $field['name']
                                : null;
                            if ($fieldName !== null && $fieldName !== '') {
                                // Convert to Statamic handle format
                                $handle = $this->convertLabelToHandle(
                                    $fieldName,
                                    $reference
                                );

                                return '{{ submission:'.$handle.' }}';
                            }
                        }
                    }
                } else {
                    // Check if it's a field name reference (like [field_name])
                    if ($reference === 'field_name') {
                        // Return a generic placeholder
                        return '{{ submission:current_field_name }}';
                    }

                    // Try to find the field by name/label
                    foreach ($allFields as $field) {
                        if (! \is_array($field)) {
                            continue;
                        }

                        // Only a text name can equal a reference that is not a number
                        if (($field['name'] ?? null) === $reference) {
                            // Convert to Statamic handle format
                            $fieldId = isset($field['id']) && \is_scalar($field['id'])
                                ? (string) $field['id']
                                : 'unknown';
                            $handle = $this->convertLabelToHandle(
                                $reference,
                                $fieldId
                            );

                            return '{{ submission:'.$handle.' }}';
                        }
                    }

                    // Fall back to the field key (`[provider_email_address]`).
                    //
                    // Deliberately last: field keys and labels share the same bracket
                    // syntax, so trying the key only after the label lookup has failed
                    // leaves every reference that already resolved untouched. wordpress-importer-archive#39
                    foreach ($allFields as $field) {
                        if (! \is_array($field)) {
                            continue;
                        }

                        // As with names, only a text key can equal the reference
                        if (($field['field_key'] ?? null) !== $reference) {
                            continue;
                        }

                        $fieldName = isset($field['name']) && \is_scalar($field['name'])
                            ? (string) $field['name']
                            : null;
                        $fieldId = isset($field['id']) && \is_scalar($field['id'])
                            ? (string) $field['id']
                            : 'unknown';

                        // Handles are built from the label, falling back to the key
                        // when the field carries no label.
                        $handle = $this->convertLabelToHandle(
                            $fieldName !== null && $fieldName !== '' ? $fieldName : $reference,
                            $fieldId
                        );

                        return '{{ submission:'.$handle.' }}';
                    }
                }

                // If no match found, return the original reference
                return $fullMatch;
            },
            $text
        );

        return $translatedText ?? $text;
    }

    /**
     * Process a single confirmation view and return the mapped data.
     *
     * @param  array<int|string, mixed>  $processedViewData  The processed view data (including parsed content)
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @param  bool  $isAdditional  Whether this is an additional confirmation (affects field structure)
     * @return array<int|string, mixed>|null The mapped confirmation data or null if no data was mapped
     */
    protected function processSingleConfirmationView(
        array $processedViewData,
        array $formData,
        string $siteHandle,
        $assetImporter = null,
        bool $isAdditional = false
    ): ?array {
        $mappedData = [];

        // Formidable Forms: Process both view-level and content mappings

        // For additional confirmations, process view-level mappings (like title, status, post_name)
        // For default confirmations, only process content mappings
        if ($isAdditional) {
            $this->processMappingConfigurations(
                'FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION',
                $processedViewData,
                $formData,
                $siteHandle,
                $assetImporter,
                function (string $statamicKey, $value) use (&$mappedData): void {
                    // For additional confirmations, view-level mappings go to the top level
                    $mappedData[$statamicKey] = $value;
                }
            );
        }

        // Then, process content mappings (like success_action, success_msg)
        $this->processMappingConfigurations(
            'FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION_CONTENT',
            $processedViewData,
            $formData,
            $siteHandle,
            $assetImporter,
            function (string $statamicKey, $value) use (&$mappedData, $isAdditional): void {
                // The content table sets no other field, and names neither `name` nor `enabled`,
                // which only the view-level table above sets
                if ($isAdditional) {
                    // Content fields of an additional confirmation go into confirmation_settings
                    if (
                        ! isset($mappedData['confirmation_settings'])
                        || ! \is_array($mappedData['confirmation_settings'])
                    ) {
                        $mappedData['confirmation_settings'] = [];
                    }

                    $mappedData['confirmation_settings'][$statamicKey] = $value;
                } else {
                    // For default confirmation, set fields directly
                    $mappedData[$statamicKey] = $value;
                }
            }
        );

        // Add required fields for additional confirmations
        if ($isAdditional) {
            $mappedData['type'] = 'confirmation';
            // Note: id should now come from the view-level mapping (post_name -> id)

            // Process conditional logic if present
            $this->processConditionalLogic(
                $processedViewData,
                $formData,
                $mappedData,
                'conditional_logic'
            );
        }

        // Return null if no data was mapped
        return $mappedData === [] ? null : $mappedData;
    }

    /**
     * Process conditional logic for actions (confirmations and email notifications).
     *
     * This method extracts conditional logic from processed view data and maps it
     * to Statamic conditional logic attributes using the FieldtypeMapping class.
     *
     * @param  array<int|string, mixed>  $processedViewData  The processed view data containing conditions
     * @param  array<int|string, mixed>  $formData  The complete form data for field context
     * @param  array<int|string, mixed>  &$targetArray  The target array to store conditional logic in
     * @param  string  $key  The key to store conditional logic under
     */
    private function processConditionalLogic(
        array $processedViewData,
        array $formData,
        array &$targetArray,
        string $key
    ): void {
        // Process conditional logic if present
        if (
            isset($processedViewData['conditions']) &&
            \is_array($processedViewData['conditions'])
        ) {
            $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                ? $formData['fields']
                : [];
            $conditionalLogic = FieldtypeMapping::mapActionConditionalLogic(
                $processedViewData['conditions'],
                $formFields
            );

            // Store conditional logic under the specified key if not empty
            if ($conditionalLogic !== []) {
                $targetArray[$key] = $conditionalLogic;
            }
        }
    }

    /**
     * The human title of a `frm_form_actions` view, for the logging context's `source_name`.
     *
     * **`title` is where the title actually is.** The export writes the action post's title as a
     * `title` child, and the migration API loader copies it to the same key, so both paths carry
     * it. The resolution used to read `post_title` and then `name`, which neither path produces,
     * and fell to `excerpt` on all 71 views measured in `communications-uams-edu_web`. Every
     * notification was then labelled by its type (`email`) instead of its name (`Send Email`)
     * (wordpress-importer-archive#1121).
     *
     * **Never the slug.** `name` and `post_name` hold `1_email_1`, which is not a title, so neither
     * is read. An empty `title` falls through to the action type, which is still better than
     * nothing in a log line.
     *
     * @param  array<int|string, mixed>  $view  One view, from either loader
     * @param  string  $default  The label when the view carries none of the keys read
     * @return string The title to log the view under
     */
    public static function resolveViewTitle(
        array $view,
        string $default
    ): string {
        // Read the title keys in order of preference, skipping empty values
        foreach (['post_title', 'title', 'excerpt'] as $key) {
            if (isset($view[$key]) && \is_string($view[$key]) && $view[$key] !== '') {
                return $view[$key];
            }
        }

        return $default;
    }

    /**
     * Identify the form being configured, for the logging context.
     *
     * @param  array<int|string, mixed>  $formData  The Formidable Forms form data
     * @return int|string The form ID, else its key, else "?"
     */
    private function formIdentifierForLogging(
        array $formData
    ): int|string {
        foreach (['id', 'form_key'] as $key) {
            if (isset($formData[$key]) && (\is_int($formData[$key]) || \is_string($formData[$key]))) {
                return $formData[$key];
            }
        }

        return '?';
    }
}
