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
use Neos\Flow\Log\Utility\LogEnvironment;
use Psr\Log\LoggerInterface;
use Two13Tec\L10nGuy\Domain\Dto\CatalogEntry;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\ReferenceIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Utility\PathResolver;

/**
 * Renders unused command output and resolves exit codes.
 *
 * @Flow\Scope("singleton")
 */
class UnusedReportRenderer
{
    public function __construct(
        private readonly TableFormatter $tableFormatter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Log catalog diagnostics (errors and missing catalogs)
     */
    public function logDiagnostics(CatalogIndex $catalogIndex): void
    {
        foreach ($catalogIndex->errors() as $error) {
            $this->logger->error(
                $error['message'],
                array_merge($error['context'], LogEnvironment::fromMethodName(__METHOD__))
            );
        }

        foreach ($catalogIndex->missingCatalogs() as $missing) {
            $this->logger->warning(
                sprintf(
                    'Missing catalog for locale %s (%s:%s)',
                    $missing['locale'],
                    $missing['packageKey'],
                    $missing['sourceName']
                ),
                LogEnvironment::fromMethodName(__METHOD__)
            );
        }
    }

    /**
     * Render unused entries as a table
     */
    public function renderTable(array $unusedEntries): string
    {
        if ($unusedEntries === []) {
            return 'No unused translations detected.';
        }

        $this->sortCatalogEntries($unusedEntries);

        $grouped = [];
        foreach ($unusedEntries as $entry) {
            $grouped[$entry->locale][] = $entry;
        }

        $output = [];
        ksort($grouped);

        foreach ($grouped as $locale => $entries) {
            $table = $this->tableFormatter->createStyledTable();
            $hidePackagePrefix = $this->tableFormatter->isSinglePackage(
                array_map(
                    fn (CatalogEntry $entry) => $entry->packageKey,
                    $entries
                )
            );

            foreach ($entries as $entry) {
                $table->row([
                    'Source/ID' => $this->tableFormatter->formatSourceCell(
                        $entry->packageKey,
                        $entry->sourceName,
                        $entry->identifier,
                        $hidePackagePrefix
                    ),
                    'File' => $this->tableFormatter->formatFileColumn($table, $entry->filePath),
                ]);
            }

            $output[] = sprintf('Locale "%s":%s%s', $locale, PHP_EOL, (string)$table);
        }

        return PHP_EOL . implode(PHP_EOL, $output);
    }

    /**
     * Render unused entries as JSON
     */
    public function renderJson(
        array $unusedEntries,
        ReferenceIndex $referenceIndex,
        CatalogIndex $catalogIndex
    ): string {
        $payload = [
            'unused' => array_map(fn (CatalogEntry $entry) => [
                'locale' => $entry->locale,
                'package' => $entry->packageKey,
                'source' => $entry->sourceName,
                'id' => $entry->identifier,
                'issue' => 'unused',
                'state' => $entry->state,
                'sourceText' => $entry->source,
                'targetText' => $entry->target,
                'file' => PathResolver::relativePath($entry->filePath),
            ], $unusedEntries),
            'duplicates' => $this->summarizeReferenceDuplicates($referenceIndex),
            'diagnostics' => [
                'errors' => $catalogIndex->errors(),
                'missingCatalogs' => $catalogIndex->missingCatalogs(),
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Resolve exit code based on catalog state and unused entries
     */
    public function resolveExitCode(
        CatalogIndex $catalogIndex,
        array $unusedEntries,
        ScanConfiguration $configuration,
        array $exitCodes
    ): int {
        $exitCodeHelper = fn (string $key, int $fallback) => $exitCodes[$key] ?? $fallback;

        if ($catalogIndex->errors() !== []) {
            return $exitCodeHelper('failure', 7);
        }

        if ($unusedEntries !== [] && !$configuration->update) {
            return $exitCodeHelper('unused', 6);
        }

        return $exitCodeHelper('success', 0);
    }

    /**
     * @param list<CatalogEntry> $entries
     */
    private function sortCatalogEntries(array &$entries): void
    {
        usort(
            $entries,
            fn (CatalogEntry $a, CatalogEntry $b) => [$a->locale, $a->packageKey, $a->sourceName, $a->identifier]
                <=> [$b->locale, $b->packageKey, $b->sourceName, $b->identifier]
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
                    fn ($reference) => [
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
