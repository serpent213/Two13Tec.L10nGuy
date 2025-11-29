<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Service;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Utility\Files;
use Two13Tec\L10nGuy\Domain\Dto\CatalogEntry;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Utility\PathResolver;

/**
 * Applies catalog mutations and writes deterministic XLF files.
 *
 * @Flow\Scope("singleton")
 */
final class CatalogWriter
{
    public function __construct(
        #[Flow\InjectConfiguration(path: 'tabWidth', package: 'Two13Tec.L10nGuy')]
        protected int $tabWidth = 2
    ) {
        if ($this->tabWidth < 0) {
            $this->tabWidth = 0;
        }
    }

    /**
     * @param list<CatalogMutation> $mutations
     * @return list<string> Absolute file paths that were touched
     */
    public function write(array $mutations, CatalogIndex $catalogIndex, ScanConfiguration $configuration, string $basePath = FLOW_PATH_ROOT): array
    {
        if ($mutations === []) {
            return [];
        }

        $grouped = $this->groupMutations($mutations);
        $touched = [];

        foreach ($grouped as $key => $group) {
            [$locale, $packageKey, $sourceName] = explode('|', $key, 3);
            $existingPath = $catalogIndex->catalogPath($locale, $packageKey, $sourceName);
            $filePath = $existingPath ?? $this->resolveCatalogPath($configuration, $basePath, $packageKey, $locale, $sourceName);
            if ($filePath === null) {
                continue;
            }

            $parsed = CatalogFileParser::parse($filePath);
            $metadata = $this->resolveMetadata($parsed['meta'], $packageKey, $locale);
            $writeTarget = $this->shouldWriteTarget($metadata, $locale);
            $units = $parsed['units'];
            $structure = [
                'fileAttributes' => $parsed['fileAttributes'] ?? [],
                'fileChildren' => $parsed['fileChildren'] ?? [],
                'bodyOrder' => $parsed['bodyOrder'] ?? [],
            ];
            $updated = false;

            foreach ($group as $mutation) {
                if ($mutation->identifier === '' || isset($units[$mutation->identifier])) {
                    continue;
                }
                $units[$mutation->identifier] = [
                    'source' => $mutation->source,
                    'target' => $writeTarget ? $mutation->target : null,
                    'state' => $writeTarget ? CatalogEntry::STATE_NEEDS_REVIEW : null,
                    'attributes' => [],
                    'sourceAttributes' => [],
                    'targetAttributes' => [],
                    'children' => [],
                    'hasSource' => true,
                    'hasTarget' => $writeTarget && $mutation->target !== null && $mutation->target !== '',
                ];
                $updated = true;
            }

            if (!$updated) {
                continue;
            }

            ksort($units, SORT_NATURAL | SORT_FLAG_CASE);

            if ($configuration->dryRun) {
                $touched[] = $filePath;
                continue;
            }

            $this->writeCatalogFile($filePath, $metadata, $units, $structure);
            $touched[] = $filePath;
        }

        return array_values(array_unique($touched));
    }

    /**
     * Delete catalog entries that are no longer referenced anywhere.
     *
     * @param list<CatalogEntry> $entries
     * @return list<string>
     */
    public function deleteEntries(array $entries, ScanConfiguration $configuration): array
    {
        if ($entries === []) {
            return [];
        }

        $grouped = $this->groupEntriesByFile($entries);
        $touched = [];

        foreach ($grouped as $filePath => $groupEntries) {
            if ($filePath === '' || !is_file($filePath)) {
                continue;
            }

            $parsed = CatalogFileParser::parse($filePath);
            $units = $parsed['units'];
            $metadata = $this->resolveMetadata(
                $parsed['meta'],
                $groupEntries[0]->packageKey,
                $groupEntries[0]->locale
            );
            $structure = [
                'fileAttributes' => $parsed['fileAttributes'] ?? [],
                'fileChildren' => $parsed['fileChildren'] ?? [],
                'bodyOrder' => $parsed['bodyOrder'] ?? [],
            ];
            $updated = false;

            foreach ($groupEntries as $entry) {
                if (!isset($units[$entry->identifier])) {
                    continue;
                }
                unset($units[$entry->identifier]);
                $updated = true;
            }

            if (!$updated) {
                continue;
            }

            if ($units !== []) {
                ksort($units, SORT_NATURAL | SORT_FLAG_CASE);
            }

            if ($configuration->dryRun) {
                $touched[] = $filePath;
                continue;
            }

            $this->writeCatalogFile($filePath, $metadata, $units, $structure);
            $touched[] = $filePath;
        }

        return array_values(array_unique($touched));
    }

