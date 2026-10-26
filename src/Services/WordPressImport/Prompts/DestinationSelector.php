<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts;

use Illuminate\Console\Command;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Taxonomies\Taxonomy as StatamicTaxonomy;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;

/**
 * Handles the selection of an import destination (collection or taxonomy) and its
 * configuration.
 *
 * This class prompts the user to choose between importing into a collection or
 * taxonomy, selects the specific handle, and enables the site for the chosen
 * destination if necessary.
 */
class DestinationSelector
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
     * Create a new DestinationSelector instance.
     *
     * @param  SiteManager  $siteManager  The site manager service
     */
    public function __construct(private SiteManager $siteManager) {}

    /**
     * Prompt the user to choose whether to import into a collection or taxonomy and
     * select the handle.
     *
     * If collection is chosen, prompts for a specific collection; otherwise, for a
     * taxonomy. Handles enabling the site for the selected destination if not already
     * enabled.
     *
     * @param  string  $site  The selected site handle
     * @param  string  $contentType  The selected content type
     * @param  InputInterface  $input  Console input interface for user interaction
     * @param  OutputInterface  $output  Console output interface for feedback
     * @param  Command  $command  Command instance for helper access and output
     * @return array{type: string, handle: string} Selected destination type and handle
     */
    public function select(
        string $site,
        string $contentType,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): array {
        $options = ['collection' => 'Collection', 'taxonomy' => 'Taxonomy'];
        $selectedOption = $this->askChoice(
            "Would you like to import '$contentType' items into a collection or a taxonomy?",
            $options,
            'collection',
            $input,
            $output,
            $command
        );
        $destinationType = \array_search(
            $selectedOption,
            $options,
            true
        );

        if ($destinationType === 'collection') {
            $collectionHandles = Collection::all();
            $collections = [];

            foreach ($collectionHandles as $handle) {
                if (! \is_string($handle)) {
                    continue;
                }

                $collection = Collection::findByHandle($handle);

                if ($collection !== null) {
                    $title = $collection->title();
                    $collections[$handle] = \is_string($title) ? $title : $handle;
                }
            }

            if ($collections === []) {
                $command->error('No collections found in Statamic.');
                exit(1);
            }

            $collectionHandle = $this->selectCollection(
                $site,
                $collections,
                $input,
                $output,
                $command
            );

            return ['type' => 'collection', 'handle' => $collectionHandle];
        }

        $taxonomyHandles = Taxonomy::all();
        $taxonomies = [];
        foreach ($taxonomyHandles as $handle) {
            if (! \is_string($handle)) {
                continue;
            }

            $taxonomy = Taxonomy::findByHandle($handle);

            if ($taxonomy instanceof StatamicTaxonomy) {
                $title = $taxonomy->title();
                $taxonomies[$handle] = \is_string($title) ? $title : $handle;
            }
        }

        if ($taxonomies === []) {
            $command->error('No taxonomies found in Statamic.');
            exit(1);
        }

        $taxonomyHandle = $this->selectTaxonomy(
            $site,
            $taxonomies,
            $input,
            $output,
            $command
        );

        return ['type' => 'taxonomy', 'handle' => $taxonomyHandle];
    }

    /**
     * Prompt the user to select a collection and enable it for the site if needed.
     *
     * @param  string  $site  The selected site handle
     * @param  array<int|string, mixed>  $collections  Array of collection handles to titles
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return string The selected collection handle
     */
    protected function selectCollection(
        string $site,
        array $collections,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): string {
        $selected = $this->askChoice(
            'Which collection would you like to import into?',
            $collections,
            $this->defaultChoiceKey($collections),
            $input,
            $output,
            $command
        );
        $collectionHandle = $this->resolveChoiceHandle(
            $selected,
            $collections
        );

        Collection::findByHandle($collectionHandle);

        if (
            ! $this->siteManager->isCollectionEnabledForSite(
                $site,
                $collectionHandle
            )
        ) {
            $output->writeln("<comment>The '$collectionHandle' collection is not enabled for the '$site' site.</comment>");
            $enable = $this->askConfirmation(
                "Would you like to enable the '$collectionHandle' collection for the '$site' site?",
                false,
                $input,
                $output,
                $command
            );

            if (! $enable) {
                return $this->selectCollection(
                    $site,
                    $collections,
                    $input,
                    $output,
                    $command
                );
            }

            $this->siteManager->enableCollectionForSite(
                $site,
                $collectionHandle,
                $output
            );
        }

        return $collectionHandle;
    }

    /**
     * Prompt the user to select a taxonomy and enable it for the site if needed.
     *
     * @param  string  $site  The selected site handle
     * @param  array<int|string, mixed>  $statamicTaxonomyHandles  Array of Statamic taxonomy handles to titles
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return string The selected taxonomy handle
     */
    protected function selectTaxonomy(
        string $site,
        array $statamicTaxonomyHandles,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): string {
        $selected = $this->askChoice(
            'Which taxonomy would you like to import into?',
            $statamicTaxonomyHandles,
            $this->defaultChoiceKey($statamicTaxonomyHandles),
            $input,
            $output,
            $command
        );
        $taxonomyHandle = $this->resolveChoiceHandle(
            $selected,
            $statamicTaxonomyHandles
        );

        Taxonomy::findByHandle($taxonomyHandle);

        if (
            ! $this->siteManager->isTaxonomyEnabledForSite(
                $site,
                $taxonomyHandle
            )
        ) {
            $output->writeln("<comment>The '$taxonomyHandle' taxonomy is not enabled for the '$site' site.</comment>");
            $enable = $this->askConfirmation(
                "Would you like to enable the '$taxonomyHandle' taxonomy for the '$site' site?",
                false,
                $input,
                $output,
                $command
            );

            if (! $enable) {
                return $this->selectTaxonomy(
                    $site,
                    $statamicTaxonomyHandles,
                    $input,
                    $output,
                    $command
                );
            }

            $this->siteManager->enableTaxonomyForSite(
                $site,
                $taxonomyHandle,
                $output
            );
        }

        return $taxonomyHandle;
    }

    /**
     * Resolve the first valid array key for use as an askChoice default.
     *
     * @param  array<int|string, mixed>  $options  The choice options keyed by handle
     * @return int|string The default choice key
     */
    private function defaultChoiceKey(
        array $options
    ): int|string {
        foreach (\array_keys($options) as $key) {
            return $key;
        }

        return 0;
    }

    /**
     * Resolve a selected choice label back to its option handle key.
     *
     * @param  string  $selected  The selected choice label
     * @param  array<int|string, mixed>  $options  The choice options keyed by handle
     * @return string The resolved handle key
     */
    private function resolveChoiceHandle(
        string $selected,
        array $options
    ): string {
        $handle = \array_search(
            $selected,
            $options,
            true
        );

        if ($handle === false) {
            $fallback = $this->defaultChoiceKey($options);

            return \is_string($fallback) ? $fallback : (string) $fallback;
        }

        return \is_string($handle) ? $handle : (string) $handle;
    }
}
