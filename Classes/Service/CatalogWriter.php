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
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
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
        protected int $tabWidth = 2,
        #[Flow\InjectConfiguration(path: 'orderById', package: 'Two13Tec.L10nGuy')]
        protected bool $orderById = false
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
            $newState = $configuration->newState;
            $newStateQualifier = $configuration->newStateQualifier;
            $llmConfiguration = $configuration->llm;
            $units = $parsed['units'];
            $structure = [
                'fileAttributes' => $parsed['fileAttributes'] ?? [],
                'fileChildren' => $parsed['fileChildren'] ?? [],
                'bodyOrder' => $parsed['bodyOrder'] ?? [],
            ];
            $updated = false;

            foreach ($group as $mutation) {
                $updated = $this->applyMutation($units, $mutation, $writeTarget, $newState, $newStateQualifier, $llmConfiguration) || $updated;
            }

            if (!$updated) {
                continue;
            }

            $units = $this->orderUnits($units);

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
                $plural = $this->parsePluralIdentifier($entry->identifier);
                if ($plural !== null) {
                    $baseId = $plural['base'];
                    $formIndex = $plural['index'];
                    if (isset($units[$baseId]) && ($units[$baseId]['type'] ?? '') === 'plural') {
                        if (isset($units[$baseId]['forms'][$formIndex])) {
                            unset($units[$baseId]['forms'][$formIndex]);
                            $units[$baseId]['children'] = array_values(
                                array_filter(
                                    $units[$baseId]['children'] ?? [],
                                    static fn (array $child): bool => ($child['identifier'] ?? null) !== $entry->identifier
                                )
                            );
                            if ($units[$baseId]['forms'] === []) {
                                unset($units[$baseId]);
                            }
                            $updated = true;
                        }
                        continue;
                    }
                }
                if (!isset($units[$entry->identifier])) {
                    continue;
                }
                unset($units[$entry->identifier]);
                $updated = true;
            }

            if (!$updated) {
                continue;
            }

            $units = $this->orderUnits($units);

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
     *     type: 'single',
     *     source: ?string,
     *     target: ?string,
     *     state: ?string,
     *     attributes?: array<string, string>,
     *     sourceAttributes?: array<string, string>,
     *     targetAttributes?: array<string, string>,
     *     children?: list<array{type: string, xml?: string}>
     * }|array{
     *     type: 'plural',
     *     restype?: string,
     *     attributes?: array<string, string>,
     *     forms: array<int, array{
     *         id?: string,
     *         source: ?string,
     *         target: ?string,
     *         state: ?string,
     *         attributes?: array<string, string>,
     *         sourceAttributes?: array<string, string>,
     *         targetAttributes?: array<string, string>,
     *         children?: list<array{type: string, xml?: string}>,
     *         hasSource?: bool,
     *         hasTarget?: bool
     *     }>,
     *     children?: list<array{type: 'form', identifier: string, index: int}|array{type: 'unknown', xml: string}>
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
     * @param array<string, mixed> $units
     */
    private function applyMutation(
        array &$units,
        CatalogMutation $mutation,
        bool $writeTarget,
        ?string $newState,
        ?string $newStateQualifier,
        ?LlmConfiguration $llmConfiguration
    ): bool {
        $identifier = $mutation->identifier;
        if ($identifier === '') {
            return false;
        }

        $plural = $this->parsePluralIdentifier($identifier);
        if ($plural !== null) {
            $baseId = $plural['base'];
            $formIndex = $plural['index'];
            if (!isset($units[$baseId])) {
                $units[$baseId] = [
                    'type' => 'plural',
                    'restype' => 'x-gettext-plurals',
                    'attributes' => [],
                    'forms' => [],
                    'children' => [],
                ];
            }
            if (($units[$baseId]['type'] ?? '') !== 'plural') {
                return false;
            }
            if (isset($units[$baseId]['forms'][$formIndex])) {
                return false;
            }

            $units[$baseId]['forms'][$formIndex] = $this->buildUnitFromMutation(
                $mutation,
                $writeTarget,
                $newState,
                $newStateQualifier,
                $llmConfiguration,
                $identifier
            );
            $units[$baseId]['children'] ??= [];
            $units[$baseId]['children'][] = [
                'type' => 'form',
                'identifier' => $identifier,
                'index' => $formIndex,
            ];
            if ($this->orderById) {
                ksort($units[$baseId]['forms'], SORT_NUMERIC);
            }

            return true;
        }

        if (isset($units[$identifier]) && ($units[$identifier]['type'] ?? 'single') === 'plural') {
            $formIdentifier = $identifier . '[0]';
            if (isset($units[$identifier]['forms'][0])) {
                return false;
            }
            $units[$identifier]['forms'][0] = $this->buildUnitFromMutation(
                $mutation,
                $writeTarget,
                $newState,
                $newStateQualifier,
                $llmConfiguration,
                $formIdentifier
            );
            $units[$identifier]['children'] ??= [];
            $units[$identifier]['children'][] = [
                'type' => 'form',
                'identifier' => $formIdentifier,
                'index' => 0,
            ];
            if ($this->orderById) {
                ksort($units[$identifier]['forms'], SORT_NUMERIC);
            }

            return true;
        }

        if (isset($units[$identifier])) {
            return false;
        }

        $units[$identifier] = array_merge(
            ['type' => 'single'],
            $this->buildUnitFromMutation(
                $mutation,
                $writeTarget,
                $newState,
                $newStateQualifier,
                $llmConfiguration,
                $identifier
            )
        );

        return true;
    }

    private function buildUnitFromMutation(
        CatalogMutation $mutation,
        bool $writeTarget,
        ?string $newState,
        ?string $newStateQualifier,
        ?LlmConfiguration $llmConfiguration,
        string $identifier
    ): array {
        $target = $writeTarget ? $mutation->target : null;
        $hasTarget = $writeTarget && $mutation->target !== null && $mutation->target !== '';
        $source = $mutation->source;
        if (!$writeTarget && $mutation->target !== '' && $mutation->target !== $source) {
            $source = $mutation->target;
        }
        $sourceAttributes = [];
        $state = $this->resolveReviewState($mutation, $newState, $llmConfiguration);
        $stateQualifier = $this->resolveStateQualifier($mutation, $newStateQualifier, $llmConfiguration);

        $this->appendStateAttributes($sourceAttributes, $state, $stateQualifier, !$writeTarget);

        return [
            'id' => $identifier,
            'source' => $source,
            'target' => $target,
            'state' => $state !== null && $writeTarget ? $state : null,
            'attributes' => [],
            'sourceAttributes' => $sourceAttributes,
            'targetAttributes' => $this->buildTargetAttributes($state, $stateQualifier, $writeTarget),
            'children' => [],
            'hasSource' => true,
            'hasTarget' => $hasTarget,
            'notes' => $this->buildLlmNotes($mutation, $llmConfiguration),
        ];
    }

    private function resolveReviewState(
        CatalogMutation $mutation,
        ?string $newState,
        ?LlmConfiguration $llmConfiguration
    ): ?string {
        if ($mutation->isLlmGenerated && $llmConfiguration !== null) {
            $state = trim((string)$llmConfiguration->newState);
            if ($state !== '') {
                return $state;
            }
        }

        $state = trim((string)$newState);

        return $state === '' ? null : $state;
    }

    private function resolveStateQualifier(
        CatalogMutation $mutation,
        ?string $newStateQualifier,
        ?LlmConfiguration $llmConfiguration
    ): ?string {
        if ($mutation->isLlmGenerated && $llmConfiguration !== null) {
            $qualifier = trim((string)$llmConfiguration->newStateQualifier);
            if ($qualifier !== '') {
                return $qualifier;
            }
        }

        $qualifier = trim((string)$newStateQualifier);

        return $qualifier === '' ? null : $qualifier;
    }

    private function appendStateAttributes(array &$attributes, ?string $state, ?string $stateQualifier, bool $enabled): void
    {
        if (!$enabled) {
            return;
        }
        if ($state !== null) {
            $attributes['state'] = $state;
        }
        if ($stateQualifier !== null) {
            $attributes['state-qualifier'] = $stateQualifier;
        }
    }

    private function buildTargetAttributes(?string $state, ?string $stateQualifier, bool $writeTarget): array
    {
        if (!$writeTarget) {
            return [];
        }

        $targetAttributes = [];
        if ($stateQualifier !== null) {
            $targetAttributes['state-qualifier'] = $stateQualifier;
        }

        return $targetAttributes;
    }

    /**
     * @return list<array{from?: string, priority?: int, content?: string}>
     */
    private function buildLlmNotes(CatalogMutation $mutation, ?LlmConfiguration $llmConfiguration): array
    {
        if (!$mutation->isLlmGenerated || $llmConfiguration === null || !$llmConfiguration->noteEnabled) {
            return [];
        }

        $metaParts = [];
        if ($mutation->llmProvider !== null && $mutation->llmProvider !== '') {
            $metaParts[] = sprintf('provider:%s', $mutation->llmProvider);
        }
        if ($mutation->llmModel !== null && $mutation->llmModel !== '') {
            $metaParts[] = sprintf('model:%s', $mutation->llmModel);
        }
        if ($mutation->llmGeneratedAt instanceof \DateTimeInterface) {
            $metaParts[] = sprintf('generated:%s', $mutation->llmGeneratedAt->format(\DATE_ATOM));
        }

        if ($metaParts === []) {
            return [];
        }

        return [[
            'from' => 'l10nguy',
            'content' => implode(' ', $metaParts),
        ]];
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
     *     type: 'single',
     *     source: ?string,
     *     target: ?string,
     *     state: ?string,
     *     attributes?: array<string, string>,
     *     sourceAttributes?: array<string, string>,
     *     targetAttributes?: array<string, string>,
     *     children?: list<array{type: string, xml?: string}>
     * }|array{
     *     type: 'plural',
     *     restype?: string,
     *     attributes?: array<string, string>,
     *     forms: array<int, array{
     *         id?: string,
     *         source: ?string,
     *         target: ?string,
     *         state: ?string,
     *         attributes?: array<string, string>,
     *         sourceAttributes?: array<string, string>,
     *         targetAttributes?: array<string, string>,
     *         children?: list<array{type: string, xml?: string}>,
     *         hasSource?: bool,
     *         hasTarget?: bool
     *     }>,
     *     children?: list<array{type: 'form', identifier: string, index: int}|array{type: 'unknown', xml: string}>
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
     *     type: 'single',
     *     source: ?string,
     *     target: ?string,
     *     state: ?string,
     *     attributes?: array<string, string>,
     *     sourceAttributes?: array<string, string>,
     *     targetAttributes?: array<string, string>,
     *     children?: list<array{type: string, xml?: string}>
     * }|array{
     *     type: 'plural',
     *     restype?: string,
     *     attributes?: array<string, string>,
     *     forms: array<int, array{
     *         id?: string,
     *         source: ?string,
     *         target: ?string,
     *         state: ?string,
     *         attributes?: array<string, string>,
     *         sourceAttributes?: array<string, string>,
     *         targetAttributes?: array<string, string>,
     *         children?: list<array{type: string, xml?: string}>,
     *         hasSource?: bool,
     *         hasTarget?: bool
     *     }>,
     *     children?: list<array{type: 'form', identifier: string, index: int}|array{type: 'unknown', xml: string}>
     * }> $units
     * @param array{
     *     fileAttributes?: array<string, string>,
     *     fileChildren?: list<string>,
     *     bodyOrder?: list<array{type: 'trans-unit'|'plural', identifier: string}|array{type: 'unknown', xml: string}>
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

        $sortedUnits = $this->orderUnits($units);
        $usedIdentifiers = [];

        $bodyOrder = $structure['bodyOrder'] ?? [];
        $processOrderedIdentifiers = !$this->orderById;
        foreach ($bodyOrder as $element) {
            if (($element['type'] ?? '') === 'unknown' && isset($element['xml'])) {
                foreach ($this->indentBlock((string)$element['xml'], 3) as $line) {
                    $lines[] = $line;
                }
                continue;
            }
            if (!$processOrderedIdentifiers) {
                continue;
            }
            if (!isset($element['identifier'])) {
                continue;
            }
            $identifier = (string)$element['identifier'];
            if (!isset($sortedUnits[$identifier])) {
                continue;
            }
            $usedIdentifiers[$identifier] = true;
            $lines = array_merge($lines, $this->renderUnit($identifier, $sortedUnits[$identifier]));
        }

        $remainingUnits = array_diff_key($sortedUnits, $usedIdentifiers);
        if (!$this->orderById && $usedIdentifiers !== []) {
            $identifiers = array_keys($remainingUnits);
            natcasesort($identifiers);
            foreach ($identifiers as $identifier) {
                $lines = array_merge($lines, $this->renderUnit((string)$identifier, $remainingUnits[$identifier]));
            }
        } else {
            foreach ($remainingUnits as $identifier => $unit) {
                $lines = array_merge($lines, $this->renderUnit($identifier, $unit));
            }
        }

        $lines[] = $this->indent(2) . '</body>';
        $lines[] = $this->indent(1) . '</file>';
        $lines[] = '</xliff>';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<string, mixed> $units
     * @return array<string, mixed>
     */
    private function orderUnits(array $units): array
    {
        if (!$this->orderById || $units === []) {
            return $units;
        }

        $identifiers = array_keys($units);
        natcasesort($identifiers);

        $ordered = [];
        foreach ($identifiers as $identifier) {
            $ordered[(string)$identifier] = $units[$identifier];
        }

        return $ordered;
    }

    /**
     * @param array<int, array<mixed>> $forms
     * @return array<int, array<mixed>>
     */
    private function orderPluralForms(array $forms): array
    {
        if (!$this->orderById || $forms === []) {
            return $forms;
        }

        $indices = array_keys($forms);
        sort($indices, SORT_NUMERIC);

        $ordered = [];
        foreach ($indices as $index) {
            $ordered[(int)$index] = $forms[$index];
        }

        return $ordered;
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

    private function renderUnit(string $identifier, array $unit): array
    {
        if (($unit['type'] ?? 'single') === 'plural') {
            return $this->renderPluralGroup($identifier, $unit);
        }

        return $this->renderTransUnit($identifier, $unit);
    }

    private function renderPluralGroup(string $identifier, array $unit): array
    {
        $attributes = [
            'id' => $identifier,
            'restype' => $unit['restype'] ?? 'x-gettext-plurals',
        ];
        $attributes = $this->mergeAttributes($attributes, $unit['attributes'] ?? []);

        $lines = [];
        $lines[] = sprintf('%s<group %s>', $this->indent(3), $this->formatAttributes($attributes));

        $forms = $this->orderPluralForms($unit['forms'] ?? []);
        $formQueue = [];
        foreach ($forms as $index => $form) {
            $formQueue[(int)$index] = $form;
        }

        $renderedForms = [];
        foreach ($unit['children'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'unknown' && isset($child['xml'])) {
                foreach ($this->indentBlock((string)$child['xml'], 4) as $line) {
                    $lines[] = $line;
                }
                continue;
            }
            if (($child['type'] ?? '') !== 'form') {
                continue;
            }
            $index = isset($child['index']) ? (int)$child['index'] : null;
            if ($index === null || !isset($formQueue[$index])) {
                continue;
            }
            $formIdentifier = $formQueue[$index]['id'] ?? ($identifier . '[' . $index . ']');
            $lines = array_merge(
                $lines,
                $this->renderTransUnit($formIdentifier, $formQueue[$index], 4)
            );
            $renderedForms[$index] = true;
        }

        foreach ($formQueue as $index => $form) {
            if (isset($renderedForms[$index])) {
                continue;
            }
            $formIdentifier = $form['id'] ?? ($identifier . '[' . $index . ']');
            $lines = array_merge($lines, $this->renderTransUnit($formIdentifier, $form, 4));
        }

        $lines[] = $this->indent(3) . '</group>';

        return $lines;
    }

    private function renderTransUnit(string $identifier, array $unit, int $indentLevel = 3): array
    {
        $attributes = ['id' => $identifier, 'xml:space' => 'preserve'];
        $attributes = $this->mergeAttributes($attributes, $unit['attributes'] ?? []);

        $lines = [];
        $lines[] = sprintf('%s<trans-unit %s>', $this->indent($indentLevel), $this->formatAttributes($attributes));

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
                    $this->indent($indentLevel + 1),
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
                    $this->indent($indentLevel + 1),
                    $this->formatOptionalAttributes($targetAttributes),
                    $this->escape($target ?? '')
                );
                continue;
            }
            if ($type === 'unknown' && isset($child['xml'])) {
                foreach ($this->indentBlock((string)$child['xml'], $indentLevel + 1) as $line) {
                    $lines[] = $line;
                }
            }
        }

        foreach ($unit['notes'] ?? [] as $note) {
            $noteAttributes = [];
            if (isset($note['from'])) {
                $noteAttributes['from'] = $note['from'];
            }
            if (isset($note['priority'])) {
                $noteAttributes['priority'] = (string)$note['priority'];
            }

            $lines[] = sprintf(
                '%s<note%s>%s</note>',
                $this->indent($indentLevel + 1),
                $this->formatOptionalAttributes($noteAttributes),
                $this->escape($note['content'] ?? '')
            );
        }

        $lines[] = $this->indent($indentLevel) . '</trans-unit>';

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

    private function parsePluralIdentifier(string $identifier): ?array
    {
        $match = [];
        if (preg_match('/^(.*)\\[(\\d+)\\]$/', $identifier, $match) !== 1) {
            return null;
        }

        return [
            'base' => $match[1],
            'index' => (int)$match[2],
        ];
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
