<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;

/**
 * Processing context for WordPress import operations.
 *
 * Provides a centralized way to access processing context data
 * like XML file path without passing it through method parameters.
 */
class ProcessingContext
{
    /**
     * The current XML file path being processed.
     */
    private static ?string $xmlPath = null;

    /**
     * The current WordPress page link being processed.
     */
    private static ?string $wordPressPageLink = null;

    /**
     * The current entry data being processed.
     *
     * @var array<int|string, mixed>|null
     */
    private static ?array $entryData = null;

    /**
     * The current collection handle being processed.
     */
    private static ?string $collectionHandle = null;

    /**
     * The current Statamic entry ID being processed.
     */
    private static ?string $statamicEntryId = null;

    /**
     * The current attachments array being processed.
     *
     * @var array<int|string, mixed>|null
     */
    private static ?array $attachments = null;

    /**
     * The current WordPress post ID being processed.
     */
    private static ?string $wordPressPostId = null;

    /**
     * The current shortcode content being processed.
     */
    private static ?string $shortcodeContent = null;

    /**
     * The current audio series configuration.
     */
    private static ?AudioSeriesConfig $audioSeriesConfig = null;

    /**
     * The current WordPress post date for audio processing.
     */
    private static ?string $wordPressPostDate = null;

    /**
     * The current WordPress post title for audio processing.
     */
    private static ?string $wordPressPostTitle = null;

    /**
     * The current segment title for audio processing.
     */
    private static ?string $segmentTitle = null;

    /**
     * The current audio asset title for shortcode metadata.
     */
    private static ?string $audioAssetTitle = null;

    /**
     * The current audio asset series value for shortcode metadata.
     */
    private static ?string $audioAssetSeries = null;

    /**
     * The current audio asset excerpt for shortcode metadata.
     *
     * @var ?array<int, array<int|string, mixed>> The Bard excerpt content
     */
    private static ?array $audioAssetExcerpt = null;

    /**
     * The current audio asset transcript for shortcode metadata.
     *
     * @var ?array<int, array<int|string, mixed>> The Bard transcript content
     */
    private static ?array $audioAssetTranscript = null;

    /**
     * The current MinervaKB settings being processed.
     *
     * @var array<int|string, mixed>|null
     */
    private static ?array $minervaKbSettings = null;

    /**
     * The current knowledge base entry ID being processed.
     */
    private static ?string $knowledgeBaseId = null;

    /**
     * Set the current XML file path.
     *
     * @param  ?string  $xmlPath  The XML file path
     */
    public static function setXmlPath(
        ?string $xmlPath
    ): void {
        self::$xmlPath = $xmlPath;
    }

    /**
     * Get the current XML file path.
     *
     * @return ?string The XML file path
     */
    public static function getXmlPath(): ?string
    {
        return self::$xmlPath;
    }

    /**
     * Clear the current XML file path.
     */
    public static function clearXmlPath(): void
    {
        self::$xmlPath = null;
    }

    /**
     * Set the current WordPress page link.
     *
     * @param  ?string  $wordPressPageLink  The current WordPress page link
     */
    public static function setWordPressPageLink(
        ?string $wordPressPageLink
    ): void {
        self::$wordPressPageLink = $wordPressPageLink;
    }

    /**
     * Get the current WordPress page link.
     *
     * @return ?string The current WordPress page link
     */
    public static function getWordPressPageLink(): ?string
    {
        return self::$wordPressPageLink;
    }

    /**
     * Clear the current WordPress page link.
     */
    public static function clearWordPressPageLink(): void
    {
        self::$wordPressPageLink = null;
    }

    /**
     * Set the current entry data.
     *
     * @param  array<int|string, mixed>|null  $entryData  The entry data
     */
    public static function setEntryData(
        ?array $entryData
    ): void {
        self::$entryData = $entryData;
    }

    /**
     * Get the current entry data.
     *
     * @return array<int|string, mixed>|null The entry data
     */
    public static function getEntryData(): ?array
    {
        return self::$entryData;
    }

