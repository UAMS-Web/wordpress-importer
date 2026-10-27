<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi;

use SimpleXMLElement;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Globals\Creators\RedirectRuleGlobalCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;
use UamsWeb\WxrCleanup\Transform;
use XMLWriter;

/**
 * Writes migration API content out as WXR part files.
 *
 * **Why this materializes to disk rather than implementing the parser's interface.**
 * `XmlParser`'s seven public getters look like the seam, but they are a facade. Six entry
 * creators — `PostEntryCreator`, `PageEntryCreator`, `PeopleEntryCreator`, `KbEntryCreator`,
 * `TablePressEntryCreator` and `LogoListItemEntryCreator` — reach past the parser entirely,
 * opening their own stream over `getFilePaths()` and reading the files themselves.
 * Since `wordpress-importer-archive#662` they do that through `ContentStreamFactory::open()`, which raises
 * `EmptyContentSourceException` rather than short-circuiting — so a
 * source with no file paths imports zero posts and reports success.
 *
 * Producing real files makes every one of those bypasses work by construction rather than by
 * having found them all, and reuses the sanitizer, split-part ordering and memory-efficient
 * streaming that already exist. The rejected alternatives are recorded in `docs/DECISIONS.md`;
 * the interface extraction it deferred landed in wordpress-importer-archive#662.
 *
 * The payload shapes below are read from `UAMS-Web/uamswp-migration-api`'s `Content/*Payload`
 * classes, not inferred — `terms` arrives grouped by taxonomy, and `meta` maps each key to a
 * **list** of values, because `get_post_meta()` without a key returns every row.
 */
class WxrMaterializer
{
    /**
     * The sites whose exports are cleaned before import, by `base_blog_url` host (wordpress-importer-archive#1303).
     *
     * @var list<string>
     */
    public const CLEANED_SITES = ['news.uams.edu'];

    /**
     * The WXR namespace declarations every part carries.
     *
     * @var array<string, string>
     */
    private const array NAMESPACES = [
        'xmlns:excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
        'xmlns:content' => 'http://purl.org/rss/1.0/modules/content/',
        'xmlns:wfw' => 'http://wellformedweb.org/CommentAPI/',
        'xmlns:dc' => 'http://purl.org/dc/elements/1.1/',
        'xmlns:wp' => 'http://wordpress.org/export/1.2/',
    ];

    /**
     * Characters XML 1.0 cannot represent, less tab, newline and carriage return.
     *
     * Matches `WxrSanitizer`'s own C0 pattern. Writing these would produce a document the
     * sanitizer has to repair on the way back in; not writing them is cheaper and honest.
     */
    private const string ILLEGAL_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u';

    /**
     * The manifest a run writes beside its parts.
     *
     * Named so it sorts beside `wxr.000.xml` in a directory listing and cannot collide with
     * a part, whose name is always `wxr.%03d.xml`.
     */
    private const string MANIFEST_NAME = 'wxr.manifest.json';

    /**
     * The most ids or files one attachment request names.
     *
     * The API caps `file[]` at 100 paths (its D-085). `include[]` has no cap of its own, but
     * the same bound keeps each request to one page and its query string finite.
     */
    private const int REFERENCE_CHUNK = 100;

    /**
     * The most encoded query-string bytes one attachment request's references take.
     *
     * Leaves room under an 8 KB request line for the method, the route and the other
     * parameters. See `referenceChunks()`.
     */
    private const int REFERENCE_QUERY_BYTES = 6000;

    /**
     * How many unresolved references the manifest lists by name, beside their counts.
     */
    private const int REPORTED_EXAMPLES = 20;

    /**
     * Content that exists on the site but the API will not serve.
     *
     * @var list<array{kind: string, name: string, records: int}>
     */
    private array $unserved = [];

    /**
     * How a scoped run read its attachments, or null for an unscoped run (wordpress-importer-archive#1175).
     *
     * @var array{resolution: string, reason: string, ids_requested: int, paths_requested: int, records: int, unresolved_image_count: int, unresolved_images: list<string>, unresolved_link_count: int, unresolved_links: list<string>, unserved_ids: list<int>}|null
     */
    private ?array $attachmentResolution = null;

    /**
     * What this run read from each redirect plugin a vanity domain runs (wordpress-importer-archive#1307).
     *
     * One entry per plugin served on the site: how many rules the plugin serves, how many
     * were written as `redirect_rule` items, and what the rules carry that the redirect
     * domains collection cannot, each counted by the rules it affects.
     *
     * @var list<array{host: string, plugin: string, rules: int, written: int, unsupported: array<string, int>}>
     */
    private array $redirectPluginRules = [];