    /**
     * Re-render an existing catalog with deterministic formatting.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, array{
     *     source: ?string,
     *     target: ?string,
     *     state: ?string,
     *     attributes?: array<string, string>,
     *     sourceAttributes?: array<string, string>,
     *     targetAttributes?: array<string, string>,
     *     children?: list<array{type: string, xml?: string}>
     * }> $units
     * @return bool True when the catalog already matched the canonical format
     */
    public function reformatCatalog(
        string $filePath,
        array $metadata,
        array $units,
        string $packageKey,
        string $locale,
        bool $applyChanges = true,
        array $structure = []
    ): bool {
        $resolvedMetadata = $this->resolveMetadata($metadata, $packageKey, $locale);
        $xml = $this->renderCatalog($resolvedMetadata, $units, $structure);
        $currentContents = is_file($filePath) ? (string)file_get_contents($filePath) : '';

        if ($xml === $currentContents) {
            return true;
        }

        if (!$applyChanges) {
            return false;
        }

        $this->persistCatalog($filePath, $xml);

        return false;
    }

    /**
     * @param list<CatalogMutation> $mutations
     * @return array<string, list<CatalogMutation>>
     */
    private function groupMutations(array $mutations): array
    {
        $grouped = [];
        foreach ($mutations as $mutation) {
            $key = implode('|', [$mutation->locale, $mutation->packageKey, $mutation->sourceName]);
            $grouped[$key] ??= [];
            $grouped[$key][] = $mutation;
        }

        return $grouped;
    }

    /**
     * @param list<CatalogEntry> $entries
     * @return array<string, list<CatalogEntry>>
     */
    private function groupEntriesByFile(array $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $grouped[$entry->filePath] ??= [];
            $grouped[$entry->filePath][] = $entry;
        }