    /**
     * Clear the current entry data.
     */
    public static function clearEntryData(): void
    {
        self::$entryData = null;
    }

    /**
     * Set the current collection handle.
     *
     * @param  ?string  $collectionHandle  The collection handle
     */
    public static function setCollectionHandle(
        ?string $collectionHandle
    ): void {
        self::$collectionHandle = $collectionHandle;
    }

    /**
     * Get the current collection handle.
     *
     * @return ?string The collection handle
     */
    public static function getCollectionHandle(): ?string
    {
        return self::$collectionHandle;
    }

    /**
     * Clear the current collection handle.
     */
    public static function clearCollectionHandle(): void
    {
        self::$collectionHandle = null;
    }

    /**
     * Set the current Statamic entry ID.
     *
     * @param  ?string  $statamicEntryId  The current Statamic entry ID
     */
    public static function setStatamicEntryId(
        ?string $statamicEntryId
    ): void {
        self::$statamicEntryId = $statamicEntryId;
    }

    /**
     * Get the current Statamic entry ID.
     *
     * @return ?string The current Statamic entry ID
     */
    public static function getStatamicEntryId(): ?string
    {
        return self::$statamicEntryId;
    }

    /**
     * Clear the current Statamic entry ID.
     */
    public static function clearStatamicEntryId(): void
    {
        self::$statamicEntryId = null;
    }

    /**
     * Set the current attachments array.
     *
     * @param  array<int|string, mixed>|null  $attachments  The attachments array
     */
    public static function setAttachments(
        ?array $attachments
    ): void {
        self::$attachments = $attachments;
    }

    /**
     * Get the current attachments array.
     *
     * @return array<int|string, mixed>|null The attachments array
     */
    public static function getAttachments(): ?array
    {
        return self::$attachments;
    }

    /**
     * Clear the current attachments array.
     */
    public static function clearAttachments(): void
    {
        self::$attachments = null;
    }

    /**
     * Form plugin configuration context for log messages (Gravity Forms, Formidable, etc.).
     *
     * When set, log messages use "from {plugin} form {id} ({source}: {name})" instead of
     * "from entry: {url}". Keys: form_id, source ('notification'|'view'|'confirmation'),
     * source_name; optional 'plugin' (e.g., 'Gravity Forms') improves the message.
     *
     * @var ?array{form_id: int|string, source: string, source_name: string, plugin?: string} Form config context or null
     */
    private static ?array $formConfigContext = null;

    /**
     * Set the current form plugin configuration context for log messages.
     *
     * When processing form notifications, views, or confirmations, set this so
     * warnings indicate the form and notification/view/confirmation instead of
     * the current entry URL.
     *
     * @param  array{form_id: int|string, source: string, source_name: string, plugin?: string}  $context  Form id, source, source_name, optional plugin name
     */
    public static function setFormConfigContext(
        array $context
    ): void {
        self::$formConfigContext = $context;
    }

    /**
     * Get the current form plugin configuration context.
     *
     * @return ?array{form_id: int|string, source: string, source_name: string, plugin?: string} Form config context or null
     */
    public static function getFormConfigContext(): ?array
    {
        return self::$formConfigContext;
    }

    /**
     * Clear the current Gravity Forms form configuration context.
     */
    public static function clearFormConfigContext(): void
    {
        self::$formConfigContext = null;
    }

    /**
     * Set the current WordPress post ID.
     *
     * @param  ?string  $wordPressPostId  The WordPress post ID
     */
    public static function setWordPressPostId(
        ?string $wordPressPostId
    ): void {
        self::$wordPressPostId = $wordPressPostId;
    }

    /**
     * Get the current WordPress post ID.
     *
     * @return ?string The WordPress post ID
     */
    public static function getWordPressPostId(): ?string
    {
        return self::$wordPressPostId;
    }

    /**
     * Clear the current WordPress post ID.
     */
    public static function clearWordPressPostId(): void
    {
        self::$wordPressPostId = null;
    }

    /**
     * Set the current shortcode content.
     *
     * @param  ?string  $shortcodeContent  The shortcode content
     */
    public static function setShortcodeContent(
        ?string $shortcodeContent
    ): void {
        self::$shortcodeContent = $shortcodeContent;
    }

