<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Statamic\Forms\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

/**
 * Shared functionality for form plugin coordinators.
 */
trait FormPluginCoordinatorTrait
{
    protected BaseContentDetector $contentDetector;

    protected BaseFormIdExtractor $formIdExtractor;

    protected BaseDataManager $dataManager;

    protected ?FormPluginFormCreationCoordinatorContract $formCreationCoordinator = null;

    protected FormPluginUserInteractionHandlerContract $userInteractionHandler;

    protected function initializeFormPluginCoordinator(
        BaseContentDetector $contentDetector,
        BaseFormIdExtractor $formIdExtractor,
        BaseDataManager $dataManager,
        ?FormPluginFormCreationCoordinatorContract $formCreationCoordinator,
        FormPluginUserInteractionHandlerContract $userInteractionHandler
    ): void {
        $this->contentDetector = $contentDetector;
        $this->formIdExtractor = $formIdExtractor;
        $this->dataManager = $dataManager;
        $this->formCreationCoordinator = $formCreationCoordinator;
        $this->userInteractionHandler = $userInteractionHandler;
        $this->setupCoordinatorDependency();
    }

    /**
     * @param  array<int|string, mixed>|null  $forms  Loaded form plugin JSON data
     */
    protected function setFormPluginData(?array $forms): void
    {
        $this->dataManager->setFormsData($forms);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    protected function getFormPluginData(): ?array
    {
        return $this->dataManager->getFormsData();
    }

    protected function hasFormPluginContent(XmlParser $xmlParser): bool
    {
        return $this->contentDetector->hasFormPluginContent($xmlParser);
    }

    /**
     * Collect the form IDs the imported content references, with the pages referencing them.
     *
     * Replaces the former `getFormPluginFormIdsUsed()`, which discarded the page slugs and
     * had **no caller** for Gravity Forms or Formidable Forms. The slugs are the reason
     * extraction exists at all now: form *creation* reads IDs recorded by the block
     * processors, so the only thing extraction can tell anyone is which page referenced a
     * form the supplied export does not contain. Discarding the slugs discarded that.
     *
     * The key type is `array-key`, not `string`, and deliberately so: PHP coerces a
     * numeric-string array key to an int, so a form ID of `'7'` is stored under `7`
     * however it is written. Callers must compare as strings rather than strictly, which
     * is why this does not promise `array<string, …>` it cannot deliver.
     *
     * @param  XmlParser  $xmlParser  The XML parser for the export being imported
     * @return array<array-key, list<string>> Form ID => the page slugs referencing it
     */
    protected function getFormPluginFormIdsWithPages(XmlParser $xmlParser): array
    {
        $referenced = [];

        foreach (
            $this->formIdExtractor->getFormIdsUsedWithPageSlugs($xmlParser) as $formId => $pageSlugs
        ) {
            // Ids arrive as ints or numeric strings depending on how the plugin writes the
            // block attribute; normalise so callers compare against one type. An array key
            // is already an int or a string, and only the string '' is empty, so dropping
            // the cast changes no result; it keeps the empty check reading as a string test.
            // @pest-mutate-ignore: RemoveStringCast
            $key = (string) $formId;

            if ($key === '') {
                continue;
            }

            // The extractor already excludes empty slugs, so this only re-keys.
            $referenced[$key] = $pageSlugs;
        }

        return $referenced;
    }

    /**
     * @param  Closure(int, array<int|string, mixed>, string, string, AssetImporter): ?Form  $createForm
     */
    protected function createFormPluginFormIfNeeded(
        int $formId,
        string $siteName,
        string $siteHandle,
        Closure $createForm,
        string $pluginName,
        AssetImporter $assetImporter
    ): ?Form {
        $formsData = $this->dataManager->getFormsData();

        if ($formsData === null) {
            Log::error("{$pluginName}Coordinator: {$pluginName} data is not loaded. Cannot create form for ID $formId.");

            return null;
        }

        return $createForm(
            $formId,
            $formsData,
            $siteName,
            $siteHandle,
            $assetImporter
        );
    }

    protected function getCreatedFormHandleForPlugin(int $formId): ?string
    {
        if ($this->formCreationCoordinator === null) {
            return null;
        }

        return $this->formCreationCoordinator->getCreatedFormHandle($formId);
    }

    private function setupCoordinatorDependency(): void
    {
        $this->formCreationCoordinator ??= $this->createFormCreationCoordinator();

        $this->formCreationCoordinator->setCoordinator($this);
    }

    public function getFormCreationCoordinator(): ?FormPluginFormCreationCoordinatorContract
    {
        return $this->formCreationCoordinator;
    }

    abstract protected function createFormCreationCoordinator(): FormPluginFormCreationCoordinatorContract;

    protected function clearFormPluginData(): void
    {
        $this->dataManager->clearFormsData();
    }

    protected function handleFormPluginDetection(
        XmlParser $xmlParser,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): bool {
        return $this->userInteractionHandler->handleFormPluginDetection(
            $xmlParser,
            $input,
            $output,
            $command
        );
    }
}
