<?php

namespace Uo\AtlassianJiraMigration\Utils;


class ADFSanitizer
{
    private const MAX_DEPTH = 10;

    private const ALLOWED_TYPES = [
        'doc', 'paragraph', 'text', 'heading', 'bulletList', 'orderedList', 'listItem',
        'table', 'tableRow', 'tableCell', 'tableHeader', 'mediaSingle', 'media',
        'blockquote', 'codeBlock', 'panel', 'rule', 'hardBreak', 'emoji'
    ];

    /**
     * Validates a final ADF node, ensuring it is ready to be sent to Jira.
     *
     * This function processes a final ADF node by performing the following steps:
     * 1. Skips empty or invalid nodes.
     * 2. Ensures 'content' is an array, or removes it if not.
     * 3. Recursively validates content.
     * 4. Skips malformed 'marks'.
     * 5. Removes empty 'attrs' unless required.
     *
     * @param array $node The ADF node to be validated.
     * @return array|null The validated ADF node, or null if it is not valid.
     */
    public function validateFinalADF(array $node): ?array {
        if (!is_array($node) || !isset($node['type'])) {
            return null;
        }

        // Force 'content' to be an array if present, or remove if invalid
        if (isset($node['content']) && !is_array($node['content'])) {
            unset($node['content']);
        }

        // Validate content recursively
        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_values(array_filter(array_map(
                fn($child) => $this->validateFinalADF($child),
                $node['content']
            )));
        }

        // Strip marks if malformed
        if (isset($node['marks']) && !is_array($node['marks'])) {
            unset($node['marks']);
        }

        // Strip empty or non-associative attrs
        if (isset($node['attrs']) && (!is_array($node['attrs']) || array_values($node['attrs']) === $node['attrs'])) {
            unset($node['attrs']);
        }

