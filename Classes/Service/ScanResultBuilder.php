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
use Two13Tec\L10nGuy\Domain\Dto\ScanResultCollector;
use Two13Tec\L10nGuy\Domain\Dto\TranslationKey;
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

        $collector = new ScanResultCollector();

        foreach ($this->iterateFilteredReferences($referenceIndex, $configuration) as [$key, $reference]) {
            $this->processReference($key, $reference, $locales, $catalogIndex, $collector);
        }

        return $collector->build($referenceIndex, $catalogIndex);
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
     * @return iterable<array{0: TranslationKey, 1: TranslationReference}>
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
                    yield [new TranslationKey($packageKey, $sourceName, $identifier), $reference];
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
        TranslationKey $key,
        TranslationReference $reference,
        array $locales,
        CatalogIndex $catalogIndex,
        ScanResultCollector $collector
    ): void {
        $referencePlaceholderNames = $reference->placeholderNames();
        $fallbackPlaceholders = $reference->fallbackPlaceholders();

        foreach ($locales as $locale) {
            $identifier = $key->identifier;
            $entries = $catalogIndex->entriesFor($locale, $key);

            if ($reference->isPlural) {
                $this->processPluralReference(
                    $key,
                    $reference,
                    $fallbackPlaceholders,
                    $referencePlaceholderNames,
                    $locale,
                    $entries,
                    $catalogIndex,
                    $collector
                );
                continue;
            }

            $identifier = $key->identifier;
            $entry = $entries[$identifier] ?? null;

            if ($entry === null) {
                $pluralForms = $catalogIndex->pluralGroup($locale, $key);
                if ($pluralForms !== []) {
                    $groupEntries = $this->entriesById($entries, $pluralForms);
                    if ($groupEntries !== []) {
                        $this->detectPlaceholderMismatchForEntries(
                            $key,
                            $reference,
                            $fallbackPlaceholders,
                            $referencePlaceholderNames,
                            $locale,
                            $groupEntries,
                            $collector
                        );
                        continue;
                    }
                }

                $collector->addMissing(new MissingTranslation(
                    locale: $locale,
                    key: $key,
                    reference: $reference
                ));
                continue;
            }

            $this->detectPlaceholderMismatchForEntries(
                $key,
                $reference,
                $fallbackPlaceholders,
                $referencePlaceholderNames,
                $locale,
                [$entry],
                $collector
            );
        }
    }

    /**
     * @param array<string, CatalogEntry> $entries
     * @param list<MissingTranslation> $missing
     * @param list<PlaceholderMismatch> $placeholderMismatches
     */
    private function processPluralReference(
        TranslationKey $key,
        TranslationReference $reference,
        array $fallbackPlaceholders,
        array $referencePlaceholderNames,
        string $locale,
        array $entries,
        CatalogIndex $catalogIndex,
        ScanResultCollector $collector
    ): void {
        $pluralForms = $catalogIndex->pluralGroup($locale, $key);
        if ($pluralForms === []) {
            foreach ($entries as $entryIdentifier => $entry) {
                $parsed = $this->parsePluralIdentifier($entryIdentifier);
                if ($parsed !== null && $parsed['base'] === $key->identifier) {
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
            $collector->addMissing(new MissingTranslation(
                locale: $locale,
                key: $key->withIdentifier(sprintf('%s[%d]', $key->identifier, $expectedForm)),
                reference: $reference
            ));
        }

        if ($formsByIndex === []) {
            return;
        }

        $this->detectPlaceholderMismatchForEntries(
            $key,
            $reference,
            $fallbackPlaceholders,
            $referencePlaceholderNames,
            $locale,
            array_values($formsByIndex),
            $collector
        );
    }

    /**
     * @param list<PlaceholderMismatch> $placeholderMismatches
     * @param list<string> $fallbackPlaceholders
     * @param list<string> $referencePlaceholderNames
     * @param list<CatalogEntry> $entries
     */
    private function detectPlaceholderMismatchForEntries(
        TranslationKey $key,
        TranslationReference $reference,
        array $fallbackPlaceholders,
        array $referencePlaceholderNames,
        string $locale,
        array $entries,
        ScanResultCollector $collector
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

        $collector->addPlaceholderMismatch(
            new PlaceholderMismatch(
                locale: $locale,
                key: $key,
                missingPlaceholders: $missingPlaceholders,
                referencePlaceholders: array_values($referencePlaceholderNames),
                catalogPlaceholders: $catalogPlaceholders,
                reference: $reference,
                catalogEntry: $entries[0] ?? null
            )
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
