<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Contracts\FileLoaderInterface;

/**
 * Sources a form plugin's definitions from the migration API, or from a file.
 *
 * **This is the single place the file-versus-API decision is made for form plugins (wordpress-importer-archive#809).**
 * Every consumer below it — `FormPluginCoordinator`, `FormConfigurationManagerTrait`,
 * `FieldtypeMappingTrait` — receives the same array either way and carries no conditional,
 * which is what makes one path survive rather than two.
 *
 * **It is generic by construction because two more plugins adopt it.** The endpoint slug is
 * a constructor argument, so `wordpress-importer-archive#810` (Ninja) and `wordpress-importer-archive#811` (Formidable) supply `ninja` and
 * `formidable` and inherit the whole seam; what they do NOT inherit is the payload handling,
 * which genuinely differs per plugin and stays in their own mapping code.
 *
 * **The envelope is unwrapped here and nowhere else.** A committed Gravity Forms export wraps
 * its forms as `{"0": {form}, …, "version": "2.x"}`; the API serves `GFAPI::get_form()` output
 * directly, which is the same native form array without that wrapper. Returning a plain list
 * of forms therefore hands the mapping exactly what the file path hands it: `Validator`
 * reaches the first form through `reset()` either way, and the `version` element is read
 * nowhere in `src/` — measured at 0 occurrences of `['version']` against 43 of `['title']`
 * as a control.
 *
 * Mirrors `MinervaKbSettings\Loader`, including its singleton hazard: see
 * `clearMigrationApiSource()`.
 */
class MigrationApiFormLoader implements FileLoaderInterface
{
    /**
     * The migration API client for this run, when one is configured.
     */
    private ?Client $client = null;

    /**
     * The network site id to read, when scoping is needed.
     */
    private ?int $site = null;

    /**
     * Create a loader for one form plugin.
     *
     * @param  string  $pluginName  The plugin name, for logging and operator-facing text
     * @param  string  $endpointSlug  The migration API forms slug, e.g. `gravity`
     * @param  FileLoaderInterface  $fileLoader  The loader used when no API source is set
     */
    public function __construct(
        private readonly string $pluginName,
        private readonly string $endpointSlug,
        private readonly FileLoaderInterface $fileLoader,
    ) {}

    /**
     * Read this plugin's definitions from the migration API instead of a file for this run.
     *
     * Supplied after construction, because the source is only known once the command has
     * parsed its options — the same idiom the entry creators and coordinators already use.
     *
     * @param  Client  $client  The migration API client
     * @param  int|null  $site  The network site id to read, when scoping is needed
     */
    public function setMigrationApiSource(
        Client $client,
        ?int $site = null
    ): void {
        $this->client = $client;
        $this->site = $site;
    }

    /**
     * Whether this run reads definitions from the migration API.
     *
     * The selector asks so it can skip the file prompt: on an API-sourced run there is no
     * local file to prompt for, and prompting for one is how wordpress-importer-archive#679 made the load path
     * unreachable in production — the operator pressed Enter and the selector returned
     * before the loader was ever consulted.
     *
     * @return bool True when a migration API source has been configured
     */
    public function hasMigrationApiSource(): bool
    {
        return $this->client instanceof Client;
    }

    /**
     * Return this loader to reading from a file.
     *
     * The loader is a container singleton, so a source it was given outlives the run that
     * set it. One command per process makes that harmless in production; a second import in
     * the same process would otherwise inherit the first run's source and read the API while
     * its operator answered a file prompt. The command sets the source on **both** branches
     * rather than only the API one, so it describes the current run instead of a previous one.
     */
    public function clearMigrationApiSource(): void
    {
        $this->client = null;
        $this->site = null;
    }

