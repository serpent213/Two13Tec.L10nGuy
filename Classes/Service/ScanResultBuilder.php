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

        foreach ($referenceIndex->references() as $packageKey => $sources) {
            if ($configuration->packageKey !== null && $configuration->packageKey !== $packageKey) {
                continue;
            }

            foreach ($sources as $sourceName => $identifiers) {
                if ($configuration->sourceName !== null && $configuration->sourceName !== $sourceName) {
                    continue;
                }

                foreach ($identifiers as $identifier => $reference) {
                    $referencePlaceholderNames = array_keys($reference->placeholders);
                    $fallbackPlaceholders = $this->extractPlaceholders($reference->fallback);

                    foreach ($locales as $locale) {
                        $entries = $catalogIndex->entriesFor($locale, $packageKey, $sourceName);
                        $entry = $entries[$identifier] ?? null;

                        if ($entry === null) {
                            $missing[] = new MissingTranslation(
                                locale: $locale,
                                packageKey: $packageKey,
                                sourceName: $sourceName,
                                identifier: $identifier,
                                reference: $reference
                            );
                            continue;
                        }

                        $catalogPlaceholders = $this->extractPlaceholdersFromEntry($entry);
                        $expectedPlaceholders = array_unique(
                            array_merge($catalogPlaceholders, $fallbackPlaceholders)
                        );
                        sort($expectedPlaceholders);

                        $missingPlaceholders = array_values(array_diff($expectedPlaceholders, $referencePlaceholderNames));
                        if ($missingPlaceholders !== []) {
                            $placeholderMismatches[] = new PlaceholderMismatch(
                                locale: $locale,
                                packageKey: $packageKey,
                                sourceName: $sourceName,
                                identifier: $identifier,
                                missingPlaceholders: $missingPlaceholders,
                                referencePlaceholders: array_values($referencePlaceholderNames),
                                catalogPlaceholders: $catalogPlaceholders,
                                reference: $reference,
                                catalogEntry: $entry
                            );
                        }
                    }
                }
            }
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
}
