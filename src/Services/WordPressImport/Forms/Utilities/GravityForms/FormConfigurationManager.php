<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

use DateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormConfigurationManagerTrait;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Manages form configuration mapping from Gravity Forms to Statamic.
 *
 * This class is responsible for mapping Gravity Forms form configuration
 * data (notifications, etc.) to Statamic form settings.
 *
 * Supports complex conditional mapping with 'based_on_other_attribute_value'
 * structure for scenarios where the target Statamic field depends on the value of
 * other fields. This enables dynamic field mapping based on conditional logic,
 * similar to Formidable Forms but adapted for Gravity Forms' data structure.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * form configuration mapping operations.
 *
 * ---------------------------------------------------------------------------
 * Notification import audit (2026-06-02, issue wordpress-importer-archive#25)
 * ---------------------------------------------------------------------------
 * Audited against the 150-file Gravity Forms export corpus
 * (`GravityForms/` in `UAMS-Web/wordpress-importer-exports`, 1,503 forms,
 * 2,221 notifications).
 *
 * Coverage:
 *   - 17 of 18 canonical notification fields are mapped (`id`, `name`,
 *     `isActive`, `to`, `toEmail`, `toType`, `toField`, `cc`, `bcc`, `from`,
 *     `fromName`, `replyTo`, `subject`, `message`, `enableAttachments`,
 *     `routing`, `conditionalLogic`).
 *   - 3 fields are intentionally dropped: `service` (always `"wordpress"`),
 *     `event` (no Statamic analog -- see Skipped below, which is how the one
 *     event that carried meaning is handled), and `disableAutoformat` (no
 *     Statamic analog).
 *   - 6,961 of 7,045 merge-tag occurrences (98.8%) resolve cleanly via
 *     `getVariableMappings()`, `processFieldMergeTags()`, and the today/
 *     quiz/survey format mappers.
 *   - Legacy `*Field` notification aliases (`fromField`, `fromNameField`,
 *     `replyToField`) map to `from` / `from_name` / `reply_to` as
 *     `{{ submission:<handle> }}` merge tags via `field_id_to_submission_tag`,
 *     preferring the field reference over the literal (issue wordpress-importer-archive#34). The 44
 *     notifications that populate From Name via `fromNameField` now import the
 *     applicant's field value instead of the `{{ site:name }}` default.
 *
 * Skipped (recognized, deliberately not imported):
 *   - Save & Continue notifications (`event=form_save_email_requested`,
 *     `toType=hidden`, `{save_link}` token): 96 notifications in the corpus.
 *     Statamic has no Save & Continue analog, so an imported action would send
 *     to nobody (the recipient is resolved at GF runtime from the address the
 *     visitor types into the saved-link request — there is no field to copy)
 *     and would carry a literal, unresolvable `{save_link}` in its body. A
 *     recipient-less email action is worse than no email action, so the whole
 *     notification is skipped and warned about instead (issue wordpress-importer-archive#33).
 *
 * Dropped with a signal (recognized, no analog, reported when it matters):
 *   - Notification Scheduler add-on fields (`schedule*`, 12 notifications on
 *     one form): explicit `null` entries in the mapping array. All 12 corpus
 *     occurrences are `scheduleType="immediate"`, which is what the import
 *     already produces; any other `scheduleType` is warned about rather than
 *     silently collapsing to an immediate send (issue wordpress-importer-archive#35).
 *
 * Neutralized (recognized, deliberately resolved to nothing):
 *   - Current-user merge tags `{user:display_name}` / `{user:user_email}`:
 *     41 occurrences across 11 export files (`fromName` 17, `from` 13,
 *     `replyTo` 5, `bcc` 3, `subject` 2, `message` 1). They resolve to the
 *     logged-in WordPress user, and the target app has no front-end
 *     authentication, so `processCurrentUserMergeTags()` strips them and warns.
 *     A value that held nothing else collapses to empty and falls back to the
 *     site-default From. Previously they fell through unresolved and shipped as
 *     a literal token (issue wordpress-importer-archive#444).
 *   - Entry-author merge tags `{created_by:…}`: 2 occurrences in the corpus,
 *     both whole-value (`from` = `{created_by:user_email}`, `fromName` =
 *     `{created_by:display_name}`). They resolve to the WordPress user who
 *     created the entry — the same absent-user problem as `{user:…}`, at a
 *     different scope — and match neither `getVariableMappings()` nor the
 *     field-merge-tag path, which lists `created_by` as a system tag it must
 *     not translate. The same `processCurrentUserMergeTags()` pattern strips
 *     the whole `{created_by:<property>}` family and warns (issue wordpress-importer-archive#497).
 *
 * Benign/no-op fields present in the corpus but not mapped (no real data
 * loss):
 *   - `type` (always `"admin"`, 167 occurrences) -- pre-1.7 admin/user
 *     notification distinction with no Statamic analog.
 *   - `isDefault` (63 occurrences) -- marks the default notification; no
 *     behavioral impact.
 *   - `notification_conditional_logic` / `notification_conditional_logic_object`
 *     (1,055 occurrences) -- legacy pre-2.5 conditional logic storage,
 *     always disabled (`"0"`) or empty in the corpus.
 *
 * Full audit artifacts: see issue UAMS-Web/wordpress-importer-archive#25.
 * ---------------------------------------------------------------------------
 */
class FormConfigurationManager
{
    /**
     * Use shared functionality for form configuration management.
     */
    use FormConfigurationManagerTrait;

    /**
     * Gravity Forms notification event fired for a Save & Continue link email.
     *
     * These notifications are skipped on import — Statamic has no Save & Continue
     * analog, so the imported action would have no recipient and an unresolvable
     * `{save_link}` token. wordpress-importer-archive#33
     */
    protected const string NOTIFICATION_EVENT_SAVE_AND_CONTINUE = 'form_save_email_requested';

    /**
     * Notification Scheduler `scheduleType` value meaning "send immediately".
     *
     * This is the add-on's default and the only behavior the import can reproduce,
     * so it is the one value that needs no warning. wordpress-importer-archive#35
     */
    protected const string NOTIFICATION_SCHEDULE_TYPE_IMMEDIATE = 'immediate';

    /**
     * Gravity Forms system merge tags, which are never read as a field merge tag even when
     * they carry a number, such as `{entry_id:5}`.
     *
     * @var list<string>
     */
    protected const array SYSTEM_MERGE_TAGS = [
        'activation_url',
        'admin_email',
        'all_fields',
        'all_quiz_results',
        'apc_media',
        'created_by',
        'current_page_number',
        'custom_field',
        'date_created',
        'date_updated',
        'embed_post',
        'embed_url',
        'entry_id',
        'entry_url',
        'entry',
        'form_id',
        'form_title',
        'ip',
        'payment_action',
        'payment_date',
        'post_edit_url',
        'post_id',
        'pricing_fields',
        'quiz_grade',
        'quiz_passfail',
        'quiz_percent',
        'quiz_score',
        'quiz',
        'referer',
        'save_email_input',
        'score',
        'source_page_number',
        'square_receipt_url',
        'survey_total_score',
        'today',
        'user_agent',
        'user',
    ];

    /**
     * Mapping from Gravity Forms form configuration fields to Statamic form fields.
     *
     * Key: Gravity Forms form configuration field name (supports dot notation
     * for nested objects)
     * Value: Statamic form field name (null to exclude from mapping)
     *
     * Note: Email configuration fields (notifications) are mapped separately
     * using the FORM_CONFIGURATION_ACTION_MAPPING_EMAIL array.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected const FORM_CONFIGURATION_MAPPING = [
        'description' => [
            'statamic_attribute' => 'description',
            'strip_html' => true,
        ],
        'title' => [
            'statamic_attribute' => 'heading',
        ],
        'button.text' => [
            'statamic_attribute' => 'submit',
        ],
        'limitEntries' => [
            'statamic_attribute' => 'limit_entries',
            'value_translation' => [
                'true' => true,
                'false' => null,
            ],
        ],
        'limitEntriesCount' => [
            'statamic_attribute' => 'limit_entries_count',
        ],
        'limitEntriesPeriod' => [
            'statamic_attribute' => [
                'based_on_other_attribute_value' => [
                    'limitEntries' => [
                        'true' => 'limit_entries_period',
                        'false' => null,
                    ],
                ],
            ],
            'value_translation' => [
                '#empty' => 'total',
                'day' => 'day',
                'week' => 'week',
                'month' => 'month',
                'year' => 'year',
            ],
        ],
        'limitEntriesMessage' => [
            'statamic_attribute' => 'limit_entries_message',
        ],
        'scheduleForm' => [
            'statamic_attribute' => 'schedule_form_query',
            'value_translation' => [
                'true' => true,
                'false' => null,
            ],
        ],
        'scheduleStart' => [
            'statamic_attribute' => 'schedule_form.start_datetime',
            'datetime_combination' => [
                'date_field' => 'scheduleStart',
                'hour_field' => 'scheduleStartHour',
                'minute_field' => 'scheduleStartMinute',
                'ampm_field' => 'scheduleStartAmpm',
            ],
        ],
        'scheduleStartHour' => [
            'statamic_attribute' => null, // Handled by scheduleStart datetime_combination
        ],
        'scheduleStartMinute' => [
            'statamic_attribute' => null, // Handled by scheduleStart datetime_combination
        ],
        'scheduleStartAmpm' => [
            'statamic_attribute' => null, // Handled by scheduleStart datetime_combination
        ],
        'scheduleEnd' => [
            'statamic_attribute' => 'schedule_form.end_datetime',
            'datetime_combination' => [
                'date_field' => 'scheduleEnd',
                'hour_field' => 'scheduleEndHour',
                'minute_field' => 'scheduleEndMinute',
                'ampm_field' => 'scheduleEndAmpm',
            ],
        ],
        'scheduleEndHour' => [
            'statamic_attribute' => null, // Handled by scheduleEnd datetime_combination
        ],
        'scheduleEndMinute' => [
            'statamic_attribute' => null, // Handled by scheduleEnd datetime_combination
        ],
        'scheduleEndAmpm' => [
            'statamic_attribute' => null, // Handled by scheduleEnd datetime_combination
        ],
        'schedulePendingMessage' => [
            'statamic_attribute' => 'schedule_pending_message',
        ],
        'scheduleMessage' => [
            'statamic_attribute' => 'schedule_expired_message',
        ],
        'requireLogin' => [
            'statamic_attribute' => 'require_login_query',
            'value_translation' => [
                'true' => true,
                'false' => null,
            ],
        ],
        'requireLoginMessage' => [
            'statamic_attribute' => 'require_login_message',
        ],

        // ---
        // Quiz grading, stored by the Gravity Forms Quiz add-on under a form-level
        // `gravityformsquiz` settings object. The Statamic targets are shipped
        // (`App\Services\Forms\QuizScorer` reads them), so the mapping is a plain
        // passthrough — no `#empty` translation, which keeps a form without any
        // `gravityformsquiz` settings from writing the keys at all. wordpress-importer-archive#420
        // ---
        'gravityformsquiz.grading' => [
            'statamic_attribute' => 'quiz_grading',
        ],
        'gravityformsquiz.passPercent' => [
            // Gravity Forms stores this as a numeric string; the Statamic field is an
            // integer.
            'statamic_attribute' => 'quiz_pass_percent',
            'as_integer' => true,
        ],
        'gravityformsquiz.grades' => [
            // Rows are already `{ text, value }`, matching the Statamic grid. Gravity
            // Forms keeps them even when grading is `passfail`; the Statamic side gates
            // the field on `letter` and strips it otherwise.
            'statamic_attribute' => 'quiz_grades',
        ],
    ];

    /**
     * Mapping from Gravity Forms confirmation fields to Statamic confirmation fields.
     *
     * Key: Gravity Forms confirmation field name
     * Value: Statamic confirmation field name (null to exclude from mapping)
     *
     * Note: This mapping is used for both default and additional confirmations.
     * The actual target path is determined by the confirmation type
     * (default vs additional).
     *
     * Supports conditional mapping with 'based_on_other_attribute_value' structure
     * for complex scenarios where the target field depends on other field values.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected const FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION = [
        'id' => [
            'statamic_attribute' => null, // Handled separately at top level for additional confirmations
        ],
        'name' => [
            'statamic_attribute' => null, // Handled separately at top level for additional confirmations
        ],
        'isDefault' => [
            'statamic_attribute' => null, // Handled separately at top level for additional confirmations
        ],
        'isActive' => [
            'statamic_attribute' => null, // Handled separately at top level for additional confirmations
        ],
        'type' => [
            'statamic_attribute' => 'confirmation_type',
            'value_translation' => [
                'message' => 'message',
                'redirect' => 'redirect',
                'page' => 'entry',
            ],
        ],
        'message' => [
            'statamic_attribute' => 'confirmation_message',
            'as_bard' => true,
            'translate_field_references' => true,
        ],
        'url' => [
            'statamic_attribute' => 'confirmation_redirect_url',
            'unescape_slashes' => true,
        ],
        'pageId' => [
            'statamic_attribute' => 'confirmation_page',
            'translate_page_references' => true,
        ],
        'queryString' => [
            'statamic_attribute' => 'confirmation_query_string',
            'translate_field_references' => true,
            'trim' => true,
        ],
        'disableAutoformat' => [
            'statamic_attribute' => null,
        ],
        'conditionalLogic' => [
            'statamic_attribute' => null, // Skip conditionalLogic as it's handled separately
        ],
    ];

    /**
     * Mapping from Gravity Forms notification fields to Statamic email fields.
     *
     * Key: Gravity Forms notification field name
     * Value: Statamic email field name (null to exclude from mapping)
     *
     * Supports conditional mapping with 'based_on_other_attribute_value' structure
     * for complex scenarios where the target field depends on other field values.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected const FORM_CONFIGURATION_ACTION_MAPPING_EMAIL = [
        'id' => [
            'statamic_attribute' => 'id',
        ],

        // Email Notification Name
        'name' => [
            'statamic_attribute' => 'name',
        ],

        // Send To Email
        // --------------------------------------------------------------------------------
        // The `toEmail` field is enabled in the Gravity Forms UI when the `toType` field
        // is set to `email`.
        //
        // The `toEmail` and `to` fields are the same value in the Gravity Forms JSON
        // structure.
        // --------------------------------------------------------------------------------
        'to' => [
            'statamic_attribute' => 'to',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],
        'toEmail' => [
            'statamic_attribute' => 'to',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // CC
        // --------------------------------------------------------------------------------
        // The `cc` field is in the JSON structure, but it is not visible in the Gravity
        // Forms UI.
        //
        // The `cc` field has no values in any of the active UAMS forms.
        // --------------------------------------------------------------------------------
        'cc' => [
            'statamic_attribute' => 'cc',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // BCC
        'bcc' => [
            'statamic_attribute' => 'bcc',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // From Email
        'from' => [
            'statamic_attribute' => 'from',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // From Name
        'fromName' => [
            'statamic_attribute' => 'from_name',
            'value_translation' => [
                '#empty' => '{{ site:name }}',
            ],
        ],

        // Reply To
        'replyTo' => [
            'statamic_attribute' => 'reply_to',
            'translate_field_references' => true,
            'trim_comma_separated_values' => true,
        ],

        // Subject
        'subject' => [
            'statamic_attribute' => 'subject',
            'translate_field_references' => true,
        ],

        // Attach uploaded fields to notification
        'enableAttachments' => [
            'statamic_attribute' => 'attachments',
            'value_translation' => [
                'true' => true,
                'false' => null,
            ],
        ],

        // Active
        'isActive' => [
            'statamic_attribute' => 'enabled',
        ],

        // Message
        'message' => [
            'statamic_attribute' => 'message',
            'as_bard' => true,
            'translate_field_references' => true,
        ],

        // Send To (type)
        'toType' => [
            'statamic_attribute' => 'to_type',
            'statamic_other_field_value' => [
                'cc_type' => 'email',
                'bcc_type' => 'email',
                'reply_to_type' => 'email',
            ],
        ],

        // Send To Field
        // --------------------------------------------------------------------------------
        // The `toField` field is enabled in the Gravity Forms UI when the `toType` field
        // is set to `field`.
        // --------------------------------------------------------------------------------
        'toField' => [
            'statamic_attribute' => 'to_field',
            'translate_field_references' => true,
        ],

        // Legacy `*Field` notification aliases (fromField, fromNameField, replyToField)
        // --------------------------------------------------------------------------------
        // Pre-1.7 aliases that populate From / From Name / Reply To from a form field
        // rather than a literal. Gravity Forms still emits them for backward
        // compatibility. Unlike `to`/`to_field`, Statamic's `from`/`from_name`/`reply_to`
        // have no dedicated field selector, so `field_id_to_submission_tag` resolves the
        // field ID to a handle and emits a `{{ submission:<handle> }}` merge tag into the
        // same attribute (see FormConfigurationManagerTrait::buildEmailConfiguration).
        //
        // These entries are placed after the literal `from`/`fromName`/`replyTo` entries
        // above so a set field reference overwrites the literal, matching the Gravity
        // Forms UI (issue wordpress-importer-archive#34).
        // --------------------------------------------------------------------------------
        'fromField' => [
            'statamic_attribute' => 'from',
            'field_id_to_submission_tag' => true,
        ],
        'fromNameField' => [
            'statamic_attribute' => 'from_name',
            'field_id_to_submission_tag' => true,
        ],
        'replyToField' => [
            'statamic_attribute' => 'reply_to',
            'field_id_to_submission_tag' => true,
        ],

        // Email Service
        // --------------------------------------------------------------------------------
        // The `service` field is hidden in the Gravity Forms UI.
        //
        // All instances of this field have the value "wordpress" in the active UAMS forms.
        // --------------------------------------------------------------------------------
        'service' => [
            'statamic_attribute' => null,
        ],

        // Event
        // --------------------------------------------------------------------------------
        // There is no analog to this in Statamic.
        //
        // Dropping the value is correct for `form_submission`, which is what a Statamic
        // email action already means. It is NOT correct for `form_save_email_requested`
        // (Save & Continue): that notification is skipped outright before it reaches this
        // mapping, because Statamic has no Save & Continue analog and the imported action
        // would carry no recipient and an unresolvable `{save_link}` token. See
        // isSaveAndContinueNotification() (issue wordpress-importer-archive#33).
        // --------------------------------------------------------------------------------
        'event' => [
            'statamic_attribute' => null,
        ],

        // Notification Scheduler add-on (issue wordpress-importer-archive#35)
        // --------------------------------------------------------------------------------
        // INTENTIONALLY DROPPED. The add-on schedules a notification for a delay, an
        // absolute date, or a field-derived time, optionally recurring. Statamic sends an
        // email action when the submission is made and has no analog for any of it.
        //
        // All 12 scheduler-bearing notifications in the export corpus are
        // `scheduleType="immediate"` — the add-on's default and exactly what the import
        // already produces — so nothing in the corpus loses behavior. Anything other than
        // `immediate` is reported by warnUnhonoredNotificationSchedule() rather than
        // collapsing to an immediate send silently.
        //
        // These entries are explicit so a future reader sees a decision rather than an
        // oversight.
        // --------------------------------------------------------------------------------
        'schedule' => [
            'statamic_attribute' => null,
        ],
        'scheduleType' => [
            'statamic_attribute' => null,
        ],
        'scheduleDelayOffset' => [
            'statamic_attribute' => null,
        ],
        'scheduleDelayOffsetUnit' => [
            'statamic_attribute' => null,
        ],
        'scheduleDate' => [
            'statamic_attribute' => null,
        ],
        'scheduleAmpm' => [
            'statamic_attribute' => null,
        ],
        'scheduleHour' => [
            'statamic_attribute' => null,
        ],
        'scheduleMinute' => [
            'statamic_attribute' => null,
        ],
        'scheduleEnableRecurring' => [
            'statamic_attribute' => null,
        ],
        'scheduleRecurringInterval' => [
            'statamic_attribute' => null,
        ],
        'scheduleRecurringEnding' => [
            'statamic_attribute' => null,
        ],
        'scheduleRecurringEndingValue' => [
            'statamic_attribute' => null,
        ],
        'scheduleRecurringEndingUnit' => [
            'statamic_attribute' => null,
        ],
        'scheduleFieldOffset' => [
            'statamic_attribute' => null,
        ],
        'scheduleFieldOffsetUnit' => [
            'statamic_attribute' => null,
        ],
        'scheduleField' => [
            'statamic_attribute' => null,
        ],
        'scheduleFieldAmpm' => [
            'statamic_attribute' => null,
        ],

        // From Name
        // --------------------------------------------------------------------------------
        // The `toField` field is enabled in the Gravity Forms UI when the `toType` field
        // is set to `routing`.
        // --------------------------------------------------------------------------------
        'routing' => [
            'statamic_attribute' => 'to_routing',
        ],

        // Conditional Logic
        // --------------------------------------------------------------------------------
        // Skip `conditionalLogic` as it's handled separately.
        // --------------------------------------------------------------------------------
        'conditionalLogic' => [
            'statamic_attribute' => null,
        ],

        // Disable auto-formatting
        // --------------------------------------------------------------------------------
        // There is no analog to this in Statamic.
        // --------------------------------------------------------------------------------
        'disableAutoformat' => [
            'statamic_attribute' => null,
        ],
    ];

    /**
     * Configure a Statamic form with settings from Gravity Forms data.
     *
     * Maps Gravity Forms notifications to Statamic email configurations
     * and form configuration fields, then applies them to the provided form.
     *
     * @param  Form  $form  The Statamic form to configure
     * @param  array<int|string, mixed>  $gravityFormsData  The Gravity Forms form data
     * @param  string  $siteHandle  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @return bool True if configuration was successful, false otherwise
     */
    public function configureFormEmailSettings(
        Form $form,
        array $gravityFormsData,
        string $siteHandle,
        $assetImporter
    ): bool {
        return $this->configureFormSettingsInternal(
            $form,
            $gravityFormsData,
            'Gravity Forms',
            $siteHandle,
            $assetImporter
        );
    }

    /**
     * Process complex today format mappings that require regex patterns.
     *
     * @param  string  $value  The value to process
     * @return string The processed value
     */
    private function processTodayFormatMappings(
        string $value
    ): string {
        // Handle {today:format:format_string} patterns
        $processedValue = \preg_replace(
            '/\{today:format:([^}]+)\}/',
            '{{ today format="$1" }}',
            $value
        );

        return $processedValue ?? $value;
    }

    /**
     * Process complex quiz format mappings that require regex patterns.
     *
     * @param  string  $value  The value to process
     * @return string The processed value
     */
    private function processQuizFormatMappings(
        string $value
    ): string {
        // Handle {quiz:id=handle} patterns
        $processedValue = \preg_replace(
            '/\{quiz:id=([^}]+)\}/',
            '{{ quiz handle="$1" }}',
            $value
        );

        $value = $processedValue ?? $value;

        // Handle {quiz_score:id=handle} patterns
        $processedValue = \preg_replace(
            '/\{quiz_score:id=([^}]+)\}/',
            '{{ quiz:score handle="$1" }}',
            $value
        );

        return $processedValue ?? $value;
    }

    /**
     * Process complex survey format mappings that require regex patterns.
     *
     * @param  string  $value  The value to process
     * @return string The processed value
     */
    private function processSurveyFormatMappings(
        string $value
    ): string {
        // Handle {score:id=handle} patterns
        $processedValue = \preg_replace(
            '/\{score:id=([^}]+)\}/',
            '{{ survey:score handle="$1" }}',
            $value
        );

        return $processedValue ?? $value;
    }

    /**
     * Process complex format mappings that require regex patterns.
     *
     * This method handles all the complex format mappings specific to Gravity Forms,
     * including today format, quiz format, and survey format mappings.
     *
     * @param  string  $value  The value to process
     * @return string The processed value
     */
    protected function processComplexFormatMappings(
        string $value
    ): string {
        // Handle {today:format:format_string} patterns
        $value = $this->processTodayFormatMappings($value);

        // Handle {quiz:id=handle} and {quiz_score:id=handle} patterns
        $value = $this->processQuizFormatMappings($value);

        // Handle {score:id=handle} patterns
        $value = $this->processSurveyFormatMappings($value);

        return $value;
    }

    /**
     * Strip current-user and entry-author merge tags from every string in a
     * notification.
     *
     * @param  array<int|string, mixed>  $notification  The Gravity Forms notification
     * @return array<int|string, mixed> The notification with the tags removed
     */
    protected function neutralizeCurrentUserMergeTags(
        array $notification
    ): array {
        foreach ($notification as $key => $value) {
            if (\is_string($value)) {
                $notification[$key] = $this->processCurrentUserMergeTags($value);

                continue;
            }

            // `routing` carries per-rule recipients as nested arrays.
            if (\is_array($value)) {
                $notification[$key] = $this->neutralizeCurrentUserMergeTags($value);
            }
        }

        return $notification;
    }

    /**
     * Neutralize Gravity Forms merge tags that resolve to a WordPress user.
     *
     * Two families, same problem at different scope:
     *
     *   - `{user:display_name}` / `{user:user_email}` resolve to the logged-in
     *     WordPress user. wordpress-importer-archive#444
     *   - `{created_by:<property>}` resolves to the WordPress user who created the
     *     entry. wordpress-importer-archive#497
     *
     * The target Statamic app has no front-end authentication, so there is no user
     * to resolve either family against, and the source sites are not uniformly
     * login-gated — Gravity Forms already resolved these to empty for any anonymous
     * submitter and fell back to the site-default From. Left alone they would ship
     * as a literal token in the imported notification config: neither family is in
     * `getVariableMappings()`, and `created_by` is on the system-merge-tag list the
     * field-merge-tag path deliberately does not translate.
     *
     * They are removed rather than blanking the whole value, because the corpus
     * carries them embedded in longer strings (a `from` of
     * `{Name (First):1.3}oedassistance@uams.edu{user:display_name}`, a subject of
     * `Formulary Request ({date_dmy}_{user:display_name})`). A value that held
     * nothing else collapses to empty, which falls back to `MAIL_FROM`.
     *
     * The `{user:…}` alternative stays pinned to the two observed properties, so
     * widening for `{created_by:…}` cannot change wordpress-importer-archive#444's behavior. The
     * `{created_by:…}` alternative takes the whole property family rather than the
     * two corpus occurrences: every WordPress user property is equally
     * unresolvable here, and the class is bounded — it cannot span a `}`, so it
     * can never swallow a neighbouring tag.
     *
     * @param  string  $value  The value to process
     * @return string The value with any user-resolving merge tags removed
     */
    protected function processCurrentUserMergeTags(
        string $value
    ): string {
        $pattern = '/\{user:(?:display_name|user_email)\}|\{created_by:[A-Za-z0-9_]+\}/';

        // Find every tag. A value with none passes through the strip loop below unchanged.
        $matches = [];
        \preg_match_all(
            $pattern,
            $value,
            $matches
        );

        // Warn once per occurrence so the import report accounts for every tag that
        // was dropped rather than translated.
        foreach ($matches[0] as $mergeTag) {
            // Name the right absent user for the family that matched
            $subject = \str_starts_with($mergeTag, '{created_by:')
                ? 'Entry-author'
                : 'Current-user';

            ImportWarningManager::addWarning(
                'forms',
                'neutralized_merge_tags',
                [
                    'merge_tag' => $mergeTag,
                    'reason' => $subject.' merge tags have no Statamic equivalent; '
                        .'the value falls back to the site-default From.',
                ]
            );
        }

        // Strip repeatedly until the value stops changing. A single pass lets an
        // overlapping tag reassemble into a live one — removing the inner tag from
        // `{user:dis{user:user_email}play_name}` yields `{user:display_name}`, which
        // would then ship untouched. wordpress-importer-archive#444
        while (\preg_match($pattern, $value) === 1) {
            $stripped = \preg_replace(
                $pattern,
                '',
                $value
            ) ?? $value;

            // A strip that changed nothing could only be a failed one; stop rather than loop
            if ($stripped === $value) {
                break;
            }

            $value = $stripped;
        }

        return $value;
    }

    /**
     * Process Gravity Forms Field Merge Tags and convert them to Statamic submission
     * field tags.
     *
     * Handles patterns like:
     *   - {Something:1} -> {{ submission:field_handle }}
     *   - {:1} -> {{ submission:field_handle }}
     *   - {Something:1:} -> {{ submission:field_handle }}
     *   - {:1:} -> {{ submission:field_handle }}
     *
     * @param  string  $value  The value to process
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @return string The processed value with field merge tags converted
     */
    protected function processFieldMergeTags(
        string $value,
        array $formFields
    ): string {

        // Pattern to match any merge tag with braces
        $pattern = '/\{([^}]+)\}/';

        $result = preg_replace_callback(
            $pattern,
            function ($matches) use ($formFields): string {
                $fullMatch = $matches[0];
                $content = $matches[1];

                // Parse the content to find the field ID (which is always a number)
                $parts = \explode(
                    ':',
                    $content
                );

                // If there's only one part, it's not a field merge tag
                if (\count($parts) < 2) {
                    return $fullMatch;
                }

                // Find the field ID by looking for a number (integer or decimal)
                $fieldId = null;
                $descriptorParts = [];

                foreach ($parts as $part) {
                    // Check if this part is a number (field ID)
                    if (
                        \preg_match(
                            '/^\d+(?:\.\d+)?$/',
                            $part
                        ) === 1
                    ) {
                        $fieldId = $part;
                        break;
                    } else { // Otherwise
                        // This part is part of the descriptor
                        $descriptorParts[] = $part;
                    }
                }

                // If no field ID found, this is not a field merge tag
                if ($fieldId === null) {
                    return $fullMatch;
                }

                // Join the descriptor parts
                $descriptor = \implode(
                    ':',
                    $descriptorParts
                );

                // Skip if this is a system merge tag
                // A descriptor is text and the system tags are words, so a loose comparison
                // matches exactly what this strict one does.
                // @pest-mutate-ignore: TrueToFalse
                if (
                    \in_array($descriptor, self::SYSTEM_MERGE_TAGS, true)
                ) {
                    return $fullMatch; // Return original unchanged
                }

                // Find the field handle for this field ID
                $fieldHandle = $this->getFieldHandleById(
                    $fieldId,
                    $formFields
                );

                if ($fieldHandle !== null) {
                    return "{{ submission:{$fieldHandle} }}";
                }

                // If field handle not found, return original unchanged
                return $fullMatch;
            },
            $value
        );

        // Clean up variables to free up memory
        unset(
            $formFields,
            $pattern,
        );

        return \is_string($result) ? $result : $value;
    }

    /**
     * Get the field name/label from a field array (Gravity Forms specific).
     *
     * @param  array<int|string, mixed>  $field  The field array
     * @return string The field name/label
     */
    protected function getFieldNameFromField(
        array $field
    ): string {
        // Gravity Forms uses 'label' property for field names
        if (isset($field['label']) && \is_scalar($field['label'])) {
            return (string) $field['label'];
        }

        return '';
    }

    /**
     * Get the subfield handle for a given subfield ID from the form fields array.
     *
     * @param  string  $subfieldId  The subfield ID (e.g., "67.1")
     * @param  array<int|string, mixed>  $formFields  Array of form fields
     * @return ?string The subfield handle or null if not found
     */
    protected function getSubfieldHandleById(
        string $subfieldId,
        array $formFields
    ): ?string {
        // Parse the subfield ID to get parent field ID and subfield number
        $parts = \explode(
            '.',
            $subfieldId
        );
        if (\count($parts) !== 2) {
            return null;
        }

        $parentFieldId = $parts[0];

        // Find the parent field
        foreach ($formFields as $field) {
            if (! \is_array($field)) {
                continue;
            }

            if (! isset($field['id']) || ! \is_scalar($field['id']) || (string) $field['id'] !== $parentFieldId) {
                continue;
            }

            // A subfield is named after a labelled, typed parent that lists its inputs
            if (! isset($field['label']) || ! \is_scalar($field['label'])) {
                continue;
            }

            if (! isset($field['type']) || ! \is_scalar($field['type'])) {
                continue;
            }

            if (! isset($field['inputs']) || ! \is_array($field['inputs'])) {
                continue;
            }

            // Get the parent field handle
            $parentHandle = $this->convertLabelToHandle(
                (string) $field['label'],
                $parentFieldId
            );

            // Find the subfield in the inputs array
            foreach ($field['inputs'] as $input) {
                if (! \is_array($input)) {
                    continue;
                }

                if (! isset($input['id']) || ! \is_scalar($input['id']) || (string) $input['id'] !== $subfieldId) {
                    continue;
                }

                // An input with no label has no mapped subfield
                if (! isset($input['label']) || ! \is_scalar($input['label'])) {
                    continue;
                }

                // Get the subfield handle from the mapping
                $subfieldHandle = $this->getSubfieldHandleFromMapping(
                    (string) $field['type'],
                    (string) $input['label'],
                    FieldtypeMapping::getSubfieldMapping()
                );

                if ($subfieldHandle !== null) {
                    // Return the combined handle: parent.subfield
                    return $parentHandle.'.'.$subfieldHandle;
                }
            }
        }

        // Clean up variables to free up memory
        unset(
            $subfieldId,
            $formFields,
            $parts,
            $parentFieldId,
            $field,
            $parentHandle,
            $input,
            $subfieldHandle,
        );

        return null;
    }

    /**
     * Get the subfield handle from the mapping based on field type and subfield label.
     *
     * @param  string  $fieldType  The Gravity Forms field type
     * @param  string  $subfieldLabel  The subfield label
     * @param  array<int|string, mixed>  $subfieldMapping  The subfield mapping configuration
     * @return ?string The subfield handle or null if not found
     */
    protected function getSubfieldHandleFromMapping(
        string $fieldType,
        string $subfieldLabel,
        array $subfieldMapping
    ): ?string {
        // Read the subfield's settings, which the mapping keys by field type and label
        $subfieldConfig = data_get(
            $subfieldMapping,
            [
                'fieldtypes',
                $fieldType,
                'subfields',
                $subfieldLabel,
            ]
        );

        // Return the subfield's handle, if it names one
        $handle = data_get(
            $subfieldConfig,
            'handle'
        );

        return \is_string($handle) ? $handle : null;
    }

    /**
     * Convert Gravity Forms routing rules to Statamic format.
     *
     * @param  array<int|string, mixed>  $routingRules  Array of Gravity Forms routing rules
     * @param  array<int|string, mixed>  $formFields  Array of form fields for field ID to handle mapping
     * @return array<int|string, mixed> Array of Statamic routing rules
     */
    protected function convertRoutingRulesToStatamicFormat(
        array $routingRules,
        array $formFields
    ): array {
        $statamicRoutingRules = [];

        foreach ($routingRules as $rule) {
            if (! \is_array($rule)) {
                continue;
            }

            // A rule names its field by ID
            if (! isset($rule['fieldId']) || ! \is_scalar($rule['fieldId'])) {
                continue;
            }

            // Convert the field ID to a field handle
            $fieldHandle = $this->getFieldHandleById(
                (string) $rule['fieldId'],
                $formFields
            );

            // Skip this rule if field handle not found
            if ($fieldHandle === null) {
                continue;
            }

            // Convert operator to Statamic format
            $operator = $this->convertRoutingOperatorToStatamicFormat(
                isset($rule['operator']) && \is_scalar($rule['operator'])
                    ? (string) $rule['operator']
                    : ''
            );

            // Create Statamic routing rule
            $statamicRule = [
                'field' => $fieldHandle,
                'operator' => $operator,
                'value' => isset($rule['value']) && \is_scalar($rule['value'])
                    ? (string) $rule['value']
                    : '',
                'email' => isset($rule['email']) && \is_scalar($rule['email'])
                    ? (string) $rule['email']
                    : '',
            ];

            $statamicRoutingRules[] = $statamicRule;
        }

        // Clean up variables to free up memory
        unset(
            $routingRules,
            $formFields,
            $rule,
            $fieldHandle,
            $operator,
            $statamicRule,
        );

        return $statamicRoutingRules;
    }

    /**
     * Convert Gravity Forms routing operator to Statamic format.
     *
     * @param  string  $operator  The routing operator
     * @return string The Statamic operator
     */
    protected function convertRoutingOperatorToStatamicFormat(
        string $operator
    ): string {
        // Map Gravity Forms operators to Statamic operators
        $operatorMapping = [
            'is' => 'equals',
            'isnot' => 'not',
            '>' => 'greater_than',
            '<' => 'less_than',
        ];

        // Return mapped operator or original if not found
        $result = $operatorMapping[$operator] ?? $operator;

        // Clean up variables to free up memory
        unset(
            $operator,
            $operatorMapping,
        );

        return $result;
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
        // Gravity Forms doesn't have options JSON parsing like Formidable Forms
        // Return the form data as-is
        return $formData;
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
        // Gravity Forms: Handle datetime field processing for schedule form fields
        // This logic was previously hardcoded in the trait but is specific to Gravity Forms

        // Process schedule form datetime fields that require special handling
        $scheduleFields = ['schedule_form.start_datetime', 'schedule_form.end_datetime'];

        foreach ($scheduleFields as $fieldPath) {
            if (
                str_contains(
                    $fieldPath,
                    '.'
                )
            ) {
                $parts = \explode(
                    '.',
                    $fieldPath
                );
                $parentKey = $parts[0];
                $childKey = $parts[1];

                // Check if we have a value for this datetime field
                if (
                    isset($currentFormData[$parentKey])
                    && \is_array($currentFormData[$parentKey])
                    && \array_key_exists($childKey, $currentFormData[$parentKey])
                ) {
                    $value = $currentFormData[$parentKey][$childKey];

                    // Only set the value if it's not null (indicating complete data)
                    if ($value === null) {
                        // Remove the field if the value is null
                        unset($currentFormData[$parentKey][$childKey]);

                        // If the parent array is now empty, remove it too
                        if ($currentFormData[$parentKey] === []) {
                            unset($currentFormData[$parentKey]);
                        }
                    }
                }
            }
        }

        // Clean up variables to free up memory
        unset(
            $formData,
            $siteHandle,
            $assetImporter,
            $scheduleFields,
            $fieldPath,
            $parts,
            $parentKey,
            $childKey,
            $value,
        );
    }

    /**
     * Map confirmation fields from Gravity Forms data to Statamic form settings.
     *
     * Maps confirmation fields from all confirmations in the confirmations array
     * to Statamic confirmation configuration fields. Handles both default and
     * additional confirmations with proper nested group structure.
     *
     * Supports conditional mapping with 'based_on_other_attribute_value' structure
     * for complex scenarios where the target field depends on other field values.
     *
     * @param  Form  $form  The Statamic form to configure
     * @param  array<int|string, mixed>  $formData  The Gravity Forms form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    protected function mapConfirmationFields(
        Form $form,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        // Check if confirmations exist and have at least one item
        if (! isset($formData['confirmations']) || ! \is_array($formData['confirmations']) || $formData['confirmations'] === []) {
            return;
        }

        // Get the current form data. A Statamic form's data is always a collection; the check
        // narrows the untyped accessor for PHPStan.
        $formDataStore = $form->data();
        // @pest-mutate-ignore: InstanceOfToTrue
        $currentFormData = $formDataStore instanceof Collection ? $formDataStore->all() : [];

        // Initialize arrays for default and additional confirmations
        $defaultConfirmation = null;
        $additionalConfirmations = [];

        // Process each confirmation
        foreach ($formData['confirmations'] as $confirmation) {
            if (! \is_array($confirmation)) {
                continue;
            }

            // Skip confirmations that don't have event equal to 'form_submission'
            if (! isset($confirmation['event']) || $confirmation['event'] !== 'form_submission') {
                continue;
            }

            // Check if this is the default confirmation
            if (isset($confirmation['isDefault']) && $confirmation['isDefault'] === true) {
                $defaultConfirmation = $confirmation;
            } else { // Otherwise
                // This is an additional confirmation
                $additionalConfirmations[] = $confirmation;
            }
        }

        // Map default confirmation if it exists
        if ($defaultConfirmation !== null) {
            $this->mapDefaultConfirmation(
                $currentFormData,
                $defaultConfirmation,
                $formData,
                $siteHandle,
                $assetImporter
            );
        }

        // Map additional confirmations if they exist
        if ($additionalConfirmations !== []) {
            $this->mapAdditionalConfirmations(
                $currentFormData,
                $additionalConfirmations,
                $formData,
                $siteHandle,
                $assetImporter
            );
        }

        // Apply the mapped confirmation data to the form
        $form->merge($currentFormData);

        // Clean up variables to free up memory
        unset(
            $formData,
            $siteHandle,
            $assetImporter,
            $currentFormData,
            $defaultConfirmation,
            $additionalConfirmations,
            $confirmation,
        );
    }

    /**
     * Map default confirmation to Statamic form settings.
     *
     * Maps a default confirmation to the confirmation_settings_default group field.
     *
     * @param  array<int|string, mixed>  &$currentFormData  The current form data (passed by reference)
     * @param  array<int|string, mixed>  $confirmation  The confirmation data
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    private function mapDefaultConfirmation(
        array &$currentFormData,
        array $confirmation,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        $formIdForContext = isset($formData['id']) && \is_scalar($formData['id'])
            ? $formData['id']
            : '?';
        $sourceNameForContext = isset($confirmation['name']) && \is_scalar($confirmation['name'])
            ? (string) $confirmation['name']
            : 'Default';

        // Set form config context so log messages identify this confirmation
        $this->setFormConfigContextForLogging([
            'form_id' => $formIdForContext,
            'source' => 'confirmation',
            'source_name' => $sourceNameForContext,
            'plugin' => 'Gravity Forms',
        ]);

        // Wrap processing in try/finally so form config context is always cleared
        try {
            $currentFormData['confirmation_settings_default'] ??= [];

            // ---
            // Use common mapping logic
            // ---

            // Get form fields for mapping
            $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                ? $formData['fields']
                : [];

            // Map confirmation fields to Statamic structure. The confirmation table spreads no
            // value and sets no other field, so the mapped data it returns is always empty.
            $this->processConfirmationFieldMapping(
                $confirmation,
                $formData,
                $siteHandle,
                $assetImporter,
                $formFields,
                static::FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION,
                function (string $statamicKey, $value, $mappingConfig) use (&$currentFormData): void {
                    if (
                        ! isset($currentFormData['confirmation_settings_default'])
                        || ! \is_array($currentFormData['confirmation_settings_default'])
                    ) {
                        $currentFormData['confirmation_settings_default'] = [];
                    }

                    // Store the value in the default confirmation settings group
                    $currentFormData['confirmation_settings_default'][$statamicKey] = $value;
                },
                // This is a default confirmation. The flag only excludes entries marked for it,
                // and this class's confirmation table marks none.
                // @pest-mutate-ignore: TrueToFalse
                true
            );

            // ---
            // Note: Default confirmations do not support conditional logic in Statamic.
            // Conditional logic is only supported for additional confirmations.
            // ---
        } finally {
            // Clear form config context so logs do not attribute later processing
            $this->clearFormConfigContextForLogging();
        }

        // Clean up variables to free up memory
        unset(
            $confirmation,
            $formData,
            $siteHandle,
            $assetImporter,
        );
    }

    /**
     * Map additional confirmations to Statamic form settings.
     *
     * Maps additional confirmations to the confirmation_additional replicator field.
     *
     * @param  array<int|string, mixed>  &$currentFormData  The current form data (passed by reference)
     * @param  list<array<int|string, mixed>>  $additionalConfirmations  Array of additional confirmations
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     */
    private function mapAdditionalConfirmations(
        array &$currentFormData,
        array $additionalConfirmations,
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): void {
        $currentFormData['confirmation_additional'] ??= [];

        // Process each additional confirmation
        foreach ($additionalConfirmations as $confirmation) {
            $formIdForContext = isset($formData['id']) && \is_scalar($formData['id'])
                ? $formData['id']
                : '?';
            $sourceNameForContext = isset($confirmation['name']) && \is_scalar($confirmation['name'])
                ? (string) $confirmation['name']
                : 'Additional';

            // Set form config context so log messages identify this confirmation
            $this->setFormConfigContextForLogging([
                'form_id' => $formIdForContext,
                'source' => 'confirmation',
                'source_name' => $sourceNameForContext,
                'plugin' => 'Gravity Forms',
            ]);

            // Wrap processing in try/finally so form config context is always cleared
            try {
                // Create a new replicator set for this confirmation
                $replicatorSet = [
                    'type' => 'confirmation',
                    'enabled' => isset($confirmation['isActive']) ? (bool) $confirmation['isActive'] : true,
                ];

                // ---
                // Map the confirmation ID if present
                // ---

                // Map confirmation ID when present
                if (isset($confirmation['id'])) {
                    // Store confirmation ID in replicator set
                    $replicatorSet['id'] = $confirmation['id'];
                }

                // ---
                // Map the confirmation name
                // ---

                // Map confirmation name when present
                if (isset($confirmation['name'])) {
                    // Store confirmation name in replicator set
                    $replicatorSet['confirmation_name'] = $confirmation['name'];
                }

                // Initialize the confirmation settings group
                $replicatorSet['confirmation_settings'] = [];

                // ---
                // Use common mapping logic
                // ---

                // Get form fields for mapping
                $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                    ? $formData['fields']
                    : [];

                // Map confirmation fields to Statamic structure; as for the default confirmation,
                // the mapped data it returns is always empty
                $this->processConfirmationFieldMapping(
                    $confirmation,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields,
                    static::FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION,
                    function (string $statamicKey, $value, $mappingConfig) use (&$replicatorSet): void {
                        // Store the value in the confirmation settings group
                        $replicatorSet['confirmation_settings'][$statamicKey] = $value;
                    },
                    // This is an additional confirmation; as above, the table marks no entry the
                    // flag could exclude.
                    // @pest-mutate-ignore: FalseToTrue
                    false
                );

                // ---
                // Map conditional logic if present
                // ---

                // Map conditional logic when present
                if (
                    isset($confirmation['conditionalLogic'])
                    && \is_array($confirmation['conditionalLogic'])
                ) {
                    // Convert conditional logic to Statamic format
                    $conditionalLogic = FieldtypeMapping::mapConditionalLogic(
                        $confirmation['conditionalLogic'],
                        $formFields
                    );

                    // ---
                    // Store conditional logic at root level if not empty
                    // ---

                    // Store conditional logic when not empty
                    if ($conditionalLogic !== []) {
                        // Add conditional logic to replicator set
                        $replicatorSet['conditional_logic'] = $conditionalLogic;
                    }
                }

                // Add the replicator set to the additional confirmations array
                if (
                    ! isset($currentFormData['confirmation_additional'])
                    || ! \is_array($currentFormData['confirmation_additional'])
                ) {
                    $currentFormData['confirmation_additional'] = [];
                }

                $currentFormData['confirmation_additional'][] = $replicatorSet;
            } finally {
                // Clear form config context so logs do not attribute later processing
                $this->clearFormConfigContextForLogging();
            }
        }

        // Clean up variables to free up memory
        unset(
            $additionalConfirmations,
            $formData,
            $siteHandle,
            $assetImporter,
            $confirmation,
            $replicatorSet,
        );
    }

    /**
     * Process datetime fields for Gravity Forms schedule form start and end times.
     *
     * Combines date, hour, minute, and am/pm fields into a single datetime string.
     * The method handles the complex mapping where multiple source fields map to
     * a single target field.
     *
     * @param  string  $statamicKey  The Statamic field handle (schedule_form.start_datetime or
     *                               schedule_form.end_datetime)
     * @param  mixed  $value  The original value (this will be one of the component fields)
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @return ?string The formatted datetime string or null if incomplete
     */
    protected function processDateTimeField(
        string $statamicKey,
        $value,
        array $formData
    ): ?string {
        // Determine if this is start or end datetime
        $prefix = $statamicKey === 'schedule_form.start_datetime' ? 'scheduleStart' : 'scheduleEnd';

        // Read the date, hour, minute and meridiem from the form data
        $parts = $this->readDateTimeParts(
            $formData,
            [
                $prefix,
                $prefix.'Hour',
                $prefix.'Minute',
                $prefix.'Ampm',
            ]
        );
        if ($parts === null) {
            return null;
        }

        // Combine them, warning about a date that cannot be read (assuming a format like "08/19/2025")
        $formattedDateTime = $this->formatDateTimeParts($parts);
        if ($formattedDateTime === null) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'prefix' => $prefix,
                    'date' => $parts[0],
                ]
            );

            // Log warning for invalid date format
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "Invalid date format for {$prefix}: {$parts[0]}",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        return $formattedDateTime;
    }

    /**
     * Process Gravity Forms specific field types that require special handling.
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
        // Handle datetime fields for schedule form (Gravity Forms specific)
        if (
            $statamicKey === 'schedule_form.start_datetime'
            || $statamicKey === 'schedule_form.end_datetime'
        ) {
            return $this->processDateTimeField(
                $statamicKey,
                $value,
                $formData
            );
        }

        // Return null if no special processing was needed
        return null;
    }

    /**
     * Process datetime combination fields and return the combined datetime value.
     *
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration with datetime_combination
     * @param  array<int|string, mixed>  $formData  The complete form data for context
     * @return ?string The combined datetime string or null if incomplete
     */
    protected function processDatetimeCombination(
        array $mappingConfig,
        array $formData
    ): ?string {
        // Read the date, hour, minute and meridiem from the form fields the mapping names
        $parts = $this->readDateTimeParts(
            $formData,
            [
                data_get($mappingConfig, 'datetime_combination.date_field'),
                data_get($mappingConfig, 'datetime_combination.hour_field'),
                data_get($mappingConfig, 'datetime_combination.minute_field'),
                data_get($mappingConfig, 'datetime_combination.ampm_field'),
            ]
        );
        if ($parts === null) {
            return null;
        }

        // Accept a date written with escaped slashes ("09\/03\/2025") as well as plain ones
        $parts[0] = \str_replace(
            '\\/',
            '/',
            $parts[0]
        );

        return $this->formatDateTimeParts($parts);
    }

    /**
     * Read a datetime's date, hour, minute and meridiem from the form data.
     *
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  array{0: mixed, 1: mixed, 2: mixed, 3: mixed}  $fields  The keys of the date, hour, minute and meridiem
     * @return ?array{0: string, 1: string, 2: string, 3: string} The four parts, or null when one is missing, blank or not a scalar
     */
    private function readDateTimeParts(
        array $formData,
        array $fields
    ): ?array {
        $date = $this->readDateTimePart($formData, $fields[0]);
        $hour = $this->readDateTimePart($formData, $fields[1]);
        $minute = $this->readDateTimePart($formData, $fields[2]);
        $ampm = $this->readDateTimePart($formData, $fields[3]);

        // A part that is missing means there is no datetime to combine
        if ($date === null || $hour === null || $minute === null || $ampm === null) {
            return null;
        }

        return [
            $date,
            $hour,
            $minute,
            $ampm,
        ];
    }

    /**
     * Read one part of a datetime from the form data.
     *
     * @param  array<int|string, mixed>  $formData  The form data
     * @param  mixed  $field  The part's key; only non-empty text names a form field
     * @return ?string The part, or null when it is missing, blank or not a scalar
     */
    private function readDateTimePart(
        array $formData,
        mixed $field
    ): ?string {
        $part = \is_string($field) && $field !== ''
            ? ($formData[$field] ?? null)
            : null;

        if (! \is_scalar($part) || (string) $part === '') {
            return null;
        }

        return (string) $part;
    }

    /**
     * Combine a date and a 12-hour time into a 24-hour datetime.
     *
     * @param  array{0: string, 1: string, 2: string, 3: string}  $parts  The date (m/d/Y), hour, minute and meridiem
     * @return ?string The datetime as "Y-m-d H:i", or null when the date cannot be read
     */
    private function formatDateTimeParts(
        array $parts
    ): ?string {
        [$date, $hour, $minute, $ampm] = $parts;

        // Convert 12-hour format to 24-hour format
        $hour24 = (int) $hour;
        if (\strtolower($ampm) === 'pm' && $hour24 !== 12) {
            $hour24 += 12;
        } elseif (\strtolower($ampm) === 'am' && $hour24 === 12) {
            $hour24 = 0;
        }

        // Parse the date
        $dateObj = DateTime::createFromFormat(
            'm/d/Y',
            $date
        );
        if ($dateObj === false) {
            return null;
        }

        // Set the time; the format below shows no seconds
        $dateObj->setTime(
            $hour24,
            (int) $minute
        );

        return $dateObj->format('Y-m-d H:i');
    }

    /**
     * Get Gravity Forms specific variable mappings for merge tag processing.
     *
     * @return array<string, string> Array mapping Gravity Forms variables to Statamic variables
     */
    protected function getVariableMappings(): array
    {
        return [
            '{admin_email}' => '{{ admin_email }}',
            '{all_fields:empty}' => '{{ all_fields show_empty="true" }}',
            '{all_fields:nohidden}' => '{{ all_fields hide_hidden="true" }}',
            '{all_fields:value}' => '{{ all_fields show_value="true" }}',
            '{all_fields}' => '{{ all_fields }}',
            '{date_mdy}' => '{{ today format="m/d/Y" }}',
            '{entry_id}' => '{{ submission:id }}',
            '{form_id}' => '{{ form:handle }}',
            '{form_title}' => '{{ form:title }}',
            '{ip}' => '{{ ip }}',
            '{quiz_grade}' => '{{ quiz:grade }}',
            '{quiz_passfail}' => '{{ quiz:passfail }}',
            '{quiz_percent}' => '{{ quiz:percent }}',
            '{quiz_score}' => '{{ quiz:score }}',
            '{quiz}' => '{{ quiz }}',
            '{referer}' => '{{ referrer }}',
            '{score}' => '{{ survey:score }}',
            '{today:timestamp}' => '{{ today:timestamp }}',
            '{today:time}' => '{{ today:time }}',
            '{today}' => '{{ today }}',
        ];
    }

    /**
     * Check if the form has notifications (Gravity Forms specific).
     *
     * @param  array<int|string, mixed>  $formData  The Gravity Forms form data
     * @return bool True if the form has notifications, false otherwise
     */
    protected function hasNotifications(
        array $formData
    ): bool {
        return isset($formData['notifications']) && \is_array($formData['notifications']) && $formData['notifications'] !== [];
    }

    /**
     * Determine whether a notification is a Gravity Forms Save & Continue email.
     *
     * Gravity Forms fires these from the `form_save_email_requested` event, which
     * has no Statamic analog — verified: `save_and_continue`, `save_link`,
     * `resume_link` and partial-submission mechanisms return zero hits across the
     * host app. wordpress-importer-archive#33
     *
     * @param  array<int|string, mixed>  $notification  The Gravity Forms notification
     * @return bool True when the notification belongs to the Save & Continue flow
     */
    protected function isSaveAndContinueNotification(
        array $notification
    ): bool {
        return isset($notification['event'])
            && \is_string($notification['event'])
            && $notification['event'] === self::NOTIFICATION_EVENT_SAVE_AND_CONTINUE;
    }

    /**
     * Warn that a Save & Continue notification was skipped rather than imported.
     *
     * Importing one produces a live but broken email action — no recipient
     * (Gravity Forms resolves it at runtime from the address the visitor types
     * into the saved-link request, so there is no field to copy) and a literal
     * `{save_link}` token in the body that nothing substitutes. A recipient-less
     * email action reads as real to anyone inspecting the form YAML, so writing
     * nothing is the safer outcome. wordpress-importer-archive#33
     *
     * @param  array<int|string, mixed>  $notification  The skipped notification
     * @param  int|float|string|bool  $formId  The Gravity Forms form id
     * @param  string  $notificationName  The notification's display name
     */
    protected function warnSkippedSaveAndContinueNotification(
        array $notification,
        int|float|string|bool $formId,
        string $notificationName
    ): void {
        // Identify the notification precisely so the migrating team can find it
        $notificationId = isset($notification['id']) && \is_scalar($notification['id'])
            ? (string) $notification['id']
            : '?';

        ImportWarningManager::addWarning(
            'forms',
            'skipped_notifications',
            [
                'plugin' => 'Gravity Forms',
                'form_id' => (string) $formId,
                'notification_id' => $notificationId,
                'notification_name' => $notificationName,
                'event' => self::NOTIFICATION_EVENT_SAVE_AND_CONTINUE,
                'reason' => 'Statamic has no Save & Continue analog; importing this '
                    .'notification would create an email action with no recipient and an '
                    .'unresolvable {save_link} token, so it was skipped.',
            ]
        );
    }

    /**
     * Warn when a Notification Scheduler delay cannot be honored on import.
     *
     * The Notification Scheduler add-on's `schedule*` fields have no Statamic
     * analog and are mapped to null (see
     * FORM_CONFIGURATION_ACTION_MAPPING_EMAIL). Every one of the 12
     * scheduler-bearing notifications in the export corpus is `immediate`, so
     * nothing is lost there — but the corpus is a sample of what the importer
     * will see, not a bound on it. A future export carrying a delayed or
     * recurring notification would otherwise collapse to an immediate send with
     * no signal anywhere, so anything other than `immediate` is reported. wordpress-importer-archive#35
     *
     * @param  array<int|string, mixed>  $notification  The Gravity Forms notification
     * @param  int|float|string|bool  $formId  The Gravity Forms form id
     * @param  string  $notificationName  The notification's display name
     */
    protected function warnUnhonoredNotificationSchedule(
        array $notification,
        int|float|string|bool $formId,
        string $notificationName
    ): void {
        // An absent scheduleType is the add-on not being in use at all
        if (
            ! isset($notification['scheduleType'])
            || ! \is_string($notification['scheduleType'])
        ) {
            return;
        }

        $scheduleType = \trim($notification['scheduleType']);

        // An empty or immediate schedule already matches what the import produces
        if (
            $scheduleType === ''
            || $scheduleType === self::NOTIFICATION_SCHEDULE_TYPE_IMMEDIATE
        ) {
            return;
        }

        // Identify the notification precisely so the migrating team can find it
        $notificationId = isset($notification['id']) && \is_scalar($notification['id'])
            ? (string) $notification['id']
            : '?';

        ImportWarningManager::addWarning(
            'forms',
            'unhonored_notification_schedule',
            [
                'plugin' => 'Gravity Forms',
                'form_id' => (string) $formId,
                'notification_id' => $notificationId,
                'notification_name' => $notificationName,
                'schedule_type' => $scheduleType,
                'reason' => 'The Notification Scheduler add-on has no Statamic analog; '
                    .'this notification will send immediately instead of on its '
                    .'configured schedule.',
            ]
        );
    }

    /**
     * Map Gravity Forms notifications to Statamic email configurations.
     *
     * Supports conditional mapping with 'based_on_other_attribute_value' structure
     * for complex scenarios where the target field depends on other field values.
     *
     * @param  array<int|string, mixed>  $formData  The Gravity Forms form data
     * @param  string  $siteHandle  The Statamic site handle
     * @param  ?AssetImporter  $assetImporter  The asset importer instance
     * @return array<int|string, mixed> Array of Statamic email configurations
     */
    protected function mapNotificationsToEmailConfigurations(
        array $formData,
        string $siteHandle,
        $assetImporter = null
    ): array {
        $notifications = isset($formData['notifications']) && \is_array($formData['notifications'])
            ? $formData['notifications']
            : [];
        $formFields = isset($formData['fields']) && \is_array($formData['fields'])
            ? $formData['fields']
            : [];
        $emailConfigurations = [];

        // Process each notification and map it to Statamic format
        foreach ($notifications as $notification) {
            if (! \is_array($notification)) {
                continue;
            }

            $formIdForContext = isset($formData['id']) && \is_scalar($formData['id'])
                ? $formData['id']
                : '?';
            $sourceNameForContext = isset($notification['name']) && \is_scalar($notification['name'])
                ? (string) $notification['name']
                : 'Unnamed';

            // Drop Save & Continue notifications rather than importing a dead one. wordpress-importer-archive#33
            if ($this->isSaveAndContinueNotification($notification)) {
                $this->warnSkippedSaveAndContinueNotification(
                    $notification,
                    $formIdForContext,
                    $sourceNameForContext
                );

                continue;
            }

            // Record any Notification Scheduler delay the import cannot honor. wordpress-importer-archive#35
            $this->warnUnhonoredNotificationSchedule(
                $notification,
                $formIdForContext,
                $sourceNameForContext
            );

            // Set form config context so log messages identify this notification
            $this->setFormConfigContextForLogging([
                'form_id' => $formIdForContext,
                'source' => 'notification',
                'source_name' => $sourceNameForContext,
                'plugin' => 'Gravity Forms',
            ]);

            // Wrap processing in try/finally so form config context is always cleared
            try {
                // Neutralize current-user merge tags before anything maps the values.
                //
                // This has to happen ahead of buildEmailConfiguration(), because that
                // applies `value_translation` first and only strips merge tags
                // afterwards. A `fromName` of exactly `{user:display_name}` — which is
                // every one of the 17 corpus `fromName` occurrences — would otherwise
                // reach `value_translation` still holding the tag, miss the
                // `#empty => '{{ site:name }}'` branch declared for precisely this
                // case, and persist as an empty From label. Stripping first makes it
                // indistinguishable from an empty `fromName`, which is the intent. wordpress-importer-archive#444
                $notification = $this->neutralizeCurrentUserMergeTags($notification);

                // Build the base email configuration using common logic
                $emailConfig = $this->buildEmailConfiguration(
                    $notification,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // ---
                // Map conditional logic if present (Gravity Forms specific)
                // ---

                // Map conditional logic when present
                if (
                    isset($notification['conditionalLogic'])
                    && \is_array($notification['conditionalLogic'])
                ) {
                    // Convert conditional logic to Statamic format
                    $conditionalLogic = FieldtypeMapping::mapConditionalLogic(
                        $notification['conditionalLogic'],
                        $formFields
                    );

                    // ---
                    // Store conditional logic under 'conditional_logic' key if not empty
                    // ---

                    // Store conditional logic when not empty
                    if ($conditionalLogic !== []) {
                        // Add conditional logic to email configuration
                        $emailConfig['conditional_logic'] = $conditionalLogic;
                    }
                }

                // Add the mapped configuration to the array
                $emailConfigurations[] = $emailConfig;
            } finally {
                // Clear form config context so logs do not attribute later processing
                $this->clearFormConfigContextForLogging();
            }
        }

        // Clean up variables to free up memory
        unset(
            $notifications,
            $formFields,
            $formData,
            $siteHandle,
            $assetImporter,
            $notification,
            $emailConfig,
            $conditionalLogic,
        );

        // Return mapped email configurations
        return $emailConfigurations;
    }

    /**
     * Translate field references in string values to Statamic format.
     *
     * Gravity Forms writes a field reference as a field merge tag ({Something:67}), so it
     * is translated exactly as one, into a Statamic submission field tag. A bare number
     * such as {67} is not a field reference and is left alone.
     *
     * @param  string  $text  The text containing field references
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return string The text with field references translated to Statamic format
     */
    protected function translateFieldReferences(
        string $text,
        array $allFields = []
    ): string {
        // A field reference is written exactly as a field merge tag, so it is resolved the same way
        return $this->processFieldMergeTags(
            $text,
            $allFields
        );
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

        // Gravity Forms: Use direct mapping
        foreach (static::FORM_CONFIGURATION_ACTION_MAPPING_CONFIRMATION as $pluginKey => $mappingConfig) {
            // Handle complex mapping configurations (arrays) vs simple string mappings
            $statamicKey = $mappingConfig['statamic_attribute'];

            // Skip the entries handled elsewhere, which map to no attribute
            if (! \is_string($statamicKey)) {
                continue;
            }

            // Extract the value using dot notation if needed
            $value = $this->extractValueFromData(
                $processedViewData,
                $pluginKey
            );

            // Only include the field if the value exists and is not empty
            if ($value !== null && $value !== '') {
                // Process the value using the common configuration processing method
                $formFields = isset($formData['fields']) && \is_array($formData['fields'])
                    ? $formData['fields']
                    : [];
                $value = $this->processMappingConfiguration(
                    $value,
                    $mappingConfig,
                    $formData,
                    $siteHandle,
                    $assetImporter,
                    $formFields
                );

                // Set the value in the appropriate structure. The confirmation table spreads no
                // value over several attributes, so each lands under its own attribute.
                if ($isAdditional) {
                    // For additional confirmations, add to confirmation_settings
                    if (
                        ! isset($mappedData['confirmation_settings'])
                        || ! \is_array($mappedData['confirmation_settings'])
                    ) {
                        $mappedData['confirmation_settings'] = [];
                    }

                    $mappedData['confirmation_settings'][$statamicKey] = $value;
                } else { // Otherwise
                    // For default confirmation, set directly
                    $mappedData[$statamicKey] = $value;
                }
            }
        }

        // Return null if no data was mapped
        return $mappedData === [] ? null : $mappedData;
    }
}