        return $grouped;
    }

    /**
     * @param array{
     *     productName: ?string,
     *     sourceLanguage: ?string,
     *     targetLanguage: ?string,
     *     original?: ?string,
     *     datatype?: ?string
     * } $meta
     * @return array{
     *     productName: string,
     *     sourceLanguage: string,
     *     targetLanguage: ?string,
     *     original: string,
     *     datatype: string
     * }
     */
    private function resolveMetadata(array $meta, string $packageKey, string $locale): array
    {
        $productName = $meta['productName'] ?: $packageKey;
        $sourceLanguage = $meta['sourceLanguage'] ?: 'en';
        $targetLanguage = $meta['targetLanguage'];
        if ($targetLanguage === null && strcasecmp($sourceLanguage, $locale) !== 0) {
            $targetLanguage = $locale;
        }

        return [
            'productName' => $productName,
            'sourceLanguage' => $sourceLanguage,
            'targetLanguage' => $targetLanguage,
            'original' => $meta['original'] ?? '',
            'datatype' => $meta['datatype'] ?? 'plaintext',
        ];
    }

    private function shouldWriteTarget(array $metadata, string $locale): bool
    {
        $targetLanguage = $metadata['targetLanguage'] ?? null;
        if ($targetLanguage !== null && $targetLanguage !== '') {
            return true;
        }

        return strcasecmp($metadata['sourceLanguage'], $locale) !== 0;
    }

    /**
     * @param array<string, array{
     *     source: ?string,
     *     target: ?string,
     *     state: ?string,
     *     attributes?: array<string, string>,
     *     sourceAttributes?: array<string, string>,
     *     targetAttributes?: array<string, string>,
     *     children?: list<array{type: string, xml?: string}>
     * }> $units
     * @param array<string, mixed> $structure
     */
    private function writeCatalogFile(string $filePath, array $metadata, array $units, array $structure): void
    {
        $xml = $this->renderCatalog($metadata, $units, $structure);
        $this->persistCatalog($filePath, $xml);
    }

    /**
     * @param array<string, array{
     *     source: ?string,
     *     target: ?string,
     *     state: ?string,
     *     attributes?: array<string, string>,
     *     sourceAttributes?: array<string, string>,
     *     targetAttributes?: array<string, string>,
     *     children?: list<array{type: string, xml?: string}>
     * }> $units
     * @param array{
     *     fileAttributes?: array<string, string>,
     *     fileChildren?: list<string>,
     *     bodyOrder?: list<array{type: 'trans-unit', identifier: string}|array{type: 'unknown', xml: string}>
     * } $structure
     */
    private function renderCatalog(array $metadata, array $units, array $structure): string
    {
        $fileAttributes = [
            'original' => $metadata['original'] ?? '',
            'product-name' => $metadata['productName'],
            'source-language' => $metadata['sourceLanguage'],
            'datatype' => $metadata['datatype'] ?? 'plaintext',
        ];
        $targetLanguage = $metadata['targetLanguage'] ?? null;
        if ($targetLanguage !== null && $targetLanguage !== '') {
            $fileAttributes['target-language'] = $targetLanguage;
        }
        $fileAttributes = $this->mergeAttributes($fileAttributes, $structure['fileAttributes'] ?? []);

        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">';
        $lines[] = $this->indent(1) . '<file ' . $this->formatAttributes($fileAttributes) . '>';
        foreach ($structure['fileChildren'] ?? [] as $childXml) {
            foreach ($this->indentBlock($childXml, 2) as $childLine) {
                $lines[] = $childLine;
            }
        }
        $lines[] = $this->indent(2) . '<body>';

        $sortedUnits = $units;
        ksort($sortedUnits, SORT_NATURAL | SORT_FLAG_CASE);
        $unitQueue = [];
        foreach ($sortedUnits as $identifier => $unit) {
            $unitQueue[] = ['identifier' => $identifier, 'unit' => $unit];
        }

        $bodyOrder = $structure['bodyOrder'] ?? [];
        foreach ($bodyOrder as $element) {
            if (($element['type'] ?? '') === 'unknown' && isset($element['xml'])) {
                foreach ($this->indentBlock((string)$element['xml'], 3) as $line) {
                    $lines[] = $line;
                }
                continue;
            }
            if ($unitQueue === []) {
                continue;
            }
            $current = array_shift($unitQueue);
            $lines = array_merge($lines, $this->renderTransUnit($current['identifier'], $current['unit']));
        }

        foreach ($unitQueue as $remaining) {
            $lines = array_merge($lines, $this->renderTransUnit($remaining['identifier'], $remaining['unit']));
        }

        $lines[] = $this->indent(2) . '</body>';
        $lines[] = $this->indent(1) . '</file>';
        $lines[] = '</xliff>';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function formatAttributes(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $name => $value) {
            $parts[] = sprintf('%s="%s"', $name, $this->escape((string)$value));
        }

        return implode(' ', $parts);
    }

    private function indent(int $level): string
    {
        if ($level <= 0 || $this->tabWidth <= 0) {
            return '';
        }

        return str_repeat(' ', $this->tabWidth * $level);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function renderTransUnit(string $identifier, array $unit): array
    {
        $attributes = ['id' => $identifier, 'xml:space' => 'preserve'];
        $attributes = $this->mergeAttributes($attributes, $unit['attributes'] ?? []);

        $lines = [];
        $lines[] = sprintf('%s<trans-unit %s>', $this->indent(3), $this->formatAttributes($attributes));

        $children = $unit['children'] ?? [];
        if ($children === []) {
            $children[] = ['type' => 'source'];
            if (($unit['target'] ?? null) !== null && ($unit['target'] !== '')) {
                $children[] = ['type' => 'target'];
            }
        }

        foreach ($children as $child) {
            $type = $child['type'] ?? '';
            if ($type === 'source') {
                $sourceAttributes = $unit['sourceAttributes'] ?? [];
                $lines[] = sprintf(
                    '%s<source%s>%s</source>',
                    $this->indent(4),
                    $this->formatOptionalAttributes($sourceAttributes),
                    $this->escape($unit['source'] ?? '')
                );
                continue;
            }
            if ($type === 'target') {
                $target = $unit['target'] ?? null;
                $hasTarget = $unit['hasTarget'] ?? false;
                if (!$hasTarget && ($target === null || $target === '')) {
                    continue;
                }
                $targetAttributes = [];
                if (($unit['state'] ?? null) !== null) {
                    $targetAttributes['state'] = $unit['state'];
                }
                $targetAttributes = $this->mergeAttributes($targetAttributes, $unit['targetAttributes'] ?? []);
                $lines[] = sprintf(
                    '%s<target%s>%s</target>',
                    $this->indent(4),
                    $this->formatOptionalAttributes($targetAttributes),
                    $this->escape($target ?? '')
                );
                continue;
            }
            if ($type === 'unknown' && isset($child['xml'])) {
                foreach ($this->indentBlock((string)$child['xml'], 4) as $line) {
                    $lines[] = $line;
                }
            }
        }

        $lines[] = $this->indent(3) . '</trans-unit>';

        return $lines;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $unknownAttributes
     * @return array<string, string>
     */
    private function mergeAttributes(array $attributes, array $unknownAttributes): array
    {
        foreach ($unknownAttributes as $name => $value) {
            if (!isset($attributes[$name])) {
                $attributes[$name] = $value;
            }
        }

        return $attributes;
    }

    private function formatOptionalAttributes(array $attributes): string
    {
        if ($attributes === []) {
            return '';
        }

        return ' ' . $this->formatAttributes($attributes);
    }

    /**
     * @return list<string>
     */
    private function indentBlock(string $xml, int $indentLevel): array
    {
        $formatted = $this->formatXmlFragment($xml, $indentLevel);
        if ($formatted !== null) {
            return preg_split('/\r\n|\r|\n/', $formatted) ?: [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $xml) ?: [];
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines !== [] && trim($lines[array_key_last($lines)]) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            return [];
        }

        $minIndent = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $leading = strlen($line) - strlen(ltrim($line, " \t"));
            $minIndent = $minIndent === null ? $leading : min($minIndent, $leading);
        }

        $indented = [];
        foreach ($lines as $line) {
            $lineIndent = strlen($line) - strlen(ltrim($line, " \t"));
            $relativeIndent = $minIndent === null ? 0 : max(0, $lineIndent - $minIndent);
            $indented[] = $this->indent($indentLevel) . str_repeat(' ', $relativeIndent) . ltrim($line, " \t");
        }

        return $indented;
    }

    private function formatXmlFragment(string $xml, int $indentLevel): ?string
    {
        $trimmed = trim($xml);
        if ($trimmed === '') {
            return null;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        $wrapped = '<__l10nguy_wrapper>' . $trimmed . '</__l10nguy_wrapper>';
        if (@$document->loadXML($wrapped) === false) {
            return null;
        }

        $lines = [];
        foreach ($document->documentElement->childNodes as $child) {
            if (!$child instanceof \DOMNode) {
                continue;
            }
            $this->renderDomNode($child, $indentLevel, $lines);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function renderDomNode(\DOMNode $node, int $indentLevel, array &$lines): void
    {
        if ($node instanceof \DOMText || $node instanceof \DOMCdataSection) {
            $text = trim($node->textContent);
            if ($text === '') {
                return;
            }
            $lines[] = $this->indent($indentLevel) . htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            return;
        }

        if ($node instanceof \DOMComment) {
            $lines[] = $this->indent($indentLevel) . '<!--' . $node->nodeValue . '-->';
            return;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        $name = $node->nodeName;
        $attributes = $this->collectDomAttributes($node);
        $attributeString = $attributes === [] ? '' : ' ' . $this->formatAttributes($attributes);

        $childElements = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText && trim($child->textContent) === '') {
                continue;
            }
            $childElements[] = $child;
        }

        if ($childElements === []) {
            $lines[] = $this->indent($indentLevel) . sprintf('<%s%s/>', $name, $attributeString);
            return;
        }

        if (count($childElements) === 1 && ($childElements[0] instanceof \DOMText || $childElements[0] instanceof \DOMCdataSection)) {
            $text = trim($childElements[0]->textContent);
            $lines[] = sprintf(
                '%s<%s%s>%s</%s>',
                $this->indent($indentLevel),
                $name,
                $attributeString,
                htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8'),
                $name
            );
            return;
        }

        $lines[] = $this->indent($indentLevel) . sprintf('<%s%s>', $name, $attributeString);
        foreach ($childElements as $child) {
            $this->renderDomNode($child, $indentLevel + 1, $lines);
        }
        $lines[] = $this->indent($indentLevel) . sprintf('</%s>', $name);
    }

    /**
     * @return array<string, string>
     */
    private function collectDomAttributes(\DOMElement $node): array
    {
        $attributes = [];
        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attribute) {
                if (!$attribute instanceof \DOMAttr) {
                    continue;
                }
                $attributes[$attribute->nodeName] = $attribute->value;
            }
        }

        ksort($attributes, SORT_NATURAL | SORT_FLAG_CASE);

        return $attributes;
    }

    private function persistCatalog(string $filePath, string $contents): void
    {
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            Files::createDirectoryRecursively($directory);
        }

        $bytesWritten = @file_put_contents($filePath, $contents);
        if ($bytesWritten === false) {
            throw new \RuntimeException(sprintf('Unable to write catalog file "%s".', $filePath));
        }
    }

    private function resolveCatalogPath(ScanConfiguration $configuration, string $basePath, string $packageKey, string $locale, string $sourceName): ?string
    {
        $relative = 'Resources/Private/Translations/' . $locale . '/' . str_replace('.', '/', $sourceName) . '.xlf';

        if ($configuration->paths !== []) {
            $path = $configuration->paths[0];
            $absoluteRoot = PathResolver::isAbsolute($path) ? $path : Files::concatenatePaths([$basePath, $path]);
            return rtrim($absoluteRoot, '/') . '/' . $relative;
        }

        $candidates = [
            $basePath . 'DistributionPackages/' . $packageKey,
            $basePath . 'Packages/Application/' . $packageKey,
            $basePath . 'Packages/Sites/' . $packageKey,
        ];

        foreach ($candidates as $packagePath) {
            if (is_dir($packagePath)) {
                return rtrim($packagePath, '/') . '/' . $relative;
            }
        }

        return null;
    }
}