    /**
     * Get the current shortcode content.
     *
     * @return ?string The shortcode content
     */
    public static function getShortcodeContent(): ?string
    {
        return self::$shortcodeContent;
    }

    /**
     * Clear the current shortcode content.
     */
    public static function clearShortcodeContent(): void
    {
        self::$shortcodeContent = null;
    }

    /**
     * Set the current audio series configuration.
     *
     * @param  ?AudioSeriesConfig  $audioSeriesConfig  The audio series configuration
     */
    public static function setAudioSeriesConfig(
        ?AudioSeriesConfig $audioSeriesConfig
    ): void {
        self::$audioSeriesConfig = $audioSeriesConfig;
    }

    /**
     * Get the current audio series configuration.
     *
     * @return ?AudioSeriesConfig The audio series configuration
     */
    public static function getAudioSeriesConfig(): ?AudioSeriesConfig
    {
        return self::$audioSeriesConfig;
    }

    /**
     * Clear the current audio series configuration.
     */
    public static function clearAudioSeriesConfig(): void
    {
        self::$audioSeriesConfig = null;
    }

    /**
     * Set the current WordPress post date.
     *
     * @param  ?string  $wordPressPostDate  The WordPress post date
     */
    public static function setWordPressPostDate(
        ?string $wordPressPostDate
    ): void {
        self::$wordPressPostDate = $wordPressPostDate;
    }

    /**
     * Get the current WordPress post date.
     *
     * @return ?string The WordPress post date
     */
    public static function getWordPressPostDate(): ?string
    {
        return self::$wordPressPostDate;
    }

    /**
     * Clear the current WordPress post date.
     */
    public static function clearWordPressPostDate(): void
    {
        self::$wordPressPostDate = null;
    }

    /**
     * Set the current WordPress post title.
     *
     * @param  ?string  $wordPressPostTitle  The WordPress post title
     */
    public static function setWordPressPostTitle(
        ?string $wordPressPostTitle
    ): void {
        self::$wordPressPostTitle = $wordPressPostTitle;
    }

    /**
     * Get the current WordPress post title.
     *
     * @return ?string The WordPress post title
     */
    public static function getWordPressPostTitle(): ?string
    {
        return self::$wordPressPostTitle;
    }

    /**
     * Clear the current WordPress post title.
     */
    public static function clearWordPressPostTitle(): void
    {
        self::$wordPressPostTitle = null;
    }

    /**
     * Set the current segment title.
     *
     * @param  ?string  $segmentTitle  The segment title
     */
    public static function setSegmentTitle(
        ?string $segmentTitle
    ): void {
        self::$segmentTitle = $segmentTitle;
    }

    /**
     * Get the current segment title.
     *
     * @return ?string The segment title
     */
    public static function getSegmentTitle(): ?string
    {
        return self::$segmentTitle;
    }

    /**
     * Clear the current segment title.
     */
    public static function clearSegmentTitle(): void
    {
        self::$segmentTitle = null;
    }

    /**
     * Set the current audio asset title.
     *
     * @param  ?string  $audioAssetTitle  The audio asset title
     */
    public static function setAudioAssetTitle(
        ?string $audioAssetTitle
    ): void {
        self::$audioAssetTitle = $audioAssetTitle;
    }

    /**
     * Get the current audio asset title.
     *
     * @return ?string The audio asset title
     */
    public static function getAudioAssetTitle(): ?string
    {
        return self::$audioAssetTitle;
    }

    /**
     * Clear the current audio asset title.
     */
    public static function clearAudioAssetTitle(): void
    {
        self::$audioAssetTitle = null;
    }

    /**
     * Set the current audio asset series value.
     *
     * @param  ?string  $audioAssetSeries  The audio asset series value
     */
    public static function setAudioAssetSeries(
        ?string $audioAssetSeries
    ): void {
        self::$audioAssetSeries = $audioAssetSeries;
    }

