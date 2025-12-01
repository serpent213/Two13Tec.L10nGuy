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
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\PlaceholderMismatch;
use Two13Tec\L10nGuy\Domain\Dto\ReferenceIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;
use Two13Tec\L10nGuy\Utility\PathResolver;

/**
 * Renders scan command output and resolves exit codes.
 *
 * @Flow\Scope("singleton")
 */
class ScanReportRenderer
{
    public function __construct(
        private readonly TableFormatter $tableFormatter
    ) {}

    /**
     * Render scan results as a table
     */
    public function renderTable(ScanResult $scanResult): string
    {
        if ($scanResult->missingTranslations === []) {
            return 'No missing translations detected.';
        }

        $this->sortMissingTranslations($scanResult->missingTranslations);

        $grouped = [];
        foreach ($scanResult->missingTranslations as $missing) {
            $grouped[$missing->locale][] = $missing;
        }

        $output = [];
        ksort($grouped);

        foreach ($grouped as $locale => $missingTranslations) {
            $table = $this->tableFormatter->createStyledTable();
            $hidePackagePrefix = $this->tableFormatter->isSinglePackage(
                array_map(
                    fn(MissingTranslation $missing) => $missing->key->packageKey,
                    $missingTranslations
                )
            );

            foreach ($missingTranslations as $missing) {
                $reference = $missing->reference;
                $table->row([
                    'Source/ID' => $this->tableFormatter->formatSourceCell(
                        $missing->key->packageKey,
                        $missing->key->sourceName,
                        $missing->key->identifier,
                        $hidePackagePrefix
                    ),
                    'File' => $this->tableFormatter->formatFileColumn($table, $reference->filePath, $reference->lineNumber),
                ]);
            }

            $output[] = sprintf('Locale "%s":%s%s', $locale, PHP_EOL, (string) $table);
        }

        return PHP_EOL . implode(PHP_EOL, $output);
    }

    /**
     * Render scan results as JSON
     */
    public function renderJson(ScanResult $scanResult, ScanConfiguration $configuration): string
    {
        $warnings = $scanResult->placeholderMismatches;
        if ($configuration->ignorePlaceholderWarnings) {
            $warnings = [];
        }

        $payload = [
            'missing' => array_map(fn(MissingTranslation $missing) => [
                'locale' => $missing->locale,
                'package' => $missing->key->packageKey,
                'source' => $missing->key->sourceName,
                'id' => $missing->key->identifier,
                'issue' => 'missing',
                'fallback' => $missing->reference->fallback,
                'placeholders' => array_keys($missing->reference->placeholders),
                'file' => PathResolver::relativePath($missing->reference->filePath),
                'line' => $missing->reference->lineNumber,
            ], $scanResult->missingTranslations),
            'warnings' => array_map(fn(PlaceholderMismatch $warning) => [
                'locale' => $warning->locale,
                'package' => $warning->key->packageKey,
                'source' => $warning->key->sourceName,
                'id' => $warning->key->identifier,
                'issue' => 'placeholder-mismatch',
                'missingPlaceholders' => $warning->missingPlaceholders,
                'referencePlaceholders' => $warning->referencePlaceholders,
                'catalogPlaceholders' => $warning->catalogPlaceholders,
                'file' => PathResolver::relativePath($warning->reference->filePath),
                'line' => $warning->reference->lineNumber,
            ], $warnings),
            'duplicates' => $this->summarizeReferenceDuplicates($scanResult->referenceIndex),
            'diagnostics' => [
                'errors' => $scanResult->catalogIndex->errors(),
                'missingCatalogs' => $scanResult->catalogIndex->missingCatalogs(),
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Resolve exit code based on scan results
     */
    public function resolveExitCode(ScanResult $scanResult, array $exitCodes): int
    {
        if ($scanResult->catalogIndex->errors() !== []) {
            return $exitCodes['failure'] ?? 7;
        }

        if ($scanResult->missingTranslations !== []) {
            return $exitCodes['missing'] ?? 5;
        }

        return $exitCodes['success'] ?? 0;
    }

    /**
     * Format placeholder warning message
     */
    public function formatPlaceholderWarningMessage(PlaceholderMismatch $warning): string
    {
        return sprintf(
            ' - [%s] %s / %s / %s missing {%s} (%s)',
            $warning->locale,
            $warning->key->packageKey,
            $warning->key->sourceName,
            $warning->key->identifier,
            implode(', ', $warning->missingPlaceholders),
            PathResolver::relativePath($warning->reference->filePath) . ':' . $warning->reference->lineNumber
        );
    }

    /**
     * @param list<MissingTranslation> $missingTranslations
     */
    private function sortMissingTranslations(array &$missingTranslations): void
    {
        usort(
            $missingTranslations,
            fn(MissingTranslation $a, MissingTranslation $b) => [$a->locale, $a->key->packageKey, $a->key->sourceName, $a->key->identifier]
                <=> [$b->locale, $b->key->packageKey, $b->key->sourceName, $b->key->identifier]
        );
    }

    private function summarizeReferenceDuplicates(ReferenceIndex $referenceIndex): array
    {
        $duplicates = [];
        foreach ($this->iterateDuplicateIdentifiers($referenceIndex) as [$packageKey, $sourceName, $identifier]) {
            $allReferences = $referenceIndex->allFor($packageKey, $sourceName, $identifier);
            $duplicates[] = [
                'package' => $packageKey,
                'source' => $sourceName,
                'id' => $identifier,
                'occurrences' => count($allReferences),
                'files' => array_map(
                    fn($reference) => [
                        'file' => PathResolver::relativePath($reference->filePath),
                        'line' => $reference->lineNumber,
                    ],
                    $allReferences
                ),
            ];
        }

        return $duplicates;
    }

    /**
     * @return iterable<array{0: string, 1: string, 2: string}>
     */
    private function iterateDuplicateIdentifiers(ReferenceIndex $referenceIndex): iterable
    {
        foreach ($referenceIndex->duplicates() as $packageKey => $sources) {
            foreach ($sources as $sourceName => $identifiers) {
                foreach (array_keys($identifiers) as $identifier) {
                    yield [$packageKey, $sourceName, $identifier];
                }
            }
        }
    }
}