    /**
     * Create a materializer.
     *
     * @param  Client  $client  The migration API client
     * @param  string  $outputDirectory  Directory the part files are written to
     * @param  int  $itemsPerPart  Items per part before rotating to a new file
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $outputDirectory,
        private readonly int $itemsPerPart = 2000,
    ) {}

    /**
     * Fetch a site's content and write it as WXR part files.
     *
     * @param  int|null  $site  The network site id to read, when scoping is needed
     * @param  string|null  $modifiedAfter  Restrict to posts modified after this timestamp
     * @param  list<string>  $consumedOptions  Option names this run has already read, so a
     *                                         row that was consumed is not reported as stranded
     * @param  string|null  $contentType  The one post type this run imports, which narrows the
     *                                    read to it, its companions and their taxonomies. Null
     *                                    reads the whole site, exactly as before (wordpress-importer-archive#1089)
     * @return list<string> The written part paths, in ascending part order
     *
     * @throws MigrationApiException When the API fails or nothing could be written
     */
    public function materialize(
        ?int $site = null,
        ?string $modifiedAfter = null,
        array $consumedOptions = [],
        ?string $contentType = null
    ): array {
        $contentType = $contentType !== null && \trim($contentType) !== '' ? \trim($contentType) : null;
        $this->attachmentResolution = null;
        $this->redirectPluginRules = [];

        // Refuse an output directory that does not exist rather than resolving a relative
        // path against the current working directory, where the parts are indistinguishable
        // from repository files. This guards the *caller*; it does not stop a mutation run
        // from scattering parts, because those mutants corrupt `partPath()` rather than this
        // property — the `.gitignore` rule is what makes that output uncommittable.
        if (\trim($this->outputDirectory) === '' || ! \is_dir($this->outputDirectory)) {
            throw new MigrationApiException(\sprintf(
                'The output directory "%s" does not exist. Refusing to scatter materialized '
                .'parts into the working directory.',
                $this->outputDirectory
            ));
        }

        // Record the parts already in the destination BEFORE writing anything. A run
        // overwrites `wxr.000.xml` onwards, so a shorter second run leaves the first run's
        // higher-numbered parts behind looking current — naming them here is what makes that
        // accumulation legible without deleting anything.
        $preExisting = $this->existingParts();

        // Mark the run in progress before the first part is written. An interrupted run
        // leaves this file saying `in_progress`, which is what distinguishes it from a
        // complete one without reading any WXR content.
        $this->writeManifest('in_progress', $site, $modifiedAfter, $preExisting, [], 0, $contentType);

        // Read the channel metadata first; every part repeats it, so nothing can be written
        // until it is known.
        $channel = $this->channelFor($site);

        // Everything after this point is addressed to the target site's OWN REST root
        // rather than scoped with `?site=`. Measured against a live multisite: the two are
        // equivalent for data endpoints, and only the per-site root works for the
        // registration-derived ones — see `siteClientFor()` and wordpress-importer-archive#671.
        $client = $this->siteClientFor($channel, $site);

        $authors = $client->paginate('authors');
        $exposedPostTypes = $this->exposedPostTypes($client);

        // A vanity redirect domain is not a Statamic site, so its redirect rules declare fewer
        // reads than a site's do (#109).
        $baseBlogUrl = $channel['base_blog_url'] ?? null;
        $redirectDomain = \is_string($baseBlogUrl)
            && RedirectRuleGlobalCreator::redirectDomainHost($baseBlogUrl) !== null;

        // With no content type every exposed type is read, which is the behavior a full run
        // has always had. With one, only it and what it declares it reads besides itself.
        $companions = null;
        $linkTargets = null;
        $postTypes = $exposedPostTypes;

        if ($contentType !== null) {
            $companions = ContentTypeReads::companions($contentType, $redirectDomain);
            $linkTargets = ContentTypeReads::linkTargets($contentType, $redirectDomain);
            $postTypes = $this->scopedPostTypes($exposedPostTypes, $contentType, $companions, $linkTargets);
        }

        $terms = $this->termsByTaxonomy($client, $contentType === null ? null : $postTypes);

        // Compare against the FULL exposed list, not the scoped one. Scoping is a choice this
        // run made; a type it chose not to read is not content the API refuses to serve, and
        // reporting it as unserved would bury the real refusals under the run's own filter.
        $this->recordUnservedContent($client, $channel, $exposedPostTypes, $site, $consumedOptions);

        $paths = [];
        $writer = null;
        $itemsInPart = 0;
        $itemsWritten = 0;

        // A scoped run reads attachments by reference (wordpress-importer-archive#1175), so it reads them LAST: the
        // references come from the items of every other type, which have to be read first.
        // An unscoped run reads every type in the API's order, exactly as before.
        // The manifest keeps the API's order, which is what `post_types` has always reported.
        $collector = null;
        $scannedTypes = [];
        $itemLinks = [];
        $itemSlugs = [];
        $readOrder = $postTypes;

        if ($contentType !== null) {
            $readOrder = \array_merge(
                \array_filter($postTypes, static fn (string $type): bool => $type !== 'attachment'),
                \in_array('attachment', $postTypes, true) ? ['attachment'] : []
            );

            $collector = new AttachmentReferenceCollector(\is_string($baseBlogUrl) ? $baseBlogUrl : '');

            // The items whose attachments get imported: the requested type and the companions
            // it pulls content from. A link target read only so links resolve is not
            // imported, so its media is not needed.
            $scannedTypes = \array_merge(
                [$contentType],
                \array_diff($companions, ['attachment'])
            );
        }

        // Rotate to a new part once this one is full, so a multi-thousand-item export splits
        // the way a real one does rather than becoming one huge file.
        $write = function (array $post) use (&$writer, &$itemsInPart, &$itemsWritten, &$paths, $channel, $authors, $terms): void {
            if (! $writer instanceof XMLWriter || $itemsInPart >= $this->itemsPerPart) {
                if ($writer instanceof XMLWriter) {
                    $this->closePart($writer);
                }

                $paths[] = $this->partPath(\count($paths));
                $writer = $this->openPart(
                    $paths[\count($paths) - 1],
                    $channel,
                    $authors,
                    $terms
                );
                $itemsInPart = 0;
            }

            $this->writeItem($writer, $post);
            $itemsInPart++;
            $itemsWritten++;
        };

        // A vanity domain running Redirection or Pretty Links keeps its rules in the plugins'
        // own tables, so they are read from their endpoints and written as `redirect_rule`
        // items, which is the type the import reads a redirect domain's rules from (wordpress-importer-archive#1307).
        // Written before the post types so a scoped run collects their attachment references
        // before it reads attachments, which it reads last.
        foreach ($this->redirectPluginPosts($client, $channel, $contentType) as $post) {
            if ($collector instanceof AttachmentReferenceCollector) {
                $collector->collect($post);
            }

            $write($post);
        }

        foreach ($readOrder as $postType) {
            $query = $modifiedAfter === null ? [] : ['modified_after' => $modifiedAfter];

            $records = $collector instanceof AttachmentReferenceCollector && $postType === 'attachment'
                ? $this->attachmentsByReference($client, $collector, $itemLinks, $itemSlugs, $query, $channel)
                : $client->paginate('posts/'.$postType, null, $query);

            foreach ($records as $post) {
                if (! \is_array($post)) {
                    continue;
                }

                // Every item's permalink is kept so a link can be checked against what was
                // read, and the items whose media is imported are scanned for references.
                if ($collector instanceof AttachmentReferenceCollector) {
                    if (\is_string($post['link'] ?? null)) {
                        $itemLinks[AttachmentReferenceCollector::normalizeLink($post['link'])] = true;
                    }

                    // A draft's link is `?page_id=N` rather than its path, and the importer
                    // still resolves a link to it by slug, so those slugs are kept as well.
                    // Only an unpublished item's: `LinkProcessor`'s structure match discards
                    // published candidates, so a published post sharing a slug with an
                    // attachment would otherwise hide a link to that attachment's page.
                    if (
                        \is_string($post['slug'] ?? null)
                        && $post['slug'] !== ''
                        && ($post['status'] ?? null) !== 'publish'
                    ) {
                        $itemSlugs[\strtolower(\rawurldecode($post['slug']))] = true;
                    }

                    if (\in_array($postType, $scannedTypes, true)) {
                        $collector->collect($post);
                    }
                }

                $write($post);
            }
        }

        // A site with no items still needs a part. `getFilePaths()` would otherwise
        // return `[]`, and since wordpress-importer-archive#662 every creator opens through
        // `ContentStreamFactory::open()`, which raises on a source serving nothing —
        // so an empty materialization would abort the import rather than import an
        // honest zero. Before wordpress-importer-archive#662 the same case short-circuited to zero silently;
        // either way this guard is what keeps a legitimately empty site working.
        if (! $writer instanceof XMLWriter) {
            $paths[] = $this->partPath(0);
            $writer = $this->openPart($paths[0], $channel, $authors, $terms);
        }

        $this->closePart($writer);

        // A site whose export is cleaned before import gets the same cleaning here, so its
        // API-sourced posts reach the parser exactly as its cleaned export would (wordpress-importer-archive#1303). Only
        // listed sites: the cleaner strips every HTML comment, including the Gutenberg block
        // delimiters other sites' content is parsed from. It runs before the manifest says
        // `complete`, so an interrupted clean leaves the run reading unfinished.
        if (self::cleansSite($channel)) {
            $this->cleanParts($paths);
        }

        // Mark the run complete only once every part is closed. Until this line runs the
        // manifest still reads `in_progress`, so a crash or an interrupt anywhere above
        // leaves the artifact describing itself as unfinished.
        $this->writeManifest(
            'complete',
            $site,
            $modifiedAfter,
            $preExisting,
            $paths,
            $itemsWritten,
            $contentType,
            $contentType === null ? null : $postTypes,
            $linkTargets
        );

        // Tell the readers that look items up in this export what it covers, so a miss caused
        // by the scope is reported rather than read as a broken link (wordpress-importer-archive#1179). A whole-site
        // read records null, which is also what clears a previous run's scope.
        MaterializationScope::set($contentType === null ? null : $postTypes, $redirectDomain);

        return $paths;
    }

