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
use Two13Tec\L10nGuy\Domain\Dto\CatalogEntry;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\PlaceholderMismatch;
use Two13Tec\L10nGuy\Domain\Dto\ReferenceIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;

/**
 * Compares reference and catalog indexes to produce actionable scan results.
 *
 * @Flow\Scope("singleton")
 */
final class ScanResultBuilder
{
    public function build(
        ScanConfiguration $configuration,
        ReferenceIndex $referenceIndex,
        CatalogIndex $catalogIndex
    ): ScanResult {
        $locales = $configuration->locales;
        if ($locales === []) {
            $locales = $catalogIndex->locales();
        }

        $missing = [];
        $placeholderMismatches = [];

        foreach ($this->iterateFilteredReferences($referenceIndex, $configuration) as [
            $packageKey,
            $sourceName,
            $identifier,
            $reference,
        ]) {
            $this->processReference(
                $packageKey,
                $sourceName,
                $identifier,
                $reference,
                $locales,
                $catalogIndex,
                $missing,
                $placeholderMismatches
            );
        }

        return new ScanResult(
            missingTranslations: $missing,
            placeholderMismatches: $placeholderMismatches,
            referenceIndex: $referenceIndex,
            catalogIndex: $catalogIndex
        );
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholdersFromEntry(CatalogEntry $entry): array
    {
        $placeholders = array_merge(
            $this->extractPlaceholders($entry->source),
            $this->extractPlaceholders($entry->target)
        );

        $placeholders = array_values(array_unique($placeholders));
        sort($placeholders);

        return $placeholders;
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        preg_match_all('/\{([A-Za-z0-9_.:-]+)\}/', $value, $matches);
        if (!isset($matches[1])) {
            return [];
        }

        $placeholders = array_values(array_unique(array_filter($matches[1], static fn ($placeholder) => $placeholder !== '')));
        sort($placeholders);

        return $placeholders;
    }

    /**
     * @return iterable<array{0: string, 1: string, 2: string, 3: TranslationReference}>
     */
    private function iterateFilteredReferences(
        ReferenceIndex $referenceIndex,
        ScanConfiguration $configuration
    ): iterable {
        foreach ($referenceIndex->references() as $packageKey => $sources) {
            if ($configuration->packageKey !== null && $configuration->packageKey !== $packageKey) {
                continue;
            }

            foreach ($sources as $sourceName => $identifiers) {
                if ($configuration->sourceName !== null && $configuration->sourceName !== $sourceName) {
                    continue;
                }

                foreach ($identifiers as $identifier => $reference) {
                    yield [$packageKey, $sourceName, $identifier, $reference];
                }
            }
        }
    }

    /**
     * @param list<string> $locales
     * @param list<MissingTranslation> $missing
     * @param list<PlaceholderMismatch> $placeholderMismatches
     */
    private function processReference(
        string $packageKey,
        string $sourceName,
        string $identifier,
        TranslationReference $reference,
        array $locales,
        CatalogIndex $catalogIndex,
        array &$missing,
        array &$placeholderMismatches
    ): void {
        $referencePlaceholderNames = array_keys($reference->placeholders);
        $fallbackPlaceholders = $this->extractPlaceholders($reference->fallback);

        foreach ($locales as $locale) {
            $entries = $catalogIndex->entriesFor($locale, $packageKey, $sourceName);

            if ($reference->isPlural) {
                $this->processPluralReference(
                    $packageKey,
                    $sourceName,
                    $identifier,
                    $reference,
                    $fallbackPlaceholders,
                    $referencePlaceholderNames,
                    $locale,
                    $entries,
                    $catalogIndex,
                    $missing,
                    $placeholderMismatches
                );
                continue;
            }

            $entry = $entries[$identifier] ?? null;

            if ($entry === null) {
                $pluralForms = $catalogIndex->pluralGroup($locale, $packageKey, $sourceName, $identifier);
                if ($pluralForms !== []) {
                    $groupEntries = $this->entriesById($entries, $pluralForms);
                    if ($groupEntries !== []) {
                        $this->detectPlaceholderMismatchForEntries(
                            $packageKey,
                            $sourceName,
                            $identifier,
                            $reference,
                            $fallbackPlaceholders,
                            $referencePlaceholderNames,
                            $locale,
                            $groupEntries,
                            $placeholderMismatches
                        );
                        continue;
                    }
                }

                $missing[] = new MissingTranslation(
                    locale: $locale,
                    packageKey: $packageKey,
                    sourceName: $sourceName,
                    identifier: $identifier,
                    reference: $reference
                );
                continue;
            }

            $this->detectPlaceholderMismatchForEntries(
                $packageKey,
                $sourceName,
                $identifier,
                $reference,
                $fallbackPlaceholders,
                $referencePlaceholderNames,
                $locale,
                [$entry],
                $placeholderMismatches
            );
        }
    }

    /**
     * @param array<string, CatalogEntry> $entries
     * @param list<MissingTranslation> $missing
     * @param list<PlaceholderMismatch> $placeholderMismatches
     */
    private function processPluralReference(
        string $packageKey,
        string $sourceName,
        string $identifier,
        TranslationReference $reference,
        array $fallbackPlaceholders,
        array $referencePlaceholderNames,
        string $locale,
        array $entries,
        CatalogIndex $catalogIndex,
        array &$missing,
        array &$placeholderMismatches
    ): void {
        $pluralForms = $catalogIndex->pluralGroup($locale, $packageKey, $sourceName, $identifier);
        if ($pluralForms === []) {
            foreach ($entries as $entryIdentifier => $entry) {
                $parsed = $this->parsePluralIdentifier($entryIdentifier);
                if ($parsed !== null && $parsed['base'] === $identifier) {
                    $pluralForms[] = $entryIdentifier;
                }
            }
        }
        $formsByIndex = [];

        foreach ($pluralForms as $formId) {
            $parsed = $this->parsePluralIdentifier($formId);
            if ($parsed === null) {
                continue;
            }
            $index = $parsed['index'];
            if (isset($entries[$formId])) {
                $formsByIndex[$index] = $entries[$formId];
            }
        }

        $expectedForms = [0, 1];
        foreach ($expectedForms as $expectedForm) {
            if (isset($formsByIndex[$expectedForm])) {
                continue;
            }
            $missing[] = new MissingTranslation(
                locale: $locale,
                packageKey: $packageKey,
                sourceName: $sourceName,
                identifier: sprintf('%s[%d]', $identifier, $expectedForm),
                reference: $reference
            );
        }

        if ($formsByIndex === []) {
            return;
        }

        $this->detectPlaceholderMismatchForEntries(
            $packageKey,
            $sourceName,
            $identifier,
            $reference,
            $fallbackPlaceholders,
            $referencePlaceholderNames,
            $locale,
            array_values($formsByIndex),
            $placeholderMismatches
        );
    }

    /**
     * @param list<PlaceholderMismatch> $placeholderMismatches
     * @param list<string> $fallbackPlaceholders
     * @param list<string> $referencePlaceholderNames
     * @param list<CatalogEntry> $entries
     */
    private function detectPlaceholderMismatchForEntries(
        string $packageKey,
        string $sourceName,
        string $identifier,
        TranslationReference $reference,
        array $fallbackPlaceholders,
        array $referencePlaceholderNames,
        string $locale,
        array $entries,
        array &$placeholderMismatches
    ): void {
        $catalogPlaceholders = $this->aggregatePlaceholdersFromEntries($entries);
        $expectedPlaceholders = array_unique(
            array_merge($catalogPlaceholders, $fallbackPlaceholders)
        );
        sort($expectedPlaceholders);

        $missingPlaceholders = array_values(array_diff($expectedPlaceholders, $referencePlaceholderNames));
        if ($missingPlaceholders === []) {
            return;
        }

        $placeholderMismatches[] = new PlaceholderMismatch(
            locale: $locale,
            packageKey: $packageKey,
            sourceName: $sourceName,
            identifier: $identifier,
            missingPlaceholders: $missingPlaceholders,
            referencePlaceholders: array_values($referencePlaceholderNames),
            catalogPlaceholders: $catalogPlaceholders,
            reference: $reference,
            catalogEntry: $entries[0] ?? null
        );
    }

    /**
     * @param array<string, CatalogEntry> $entries
     * @param list<string> $identifiers
     * @return list<CatalogEntry>
     */
    private function entriesById(array $entries, array $identifiers): array
    {
        $resolved = [];
        foreach ($identifiers as $id) {
            if (isset($entries[$id])) {
                $resolved[] = $entries[$id];
            }
        }

        return $resolved;
    }

    /**
     * @param list<CatalogEntry> $entries
     * @return list<string>
     */
    private function aggregatePlaceholdersFromEntries(array $entries): array
    {
        $placeholders = [];
        foreach ($entries as $entry) {
            $placeholders = array_merge($placeholders, $this->extractPlaceholdersFromEntry($entry));
        }

        $placeholders = array_values(array_unique($placeholders));
        sort($placeholders);

        return $placeholders;
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
}
