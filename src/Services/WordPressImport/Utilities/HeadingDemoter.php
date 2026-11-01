<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Demotes heading levels in Statamic Bard content arrays.
 *
 * Shifts heading levels down (h2→h3, h3→h4, h4→h5, h5→h6) and converts h6 to bold
 * paragraphs, used for non-ACF block content in WordPress imports to align with
 * mixed content specifications. Processes ProseMirror JSON arrays post-Tiptap
 * parsing.
 */
class HeadingDemoter
{
    /**
     * Demotes headings in a Bard content array.
     *
     * Iterates through Bard nodes, incrementing heading levels (e.g., 2 to 3) up
     * to 6. Converts level 6 headings to paragraphs with bold marks applied to all
     * text content.
     *
     * @param  array<int|string, mixed>  $bardContent  The Bard content array (ProseMirror JSON format)
     * @return array<int|string, mixed> The modified Bard content with demoted headings
     */
    public function demote(
        array $bardContent
    ): array {
        foreach ($bardContent as &$node) {
            if (! \is_array($node)) {
                continue;
            }

            $attrs = $node['attrs'] ?? null;

            if (
                ($node['type'] ?? null) !== 'heading'
                || ! \is_array($attrs)
                || ! isset($attrs['level'])
            ) {
                continue;
            }

            $currentLevel = \is_numeric($attrs['level'])
                ? (int) $attrs['level']
                : 0;

            if ($currentLevel >= 6) {
                // Convert h6 (or higher) to bold paragraph
                $content = $node['content'] ?? [];

                $node = [
                    'type' => 'paragraph',
                    'content' => \array_map(function ($textNode) {
                        if (! \is_array($textNode)) {
                            return $textNode;
                        }

                        if (($textNode['type'] ?? null) !== 'text') {
                            return $textNode;
                        }

                        $existingMarks = $textNode['marks'] ?? [];

                        if (! \is_array($existingMarks)) {
                            $existingMarks = [];
                        }

                        return \array_merge(
                            $textNode,
                            [
                                'marks' => \array_merge(
                                    $existingMarks,
                                    [['type' => 'bold']]
                                ),
                            ]
                        );
                    }, \is_array($content) ? $content : []),
                ];
            } else {
                // Demote heading level (e.g., h2→h3). Levels of six and above took
                // the branch above, so this never exceeds six.
                $updatedAttrs = $attrs;
                $updatedAttrs['level'] = $currentLevel + 1;
                $node['attrs'] = $updatedAttrs;
            }
        }

        return $bardContent;
    }
}