    /**
     * The post types a run scoped to one content type reads.
     *
     * The requested type, then the companions and link targets it declares in
     * `ContentTypeReads`, each kept only where the API exposes it, in the order the API lists
     * them.
     * Requesting an unexposed type 404s on every page, so a companion the site does not serve
     * is simply not read, exactly as a full run would not read it.
     *
     * @param  list<string>  $exposed  Every post type the API exposes
     * @param  string  $contentType  The requested content type
     * @param  list<string>  $companions  The companion post types the type declares
     * @param  list<string>  $linkTargets  The link-target post types the type declares
     * @return list<string> The post types to read
     */
    private function scopedPostTypes(
        array $exposed,
        string $contentType,
        array $companions,
        array $linkTargets
    ): array {
        $wanted = \array_merge(
            [$contentType],
            $companions,
            $linkTargets
        );

        return \array_values(\array_filter(
            $exposed,
            static fn (string $type): bool => \in_array($type, $wanted, true)
        ));
    }

    /**
     * The part files already present in the output directory.
     *
     * @return list<string> The part file names, sorted, without their directory
     */
    private function existingParts(): array
    {
        $names = [];

        $pattern = \rtrim($this->outputDirectory, \DIRECTORY_SEPARATOR)
            .\DIRECTORY_SEPARATOR
            .'wxr.[0-9][0-9][0-9].xml';

        // Match the exact shape `partPath()` writes rather than anything `.xml`, so an
        // unrelated file in the destination is never reported as a stale part.
        foreach ((array) \glob($pattern) as $path) {
            if (\is_string($path)) {
                $names[] = \basename($path);
            }
        }

        \sort($names);

        return $names;
    }

    /**
     * Write the run manifest, replacing any manifest already in the directory.
     *
     * @param  string  $status  Either `in_progress` or `complete`
     * @param  int|null  $site  The network site id this run was scoped to, if any
     * @param  string|null  $modifiedAfter  The modification cutoff this run was scoped to, if any
     * @param  list<string>  $preExisting  Part files present before this run started
     * @param  list<string>  $paths  The part paths this run has written so far
     * @param  int  $items  The number of items written so far
     * @param  string|null  $contentType  The content type this run was scoped to, if any
     * @param  list<string>|null  $postTypes  The post types a scoped run read, or null when
     *                                        the run was not scoped or has not resolved them yet
     * @param  list<string>|null  $linkTargets  The link targets a scoped run declared, or null
     *                                          when the run was not scoped or has not resolved them yet
     *
     * @throws MigrationApiException When the manifest cannot be written
     */
    private function writeManifest(
        string $status,
        ?int $site,
        ?string $modifiedAfter,
        array $preExisting,
        array $paths,
        int $items,
        ?string $contentType = null,
        ?array $postTypes = null,
        ?array $linkTargets = null
    ): void {
        $written = \array_map(
            \basename(...),
            $paths
        );

        // Parts left from an earlier run that this one did not rewrite. A reader diffing the
        // directory would otherwise treat these as part of the current artifact.
        $stale = $status === 'complete'
            ? \array_values(\array_diff($preExisting, $written))
            : [];

        $complete = $status === 'complete';

        $manifest = [
            'status' => $status,
            'scope' => [
                'site' => $site,
                'modified_after' => $modifiedAfter,
                'content_type' => $contentType,
                // What the artifact actually covers, stated rather than left to be inferred.
                // A scoped run's parts are not the site, and diffing them against a full
                // export under that assumption is the harm the label exists to prevent
                // (wordpress-importer-archive#1089). `site` means every exposed type was read.
                'coverage' => $contentType === null ? 'site' : 'content_type',
                'post_types' => $postTypes,
                // The link targets are a corpus measurement rather than a code dependency, so
                // they are named separately: a reader can then see which part of the closure
                // could go stale if the content changes (wordpress-importer-archive#1179). Each type
                // declares its own in `ContentTypeReads` (#109).
                'link_target_post_types' => $linkTargets,
                // Whether a scoped run's attachments are the ones its items reference or the
                // whole site's, and why (wordpress-importer-archive#1175). Null for an unscoped run, which reads them all.
                'attachments' => $this->attachmentResolution,
            ],
            // Null rather than an empty list while a run is in flight. Parts are written
            // after this file and never counted back into it, so `[]` here would read as
            // "no parts written" when the directory may already hold thousands — the same
            // conflation of silence with zero this repository guards everywhere else.
            'parts' => $complete ? $written : null,
            'items' => $complete ? $items : null,
            'pre_existing_parts' => $preExisting,
            'stale_parts' => $stale,
        ];

        $encoded = \json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new MigrationApiException(
                'Could not encode the materialization manifest.'
            );
        }

        $path = \rtrim($this->outputDirectory, \DIRECTORY_SEPARATOR)
            .\DIRECTORY_SEPARATOR
            .self::MANIFEST_NAME;

