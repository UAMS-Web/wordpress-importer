<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Processes alt text for images, setting alt_override if needed.
 *
 * Compares the <img> alt attribute with the attachment's alt text, setting
 * alt_override in the Bard set if they differ and the <img> alt is non-empty.
 */
class AltTextProcessor implements ImageProcessingStepInterface
{
    /**
     * Compares and sets alt_override for the image.
     *
     * Retrieves attachment alt text for wp:image blocks and compares it with
     * the <img> alt attribute. Logs when alt_override is set.
     *
     * @param  array<int|string, mixed>  $context  The processing context with 'imgAlt', 'attachmentId'
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  ?string  $pageSlug  The page slug for logging
     * @return array<int|string, mixed> The updated context with 'altOverride'
     */
    public function process(
        array $context,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?string $pageSlug = null
    ): array {
        // Another default changes the result only for an image alt equal to it, and
        // the one a mutant could use is the mutation tool's own marker text (#1314)
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $attachmentAlt = '';
        $imgAlt = $context['imgAlt'] ?? '';
        // Another default for a missing id is looked up and matches only an attachment
        // whose post ID is that text; export post IDs are numeric, so it finds none
        // and leaves the attachment alt empty, as skipping the lookup does (#1314)
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $attachmentId = (\is_string($context['attachmentId'] ?? null) || \is_int($context['attachmentId'] ?? null))
            ? (string) $context['attachmentId']
            : '';

        if ($attachmentId !== '') {
            // Find the matching attachment using the helper method
            $attachment = XpathHelper::findAttachmentById(
                $attachments,
                $attachmentId
            );

            // Check if matching attachment was found
            if ($attachment instanceof \SimpleXMLElement) {
                // Extract alt text using the helper method
                $attachmentAlt = XpathHelper::getStringValue(
                    $attachment,
                    "wp:postmeta[wp:meta_key='_wp_attachment_image_alt']/wp:meta_value"
                );
            }
        }

        $altOverride = null;

        if (
            $imgAlt !== $attachmentAlt
            && $imgAlt !== ''
        ) {
            $altOverride = $imgAlt;
        }

        $context['altOverride'] = $altOverride;

        return $context;
    }
}
