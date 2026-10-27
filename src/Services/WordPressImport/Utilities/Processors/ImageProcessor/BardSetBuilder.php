<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor;

use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Builds Statamic Bard image sets from processed data.
 *
 * Constructs a ProseMirror-compatible Bard set JSON array using the image path,
 * alignment, caption, and alt override values in the context.
 */
class BardSetBuilder implements ImageProcessingStepInterface
{
    /**
     * Builds the final Bard image set.
     *
     * Creates a Bard set with fields for image, align, caption, and alt_override
     * using context data. Includes the caption field even if empty to align with
     * Statamic's behavior. Logging is omitted to minimize verbosity.
     *
     * @param  array<int|string, mixed>  $context  Processing context with 'imagePath', 'align', 'caption', 'altOverride'
     * @param  string  $site  Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  ?string  $pageSlug  Page slug for context (optional)
     * @return array<int|string, mixed> Updated context with 'imageSet'
     */
    public function process(
        array $context,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?string $pageSlug = null
    ): array {
        if (! isset($context['imagePath'])) {
            return $context;
        }

        $values = [
            'type' => 'image',
            'image' => $context['imagePath'],
            'align' => $context['align'] ?? 'aligncenter',
        ];

        // Extract caption from context data
        $caption = $context['caption'] ?? '';

        // Only include caption field if it has a non-empty value
        if ($caption !== '') {
            // Add caption to the Bard set values
            $values['caption'] = $caption;
        }

        // Write the override under the handle the host image set declares. The
        // top-level path carries values through `data-set-attr-*` attributes, whose
        // names the DOM lowercases, so a camelCase key would not survive it (#141)
        if (
            isset($context['altOverride'])
            && $context['altOverride'] !== ''
        ) {
            $values['alt_override'] = $context['altOverride'];
        }

        $set = [
            'type' => 'set',
            'attrs' => [
                'id' => (new RowId)->generate(),
                'values' => $values,
            ],
        ];

        $context['imageSet'] = $set;

        return $context;
    }
}
