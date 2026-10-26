<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Handles interactive prompts for navigation menu selection during WordPress
 * import.
 *
 * This service provides prompts to:
 * 1. Confirm if there's an active menu in the primary navigation position
 * 2. Select which nav_menu to import nav_menu_item posts from
 * 3. Provide helpful links to WordPress menu locations
 */
class NavigationMenuSelector
{
    /**
     * Include the HasPromptsSupport trait to provide platform detection for Laravel
     * Prompts support.
     */
    use HasPromptsSupport;

    /**
     * Include the HasQuestionHelper trait to provide centralized question handling.
     */
    use HasQuestionHelper;

    /**
     * The primary-navigation location slug each UAMS theme registers.
     *
     * WordPress stores assignments in `theme_mods_<stylesheet>.nav_menu_locations`, a map of
     * **location slug** to menu term id. The prompt this replaces named the human-facing
     * labels — "White Bar" and "Primary Navigation Menu" — which are not what the option is
     * keyed by. Measured across 29 site payloads: `uams-2020` registers `primary` (13 sites
     * carry it, 4 of them alongside `quick-links`), and `uams-2016` registers `white-bar`.
     *
     * Keyed by `stylesheet` rather than by scanning every `theme_mods_*` row, because a row
     * outlives the theme that wrote it — one measured site runs `uams-2020` and still carries
     * a `theme_mods_uams-2016` row whose `nav_menu_locations` names a menu. Reading that row
     * would answer for a theme the site stopped running.
     *
     * @var array<string, string>
     */
    private const array PRIMARY_NAVIGATION_LOCATIONS = [
        'uams-2020' => 'primary',
        'uams-2016' => 'white-bar',
    ];

    /**
     * The migration API client, when this run reads from the API rather than a file.
     */
    private ?Client $client = null;

    /**
     * The network site id to scope API reads to, when one was supplied.
     */
    private ?int $site = null;

    /**
     * The resolution this run computed, cached so `/options` is fetched once.
     *
     * Null means not yet attempted; `false` means attempted and unresolvable.
     *
     * @var array{location: string, menu_id: int}|false|null
     */
    private array|false|null $resolution = null;

    /**
     * Point this selector at the migration API.
     *
     * Mirrors `MinervaKbSettings\Loader::setMigrationApiSource()`: the collaborator is
     * supplied after construction, because the source is only known once the command has
     * parsed its options.
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
        $this->resolution = null;
    }

    /**
     * Return this selector to prompting the operator.
     *
     * This class is a container singleton, so the source it was last given outlives the run
     * that set it. The command sets the source explicitly on **both** branches rather than
     * only on the API one, so a second import in the same process cannot inherit the first
     * one's source and answer from the API while its operator waits at a prompt.
     */
    public function clearMigrationApiSource(): void
    {
        $this->client = null;
        $this->site = null;
        $this->resolution = null;
    }

    /**
     * Whether this run answers the navigation prompts from the migration API.
     *
     * @return bool True when a migration API source has been configured
     */
    public function hasMigrationApiSource(): bool
    {
        return $this->client instanceof Client;
    }

    /**
     * Prompt user to confirm if there's an active menu in the primary navigation
     * position.
     *
     * @param  XmlParser  $xmlParser  Parser instance for accessing WordPress XML data
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  The console command instance
     * @return bool True if there's an active menu, false otherwise
     */
    public function confirmActivePrimaryNavigation(
        XmlParser $xmlParser,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): bool {
        // An API-sourced run answers this from the options table rather than asking. The
        // prompt exists because a WXR export carries no options row, so a human had to read
        // the menu-locations screen; that constraint does not apply here (wordpress-importer-archive#1145).
        if ($this->client instanceof Client) {
            $resolved = $this->resolvePrimaryNavigation($output);

            $output->writeln('');
            $output->writeln('<info>Navigation Menu Import</info>');
            $output->writeln('');

            if ($resolved === false) {
                $output->writeln('No menu is assigned to the primary navigation position.');

                return false;
            }

            $output->writeln("Primary navigation resolved from the migration API: location '{$resolved['location']}' is assigned menu {$resolved['menu_id']}.");

            return true;
        }

        $baseBlogUrl = $xmlParser->getBaseBlogUrl();
        $menuLocationsUrl = $this->buildMenuLocationsUrl($baseBlogUrl);

        $output->writeln('');
        $output->writeln('<info>Navigation Menu Import</info>');
        $output->writeln('');
        $output->writeln('Before importing navigation menu items, we need to confirm if there is an active menu in the primary navigation position.');
        $output->writeln('');
        $output->writeln('This position is labeled differently depending on the WordPress theme.');
        $output->writeln('  - UAMS 2016: "White Bar"');
        $output->writeln('  - UAMS 2020: "Primary Navigation Menu"');
        $output->writeln('');
        $output->writeln($menuLocationsUrl);
        $output->writeln('');

        $question = 'Is there an active menu in the primary navigation position?';
        $default = false;

        // Check if the application is running unit tests or input is non-interactive
        if (
            app()->runningUnitTests()
            || ! $input->isInteractive()
        ) {
            // Return default value during automated tests.
            return $default;
        }

        if ($this->isPromptsSupported()) {
            return confirm(
                label: $question,
                default: $default
            );
        }

        // Use Symfony Console to ask for confirmation.
        return $this->askConfirmation(
            $question,
            $default,
            $input,
            $output,
            $command
        );
    }