    /**
     * Load this plugin's form definitions from whichever source the run was configured with.
     *
     * @param  string  $filePath  The export path, ignored entirely on the API path
     * @param  Command  $command  Command instance for error reporting
     * @return array<int|string, mixed>|null The forms, or null when loading failed
     */
    public function load(
        string $filePath,
        Command $command
    ): ?array {
        // No API source: this run reads a file, exactly as it did before wordpress-importer-archive#809.
        if (! $this->client instanceof Client) {
            return $this->fileLoader->load($filePath, $command);
        }

        return $this->loadFromApi($this->client, $command);
    }

    /**
     * Read every form this plugin serves from the migration API.
     *
     * The client is the one `load()` has just found configured, passed in rather than read
     * again, so no check of it is needed here (#100).
     *
     * @param  Client  $client  The migration API client for this run
     * @param  Command  $command  Command instance for error reporting
     * @return array<int|string, mixed>|null The forms, or null when the read failed
     */
    private function loadFromApi(
        Client $client,
        Command $command
    ): ?array {
        $endpoint = 'forms/'.$this->endpointSlug;

        try {
            // The site is threaded through deliberately. Omitting it reads whatever the
            // addressed root serves by default, so a run scoped to site N would materialize
            // that site's content and then map its forms against another site's definitions
            // — with nothing on screen to distinguish the two.
            //
            // `paginate()` raises rather than returning a short read, so a truncated
            // response cannot be mistaken for a plugin with fewer forms.
            $forms = $client->paginate($endpoint, $this->site);
        } catch (\Throwable $throwable) {
            $command->error(
                "Could not read {$this->pluginName} definitions from the migration API: "
                .$throwable->getMessage()
            );

            Log::error(
                "MigrationApiFormLoader::loadFromApi: {$this->pluginName} read failed",
                [
                    'endpoint' => $endpoint,
                    'site' => $this->site,
                    'message' => $throwable->getMessage(),
                ]
            );

            return null;
        }

        // Keep only the form arrays. The API serves no envelope, so this drops nothing on
        // that path — it exists so the returned shape is the same list whichever source
        // produced it, rather than depending on the server never adding a scalar.
        $definitions = \array_values(\array_filter(
            $forms,
            \is_array(...)
        ));

        // Per-plugin translation of the API payload into the shape the mapping consumes
        // from a file. Identity for Gravity and Ninja, whose payloads already match their
        // files; Formidable overrides it, because its endpoint serves a JSON shape the XML
        // export does not (wordpress-importer-archive#811). This is the sourcing boundary the mapping is deliberately
        // never taught to cross.
        $definitions = \array_map(
            fn (array $form): array => $this->translateApiForm(
                $form,
                $command
            ),
            $definitions
        );

        $command->info(\sprintf(
            '  Read %d %s form definition(s) from the migration API.',
            \count($definitions),
            $this->pluginName
        ));

        return $definitions;
    }

    /**
     * Translate one API-sourced form into the shape the mapping consumes from a file.
     *
     * The default is identity: Gravity and Ninja serve payloads that already match their
     * export files, so nothing is translated for them. Formidable overrides this because its
     * endpoint serves a genuinely different JSON shape (wordpress-importer-archive#811). Kept a hook rather than a
     * conditional so a plugin that needs no translation carries no Formidable-specific code.
     *
     * The command is passed so a translation can REPORT what the endpoint withheld rather
     * than drop it: the mapping cannot tell a member that was never served from one the
     * translation discarded, so the seam is the only place the difference is visible.
     *
     * @param  array<int|string, mixed>  $form  One form as the endpoint served it
     * @param  Command  $command  Command instance, for reporting what the translation cannot carry
     * @return array<int|string, mixed> The form in the mapping's expected shape
     */
    protected function translateApiForm(
        array $form,
        Command $command
    ): array {
        return $form;
    }

    /**
     * Get the file extension this loader's file path expects.
     *
     * @return string The file extension
     */
    public function getFileExtension(): string
    {
        return $this->fileLoader->getFileExtension();
    }

    /**
     * Get the file format description for operator prompts.
     *
     * @return string The format description
     */
    public function getFormatDescription(): string
    {
        return $this->fileLoader->getFormatDescription();
    }
}