        return $node;
    }

    /**
     * Extracts the plain text from an ADF node.
     *
     * This method recursively traverses the ADF node structure and concatenates the text of each node into a single string.
     *
     * @param array $adf The ADF node to extract the plain text from.
     * @return string The plain text from the ADF node.
     */
    public function adfToPlainText(array $adf): string {
        $text = '';

        $extractText = function ($nodes) use (&$extractText, &$text) {
            foreach ($nodes as $node) {
                if (isset($node['text'])) {
                    $text .= $node['text'];
                }
                if (isset($node['content'])) {
                    $extractText($node['content']);
                    $text .= "\n";
                }
            }
        };

        $extractText($adf['content'] ?? []);
        return trim($text);
    }

    public function sanitize(array $node, int $depth = 0, bool $inListItemContext = false): ?array
    {
        if ($depth > self::MAX_DEPTH || !isset($node['type']) || !in_array($node['type'], self::ALLOWED_TYPES)) {
            return null;
        }

        // Remove empty attrs from table structures or non-associative ones
        if (isset($node['attrs']) && (!is_array($node['attrs']) || array_values($node['attrs']) === $node['attrs'])) {
            unset($node['attrs']);
        }

        if (in_array($node['type'], ['table', 'tableRow', 'tableCell', 'tableHeader']) &&
            is_array($node['attrs'] ?? null) && empty($node['attrs'])) {
            unset($node['attrs']);
        }

        // Sanitize content
        if (isset($node['content']) && is_array($node['content'])) {
            // Flatten any nested `type: doc` nodes
            $flattenedContent = [];
            foreach ($node['content'] as $child) {
                if (is_array($child) && ($child['type'] ?? '') === 'doc' && isset($child['content']) && is_array($child['content'])) {
                    foreach ($child['content'] as $grandchild) {
                        $flattenedContent[] = $grandchild;
                    }
                } else {
                    $flattenedContent[] = $child;
                }
            }
            $node['content'] = $flattenedContent;

            if ($node['type'] === 'tableCell' || $node['type'] === 'tableHeader') {
                $node['content'] = $this->sanitizeTableCellContent($node['content'], $depth + 1);
                if (empty($node['content'])) {
                    $node['content'] = [[ 'type' => 'paragraph', 'content' => [] ]];
                }
            } elseif ($node['type'] === 'listItem') {
                $node['content'] = $this->fixListItemContent($node['content'], $depth + 1);
            } elseif ($node['type'] === 'paragraph') {
                $node['content'] = $this->sanitizeParagraphContent($node['content']);
            } else {
                $node['content'] = array_values(array_filter(array_map(
                    fn($child) => $this->sanitize($child, $depth + 1, $node['type'] === 'listItem'),
                    $node['content']
                )));
            }
        }

        // Strip invalid marks
        if (isset($node['marks']) && is_array($node['marks'])) {
            $node['marks'] = array_values(array_filter($node['marks'], fn($mark) =>
                is_array($mark) && isset($mark['type']) && is_string($mark['type'])
            ));
        }

        // Convert heading to paragraph inside table cells
        if ($this->isParentTableCellContext($depth) && $node['type'] === 'heading') {
            $node['type'] = 'paragraph';
            unset($node['attrs']);
        }

        // Drop meaningless content
        if ($node['type'] === 'paragraph' && empty($node['content'])) {
            return null;
        }
        if ($node['type'] === 'text' && (!isset($node['text']) || trim($node['text']) === '')) {
            return null;
        }
        if ($node['type'] === 'heading' && (!isset($node['attrs']['level']) || empty($node['content']))) {
            return null;
        }

        $allowedKeys = ['type', 'content', 'text', 'marks', 'attrs', 'version'];
        return array_intersect_key($node, array_flip($allowedKeys));
    }

    private function flattenADFContent(array $content, int $depth): array
    {
        $result = [];
        foreach ($content as $child) {
            $sanitized = $this->sanitize($child, $depth);
            if ($sanitized !== null) {
                $result[] = $sanitized;
            }
        }
        return $result;
    }

    private function sanitizeParagraphContent(array $content): array
    {
        $flattened = [];
        foreach ($content as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'paragraph') {
                $inner = $child['content'] ?? [];
                $flattened = array_merge($flattened, $this->sanitizeParagraphContent($inner));
            } else {
                $flattened[] = $child;
            }
        }
        return $flattened;
    }

    private function sanitizeTableCellContent(array $content, int $depth): array
    {
        $sanitized = [];
        foreach ($content as $child) {
            if (($child['type'] ?? null) === 'heading') {
                $sanitized[] = [
                    'type' => 'paragraph',
                    'content' => isset($child['content']) ? $this->flattenADFContent($child['content'], $depth + 1) : [],
                ];
            } else {
                $result = $this->sanitize($child, $depth);
                if ($result !== null) {
                    $sanitized[] = $result;
                }
            }
        }
        return $sanitized;
    }

    private function fixListItemContent(array $content, int $depth): array
    {
        $paragraphs = [];
        $lists = [];

        foreach ($content as $item) {
            if (!is_array($item) || !isset($item['type'])) {
                continue;
            }

            if (in_array($item['type'], ['bulletList', 'orderedList'])) {
                $sanitizedList = $this->sanitize($item, $depth + 1);
                if ($sanitizedList !== null) {
                    $lists[] = $sanitizedList;
                }
            } elseif ($item['type'] === 'paragraph') {
                $sanitizedParagraph = $this->sanitize($item, $depth + 1);
                if ($sanitizedParagraph !== null) {
                    $paragraphs[] = $sanitizedParagraph;
                }
            }
            // Drop anything else like bare listItems or invalid blocks
        }

        if (empty($paragraphs)) {
            $paragraphs[] = [
                'type' => 'paragraph',
                'content' => [[ 'type' => 'text', 'text' => '' ]]
            ];
        }

        return array_merge($paragraphs, $lists);
    }

    private function isParentTableCellContext(int $depth): bool
    {
        return $depth >= 2;
    }
}