    /**
     * Resolve the primary-navigation assignment from the migration API.
     *
     * Reads `stylesheet` to learn which theme is live, indexes that theme's `theme_mods_*`
     * row, and looks up the location slug the theme registers for the primary position. A
     * location mapped to a **non-zero** menu term id is an active menu; one mapped to `0` is
     * a registered location with nothing assigned, which is a real and measured state.
     *
     * The result is cached for the run, so `/options` is fetched once even though both
     * navigation prompts consult it.
     *
     * @param  OutputInterface  $output  Console output interface
     * @return array{location: string, menu_id: int}|false The resolution, or false when no
     *                                                     active menu could be established
     */
    private function resolvePrimaryNavigation(
        OutputInterface $output
    ): array|false {
        // Answer from the cache when this run already asked.
        if ($this->resolution !== null) {
            return $this->resolution;
        }

        if (! $this->client instanceof Client) {
            return $this->resolution = false;
        }

        // Read the options table. A failure here is reported and treated as "no active
        // menu" rather than raised: the navigation section is optional, and an API-sourced
        // run has no terminal to fall back to.
        try {
            $payload = $this->client->get('options', $this->site);
        } catch (MigrationApiException $migrationApiException) {
            $output->writeln('<comment>Could not read the options table to resolve the primary navigation menu: '.$migrationApiException->getMessage().'</comment>');

            return $this->resolution = false;
        }

        $options = $payload['options'] ?? null;

        if (! \is_array($options)) {
            $output->writeln('<comment>The options payload carried no options map; treating the primary navigation position as empty</comment>');

            return $this->resolution = false;
        }

        // `stylesheet` names the live theme, and so which `theme_mods_*` row to believe.
        $stylesheet = $options['stylesheet'] ?? null;

        if (! \is_string($stylesheet) || $stylesheet === '') {
            $output->writeln('<comment>The options payload named no live theme; treating the primary navigation position as empty</comment>');

            return $this->resolution = false;
        }

        $locationSlug = self::PRIMARY_NAVIGATION_LOCATIONS[$stylesheet] ?? null;

        if ($locationSlug === null) {
            $output->writeln("<comment>Theme '{$stylesheet}' registers no known primary navigation location; treating the position as empty</comment>");

            return $this->resolution = false;
        }

        $themeMods = $options['theme_mods_'.$stylesheet] ?? null;

        // The endpoint serves this row already decoded, but a string is tolerated so a
        // change in delivery does not read as an absent assignment.
        if (\is_string($themeMods)) {
            $decoded = \json_decode($themeMods, true);
            $themeMods = \is_array($decoded) ? $decoded : null;
        }

        $locations = \is_array($themeMods) ? ($themeMods['nav_menu_locations'] ?? null) : null;

        if (! \is_array($locations)) {
            return $this->resolution = false;
        }

        $menuId = $locations[$locationSlug] ?? null;

        // WordPress stores the id as an integer; a digit string is accepted because the
        // JSON round trip is not guaranteed to preserve the distinction.
        if (\is_string($menuId) && \ctype_digit($menuId)) {
            $menuId = (int) $menuId;
        }

        if (! \is_int($menuId) || $menuId === 0) {
            return $this->resolution = false;
        }

        return $this->resolution = [
            'location' => $locationSlug,
            'menu_id' => $menuId,
        ];
    }

