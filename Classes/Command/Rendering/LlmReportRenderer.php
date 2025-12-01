<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Command\Rendering;

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
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\LlmRunStatistics;
use Two13Tec\L10nGuy\Domain\Dto\TranslationKey;

/**
 * Renders LLM translation reports (used by scan and translate commands).
 *
 * @Flow\Scope("singleton")
 */
class LlmReportRenderer
{
    public function __construct(
        private readonly TableFormatter $tableFormatter
    ) {}

    /**
     * Render LLM translations table showing source and target translations
     */
    public function renderTranslationsTable(array $mutations, CatalogIndex $catalogIndex, string $sourceLocale): ?string
    {
        /** @var list<CatalogMutation> $generated */
        $generated = array_values(array_filter(
            $mutations,
            static fn(CatalogMutation $mutation): bool => $mutation->target !== ''
        ));
        if ($generated === []) {
            return null;
        }

        $rows = [];
        $translatedLocales = [];
        foreach ($generated as $mutation) {
            $key = $this->translationIdFromParts($mutation->packageKey, $mutation->sourceName, $mutation->identifier);
            $rows[$key] ??= [
                'packageKey' => $mutation->packageKey,
                'sourceName' => $mutation->sourceName,
                'identifier' => $mutation->identifier,
                'fallback' => $mutation->fallback,
                'translations' => [],
            ];
            $rows[$key]['translations'][$mutation->locale] = [
                'value' => $mutation->target,
                'existing' => false,
            ];
            $translatedLocales[$mutation->locale] = true;
        }

        foreach ($rows as $rowKey => &$row) {
            $key = new TranslationKey($row['packageKey'], $row['sourceName'], $row['identifier']);
            foreach ($catalogIndex->locales() as $locale) {
                if ($locale === $sourceLocale) {
                    continue;
                }
                $entry = $catalogIndex->entriesFor($locale, $key)[$row['identifier']] ?? null;
                if ($entry === null || $entry->target === '') {
                    continue;
                }
                if (!isset($row['translations'][$locale]) || ($row['translations'][$locale]['value'] ?? '') === '') {
                    $row['translations'][$locale] = [
                        'value' => sprintf('(%s)', $entry->target),
                        'existing' => true,
                    ];
                }
                $translatedLocales[$locale] = true;
            }
        }
        unset($row);

        $translatedLocales = array_keys($translatedLocales);
        $translatedLocales = array_values(array_filter(
            $translatedLocales,
            static fn(string $locale): bool => $locale !== $sourceLocale
        ));
        sort($translatedLocales, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($rows as &$row) {
            $sourceTranslation = $row['translations'][$sourceLocale]['value'] ?? null;
            $row['source'] = $sourceTranslation
                ?? $this->sourceTextForMutation(
                    $row['packageKey'],
                    $row['sourceName'],
                    $row['identifier'],
                    $row['fallback'],
                    $catalogIndex,
                    $sourceLocale
                );
        }
        unset($row);

        ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);

        $table = $this->tableFormatter->createStyledTable();
        $hidePackagePrefix = $this->tableFormatter->isSinglePackage(array_column($rows, 'packageKey'));

        foreach ($rows as $row) {
            $tableRow = [
                'Source/ID' => $this->tableFormatter->formatSourceCell(
                    $row['packageKey'],
                    $row['sourceName'],
                    $row['identifier'],
                    $hidePackagePrefix
                ),
                $sourceLocale => $this->tableFormatter->formatTranslationCell($row['source']),
            ];

            foreach ($translatedLocales as $locale) {
                $translation = $row['translations'][$locale] ?? '';
                $tableRow[$locale] = $this->tableFormatter->formatTranslationCell($translation);
            }

            $table->row($tableRow);
        }

        return 'LLM translations:' . PHP_EOL . (string) $table;
    }

    /**
     * Render LLM statistics line
     */
    public function renderStatistics(LlmRunStatistics $runStatistics): string
    {
        return sprintf(
            '%d LLM API calls, %s tokens in, %s tokens out',
            $runStatistics->apiCalls,
            number_format($runStatistics->estimatedInputTokens, 0, '.', '.'),
            number_format($runStatistics->estimatedOutputTokens, 0, '.', '.')
        );
    }

    private function sourceTextForMutation(
        string $packageKey,
        string $sourceName,
        string $identifier,
        string $fallback,
        CatalogIndex $catalogIndex,
        string $sourceLocale
    ): string {
        $key = new TranslationKey($packageKey, $sourceName, $identifier);
        $entries = $catalogIndex->entriesFor($sourceLocale, $key);
        $entry = $entries[$identifier] ?? null;

        return $entry?->target ?? $entry?->source ?? $fallback;
    }

    private function translationIdFromParts(string $packageKey, string $sourceName, string $identifier): string
    {
        return sprintf('%s:%s:%s', $packageKey, $sourceName, $identifier);
    }
}