    /**
     * Get the current audio asset series value.
     *
     * @return ?string The audio asset series value
     */
    public static function getAudioAssetSeries(): ?string
    {
        return self::$audioAssetSeries;
    }

    /**
     * Clear the current audio asset series value.
     */
    public static function clearAudioAssetSeries(): void
    {
        self::$audioAssetSeries = null;
    }

    /**
     * Set the current audio asset excerpt.
     *
     * @param  ?array<int, array<int|string, mixed>>  $audioAssetExcerpt  The Bard excerpt content
     */
    public static function setAudioAssetExcerpt(
        ?array $audioAssetExcerpt
    ): void {
        self::$audioAssetExcerpt = $audioAssetExcerpt;
    }

    /**
     * Get the current audio asset excerpt.
     *
     * @return ?array<int, array<int|string, mixed>> The Bard excerpt content
     */
    public static function getAudioAssetExcerpt(): ?array
    {
        return self::$audioAssetExcerpt;
    }

    /**
     * Clear the current audio asset excerpt.
     */
    public static function clearAudioAssetExcerpt(): void
    {
        self::$audioAssetExcerpt = null;
    }

    /**
     * Set the current audio asset transcript.
     *
     * @param  ?array<int, array<int|string, mixed>>  $audioAssetTranscript  The Bard transcript content
     */
    public static function setAudioAssetTranscript(
        ?array $audioAssetTranscript
    ): void {
        self::$audioAssetTranscript = $audioAssetTranscript;
    }

    /**
     * Get the current audio asset transcript.
     *
     * @return ?array<int, array<int|string, mixed>> The Bard transcript content
     */
    public static function getAudioAssetTranscript(): ?array
    {
        return self::$audioAssetTranscript;
    }

    /**
     * Clear the current audio asset transcript.
     */
    public static function clearAudioAssetTranscript(): void
    {
        self::$audioAssetTranscript = null;
    }

    /**
     * Set the current MinervaKB settings.
     *
     * @param  array<int|string, mixed>|null  $minervaKbSettings  The MinervaKB settings array
     */
    public static function setMinervaKbSettings(
        ?array $minervaKbSettings
    ): void {
        self::$minervaKbSettings = $minervaKbSettings;
    }

    /**
     * Get the current MinervaKB settings.
     *
     * @return array<int|string, mixed>|null The MinervaKB settings array
     */
    public static function getMinervaKbSettings(): ?array
    {
        return self::$minervaKbSettings;
    }

    /**
     * Clear the current MinervaKB settings.
     */
    public static function clearMinervaKbSettings(): void
    {
        self::$minervaKbSettings = null;
    }

    /**
     * Set the current knowledge base entry ID.
     *
     * @param  ?string  $knowledgeBaseId  The knowledge base entry ID
     */
    public static function setKnowledgeBaseId(
        ?string $knowledgeBaseId
    ): void {
        self::$knowledgeBaseId = $knowledgeBaseId;
    }

    /**
     * Get the current knowledge base entry ID.
     *
     * @return ?string The knowledge base entry ID
     */
    public static function getKnowledgeBaseId(): ?string
    {
        return self::$knowledgeBaseId;
    }

    /**
     * Clear the current knowledge base entry ID.
     */
    public static function clearKnowledgeBaseId(): void
    {
        self::$knowledgeBaseId = null;
    }

    /**
     * Set shortcode processing context data.
     *
     * @param  array<int|string, mixed>|null  $attachments  The attachments array
     * @param  ?string  $shortcodeContent  The shortcode content
     * @param  ?string  $pageId  The page ID
     */
    public static function setShortcodeContext(
        ?array $attachments = null,
        ?string $shortcodeContent = null,
        ?string $pageId = null
    ): void {
        // Set attachments if provided
        if ($attachments !== null) {
            self::setAttachments($attachments);
        }

        // Set shortcode content if provided
        if ($shortcodeContent !== null) {
            self::setShortcodeContent($shortcodeContent);
        }

        // Set WordPress post ID if provided
        if ($pageId !== null) {
            self::setWordPressPostId($pageId);
        }
    }
}
