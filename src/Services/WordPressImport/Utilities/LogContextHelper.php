<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Helper class for building log context with entry information.
 *
 * Provides a centralized way to enhance log context arrays with entry URL
 * or Gravity Forms form configuration context from ProcessingContext,
 * following the DRY principle to eliminate code duplication across the
 * WordPress import system.
 */
class LogContextHelper
{
    /**
     * Enhances a log context array with entry or form-config context if available.
     *
     * When Gravity Forms form config context is set (notification/confirmation
     * processing), adds form_config_context to the enhanced context and uses it
     * for the source suffix. Otherwise uses the WordPress page link from
     * ProcessingContext (or the provided pageUrl) and adds it as entry_url.
     *
     * @param  array<int|string, mixed>  $baseContext  The base log context array to enhance
     * @param  ?string  $pageUrl  Optional page URL to use instead of ProcessingContext (useful when pageUrl is
     *                            passed as a method parameter)
     * @return array{context: array<int|string, mixed>, page_url: ?string, source_suffix: ?string} Enhanced log payload
     */
    public static function enhanceWithEntryContext(
        array $baseContext,
        ?string $pageUrl = null
    ): array {
        // Get form config context from ProcessingContext when set
        $formConfig = ProcessingContext::getFormConfigContext();

        // Use provided page URL or fall back to ProcessingContext page link
        $entryUrl = $pageUrl ?? ProcessingContext::getWordPressPageLink();

        // Start with base context for the enhanced array
        $enhancedContext = $baseContext;

        // Prefer form config context when set (e.g., notification/confirmation processing)
        if ($formConfig !== null) {
            // Add form config to enhanced context for log consumers
            $enhancedContext['form_config_context'] = $formConfig;

            // Build source suffix from form config for message formatting
            $sourceSuffix = self::formatFormConfigSourceSuffix($formConfig);
        } else {
            // Build source suffix from entry URL when no form config
            $sourceSuffix = ($entryUrl !== null && $entryUrl !== '') ? " (from entry: $entryUrl)" : null;

            // Add entry URL to context when available
            if ($entryUrl !== null && $entryUrl !== '') {
                // Include entry URL in enhanced context for debugging
                $enhancedContext['entry_url'] = $entryUrl;
            }
        }

        // Return enhanced context plus page_url and source_suffix for callers
        return [
            'context' => $enhancedContext,
            'page_url' => $entryUrl,
            'source_suffix' => $sourceSuffix,
        ];
    }

    /**
     * Formats the "from" suffix for form configuration context (any plugin).
     *
     * @param  array{form_id: int|string, source: string, source_name: string, plugin?: string}  $formConfig  Form config context
     * @return string Suffix e.g. " (from Gravity Forms form 8 (notification: Admin Notification))"
     */
    private static function formatFormConfigSourceSuffix(
        array $formConfig
    ): string {
        // Get form ID from config with fallback
        $formId = $formConfig['form_id'];

        // Get source type (e.g., notification, confirmation)
        $source = $formConfig['source'];

        // Get source display name
        $sourceName = $formConfig['source_name'];

        // Get optional plugin name for display
        $plugin = $formConfig['plugin'] ?? null;

        // Build display part: plugin form id or generic form configuration
        $part = $plugin !== null && $plugin !== ''
            ? "{$plugin} form {$formId}"
            : "form configuration (form {$formId})";

        // Append source and name when source name is non-empty
        if ($sourceName !== '') {
            // Add source type and name to the display part
            $part .= " ($source: $sourceName)";
        }

        // Return full suffix string for appending to log messages
        return " (from $part)";
    }

    /**
     * Formats a log message with entry or form-config source suffix if available.
     *
     * When form config context is set, appends e.g. " (from Gravity Forms form 8
     * (notification: Admin Notification))". Otherwise appends "(from entry: {url})"
     * if page URL is provided.
     *
     * @param  string  $message  The base log message
     * @param  ?string  $pageUrl  The page URL (used only when form config context is not set)
     * @return string The formatted log message
     */
    public static function formatMessageWithEntry(
        string $message,
        ?string $pageUrl
    ): string {
        // Get form config from ProcessingContext when set
        $formConfig = ProcessingContext::getFormConfigContext();

        // Prefer form config suffix when set
        if ($formConfig !== null) {
            // Append form config source suffix to message
            return $message.self::formatFormConfigSourceSuffix($formConfig);
        }

        // Append entry URL suffix or nothing when no page URL
        return $message.(($pageUrl !== null && $pageUrl !== '') ? " (from entry: $pageUrl)" : '');
    }
}