    /**
     * Prompt user to select which nav_menu to import nav_menu_item posts from.
     *
     * @param  XmlParser  $xmlParser  Parser instance for accessing WordPress XML data
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  The console command instance
     * @return string|null The selected menu slug or null if no menu selected
     */
    public function selectNavigationMenu(
        XmlParser $xmlParser,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): ?string {
        $navMenus = $this->getNavigationMenus($xmlParser);

        if ($navMenus === []) {
            $output->writeln('<comment>No navigation menus found in the WordPress export</comment>');

            return null;
        }

        // The resolved assignment already names which menu occupies the position, so an
        // API-sourced run picks it by term id rather than asking. Without this the run still
        // stops at the second prompt and --key-from-stdin cannot complete (wordpress-importer-archive#1145).
        if ($this->client instanceof Client) {
            $resolved = $this->resolvePrimaryNavigation($output);

            if ($resolved === false) {
                return null;
            }

            foreach ($navMenus as $menu) {
                if ($menu['id'] !== (string) $resolved['menu_id']) {
                    continue;
                }

                $output->writeln("Importing '{$menu['name']}' (slug: {$menu['slug']}), the menu assigned to '{$resolved['location']}'.");

                return $menu['slug'];
            }

            $output->writeln("<comment>Menu {$resolved['menu_id']} is assigned to '{$resolved['location']}' but is not present in the export; skipping navigation import</comment>");

            return null;
        }

        $output->writeln('');
        $output->writeln('<info>Available Navigation Menus</info>');
        $output->writeln('');

        // Display available menus
        foreach ($navMenus as $menu) {
            $output->writeln("• {$menu['name']} (slug: {$menu['slug']})");
        }

        $output->writeln('');

        // Build choice options
        $choices = [];
        $menuSlugs = [];
        foreach ($navMenus as $menu) {
            $choice = "{$menu['name']} ({$menu['slug']})";
            $choices[] = $choice;
            $menuSlugs[] = $menu['slug'];
        }

        $question = 'Which navigation menu would you like to import?';
        $defaultIndex = 0;

        // Check if the application is running unit tests or input is non-interactive
        if (
            app()->runningUnitTests()
            || ! $input->isInteractive()
        ) {
            // Return first menu slug during automated tests.
            return $menuSlugs[$defaultIndex];
        }

        if ($this->isPromptsSupported()) {
            $selectedChoice = select(
                label: $question,
                options: $choices,
                default: $choices[$defaultIndex]
            );
            // Find the index of the selected choice
            $selectedIndex = \array_search(
                $selectedChoice,
                $choices,
                true
            );
            if ($selectedIndex !== false && isset($menuSlugs[$selectedIndex])) {
                return $menuSlugs[$selectedIndex];
            }

            if (
                is_int($selectedChoice)
                && isset($choices[$selectedChoice])
            ) {
                $selectedChoice = $choices[$selectedChoice];
            }

            if (! is_string($selectedChoice)) {
                return null;
            }

            // If array_search failed, try to extract the slug from the choice text
            // Format: "Menu Name (slug)"
            if (
                \preg_match(
                    '/\(([^)]+)\)$/',
                    $selectedChoice,
                    $matches
                ) === 1
            ) {
                return $matches[1];
            }

            return null;
        }

        // Use Symfony Console to select the navigation menu
        $selectedChoice = $this->askChoice(
            $question,
            $choices,
            $defaultIndex,
            $input,
            $output,
            $command
        );

        // Find the index of the selected choice
        $selectedIndex = \array_search(
            $selectedChoice,
            $choices,
            true
        );
        if ($selectedIndex !== false && isset($menuSlugs[$selectedIndex])) {
            return $menuSlugs[$selectedIndex];
        }

        // If array_search failed, try to extract the slug from the choice text
        // Format: "Menu Name (slug)"
        if (
            \preg_match(
                '/\(([^)]+)\)$/',
                $selectedChoice,
                $matches
            ) === 1
        ) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get available navigation menus from WordPress XML.
     *
     * @param  XmlParser  $xmlParser  Parser instance for accessing WordPress XML data
     * @return array<int, array{id: string, slug: string, name: string}> Array of
     *                                                                   navigation menus with normalized id, slug, and name fields
     */
    private function getNavigationMenus(
        XmlParser $xmlParser
    ): array {
        $terms = $xmlParser->getTerms();
        $navMenus = [];

        // Initialize a map to store term data, keyed by term ID, to handle duplicates
        $termDataMap = []; // Map term IDs to their data, preferring terms with metadata

        foreach ($terms as $term) {
            if (! $term instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the term taxonomy from WordPress XML
            $termTaxonomy = XpathHelper::getStringValue(
                $term,
                'wp:term_taxonomy'
            );

            // Check if this term is a navigation menu
            if ($termTaxonomy === 'nav_menu') {
                // Extract the term ID from WordPress XML
                $termId = XpathHelper::getStringValue(
                    $term,
                    'wp:term_id'
                );

                // Extract the term slug from WordPress XML
                $termSlug = XpathHelper::getStringValue(
                    $term,
                    'wp:term_slug'
                );

                // Extract and strip HTML tags from the term name
                $termName = HtmlTagStripper::strip(
                    XpathHelper::getStringValue(
                        $term,
                        'wp:term_name'
                    )
                );

                // Validate that we have all required term data before processing
                if (
                    $termId !== ''
                    && $termSlug !== ''
                    && $termName !== ''
                ) {
                    // Check if this term has metadata (more complete data)
                    $hasMetadata = $term->xpath('wp:termmeta') !== [];

                    // ---
                    // Check if we haven't seen this term ID before, or if this term has metadata and
                    // the previous one didn't
                    // ---
                    if (
                        ! isset($termDataMap[$termId])
                        || (
                            $hasMetadata
                            && ! ($termDataMap[$termId]['has_metadata'])
                        )
                    ) {
                        // Use this term's data
                        $termDataMap[$termId] = [
                            'id' => $termId,
                            'slug' => $termSlug,
                            'name' => $termName,
                            'has_metadata' => $hasMetadata,
                        ];
                    }
                }
            }
        }

        // Convert the deduplicated term data map to the final navigation menus array
        foreach ($termDataMap as $termData) {
            // Extract only the essential data needed for navigation menu selection
            $navMenus[] = [
                'id' => $termData['id'],
                'slug' => $termData['slug'],
                'name' => $termData['name'],
            ];
        }

        // Sort by name for consistent display
        usort(
            $navMenus,
            fn (array $a, array $b): int => strcmp(
                $a['name'],
                $b['name']
            )
        );

        return $navMenus;
    }

    /**
     * Build the WordPress menu locations URL with appropriate login redirect.
     *
     * This method checks which login URL is available (uams-login or wp-login.php)
     * and builds the appropriate redirect URL to the menu locations page.
     *
     * @param  string  $baseBlogUrl  The base blog URL from WordPress XML
     * @return string The complete menu locations URL with login redirect
     */
    private function buildMenuLocationsUrl(
        string $baseBlogUrl
    ): string {
        // Remove trailing slash if present
        $baseUrl = \rtrim(
            $baseBlogUrl,
            '/'
        );

        // Target URL for menu locations
        $targetUrl = "{$baseUrl}/wp-admin/nav-menus.php?action=locations";

        // Encode the target URL
        $encodedTargetUrl = urlencode($targetUrl);

        // Build the query string
        $queryString = "redirect_to={$encodedTargetUrl}&reauth=1";

        // Build the Inside UAMS login URL
        $insideUamsLoginUrl = "{$baseUrl}/classifieds/";

        // Build the UAMS login URL
        $uamsLoginUrl = "{$baseUrl}/uams-login/";

        // Build the standard WordPress login URL
        $wpLoginUrl = "{$baseUrl}/wp-login.php";
        // Check if base URL begins with https://inside.uams.edu
        if (str_starts_with($baseUrl, 'https://inside.uams.edu')) {
            return "Log in at <href={$insideUamsLoginUrl}>{$insideUamsLoginUrl}</>.\n\nOnce logged in, you can check the menu locations at <href={$targetUrl}>{$targetUrl}</>.";
        }

        // Check if base URL begins with https://inside.uams.edu
        if ($this->isUamsLoginAccessible($uamsLoginUrl)) {
            // Check if UAMS login URL is accessible (returns 200)
            // ---
            // If user is already authenticated on the relevant site, they will be redirected
            // to the dashboard. But the user can click the target URL link to access the
            // menu locations page after that.
            // ---
            return "Log in at <href={$uamsLoginUrl}>{$uamsLoginUrl}</>.\n\nOnce logged in, you can check the menu locations at <href={$targetUrl}>{$targetUrl}</>.";
        }

        // Fall back to WordPress login URL
        return "You can check the menu locations at <href={$wpLoginUrl}?{$queryString}>{$wpLoginUrl}?{$queryString}</>";
    }

    /**
     * Check if UAMS login URL is accessible by making an HTTP request.
     *
     * @param  string  $uamsLoginUrl  The UAMS login URL to test
     * @return bool True if the URL returns 200, false otherwise
     */
    private function isUamsLoginAccessible(
        string $uamsLoginUrl
    ): bool {
        try {
            // Make HTTP GET request to test UAMS login URL accessibility
            $response = Http::timeout(10)
                ->get($uamsLoginUrl);

            // Return true only if we get a 200 response
            return $response->status() === 200;
        } catch (\Exception) {
            // If any exception occurs (timeout, connection error, etc.), consider it inaccessible
            return false;
        }
    }
}
