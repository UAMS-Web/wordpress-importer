<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators;

use Carbon\Carbon;
use SimpleXMLElement;
use Statamic\Entries\Entry;
use Statamic\Facades\Term as TermFacade;
use Statamic\Taxonomies\LocalizedTerm;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\ContentStreamFactory;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\FileProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportProgress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Creates Statamic `classifieds` entries for WordPress `advert` posts (WP Adverts).
 *
 * The destination was built by `UAMS-Web/uams-statamic#2096`, whose "Destination
 * handles" table is the specification this class maps to:
 *
 *   - `post_title` / `post_content` become `title` / `description`
 *   - `advert_category` becomes the flat `classified_categories` taxonomy, all
 *     declared terms imported whether or not a listing uses them
 *   - `adverts_price` becomes the float `price`; `adverts_person`,
 *     `adverts_email`, `adverts_phone` and `adverts_location` become
 *     `contact_name`, `contact_email`, `contact_phone` and `location`
 *   - `_expiration_date` (Unix time) becomes `expiration_date` as a `Y-m-d` date,
 *     read in `America/Chicago`, the calendar the board expires listings by
 *   - `_thumbnail_id` becomes `featured_image`, and `_adverts_attachments_order`
 *     becomes `gallery` in its stored order, less the featured image
 *   - status `publish` is published; `expired` and `draft` are unpublished, and so
 *     is a published listing WP-Members gates (`_wpmem_block` `1` or `2`, wordpress-importer-archive#1241)
 *
 * `account` is left empty: WP Adverts never bound a listing to a WordPress user
 * (`_adverts_account` is empty on every listing in the export), so there is no
 * account to attach.
 *
 * The featured image is left out of the gallery because the host templates render
 * the two separately, one above the other; a listing whose featured image also led
 * its gallery would show it twice. The listing still carries every image it had.
 */
class AdvertEntryCreator extends BaseEntryCreator
{
    /**
     * The WordPress post type this creator imports.
     */
    public const string POST_TYPE = 'advert';

    /**
     * The WordPress taxonomy WP Adverts files listings under.
     */
    public const string CATEGORY_TAXONOMY = 'advert_category';

    /**
     * The timezone an expiration timestamp is read in.
     *
     * The board expires a listing the day after its expiration date "by the
     * calendar in Little Rock", per the blueprint's instructions, so the stored
     * date is that calendar's date for the timestamp rather than UTC's.
     */
    public const string EXPIRATION_TIMEZONE = 'America/Chicago';

    /**
     * Convert a WP Adverts expiration timestamp to the date the board stores.
     *
     * @param  string  $timestamp  The raw `_expiration_date` meta value (Unix seconds)
     * @return ?string The date as `Y-m-d`, or null when the value is not a timestamp
     */
    public static function expirationDateFromTimestamp(
        string $timestamp
    ): ?string {
        // Accept only a plain run of digits; anything else is not a Unix timestamp
        $timestamp = \trim($timestamp);

        if (\preg_match('/^\d+$/', $timestamp) !== 1) {
            return null;
        }

        // Read the instant on the board's own calendar
        return Carbon::createFromTimestamp(
            // Carbon takes a numeric string as readily as an int, so the cast states intent
            // @pest-mutate-ignore: RemoveIntegerCast
            (int) $timestamp,
            self::EXPIRATION_TIMEZONE
        )->format('Y-m-d');
    }

    /**
     * Convert a WP Adverts price to the number the `price` field stores.
     *
     * WP Adverts stores the price as free text. Every price in the classifieds
     * export is a plain number, but the field is unconstrained, so a value that is
     * not numeric is reported rather than guessed at.
     *
     * @param  string  $price  The raw `adverts_price` meta value
     * @return ?float The price, or null when there is none or it is not numeric
     */
    public static function priceFromText(
        string $price
    ): ?float {
        // Only a number converts. `is_numeric()` already refuses an empty string and
        // accepts surrounding whitespace, so neither needs handling here; the caller
        // reports a non-empty value that did not convert.
        if (! \is_numeric($price)) {
            return null;
        }

        // Without strict types PHP coerces a numeric string to the declared ?float on
        // return, so the cast states intent rather than changing the result
        // @pest-mutate-ignore: RemoveDoubleCast
        return (float) $price;
    }

    /**
     * Resolve the gallery's attachment ids from WP Adverts' stored order.
     *
     * `_adverts_attachments_order` is a JSON array of attachment ids in the order
     * the poster arranged them. The featured image is removed wherever it falls,
     * because it renders separately; every other id keeps its relative position.
     *
     * @param  string  $storedOrder  The raw `_adverts_attachments_order` meta value
     * @param  string  $thumbnailId  The listing's `_thumbnail_id`, or '' when it has none
     * @return list<string> The gallery attachment ids, in stored order
     */
    public static function galleryAttachmentIds(
        string $storedOrder,
        string $thumbnailId
    ): array {
        // Decode the stored order, which WP Adverts writes as a JSON array. An empty or
        // malformed value decodes to null, and an object is read for its values.
        $decoded = \json_decode($storedOrder, true);

        // A scalar has no ids. Without this return `foreach` warns on it and the
        // result is still an empty gallery, so the mutant is equivalent in value.
        if (! \is_array($decoded)) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return [];
        }

        // Keep each scalar id once, in order, skipping the featured image
        $ids = [];
        $seen = [];

        foreach ($decoded as $id) {
            if (! \is_int($id) && ! \is_string($id)) {
                continue;
            }

            // Without strict types `trim()` accepts the int as-is, so the cast states intent
            // @pest-mutate-ignore: RemoveStringCast
            $id = \trim((string) $id);

            if (
                $id === ''
                || $id === $thumbnailId
                || isset($seen[$id])
            ) {
                continue;
            }

            // `isset()` is true for any non-null marker, so the value is immaterial
            // @pest-mutate-ignore: TrueToFalse
            $seen[$id] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Creates Statamic entries for WordPress `advert` posts.
     *
     * Imports the `advert_category` terms first, so every listing's categories
     * resolve, then streams each listing, maps its fields, imports its images and
     * saves it. Status is passed to the save explicitly, because the classified
     * blueprint carries no `status_and_visibility` field for it to be inferred from.
     *
     * @param  string  $site  The Statamic site handle where entries will be created
     * @param  string  $collectionHandle  The collection handle for the entries
     * @param  XmlParser  $xmlParser  Parser instance for accessing WordPress XML data
     * @param  AssetImporter  $assetImporter  Service for importing and processing assets
     * @param  OutputInterface  $output  Console output interface for progress logging
     * @param  MetadataMapper  $metadataMapper  Service for mapping WordPress metadata (unused: the blueprint carries no SEO fields)
     * @param  ContentProcessor  $contentProcessor  Service for converting the listing body to Bard
     * @param  array<int|string, mixed>|null  $minervaKbSettings  Unused; part of the shared signature
     * @return array<int|string, mixed> Array of page data containing entry relationships and metadata
     */
    public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        MetadataMapper $metadataMapper,
        ContentProcessor $contentProcessor,
        ?array $minervaKbSettings = null
    ): array {
        // Import every declared category before any listing references one
        $this->initializeTerms(
            $xmlParser,
            [self::CATEGORY_TAXONOMY],
            $site,
            $output
        );

        // Open a streaming parser spanning every part of the export
        $xmlStreamParser = ContentStreamFactory::open(
            $xmlParser,
            'AdvertEntryCreator::createEntries'
        );

        // Collect the attachments the featured images and galleries resolve against
        $attachments = [];

        foreach ($xmlStreamParser->streamItems('attachment') as $attachment) {
            $attachments[] = $attachment;
        }

        // Import each listing
        foreach (ImportProgress::track($output, self::POST_TYPE, $xmlStreamParser->streamItems(self::POST_TYPE), $xmlStreamParser->countItems(self::POST_TYPE)) as $advert) {
            $this->importAdvert(
                $advert,
                $site,
                $collectionHandle,
                $xmlParser,
                $assetImporter,
                $output,
                $contentProcessor,
                $attachments
            );

            // Release the listing before the next one in this long-running loop
            unset($advert);

            // Memory only; nothing observable depends on when cycles are collected
            // @pest-mutate-ignore: RemoveFunctionCall
            gc_collect_cycles();
        }

        // Free the attachment list once every listing has been imported
        unset($attachments);

        // Give the categories plain titles and their descriptions
        $this->finishCategoryTerms(
            $xmlParser,
            $site,
            $output
        );

        return $this->pageData;
    }

    /**
     * Import one WP Adverts listing.
     *
     * @param  SimpleXMLElement  $advert  The listing's `<item>` element
     * @param  string  $site  The Statamic site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  XmlParser  $xmlParser  Parser instance for accessing WordPress XML data
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  OutputInterface  $output  Console output
     * @param  ContentProcessor  $contentProcessor  Service for converting the listing body to Bard
     * @param  array<int|string, mixed>  $attachments  Attachment `<item>` elements from the export
     */
    private function importAdvert(
        SimpleXMLElement $advert,
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ContentProcessor $contentProcessor,
        array $attachments
    ): void {
        // Read the listing's identity and dates
        // `strip()` decodes entities itself, so the title is decoded exactly once
        $title = HtmlTagStripper::strip(
            // A SimpleXMLElement converts to its text on a string parameter regardless
            // @pest-mutate-ignore: RemoveStringCast
            (string) $advert->title
        );
        $slug = XpathHelper::getStringValue($advert, 'wp:post_name');
        $postId = XpathHelper::getStringValue($advert, 'wp:post_id');
        $status = XpathHelper::getStringValue($advert, 'wp:status');
        $postDate = $this->extractPostDateString($advert);
        $link = (string) $advert->link;

        // Record the listing's URL for accurate log context
        ProcessingContext::setWordPressPageLink($link);

        // Generate the entry slug and id
        [$entrySlug, $entryId] = $this->generateSlugAndId(
            $slug,
            $postId,
            $title
        );

        // Honor "Skip matching files" before doing any work on the listing
        if (
            $this->findExistingEntry($collectionHandle, $site, $entryId, $entrySlug) instanceof Entry
            && $this->entryOverwriteBehavior === 'Skip matching files'
        ) {
            LinkProcessor::registerUrlMapping($link, $postId);
            $output->writeln("<comment>Skipped entry '$entrySlug' as it already exists.</comment>");

            return;
        }

        $output->writeln("Processing advert '$entrySlug'...");

        // Map the listing's own fields
        $data = [
            'blueprint' => 'classified',
            'id' => $entryId,
            'title' => $title,
            'classified_categories' => $this->resolveCategories($advert),
            'price' => $this->mapPrice($advert, $entrySlug, $site),
            'contact_name' => $this->meta($advert, 'adverts_person'),
            'contact_email' => $this->meta($advert, 'adverts_email'),
            'contact_phone' => $this->meta($advert, 'adverts_phone'),
            'location' => $this->meta($advert, 'adverts_location'),
            'expiration_date' => $this->mapExpirationDate($advert, $entrySlug, $site),
            'original_wordpress_url' => $link,
            // Loose keys the migration redirect generator reads (wordpress-importer-archive#446)
            ...XpathHelper::extractMigrationRedirectMetadata($advert),
        ];

        // Drop contact fields the listing left blank rather than storing empty strings
        foreach (['contact_phone', 'location'] as $optional) {
            if ($data[$optional] === '') {
                unset($data[$optional]);
            }
        }

        // Import the featured image and the gallery
        $this->mapImages(
            $advert,
            $data,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $link
        );

        // Convert the listing body to Bard for the set-less `description` field
        $parsedContent = $contentProcessor->process(
            XpathHelper::getStringValue($advert, 'content:encoded'),
            $site,
            $assetImporter,
            $output,
            $xmlParser,
            $title !== '' ? $title : 'Untitled Advert',
            $postId,
            $link,
            $attachments,
            $xmlParser->getTerms(),
            [],
            $data,
            $collectionHandle,
            $entrySlug,
            $entryId
        );
        $description = $parsedContent['content'] ?? [];

        if (\is_array($description) && ! $this->isEmptyContent($description)) {
            $data['description'] = $description;
        }

        // Record when WordPress last modified the listing
        $this->addUpdatedAtField(
            $data,
            $advert,
            'AdvertEntryCreator::importAdvert'
        );

        // Register the listing's URL for internal link resolution
        LinkProcessor::registerUrlMapping($link, $postId);

        // Save, publishing only listings that are live in WordPress and not gated by WP-Members (wordpress-importer-archive#1241)
        $entry = $this->saveEntry(
            $collectionHandle,
            $site,
            $entrySlug,
            $data,
            $output,
            // No explicit date: the collection is dated, so `saveEntry()` dates the
            // entry from the WordPress post date passed last
            null,
            $this->publishUnlessWpMembersGated(
                $status === 'publish',
                $this->readWpMembersBlock($advert),
                'AdvertEntryCreator::importAdvert',
                $output
            ),
            $postDate
        );

        if ($entry instanceof Entry) {
            $this->addPageData(
                $postId,
                $entryId,
                '0',
                0,
                $title !== '' ? $title : 'Untitled Advert',
                null,
                $entry
            );
        }
    }

    /**
     * Resolve a listing's categories to the terms the term pass created.
     *
     * The term pass records each category's destination against its WordPress term
     * id, so a listing's category nicenames resolve through that id to the
     * site-prefixed slug that was actually written. A category that does not
     * resolve is omitted rather than written as a slug naming no term.
     *
     * @param  SimpleXMLElement  $advert  The listing's `<item>` element
     * @return list<string> The `classified_categories` term slugs, in document order
     */
    private function resolveCategories(
        SimpleXMLElement $advert
    ): array {
        // Read the nicename-to-term-id map the term pass built
        $termIds = $this->nicenameToTermIdMap[self::CATEGORY_TAXONOMY] ?? [];

        if (! \is_array($termIds)) {
            return [];
        }

        $slugs = [];
        $seen = [];

        foreach (XpathHelper::xpathElements($advert, 'category[@domain="'.self::CATEGORY_TAXONOMY.'"]/@nicename') as $nicename) {
            // Resolve the nicename to its term id, then to the written slug
            $termId = $termIds[(string) $nicename] ?? null;

            if (! \is_scalar($termId)) {
                continue;
            }

            $slug = TermDestinationRegistry::referenceFor(
                self::CATEGORY_TAXONOMY,
                // The registry takes an int id as readily as a string one
                // @pest-mutate-ignore: RemoveStringCast
                (string) $termId
            );

            // ---
            // The term pass records a destination for every term id the map holds, so
            // a resolved id always yields a slug; the type test narrows it for the
            // analyser. Each slug is kept once, by key, since two nicenames can name
            // one term in a malformed export.
            // ---
            // @pest-mutate-ignore: BooleanOrToBooleanAnd
            if (! \is_string($slug) || isset($seen[$slug])) {
                continue;
            }

            // `isset()` is true for any non-null marker, so the value is immaterial
            // @pest-mutate-ignore: TrueToFalse
            $seen[$slug] = true;
            $slugs[] = $slug;
        }

        return $slugs;
    }

    /**
     * Map a listing's price, reporting one that is not a number.
     *
     * @param  SimpleXMLElement  $advert  The listing's `<item>` element
     * @param  string  $entrySlug  The entry slug, for the report
     * @param  string  $site  The Statamic site handle, for the report
     * @return ?float The price, or null when there is none
     */
    private function mapPrice(
        SimpleXMLElement $advert,
        string $entrySlug,
        string $site
    ): ?float {
        $raw = $this->meta($advert, 'adverts_price');
        $price = self::priceFromText($raw);

        // A non-empty price that did not convert is reported, not silently dropped
        if ($price === null && \trim($raw) !== '') {
            ImportWarningManager::addWarning(
                'content',
                'unconverted_advert_prices',
                [
                    'entry' => $entrySlug,
                    'site' => $site,
                    'value' => $raw,
                ]
            );
        }

        return $price;
    }

    /**
     * Map a listing's expiration timestamp, reporting one that is not a timestamp.
     *
     * @param  SimpleXMLElement  $advert  The listing's `<item>` element
     * @param  string  $entrySlug  The entry slug, for the report
     * @param  string  $site  The Statamic site handle, for the report
     * @return ?string The expiration date as `Y-m-d`, or null when there is none
     */
    private function mapExpirationDate(
        SimpleXMLElement $advert,
        string $entrySlug,
        string $site
    ): ?string {
        $raw = $this->meta($advert, '_expiration_date');
        $date = self::expirationDateFromTimestamp($raw);

        // The blueprint requires the date, so a missing one is reported
        if ($date === null) {
            ImportWarningManager::addWarning(
                'content',
                'unconverted_advert_expiration_dates',
                [
                    'entry' => $entrySlug,
                    'site' => $site,
                    'value' => $raw,
                ]
            );
        }

        return $date;
    }

    /**
     * Import a listing's featured image and gallery into the entry data.
     *
     * An image whose attachment is missing from the export, or that fails to
     * import, is left out; the rest keep their order.
     *
     * @param  SimpleXMLElement  $advert  The listing's `<item>` element
     * @param  array<int|string, mixed>  &$data  The entry data to populate
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  OutputInterface  $output  Console output
     * @param  array<int|string, mixed>  $attachments  Attachment `<item>` elements from the export
     * @param  string  $link  The listing's WordPress URL, for log context
     */
    private function mapImages(
        SimpleXMLElement $advert,
        array &$data,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        string $link
    ): void {
        $thumbnailId = $this->meta($advert, '_thumbnail_id');

        // Import the featured image; the importer returns null for a listing without one
        $featuredImage = FileProcessor::importFileFromAttachmentWithDisabledUploadHandling(
            $thumbnailId,
            $attachments,
            $site,
            $assetImporter,
            $output,
            'images',
            'featured image',
            'AdvertEntryCreator::mapImages',
            $link
        );

        if ($featuredImage !== null && $featuredImage !== '') {
            $data['featured_image'] = $featuredImage;
        }

        // Import the gallery in its stored order
        $gallery = [];

        foreach (self::galleryAttachmentIds($this->meta($advert, '_adverts_attachments_order'), $thumbnailId) as $attachmentId) {
            $image = FileProcessor::importFileFromAttachmentWithDisabledUploadHandling(
                $attachmentId,
                $attachments,
                $site,
                $assetImporter,
                $output,
                'images',
                'gallery image',
                'AdvertEntryCreator::mapImages',
                $link
            );

            if ($image !== null && $image !== '') {
                $gallery[] = $image;
            }
        }

        if ($gallery !== []) {
            $data['gallery'] = $gallery;
        }
    }

    /**
     * Finish the category terms the flat term pass wrote: plain titles and descriptions.
     *
     * `createTaxonomyTermsFromStoredData()` titles every flat term
     * `"{site name} — {term name}"`, copies the name exactly as the export stores it,
     * and writes nothing but the title. For this taxonomy all three are wrong:
     *
     *   - `classified_categories` is enabled on one site only, so the prefix
     *     distinguishes nothing, and the board's card and listing templates print
     *     the title as it stands. Every listing would read
     *     "Classified Ads (Inside UAMS) — General". Decided on wordpress-importer-archive#774: plain titles.
     *   - WordPress stores `&` in a term name as `&amp;`, and the templates escape
     *     the title again, so `Auto &amp; Marine` would render literally.
     *   - Two categories carry the rules for claiming reallocated UAMS property in
     *     their descriptions, and the `classified_category` term blueprint has a
     *     `description` field for exactly that.
     *
     * A title is reset only while it is still the one the importer wrote, and a
     * description only while the term has none, so an editor's change survives a
     * re-import.
     *
     * @param  XmlParser  $xmlParser  Parser instance for accessing WordPress XML data
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output
     */
    private function finishCategoryTerms(
        XmlParser $xmlParser,
        string $site,
        OutputInterface $output
    ): void {
        // ---
        // Read the terms from disk, not from the index. The term pass saved them with
        // index updating paused and rebuilt the store, but the listings saved since
        // leave it indexing only the terms some listing uses. A category no listing
        // uses (22 of the export's 23 are used) would otherwise be skipped here.
        // ---
        $this->rebuildTermStoreFromDisk('classified_categories');

        foreach (ImportProgress::track($output, 'advert category terms finished', $xmlParser->getTerms()) as $wpTerm) {
            // Consider only WP Adverts categories. `getTerms()` returns only elements, so
            // the type test is for the analyser, and WordPress term ids are unique across
            // taxonomies, so another taxonomy's term never resolves as an advert category.
            // @pest-mutate-ignore: InstanceOfToTrue, BooleanOrToBooleanAnd
            if (
                ! $wpTerm instanceof SimpleXMLElement
                || XpathHelper::getStringValue($wpTerm, 'wp:term_taxonomy') !== self::CATEGORY_TAXONOMY
            ) {
                continue;
            }

            // Find the term the term pass wrote for this category
            $destination = TermDestinationRegistry::resolve(
                self::CATEGORY_TAXONOMY,
                XpathHelper::getStringValue($wpTerm, 'wp:term_id')
            );

            if ($destination === null) {
                continue;
            }

            $term = TermFacade::find($destination['handle'].'::'.$destination['reference']);

            // A recorded destination was written to disk and the store rebuilt from it,
            // so the lookup resolves; the type test narrows it for the analyser
            // @pest-mutate-ignore: InstanceOfToTrue
            if (! $term instanceof LocalizedTerm) {
                continue;
            }

            // Work on the term as it reads in the site being imported
            $localized = $term->in($site);

            // `in()` on a term of this site returns the localized term; narrowed for the analyser
            // @pest-mutate-ignore: InstanceOfToTrue
            if (! $localized instanceof LocalizedTerm) {
                continue;
            }

            $changed = false;

            // Replace the importer's prefixed, still-encoded title with the plain name
            $storedName = XpathHelper::getStringValue($wpTerm, 'wp:term_name');
            $plainName = \trim(html_entity_decode($storedName, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = $localized->get('title');
            // A saved term's title is always a string; the fallback is for the analyser
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $title = \is_string($title) ? $title : '';

            // WordPress refuses a term without a name, so `$plainName` is never empty and
            // the first conjunct, and any rewiring of it, cannot change the outcome
            // @pest-mutate-ignore: EmptyStringToNotEmpty, BooleanAndToBooleanOr
            if (
                $plainName !== ''
                && $title !== $plainName
                && (
                    $title === ''
                    || $title === $storedName
                    || \str_ends_with($title, ' — '.$storedName)
                )
            ) {
                $localized->set('title', $plainName);
                $changed = true;
            }

            // Write the description WordPress carries, unless the term already has one
            $description = \trim(XpathHelper::getStringValue($wpTerm, 'wp:term_description'));
            $current = $localized->get('description');

            if (
                $description !== ''
                && (! \is_string($current) || \trim($current) === '')
            ) {
                $localized->set('description', $description);
                $changed = true;
            }

            if ($changed) {
                $localized->save();
                $output->writeln("  - Finished category '{$destination['reference']}' as '{$plainName}'.");
            }
        }
    }

    /**
     * Read one postmeta value from a listing.
     *
     * @param  SimpleXMLElement  $advert  The listing's `<item>` element
     * @param  string  $key  The meta key
     * @return string The value, or '' when the key is absent
     */
    private function meta(
        SimpleXMLElement $advert,
        string $key
    ): string {
        return XpathHelper::getStringValue(
            $advert,
            'wp:postmeta[wp:meta_key="'.$key.'"]/wp:meta_value'
        );
    }

    /**
     * Indicates whether this content type uses ShowPeople functionality.
     *
     * @return bool Always false; listings carry no ShowPeople shortcodes
     */
    public function hasShowPeople(): bool
    {
        return false;
    }

    /**
     * Returns nothing: listings have no post categories.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostCategories(): array
    {
        return [];
    }

    /**
     * Returns nothing: listings have no post tags.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Validates nothing further: every category term was created by the term pass.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output for logging
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {}
}