        // An unwritable manifest leaves the artifact unmarked, which is the defect this
        // exists to close — so it raises rather than continuing silently.
        if (\file_put_contents($path, $encoded.\PHP_EOL) === false) {
            throw new MigrationApiException(\sprintf(
                'Could not write the materialization manifest to "%s".',
                $path
            ));
        }
    }

    /**
     * How the last scoped run read its attachments, or null when it was not scoped.
     *
     * The same block the manifest records under `scope.attachments`.
     *
     * @return array{resolution: string, reason: string, ids_requested: int, paths_requested: int, records: int, unresolved_image_count: int, unresolved_images: list<string>, unresolved_link_count: int, unresolved_links: list<string>, unserved_ids: list<int>}|null
     */
    public function attachmentResolution(): ?array
    {
        return $this->attachmentResolution;
    }

    /**
     * Read a scoped run's attachments by what its items reference (wordpress-importer-archive#1175).
     *
     * **Narrowing is kept only when it can be shown to lose nothing.** After the referenced
     * ids and files are read, two checks decide it, and either one failing widens the read to
     * every attachment, as an unscoped run does:
     *
     *   images  every same-site upload image URL must resolve to a record read, through
     *           `XpathHelper::findAttachmentByUrl()`, the matcher the image pipeline itself
     *           uses. The pipeline prefers that match over a `wp-image-N` class, so an image
     *           it cannot resolve here could resolve to a different record in a full read
     *   links   every same-site permalink must match an item read, attachments included, by
     *           its link or, as `LinkProcessor`'s structure match does for a draft, by the
     *           slug its last segment names. An attachment's own page is a permalink like any
     *           page's, and cannot be picked out without the attachment items
     *
     * A read is also widened when the references would cost as many requests as reading
     * every attachment, which is correct by construction and no dearer.
     *
     * A reference in a named shape whose attachment the site does not serve is reported,
     * because narrowing is what would otherwise make it silent.
     *
     * @param  Client  $client  A client addressed to the site's own REST root
     * @param  AttachmentReferenceCollector  $collector  The references the run's items carry
     * @param  array<string, true>  $itemLinks  Normalized permalinks of every item read so far
     * @param  array<string, true>  $itemSlugs  Lowercased slugs of every item read so far
     * @param  array<string, scalar>  $query  The query every post read carries
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     * @return list<mixed> The attachment records to write
     */
    private function attachmentsByReference(
        Client $client,
        AttachmentReferenceCollector $collector,
        array $itemLinks,
        array $itemSlugs,
        array $query,
        array $channel
    ): array {
        $ids = $collector->ids();
        $paths = $collector->paths();
        $chunkSize = \min(self::REFERENCE_CHUNK, $client->recordsPerPage());
        $requests = [
            ['include', $this->referenceChunks('include', $ids, $chunkSize)],
            ['file', $this->referenceChunks('file', $paths, $chunkSize)],
        ];

        // One request per chunk, against one per page for the whole collection. The count is
        // a table inventory and can overstate what is served, so it only ever widens early.
        $narrowRequests = \count($requests[0][1]) + \count($requests[1][1]);
        $counts = $channel['counts'] ?? null;
        $byPostType = \is_array($counts) ? ($counts['by_post_type'] ?? null) : null;
        $total = \is_array($byPostType) && \is_numeric($byPostType['attachment'] ?? null)
            ? (int) $byPostType['attachment']
            : null;

        if ($total !== null && $narrowRequests > 0 && $narrowRequests >= (int) \ceil($total / $client->recordsPerPage())) {
            return $this->allAttachments($client, $query, 'cheaper', \count($ids), \count($paths), [], []);
        }

        // Read what the items reference, by id and by file. A record both select is kept once.
        $records = [];

        foreach ($requests as [$parameter, $chunks]) {
            foreach ($chunks as $chunk) {
                foreach ($client->paginate('posts/attachment', null, \array_merge($query, [$parameter => $chunk])) as $record) {
                    if (\is_array($record) && \is_numeric($record['id'] ?? null)) {
                        $records[(int) $record['id']] = $record;
                    }
                }
            }
        }

        // Every image must resolve the way the image pipeline resolves it.
        $elements = $this->attachmentElements($records);
        $unresolvedImages = [];

        foreach (\array_keys($collector->imageUrls()) as $url) {
            if (! XpathHelper::findAttachmentByUrl($elements, $url) instanceof SimpleXMLElement) {
                $unresolvedImages[] = $url;
            }
        }

        // Every permalink must match an item read, the attachments just read included.
        $known = $itemLinks;

        foreach ($records as $record) {
            if (\is_string($record['link'] ?? null)) {
                $known[AttachmentReferenceCollector::normalizeLink($record['link'])] = true;
            }
        }

        $unresolvedLinks = [];

        foreach (\array_keys(\array_diff_key($collector->links(), $known)) as $link) {
            // The last segment names a slug; one an item read carries resolves to that item.
            $segment = \strtolower(\rawurldecode(\substr($link, (int) \strrpos($link, '/') + 1)));

            if (! isset($itemSlugs[$segment])) {
                $unresolvedLinks[] = $link;
            }
        }

        if ($unresolvedImages !== [] || $unresolvedLinks !== []) {
            return $this->allAttachments(
                $client,
                $query,
                'unresolved_references',
                \count($ids),
                \count($paths),
                $unresolvedImages,
                $unresolvedLinks
            );
        }

        // A named reference the site does not serve is reported, never dropped in silence.
        $unserved = \array_diff_key($collector->namedIds(), $records);

        foreach ($unserved as $id => $reference) {
            ImportWarningManager::addWarning('migration_api', 'unserved_attachment_reference', [
                'attachment_id' => $id,
                'shape' => $reference['shape'],
                'item' => $reference['item'],
            ]);
        }

        $unservedIds = \array_keys($unserved);
        \sort($unservedIds);

        $this->attachmentResolution = [
            'resolution' => 'by_reference',
            'reason' => 'all_references_resolved',
            'ids_requested' => \count($ids),
            'paths_requested' => \count($paths),
            'records' => \count($records),
            'unresolved_image_count' => 0,
            'unresolved_images' => [],
            'unresolved_link_count' => 0,
            'unresolved_links' => [],
            'unserved_ids' => $unservedIds,
        ];

        return \array_values($records);
    }

    /**
     * Split reference values into requests bounded by count and by encoded size.
     *
     * The count bound is the API's `file[]` cap. The size bound exists because a path is
     * encoded into the query string at roughly three times its length, and a hundred long
     * paths measure over 8 KB — past Apache's default `LimitRequestLine` of 8190 and nginx's
     * default header buffer — which would answer `414` and abort the run.
     *
     * @template T of int|string
     *
     * @param  string  $parameter  The query parameter the values go under
     * @param  list<T>  $values  The values to send
     * @param  int<1, max>  $maxCount  The most values one request carries
     * @return list<non-empty-list<T>> The chunks, in order
     */
    private function referenceChunks(
        string $parameter,
        array $values,
        int $maxCount
    ): array {
        $chunks = [];
        $chunk = [];
        $bytes = 0;

        foreach ($values as $value) {
            // Encoded as the HTTP client encodes it: `name%5BN%5D=value&`.
            $size = \strlen(\rawurlencode($parameter.'['.\count($chunk).']'))
                + \strlen(\rawurlencode((string) $value))
                + 2;

            if ($chunk !== [] && (\count($chunk) >= $maxCount || $bytes + $size > self::REFERENCE_QUERY_BYTES)) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }

            $chunk[] = $value;
            $bytes += $size;
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * Read every attachment, recording why a scoped run widened to it.
     *
     * @param  Client  $client  A client addressed to the site's own REST root
     * @param  array<string, scalar>  $query  The query every post read carries
     * @param  string  $reason  `cheaper` or `unresolved_references`
     * @param  int  $idsRequested  The ids the references named
     * @param  int  $pathsRequested  The files the references named
     * @param  list<string>  $unresolvedImages  Image URLs no referenced record resolved
     * @param  list<string>  $unresolvedLinks  Permalinks no item read matched
     * @return list<mixed> Every attachment record
     */
    private function allAttachments(
        Client $client,
        array $query,
        string $reason,
        int $idsRequested,
        int $pathsRequested,
        array $unresolvedImages,
        array $unresolvedLinks
    ): array {
        $records = \array_values($client->paginate('posts/attachment', null, $query));

        $this->attachmentResolution = [
            'resolution' => 'full',
            'reason' => $reason,
            'ids_requested' => $idsRequested,
            'paths_requested' => $pathsRequested,
            'records' => \count($records),
            'unresolved_image_count' => \count($unresolvedImages),
            'unresolved_images' => \array_slice($unresolvedImages, 0, self::REPORTED_EXAMPLES),
            'unresolved_link_count' => \count($unresolvedLinks),
            'unresolved_links' => \array_slice($unresolvedLinks, 0, self::REPORTED_EXAMPLES),
            'unserved_ids' => [],
        ];

        // Widening for cost is a choice with nothing to explain; widening because a reference
        // could not be shown resolved is worth a line, since it is what a narrowed read costs.
        if ($reason === 'unresolved_references') {
            ImportWarningManager::addWarning('migration_api', 'attachment_read_widened', [
                'unresolved_images' => \count($unresolvedImages),
                'unresolved_links' => \count($unresolvedLinks),
                'examples' => \array_slice(\array_merge($unresolvedImages, $unresolvedLinks), 0, 5),
            ]);
        }

        return $records;
    }

    /**
     * Attachment records as the XML elements `XpathHelper` matches against.
     *
     * Only the id and the file URL are carried, which is all `findAttachmentByUrl()` reads.
     *
     * @param  array<int, array<array-key, mixed>>  $records  Attachment records, keyed by id
     * @return list<SimpleXMLElement> One element per record with a file URL
     */
    private function attachmentElements(
        array $records
    ): array {
        $xml = '<rss xmlns:wp="'.self::NAMESPACES['xmlns:wp'].'"><channel>';

        foreach ($records as $id => $record) {
            $attachment = $record['attachment'] ?? null;
            $url = \is_array($attachment) ? ($attachment['url'] ?? null) : null;

            if (! \is_string($url) || $url === '') {
                continue;
            }

            $xml .= '<item><wp:post_id>'.$id.'</wp:post_id><wp:attachment_url>'
                .\htmlspecialchars($url, \ENT_XML1 | \ENT_QUOTES, 'UTF-8')
                .'</wp:attachment_url></item>';
        }

        $document = \simplexml_load_string($xml.'</channel></rss>');

        if ($document === false) {
            return [];
        }

        $elements = [];

        foreach ($document->channel->item as $item) {
            $elements[] = $item;
        }

        return $elements;
    }

    /**
     * Content that exists on the site but the API will not serve.
     *
     * Empty unless `materialize()` has run. Each entry names the kind, the name, and how
     * many records are stranded.
     *
     * @return list<array{kind: string, name: string, records: int}> The unserved content
     */
    public function unservedContent(): array
    {
        return $this->unserved;
    }

    /**
     * What this run read from the redirect plugins a vanity domain runs (wordpress-importer-archive#1307).
     *
     * Empty unless `materialize()` has read a listed redirect domain on which at least one of
     * the plugins is served.
     *
     * @return list<array{host: string, plugin: string, rules: int, written: int, unsupported: array<string, int>}>
     */
    public function redirectPluginRules(): array
    {
        return $this->redirectPluginRules;
    }

    /**
     * The Redirection and Pretty Links rules a vanity domain serves, as `redirect_rule` items (wordpress-importer-archive#1307).
     *
     * Read only for a site whose host is a listed redirect domain, because only that path
     * writes the rules to the redirect domains collection, and only by a run that imports
     * redirect rules. A plugin the site does not run answers with its own error code, which
     * is recorded as the plugin being absent; any other failure raises, because a run that
     * read some rules and not others would disable the rest at the next import (wordpress-importer-archive#1311).
     *
     * What each plugin served, what was written and what the collection cannot express are
     * recorded for `redirectPluginRules()` and for the end-of-import summary.
     *
     * @param  Client  $client  A client addressed to the site's own REST root
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     * @param  string|null  $contentType  The one type this run imports, or null for every type
     * @return list<array<string, mixed>> The rules as post payloads
     *
     * @throws MigrationApiException When a plugin's rules cannot be read
     */
    private function redirectPluginPosts(
        Client $client,
        array $channel,
        ?string $contentType
    ): array {
        if ($contentType !== null && $contentType !== 'redirect_rule') {
            return [];
        }

        $baseBlogUrl = $channel['base_blog_url'] ?? null;
        $host = \is_string($baseBlogUrl) ? RedirectRuleGlobalCreator::redirectDomainHost($baseBlogUrl) : null;

        if ($host === null) {
            return [];
        }

        $posts = [];

        foreach (RedirectPluginRules::PLUGINS as $endpoint => $plugin) {
            try {
                $records = $client->paginate($endpoint);
            } catch (MigrationApiException $migrationApiException) {
                if (\in_array($migrationApiException->apiCode(), RedirectPluginRules::ABSENT_CODES, true)) {
                    continue;
                }

                throw $migrationApiException;
            }

            $written = 0;
            $unsupported = [];

            foreach ($records as $record) {
                if (! \is_array($record)) {
                    continue;
                }

                $converted = RedirectPluginRules::convert($endpoint, $record);

                foreach ($converted['unsupported'] as $feature) {
                    $unsupported[$feature] = ($unsupported[$feature] ?? 0) + 1;
                }

                if ($converted['post'] !== null) {
                    $posts[] = $converted['post'];
                    $written++;
                }
            }

            $entry = [
                'host' => $host,
                'plugin' => $plugin,
                'rules' => \count($records),
                'written' => $written,
                'unsupported' => $unsupported,
            ];

            $this->redirectPluginRules[] = $entry;

            // Recorded for the end-of-import summary, so the list survives a long run's scrollback
            foreach ($unsupported as $feature => $rules) {
                ImportWarningManager::addWarning('redirects', 'unsupported_plugin_features', [
                    'host' => $host,
                    'plugin' => $plugin,
                    'feature' => $feature,
                    'rules' => $rules,
                ]);
            }
        }

        return $posts;
    }

    /**
     * Record options the endpoint serves that this run did not read.
     *
     * The third content class, and the one WXR cannot carry at all: there is no
     * `<wp:option>` element, so every migration before the API dropped site settings in
     * silence. A run that carries no options must say so, or it reports exactly what a run
     * that carried them reports.
     *
     * Two figures, deliberately kept apart. **Not read** is what the endpoint served and
     * this run did not consume. **Withheld** is what the endpoint refused, which it reports
     * only as totals - `by_name` and `by_shape`, never which rows - so the two cannot be
     * reconciled into one number without inventing precision the payload does not have.
     * Merging them is the failure this records against.
     *
     * The entries are aggregate rather than one per row. The endpoint serves on the order of
     * a thousand options, and a per-row entry would flood `ImportWarningManager` with a
     * warning nobody reads.
     *
     * @param  Client  $client  A client addressed to the site's own REST root
     * @param  mixed  $counts  The site's `counts` block, which reports whether options exist at all
     * @param  ?int  $site  The site id, which `/options` requires
     * @param  list<string>  $consumedOptions  Option names this run actually read
     */
    private function recordUnservedOptions(
        Client $client,
        mixed $counts,
        ?int $site,
        array $consumedOptions
    ): void {
        // Ask discovery before asking the endpoint. `counts.options` is the site's own
        // whole-table figure, so a deployment that serves no options - or an API too old to
        // carry the route - costs no request and cannot abort the run. The unserved report
        // is a diagnostic; it must never be the reason an import fails.
        $rows = \is_array($counts) ? ($counts['options'] ?? null) : null;

        if (! \is_numeric($rows) || (int) $rows < 1) {
            return;
        }

        $payload = $client->get('options', $site);

        $served = $payload['options'] ?? null;
        $names = \is_array($served) ? \array_keys($served) : [];
        $unread = \array_values(\array_diff($names, $consumedOptions));

        if ($unread !== []) {
            $this->unserved[] = [
                'kind' => 'option',
                'name' => 'not read by this run',
                'records' => \count($unread),
            ];
        }

        $denied = $payload['denied'] ?? null;
        $byName = \is_array($denied) && \is_numeric($denied['by_name'] ?? null) ? (int) $denied['by_name'] : 0;
        $byShape = \is_array($denied) && \is_numeric($denied['by_shape'] ?? null) ? (int) $denied['by_shape'] : 0;

        if ($byName + $byShape > 0) {
            $this->unserved[] = [
                'kind' => 'option',
                'name' => 'withheld by the endpoint denylist',
                'records' => $byName + $byShape,
            ];
        }
    }

    /**
     * Record content that exists but will not be served.
     *
     * **Why this exists.** `exposed` is `Config::postTypes()` filtered by
     * `post_type_exists()`, and registration is process-global — so it reflects which
     * plugins were active in the request that booted, not what content exists. A site whose
     * MinervaKB plugin is inactive reports `kb` unexposed while 28 `kb` rows sit in the
     * database, and a migration of it omits them with no error on either side. Measured
     * exactly that way in wordpress-importer-archive#684: 37 records across `kb`, `uamspeople` and `logo-list-item`.
     *
     * Nothing here is a defect to fix — the API is right to refuse a type it cannot
     * describe, and `exposedPostTypes()` is right to skip it, because requesting one makes
     * every page 404. What was missing is anyone saying so.
     *
     * **The two comparisons draw on different sources, deliberately.** Post types compare
     * against `counts.by_post_type` from `/sites`, which is a database count and therefore
     * sees types no longer registered. Taxonomies compare against the `terms` count on
     * `/taxonomies`, because `counts` carries no per-taxonomy breakdown — which means a
     * taxonomy whose plugin is inactive is not listed at all and stays invisible. That gap
     * is real and recorded on wordpress-importer-archive#688 rather than papered over.
     *
     * @param  Client  $client  A client addressed to the site's own REST root
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     * @param  list<string>  $postTypes  The exposed post type names
     * @param  ?int  $site  The site id, which `/options` requires
     * @param  list<string>  $consumedOptions  Option names this run actually read
     */
    private function recordUnservedContent(
        Client $client,
        array $channel,
        array $postTypes,
        ?int $site = null,
        array $consumedOptions = []
    ): void {
        $counts = $channel['counts'] ?? null;
        $byPostType = \is_array($counts) ? ($counts['by_post_type'] ?? null) : null;

        if (\is_array($byPostType)) {
            foreach ($byPostType as $name => $records) {
                if (
                    ! \is_string($name)
                    || ! \is_numeric($records)
                    || (int) $records < 1
                    || \in_array($name, $postTypes, true)
                ) {
                    continue;
                }

                $this->unserved[] = ['kind' => 'post type', 'name' => $name, 'records' => (int) $records];
            }
        }

        foreach ($client->get('taxonomies') as $taxonomy) {
            if (
                ! \is_array($taxonomy)
                || ($taxonomy['exposed'] ?? false) === true
                || ! \is_string($taxonomy['name'] ?? null)
                || ! \is_numeric($taxonomy['terms'] ?? null)
                || (int) $taxonomy['terms'] < 1
            ) {
                continue;
            }

            $this->unserved[] = [
                'kind' => 'taxonomy',
                'name' => $taxonomy['name'],
                'records' => (int) $taxonomy['terms'],
            ];
        }

        $this->recordUnservedOptions($client, $counts, $site, $consumedOptions);

        // Also recorded for the end-of-import summary, so it survives a long run's scrollback.
        foreach ($this->unserved as $entry) {
            ImportWarningManager::addWarning('migration_api', 'unserved_content', $entry);
        }
    }

    /**
     * A client addressed to a site's own REST root.
     *
     * **Why not `?site=`.** The API switches sites on that parameter for data endpoints,
     * but refuses it for `post-types` and `taxonomies` with
     * `uamswp_migration_api_registration_is_not_site_scoped`: those are derived from
     * `post_type_exists()`, and registration is process-global, so they can only answer for
     * the site that booted the request.
     *
     * Measured against a live multisite rather than reasoned about (wordpress-importer-archive#671). For the same
     * site, `?site=2` and site 2's own root return identical results — 1 post either way
     * against site 1's 46, and 235 option rows either way against site 1's 1206 — while
     * `post-types?site=2` returns 400 and site 2's root returns 200. So the per-site root
     * is equivalent where `?site=` works and necessary where it does not, which is why
     * every call goes through it instead of branching per endpoint.
     *
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     * @param  int|null  $site  The requested site id, for the error message
     * @return Client A client for that site's root, or this one when no site was requested
     *
     * @throws MigrationApiException When the payload carries no usable `link`
     */
    private function siteClientFor(
        array $channel,
        ?int $site
    ): Client {
        // With no site requested the configured base URL is already the right root: the API
        // serves whichever site that URL belongs to.
        if ($site === null) {
            return $this->client;
        }

        $link = $channel['link'] ?? null;

        if (! \is_string($link) || \trim($link) === '') {
            // Falling back to `?site=` here would fail later with a 400 whose message is
            // about registration rather than about a missing link — a correct error
            // pointing at the wrong cause.
            throw new MigrationApiException(\sprintf(
                'The migration API reported site %d without a usable "link", so its own '
                .'REST root cannot be addressed. Site-scoped reads need that root because '
                .'the discovery endpoints refuse `?site=`.',
                $site
            ));
        }

        return $this->client->withBaseUrl($link);
    }

    /**
     * Read the channel metadata for a site.
     *
     * @param  int|null  $site  The network site id
     * @return array<array-key, mixed> The site's discovery payload
     *
     * @throws MigrationApiException When the site is not served by this API
     */
    private function channelFor(
        ?int $site
    ): array {
        $sites = $this->client->get('sites');

        foreach ($sites as $candidate) {
            if (! \is_array($candidate)) {
                continue;
            }

            // With no scoping the API serves the current site, so the first entry is it.
            if ($site === null) {
                return $candidate;
            }

            $candidateId = $candidate['id'] ?? null;

            if (\is_numeric($candidateId) && (int) $candidateId === $site) {
                return $candidate;
            }
        }

        throw new MigrationApiException(\sprintf(
            'The migration API serves no site with id %s. Refusing to materialize an export '
            .'with no channel metadata.',
            $site === null ? '(unscoped)' : (string) $site
        ));
    }

    /**
     * The post types this API exposes, in the order it reports them.
     *
     * @param  Client  $client  A client addressed to that site's own REST root
     * @return list<string> The exposed post type names
     */
    private function exposedPostTypes(
        Client $client
    ): array {
        $types = [];

        // No `?site=` — this endpoint refuses it. The client is already pointed at the
        // right site's root.
        foreach ($client->get('post-types') as $type) {
            // `exposed` is the API's own allow-list decision; a type it will not serve must
            // not be requested, or every page of it 404s.
            if (
                \is_array($type)
                && ($type['exposed'] ?? false) === true
                && \is_string($type['name'] ?? null)
            ) {
                $types[] = $type['name'];
            }
        }

        return $types;
    }

    /**
     * Every exposed taxonomy's terms, grouped by taxonomy.
     *
     * **A scoped run reads the taxonomies attached to what it reads, from `object_types`.**
     * `/taxonomies` carries the post types each taxonomy is attached to; `/post-types` carries
     * no taxonomy list at all, which is the correction recorded on wordpress-importer-archive#1089.
     *
     * **A taxonomy that declares no usable `object_types` is read, not skipped.** Absence says
     * nothing about what it is attached to, and skipping it would strip term assignments from
     * the imported entries with no error — the silent-loss shape a scoped read must not add.
     * Reading one taxonomy too many costs a request; reading one too few costs data.
     *
     * @param  Client  $client  A client addressed to that site's own REST root
     * @param  list<string>|null  $postTypes  The post types a scoped run reads, or null to read
     *                                        every exposed taxonomy
     * @return array<string, list<array<array-key, mixed>>> Terms keyed by taxonomy name
     */
    private function termsByTaxonomy(
        Client $client,
        ?array $postTypes = null
    ): array {
        $grouped = [];

        // As with post types, `?site=` is refused here.
        foreach ($client->get('taxonomies') as $taxonomy) {
            if (
                ! \is_array($taxonomy)
                || ($taxonomy['exposed'] ?? false) !== true
                || ! \is_string($taxonomy['name'] ?? null)
            ) {
                continue;
            }

            // Skip only a taxonomy PROVEN unrelated: one listing post types, none of them read.
            // Only string entries count as a declaration, so a list carrying nothing usable
            // reads as undeclared and the taxonomy is read rather than dropped.
            $objectTypes = $taxonomy['object_types'] ?? null;
            $declared = \is_array($objectTypes)
                ? \array_values(\array_filter($objectTypes, \is_string(...)))
                : [];

            if (
                $postTypes !== null
                && $declared !== []
                && \array_intersect($declared, $postTypes) === []
            ) {
                continue;
            }

            $terms = [];

            foreach ($client->paginate('terms/'.$taxonomy['name']) as $term) {
                if (\is_array($term)) {
                    $terms[] = $term;
                }
            }

            $grouped[$taxonomy['name']] = $terms;
        }

        return $grouped;
    }

    /**
     * The path of a part, numbered the way a split export numbers them.
     *
     * @param  int  $index  The zero-based part index
     * @return string The absolute part path
     */
    private function partPath(
        int $index
    ): string {
        return \rtrim($this->outputDirectory, \DIRECTORY_SEPARATOR)
            .\DIRECTORY_SEPARATOR
            .\sprintf('wxr.%03d.xml', $index);
    }

    /**
     * Open a part and write everything that precedes its items.
     *
     * @param  string  $path  The part path to write
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     * @param  array<int, mixed>  $authors  The site's authors
     * @param  array<string, list<array<array-key, mixed>>>  $terms  Terms keyed by taxonomy
     * @return XMLWriter The opened writer
     *
     * @throws MigrationApiException When the part cannot be opened for writing
     */
    private function openPart(
        string $path,
        array $channel,
        array $authors,
        array $terms
    ): XMLWriter {
        $writer = new XMLWriter;

        // Stream to the file rather than building the document in memory — this package
        // processes multi-thousand-item exports in one process.
        if ($writer->openUri($path) === false) {
            throw new MigrationApiException(\sprintf(
                'Could not open "%s" to write a materialized WXR part.',
                $path
            ));
        }

        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('rss');
        $writer->writeAttribute('version', '2.0');

        foreach (self::NAMESPACES as $attribute => $uri) {
            $writer->writeAttribute($attribute, $uri);
        }

        $writer->startElement('channel');

        $this->writeChannel($writer, $channel);
        $this->writeAuthors($writer, $authors);
        $this->writeTaxonomies($writer, $terms);

        return $writer;
    }

    /**
     * Whether a site's materialized parts run through the WXR cleaner (wordpress-importer-archive#1303).
     *
     * The decision follows the WXR workflow it mirrors: only sites whose exports are cleaned
     * before import are cleaned here, and today that is `news.uams.edu` alone. The list is
     * `wordpress-import.wxr_cleanup.sites`, matched against the host of the site's
     * `base_blog_url`, the field exports are grouped by (wordpress-importer-archive#787). Adding a site is a deliberate
     * configuration change, because the cleaner removes the block comments block-built content
     * depends on.
     *
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     */
    public static function cleansSite(array $channel): bool
    {
        $baseBlogUrl = $channel['base_blog_url'] ?? null;

        if (! \is_string($baseBlogUrl)) {
            return false;
        }

        $host = \parse_url($baseBlogUrl, \PHP_URL_HOST);

        if (! \is_string($host) || $host === '') {
            return false;
        }

        $sites = config('wordpress-import.wxr_cleanup.sites', self::CLEANED_SITES);

        if (! \is_array($sites)) {
            return false;
        }

        return array_any($sites, fn ($site): bool => \is_string($site) && \strcasecmp($site, $host) === 0);
    }

    /**
     * Run the WXR cleaner's default pipeline over every written part, in place.
     *
     * Exports are cleaned by `uams-web/wordpress-wxr-cleanup`'s CLI before they are imported.
     * Content read from the migration API never passed through it, so the same post imported
     * differently depending on its source (wordpress-importer-archive#1303). Running the cleaner's own
     * `Transform::withDefaultRules()` on each part applies the same rules, in the same order, to
     * the same WXR elements the CLI cleans, without a second copy of the rule list.
     *
     * One part is read at a time, so memory is bounded by the part size, not the run.
     *
     * @param  list<string>  $paths  The written part paths
     *
     * @throws MigrationApiException When a part cannot be read, cleaned or written back
     */
    private function cleanParts(array $paths): void
    {
        $transform = Transform::withDefaultRules();

        foreach ($paths as $path) {
            // Read the part as written
            $raw = \file_get_contents($path);

            if ($raw === false) {
                throw new MigrationApiException(\sprintf('Could not read "%s" to clean it.', $path));
            }

            $cleaned = $transform->apply($raw);

            // The cleaner suppresses libxml errors, so a part it could not parse comes back as an
            // empty document rather than an exception. Refuse to write that over the part: every
            // item that went in has to come out.
            $before = \substr_count($raw, '<item>');
            $after = \substr_count($cleaned, '<item>');

            if ($after !== $before) {
                throw new MigrationApiException(\sprintf(
                    'Cleaning "%s" returned %d of its %d items. The part was left as written.',
                    $path,
                    $after,
                    $before
                ));
            }

            if (\file_put_contents($path, $cleaned) === false) {
                throw new MigrationApiException(\sprintf('Could not write the cleaned "%s".', $path));
            }

            // Release the part's text before reading the next one
            unset($raw, $cleaned);
        }
    }

    /**
     * Close a part, ending every element the open left on the stack.
     *
     * @param  XMLWriter  $writer  The writer to close
     */
    private function closePart(
        XMLWriter $writer
    ): void {
        // </channel>, </rss>
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    /**
     * Write the channel-level metadata every part repeats.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  array<array-key, mixed>  $channel  The site's discovery payload
     */
    private function writeChannel(
        XMLWriter $writer,
        array $channel
    ): void {
        $this->writeText($writer, 'title', $channel['title'] ?? null);
        $this->writeText($writer, 'link', $channel['link'] ?? null);
        $this->writeText($writer, 'description', $channel['description'] ?? null);
        $this->writeText($writer, 'language', $channel['language'] ?? null);

        $this->writeText($writer, 'wp:wxr_version', $channel['wxr_version'] ?? '1.2');
        $this->writeText($writer, 'wp:base_site_url', $channel['base_site_url'] ?? null);

        // `getBaseBlogUrl()` reads this, and consumers resolve every relative link against
        // it — an absent value silently turns site-relative URLs into broken ones.
        $this->writeText($writer, 'wp:base_blog_url', $channel['base_blog_url'] ?? null);
    }

    /**
     * Write the `wp:author` nodes.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  array<int, mixed>  $authors  The site's authors
     */
    private function writeAuthors(
        XMLWriter $writer,
        array $authors
    ): void {
        foreach ($authors as $author) {
            if (! \is_array($author)) {
                continue;
            }

            $writer->startElement('wp:author');
            $this->writeText($writer, 'wp:author_id', $author['id'] ?? null);
            $this->writeCdata($writer, 'wp:author_login', $author['login'] ?? null);
            $this->writeCdata($writer, 'wp:author_email', $author['email'] ?? null);
            $this->writeCdata($writer, 'wp:author_display_name', $author['display_name'] ?? null);
            $this->writeCdata($writer, 'wp:author_first_name', $author['first_name'] ?? null);
            $this->writeCdata($writer, 'wp:author_last_name', $author['last_name'] ?? null);
            $writer->endElement();
        }
    }

    /**
     * Write the channel-level taxonomy nodes.
     *
     * WXR carries three distinct legacy forms and consumers read all three: `wp:category`,
     * `wp:tag`, and the generic `wp:term`. `category` and `post_tag` get their legacy node
     * **and** a generic one, exactly as a real export does.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  array<string, list<array<array-key, mixed>>>  $terms  Terms keyed by taxonomy
     */
    private function writeTaxonomies(
        XMLWriter $writer,
        array $terms
    ): void {
        foreach ($terms as $taxonomy => $rows) {
            foreach ($rows as $term) {
                if ($taxonomy === 'category') {
                    $writer->startElement('wp:category');
                    $this->writeText($writer, 'wp:term_id', $term['id'] ?? null);
                    $this->writeText($writer, 'wp:category_nicename', $term['slug'] ?? null);
                    $this->writeText($writer, 'wp:category_parent', $term['parent_slug'] ?? null);
                    $this->writeCdata($writer, 'wp:cat_name', $term['name'] ?? null);
                    $this->writeCdata($writer, 'wp:category_description', $term['description'] ?? null);
                    $writer->endElement();
                }

                if ($taxonomy === 'post_tag') {
                    $writer->startElement('wp:tag');
                    $this->writeText($writer, 'wp:term_id', $term['id'] ?? null);
                    $this->writeText($writer, 'wp:tag_slug', $term['slug'] ?? null);
                    $this->writeCdata($writer, 'wp:tag_name', $term['name'] ?? null);
                    $this->writeCdata($writer, 'wp:tag_description', $term['description'] ?? null);
                    $writer->endElement();
                }

                $writer->startElement('wp:term');
                $this->writeText($writer, 'wp:term_id', $term['id'] ?? null);
                $this->writeText($writer, 'wp:term_taxonomy', $taxonomy);
                $this->writeText($writer, 'wp:term_slug', $term['slug'] ?? null);
                $this->writeText($writer, 'wp:term_parent', $term['parent_slug'] ?? null);
                $this->writeCdata($writer, 'wp:term_name', $term['name'] ?? null);
                $this->writeCdata($writer, 'wp:term_description', $term['description'] ?? null);
                $this->writeMeta($writer, 'wp:termmeta', $term['meta'] ?? null);
                $writer->endElement();
            }
        }
    }

    /**
     * Write one `<item>`.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  array<array-key, mixed>  $post  A post payload
     */
    private function writeItem(
        XMLWriter $writer,
        array $post
    ): void {
        $writer->startElement('item');

        $this->writeCdata($writer, 'title', $post['title'] ?? null);
        $this->writeText($writer, 'link', $post['link'] ?? null);
        $this->writeText($writer, 'pubDate', $post['date_created_gmt'] ?? null);

        // The author element is `dc:creator` and carries the login, not the display name —
        // author resolution matches on the login.
        $author = $post['author'] ?? null;
        $this->writeCdata(
            $writer,
            'dc:creator',
            \is_array($author) ? ($author['login'] ?? null) : null
        );

        $this->writeText($writer, 'guid', $post['guid'] ?? null);
        $this->writeCdata($writer, 'content:encoded', $post['content'] ?? null);
        $this->writeCdata($writer, 'excerpt:encoded', $post['excerpt'] ?? null);

        $this->writeText($writer, 'wp:post_id', $post['id'] ?? null);
        $this->writeText($writer, 'wp:post_date', $post['date_created'] ?? null);
        $this->writeText($writer, 'wp:post_date_gmt', $post['date_created_gmt'] ?? null);
        $this->writeText($writer, 'wp:post_modified', $post['date_modified'] ?? null);
        $this->writeText($writer, 'wp:post_modified_gmt', $post['date_modified_gmt'] ?? null);
        $this->writeText($writer, 'wp:comment_status', $post['comment_status'] ?? null);
        $this->writeText($writer, 'wp:ping_status', $post['ping_status'] ?? null);
        $this->writeCdata($writer, 'wp:post_name', $post['slug'] ?? null);
        $this->writeText($writer, 'wp:status', $post['status'] ?? null);
        $this->writeText($writer, 'wp:post_parent', $post['parent'] ?? null);
        $this->writeText($writer, 'wp:menu_order', $post['menu_order'] ?? null);
        $this->writeText($writer, 'wp:post_type', $post['type'] ?? null);
        $this->writeCdata($writer, 'wp:post_password', $post['post_password'] ?? null);
        $this->writeText($writer, 'wp:is_sticky', ($post['is_sticky'] ?? false) === true ? '1' : '0');

        // An attachment's URL lives in its own element, and the asset pipeline reads it
        // from there rather than from the guid.
        $attachment = $post['attachment'] ?? null;

        if (\is_array($attachment) && \is_string($attachment['url'] ?? null)) {
            $this->writeText($writer, 'wp:attachment_url', $attachment['url']);
        }

        $this->writeItemTerms($writer, $post['terms'] ?? null);
        $this->writeMeta($writer, 'wp:postmeta', $post['meta'] ?? null);

        $writer->endElement();
    }

    /**
     * Write an item's `<category>` nodes.
     *
     * The payload groups these by taxonomy; WXR flattens them into `<category>` elements
     * whose `domain` attribute carries the taxonomy and whose `nicename` carries the slug.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  mixed  $terms  The post's grouped terms
     */
    private function writeItemTerms(
        XMLWriter $writer,
        mixed $terms
    ): void {
        if (! \is_array($terms)) {
            return;
        }

        foreach ($terms as $taxonomy => $rows) {
            if (! \is_string($taxonomy) || ! \is_array($rows)) {
                continue;
            }

            foreach ($rows as $term) {
                if (! \is_array($term)) {
                    continue;
                }

                $writer->startElement('category');
                $writer->writeAttribute('domain', $taxonomy);
                $writer->writeAttribute('nicename', $this->scalarToString($term['slug'] ?? null));
                $writer->writeCdata($this->cdataSafe($this->scalarToString($term['name'] ?? null)));
                $writer->endElement();
            }
        }
    }

    /**
     * Write meta rows.
     *
     * Each key maps to a **list** of values, because `get_post_meta()` without a key returns
     * every row — so one key can produce several meta elements, exactly as WXR allows.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  string  $element  The wrapping element (`wp:postmeta` or `wp:termmeta`)
     * @param  mixed  $meta  The meta payload
     */
    private function writeMeta(
        XMLWriter $writer,
        string $element,
        mixed $meta
    ): void {
        if (! \is_array($meta)) {
            return;
        }

        foreach ($meta as $key => $values) {
            if (! \is_string($key)) {
                continue;
            }

            // A scalar value is still one row; normalizing here keeps the loop below honest
            // whether the API sends a list or a bare value.
            $rows = \is_array($values) ? $values : [$values];

            foreach ($rows as $value) {
                $writer->startElement($element);
                $this->writeCdata($writer, 'wp:meta_key', $key);
                $this->writeCdata($writer, 'wp:meta_value', $value);
                $writer->endElement();
            }
        }
    }

    /**
     * Write a plain-text element, skipping it entirely when there is no value.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  string  $element  The element name
     * @param  mixed  $value  The value to write
     */
    private function writeText(
        XMLWriter $writer,
        string $element,
        mixed $value
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        $writer->writeElement($element, $this->sanitize($this->scalarToString($value)));
    }

    /**
     * Write a CDATA element, skipping it entirely when there is no value.
     *
     * @param  XMLWriter  $writer  The open writer
     * @param  string  $element  The element name
     * @param  mixed  $value  The value to write
     */
    private function writeCdata(
        XMLWriter $writer,
        string $element,
        mixed $value
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        $writer->startElement($element);
        $writer->writeCdata($this->cdataSafe($this->scalarToString($value)));
        $writer->endElement();
    }

    /**
     * Render a value as a string, serializing anything that is not scalar.
     *
     * @param  mixed  $value  The value to render
     * @return string The rendered string
     */
    private function scalarToString(
        mixed $value
    ): string {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        // A structured meta value (WordPress serializes arrays into the meta table) round-
        // trips as JSON rather than being silently dropped. Encoding cannot fail on a value
        // that was itself decoded from JSON, so the guard satisfies the return type; the
        // key is still written either way, which keeps any future loss visible.
        $encoded = \json_encode($value);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * Strip the characters XML 1.0 cannot represent.
     *
     * @param  string  $value  The value to filter
     * @return string The filtered value
     */
    private function sanitize(
        string $value
    ): string {
        $filtered = \preg_replace(self::ILLEGAL_CHARACTERS, '', $value);

        // `preg_replace` returns null only on a malformed UTF-8 subject, which cannot reach
        // here: `Client` decodes JSON, and `json_decode` rejects invalid UTF-8 outright, so
        // every string arriving from it is already valid. The coalesce satisfies the return
        // type rather than handling a state this path can produce — hence returning the
        // original value, never an empty string.
        return $filtered ?? $value;
    }

    /**
     * Make a value safe to place inside a CDATA section.
     *
     * @param  string  $value  The value to escape
     * @return string The escaped value
     */
    private function cdataSafe(
        string $value
    ): string {
        // A `]]>` inside the content would close the section early and truncate everything
        // after it — the split re-opens a new section so the bytes survive verbatim.
        return \str_replace(
            ']]>',
            ']]]]><![CDATA[>',
            $this->sanitize($value)
        );
    }
}
