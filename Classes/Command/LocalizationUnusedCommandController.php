<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Command;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use InitPHP\CLITable\Table;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Log\Utility\LogEnvironment;
use Psr\Log\LoggerInterface;
use Two13Tec\L10nGuy\Domain\Dto\CatalogEntry;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\ReferenceIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Service\CatalogIndexBuilder;
use Two13Tec\L10nGuy\Service\CatalogWriter;
use Two13Tec\L10nGuy\Service\FileDiscoveryService;
use Two13Tec\L10nGuy\Service\ReferenceIndexBuilder;
use Two13Tec\L10nGuy\Service\ScanConfigurationFactory;

/**
 * Stub for the l10n:unused CLI entry point.
 *
 * @Flow\Scope("singleton")
 */
class LocalizationUnusedCommandController extends CommandController
{
    #[Flow\Inject]
    protected ScanConfigurationFactory $scanConfigurationFactory;

    #[Flow\Inject]
    protected FileDiscoveryService $fileDiscoveryService;

    #[Flow\Inject]
    protected ReferenceIndexBuilder $referenceIndexBuilder;

    #[Flow\Inject]
    protected CatalogIndexBuilder $catalogIndexBuilder;

    #[Flow\Inject]
    protected CatalogWriter $catalogWriter;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    #[Flow\InjectConfiguration(path: 'i18n.helper.exitCodes', package: 'Neos.Flow')]
    protected array $exitCodes = [];

    /**
     * @param string|null $package Package key to limit catalog inspection
     * @param string|null $locales Optional comma separated locale list
     * @param string|null $format Output format override
     * @param bool|null $dryRun Whether to apply deletions
     * @param bool|null $delete Toggle catalog mutations (Phase 5)
     * @return void
     */
    public function unusedCommand(
        ?string $package = null,
        ?string $source = null,
        ?string $path = null,
        ?string $locales = null,
        ?string $format = null,
        ?bool $dryRun = null,
        ?bool $delete = null
    ): void {
        $configuration = $this->scanConfigurationFactory->createFromCliOptions([
            'package' => $package,
            'source' => $source,
            'paths' => $path ? [$path] : [],
            'locales' => $locales,
            'format' => $format,
            'dryRun' => $dryRun,
            'update' => $delete,
        ]);

        $isJson = $configuration->format === 'json';
        if (!$isJson) {
            $this->outputLine(
                'Prepared unused sweep for %s (locales: %s, format: %s, dry-run: %s).',
                [
                    $configuration->packageKey ?? 'all packages',
                    $configuration->locales === [] ? '<none>' : implode(', ', $configuration->locales),
                    $configuration->format,
                    $configuration->dryRun ? 'yes' : 'no',
                ]
            );
        }

        $this->fileDiscoveryService->seedFromConfiguration($configuration);

        $referenceIndex = $this->referenceIndexBuilder->build($configuration);
        $catalogIndex = $this->catalogIndexBuilder->build($configuration);
        $unusedEntries = $this->findUnusedEntries($catalogIndex, $referenceIndex, $configuration);

        $this->logCatalogDiagnostics($catalogIndex);

        if ($configuration->format === 'json') {
            $this->output($this->renderJson($unusedEntries, $referenceIndex, $catalogIndex));
            $this->outputLine();
        } else {
            $this->output($this->renderTable($unusedEntries));
            $this->outputLine();

            $duplicateCount = $referenceIndex->duplicateCount();
            if ($duplicateCount > 0) {
                $this->outputLine('Duplicate ids detected (%d occurrences).', [$duplicateCount]);
            }
        }

        if ($configuration->update && $unusedEntries !== []) {
            $touched = $this->catalogWriter->deleteEntries($unusedEntries, $configuration);

            if (!$isJson) {
                if ($touched === []) {
                    $this->outputLine('No catalog entries were deleted.');
                } else {
                    foreach ($touched as $file) {
                        $this->outputLine('Touched catalog: %s', [$this->relativePath($file)]);
                    }
                }
            }
        }

        $exitCode = $this->resolveExitCode($catalogIndex, $unusedEntries, $configuration);
        if ($exitCode !== ($this->exitCodes['success'] ?? 0)) {
            $this->quit($exitCode);
        }
    }

    /**
     * @return list<CatalogEntry>
     */
    private function findUnusedEntries(
        CatalogIndex $catalogIndex,
        ReferenceIndex $referenceIndex,
        ScanConfiguration $configuration
    ): array {
        $unused = [];
        foreach ($catalogIndex->entries() as $locale => $packages) {
            if ($configuration->locales !== [] && !in_array($locale, $configuration->locales, true)) {
                continue;
            }
            foreach ($packages as $packageKey => $sources) {
                if ($configuration->packageKey !== null && $configuration->packageKey !== $packageKey) {
                    continue;
                }
                foreach ($sources as $sourceName => $identifiers) {
                    if ($configuration->sourceName !== null && $configuration->sourceName !== $sourceName) {
                        continue;
                    }
                    foreach ($identifiers as $identifier => $entry) {
                        if ($referenceIndex->allFor($packageKey, $sourceName, $identifier) !== []) {
                            continue;
                        }
                        $unused[] = $entry;
                    }
                }
            }
        }

        return $unused;
    }

    private function renderJson(
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
                'file' => $this->relativePath($entry->filePath),
            ], $unusedEntries),
            'duplicates' => $this->summarizeDuplicates($referenceIndex),
            'diagnostics' => [
                'errors' => $catalogIndex->errors(),
                'missingCatalogs' => $catalogIndex->missingCatalogs(),
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function renderTable(array $unusedEntries): string
    {
        if ($unusedEntries === []) {
            return 'No unused translations detected.';
        }

        $table = new Table();
        $table->setHeaderStyle();
        $table->setBorderStyle();

        foreach ($unusedEntries as $entry) {
            $table->row([
                'Locale' => $entry->locale,
                'Package' => $entry->packageKey,
                'Source' => $entry->sourceName,
                'Id' => $entry->identifier,
                'Issue' => 'unused',
                'File' => $this->relativePath($entry->filePath),
            ]);
        }

        return (string)$table;
    }

    private function summarizeDuplicates(ReferenceIndex $referenceIndex): array
    {
        $duplicates = [];
        foreach ($referenceIndex->duplicates() as $packageKey => $sources) {
            foreach ($sources as $sourceName => $identifiers) {
                foreach ($identifiers as $identifier => $list) {
                    $allReferences = $referenceIndex->allFor($packageKey, $sourceName, $identifier);
                    $duplicates[] = [
                        'package' => $packageKey,
                        'source' => $sourceName,
                        'id' => $identifier,
                        'occurrences' => count($allReferences),
                        'files' => array_map(
                            fn ($reference) => [
                                'file' => $this->relativePath($reference->filePath),
                                'line' => $reference->lineNumber,
                            ],
                            $allReferences
                        ),
                    ];
                }
            }
        }

        return $duplicates;
    }

    private function logCatalogDiagnostics(CatalogIndex $catalogIndex): void
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

    private function resolveExitCode(
        CatalogIndex $catalogIndex,
        array $unusedEntries,
        ScanConfiguration $configuration
    ): int {
        if ($catalogIndex->errors() !== []) {
            return $this->exitCodes['failure'] ?? 7;
        }

        $hasUnused = $unusedEntries !== [];
        if ($configuration->update && !$configuration->dryRun) {
            $hasUnused = false;
        }

        if ($hasUnused) {
            return $this->exitCodes['unused'] ?? 6;
        }

        return $this->exitCodes['success'] ?? 0;
    }

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(FLOW_PATH_ROOT, '', $path), '/');
    }
}
