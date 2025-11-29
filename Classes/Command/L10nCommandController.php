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
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\PlaceholderMismatch;
use Two13Tec\L10nGuy\Domain\Dto\ReferenceIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;
use Two13Tec\L10nGuy\Service\CatalogFileParser;
use Two13Tec\L10nGuy\Service\CatalogIndexBuilder;
use Two13Tec\L10nGuy\Service\CatalogWriter;
use Two13Tec\L10nGuy\Service\FileDiscoveryService;
use Two13Tec\L10nGuy\Service\ReferenceIndexBuilder;
use Two13Tec\L10nGuy\Service\ScanConfigurationFactory;
use Two13Tec\L10nGuy\Service\ScanResultBuilder;

/**
 * Flow CLI controller for `./flow l10n:*`.
 *
 * @Flow\Scope("singleton")
 */
class L10nCommandController extends CommandController
{
    private const EXIT_KEY_SUCCESS = 'success';
    private const EXIT_KEY_MISSING = 'missing';
    private const EXIT_KEY_FAILURE = 'failure';
    private const EXIT_KEY_DIRTY = 'dirty';
    private const EXIT_KEY_UNUSED = 'unused';

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
    protected ScanResultBuilder $scanResultBuilder;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    #[Flow\InjectConfiguration(path: 'exitCodes', package: 'Two13Tec.L10nGuy')]
    protected array $exitCodes = [];

    /**
     * Run the localization scan and optionally write missing catalog entries.
     */
    public function scanCommand(
        ?string $package = null,
        ?string $source = null,
        ?string $path = null,
        ?string $locales = null,
        ?string $format = null,
        ?bool $dryRun = null,
        ?bool $update = null
    ): void {
        $configuration = $this->scanConfigurationFactory->createFromCliOptions([
            'package' => $package,
            'source' => $source,
            'paths' => $path ? [$path] : [],
            'locales' => $locales,
            'format' => $format,
            'dryRun' => $dryRun,
            'update' => $update,
        ]);

        $isJson = $configuration->format === 'json';
        if (!$isJson) {
            $this->outputLine(
                'Prepared scan for %s (locales: %s, format: %s, dry-run: %s).',
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
        $scanResult = $this->scanResultBuilder->build($configuration, $referenceIndex, $catalogIndex);

        if (!$isJson) {
            $this->outputLine(
                'Reference index: %d unique (%d duplicates flagged across %d occurrences).',
                [
                    $referenceIndex->uniqueCount(),
                    $referenceIndex->duplicateCount(),
                    $referenceIndex->totalCount(),
                ]
            );
        }

        $this->renderScanReport($scanResult, $configuration);

        if ($configuration->update) {
            $mutations = $this->buildMutations($scanResult);
            if (!$isJson && $mutations === []) {
                $this->outputLine('No catalog entries need to be created.');
            } elseif ($mutations !== []) {
                $touched = $this->catalogWriter->write($mutations, $catalogIndex, $configuration);
                if (!$isJson) {
                    if ($touched === []) {
                        $this->outputLine('Catalog writer did not touch any files.');
                    } else {
                        foreach ($touched as $file) {
                            $this->outputLine('Touched catalog: %s', [$this->relativePath($file)]);
                        }
                    }
                }
            }
        }

        $exitCode = $this->resolveScanExitCode($scanResult);
        if ($exitCode !== $this->exitCode(self::EXIT_KEY_SUCCESS, 0)) {
            $this->quit($exitCode);
        }
    }

    /**
     * Detect translation catalog entries that have no matching references and optionally delete them.
     *
     * @param string|null $package Package key to limit catalog inspection
     * @param string|null $locales Optional comma separated locale list
     * @param string|null $format Output format override
     * @param bool|null $dryRun Whether to apply deletions
     * @param bool|null $delete Toggle catalog mutations (Phase 5)
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
            $this->output($this->renderUnusedJson($unusedEntries, $referenceIndex, $catalogIndex));
            $this->outputLine();
        } else {
            $this->output($this->renderUnusedTable($unusedEntries));
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

        $exitCode = $this->resolveUnusedExitCode($catalogIndex, $unusedEntries, $configuration);
        if ($exitCode !== $this->exitCode(self::EXIT_KEY_SUCCESS, 0)) {
            $this->quit($exitCode);
        }
    }

    /**
     * Re-render translation catalogs with canonical formatting or verify the current state.
     *
     * @param string|null $package Package key to limit catalog formatting
     * @param string|null $source Optional source restriction (Presentation.Cards)
     * @param string|null $path Optional absolute/relative root for catalog discovery
     * @param string|null $locales Optional comma separated locale list
     * @param bool|null $check Toggle check-only mode (exits non-zero when formatting is required)
     */
    public function formatCommand(
        ?string $package = null,
        ?string $source = null,
        ?string $path = null,
        ?string $locales = null,
        ?bool $check = null
    ): void {
        $configuration = $this->scanConfigurationFactory->createFromCliOptions([
            'package' => $package,
            'source' => $source,
            'paths' => $path ? [$path] : [],
            'locales' => $locales,
        ]);
        $checkMode = (bool)$check;

        $this->outputLine(
            'Prepared format run for %s (locales: %s, check-only: %s).',
            [
                $configuration->packageKey ?? 'all packages',
                $configuration->locales === [] ? '<none>' : implode(', ', $configuration->locales),
                $checkMode ? 'yes' : 'no',
            ]
        );

        $this->fileDiscoveryService->seedFromConfiguration($configuration);

        $catalogIndex = $this->catalogIndexBuilder->build($configuration);
        $catalogs = $catalogIndex->catalogList();

        if ($catalogs === []) {
            $this->outputLine('No catalogs matched the given filters.');
            return;
        }

        $dirty = [];
        $formatted = [];

        foreach ($catalogs as $catalog) {
            $filePath = $catalog['path'];
            $parsed = CatalogFileParser::parse($filePath);
            $isClean = $this->catalogWriter->reformatCatalog(
                $filePath,
                $parsed['meta'],
                $parsed['units'],
                $catalog['packageKey'],
                $catalog['locale'],
                !$checkMode
            );

            if ($checkMode) {
                if (!$isClean) {
                    $dirty[] = $filePath;
                }
                continue;
            }

            if (!$isClean) {
                $formatted[] = $filePath;
                $this->outputLine('Formatted catalog: %s', [$this->relativePath($filePath)]);
            }
        }

        if ($checkMode) {
            if ($dirty === []) {
                $this->outputLine('All catalogs already match the canonical format.');
                return;
            }

            foreach ($dirty as $file) {
                $this->outputLine('Catalog requires formatting: %s', [$this->relativePath($file)]);
            }

            $this->quit($this->exitCode(self::EXIT_KEY_DIRTY, $this->exitCode(self::EXIT_KEY_FAILURE, 7)));
        }

        if ($formatted === []) {
            $this->outputLine('Catalogs already normalized.');
        }
    }

    private function renderScanReport(ScanResult $scanResult, ScanConfiguration $configuration): void
    {
        $catalogErrors = $scanResult->catalogIndex->errors();
        foreach ($catalogErrors as $error) {
            $this->logger->error(
                $error['message'],
                array_merge($error['context'], LogEnvironment::fromMethodName(__METHOD__))
            );
        }
        foreach ($scanResult->catalogIndex->missingCatalogs() as $missingCatalog) {
            $this->logger->warning(
                sprintf(
                    'Missing catalog for locale %s (%s:%s)',
                    $missingCatalog['locale'],
                    $missingCatalog['packageKey'],
                    $missingCatalog['sourceName']
                ),
                LogEnvironment::fromMethodName(__METHOD__)
            );
        }

        if ($configuration->format === 'json') {
            $this->output($this->renderScanJson($scanResult));
            $this->outputLine();
            return;
        }

        $this->output($this->renderScanTable($scanResult));
        $this->outputLine();

        if ($scanResult->placeholderMismatches !== []) {
            $this->outputLine('Placeholder warnings:');
            foreach ($scanResult->placeholderMismatches as $warning) {
                $this->outputLine(
                    ' - [%s] %s / %s / %s missing {%s} (%s)',
                    [
                        $warning->locale,
                        $warning->packageKey,
                        $warning->sourceName,
                        $warning->identifier,
                        implode(', ', $warning->missingPlaceholders),
                        $this->relativePath($warning->reference->filePath) . ':' . $warning->reference->lineNumber,
                    ]
                );
                $this->logger->warning(
                    sprintf(
                        'Placeholder mismatch for %s:%s:%s (%s)',
                        $warning->packageKey,
                        $warning->sourceName,
                        $warning->identifier,
                        $warning->locale
                    ),
                    array_merge(
                        [
                            'missing' => $warning->missingPlaceholders,
                            'reference' => $warning->referencePlaceholders,
                            'catalog' => $warning->catalogPlaceholders,
                            'file' => $warning->reference->filePath,
                            'line' => $warning->reference->lineNumber,
                        ],
                        LogEnvironment::fromMethodName(__METHOD__)
                    )
                );
            }
        }

        $duplicateCount = $scanResult->referenceIndex->duplicateCount();
        if ($duplicateCount > 0) {
            $this->outputLine('Duplicate ids detected (%d occurrences).', [$duplicateCount]);
        }
    }

    /**
     * @return list<CatalogMutation>
     */
    private function buildMutations(ScanResult $scanResult): array
    {
        $mutations = [];
        foreach ($scanResult->missingTranslations as $missing) {
            $fallback = $missing->reference->fallback ?? $missing->identifier;
            $mutations[] = new CatalogMutation(
                locale: $missing->locale,
                packageKey: $missing->packageKey,
                sourceName: $missing->sourceName,
                identifier: $missing->identifier,
                fallback: $fallback,
                placeholders: $missing->reference->placeholders
            );
        }

        return $mutations;
    }

    private function resolveScanExitCode(ScanResult $scanResult): int
    {
        if ($scanResult->catalogIndex->errors() !== []) {
            return $this->exitCode(self::EXIT_KEY_FAILURE, 7);
        }

        if ($scanResult->missingTranslations !== []) {
            return $this->exitCode(self::EXIT_KEY_MISSING, 5);
        }

        return $this->exitCode(self::EXIT_KEY_SUCCESS, 0);
    }

    private function renderScanTable(ScanResult $scanResult): string
    {
        if ($scanResult->missingTranslations === []) {
            return 'No missing translations detected.';
        }

        $table = new Table();
        $table->setHeaderStyle();
        $table->setBorderStyle();

        foreach ($scanResult->missingTranslations as $missing) {
            $reference = $missing->reference;
            $table->row([
                'Locale' => $missing->locale,
                'Package' => $missing->packageKey,
                'Source' => $missing->sourceName,
                'Id' => $missing->identifier,
                'Issue' => 'missing',
                'File' => $this->relativePath($reference->filePath) . ':' . $reference->lineNumber,
            ]);
        }

        return (string)$table;
    }

    private function renderScanJson(ScanResult $scanResult): string
    {
        $payload = [
            'missing' => array_map(fn (MissingTranslation $missing) => [
                'locale' => $missing->locale,
                'package' => $missing->packageKey,
                'source' => $missing->sourceName,
                'id' => $missing->identifier,
                'issue' => 'missing',
                'fallback' => $missing->reference->fallback,
                'placeholders' => array_keys($missing->reference->placeholders),
                'file' => $this->relativePath($missing->reference->filePath),
                'line' => $missing->reference->lineNumber,
            ], $scanResult->missingTranslations),
            'warnings' => array_map(fn (PlaceholderMismatch $warning) => [
                'locale' => $warning->locale,
                'package' => $warning->packageKey,
                'source' => $warning->sourceName,
                'id' => $warning->identifier,
                'issue' => 'placeholder-mismatch',
                'missingPlaceholders' => $warning->missingPlaceholders,
                'referencePlaceholders' => $warning->referencePlaceholders,
                'catalogPlaceholders' => $warning->catalogPlaceholders,
                'file' => $this->relativePath($warning->reference->filePath),
                'line' => $warning->reference->lineNumber,
            ], $scanResult->placeholderMismatches),
            'duplicates' => $this->summarizeReferenceDuplicates($scanResult->referenceIndex),
            'diagnostics' => [
                'errors' => $scanResult->catalogIndex->errors(),
                'missingCatalogs' => $scanResult->catalogIndex->missingCatalogs(),
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
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
        foreach ($this->iterateFilteredCatalogEntries($catalogIndex, $configuration) as $entry) {
            if ($referenceIndex->allFor($entry->packageKey, $entry->sourceName, $entry->identifier) !== []) {
                continue;
            }
            $unused[] = $entry;
        }

        return $unused;
    }

    private function renderUnusedJson(
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
            'duplicates' => $this->summarizeReferenceDuplicates($referenceIndex),
            'diagnostics' => [
                'errors' => $catalogIndex->errors(),
                'missingCatalogs' => $catalogIndex->missingCatalogs(),
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function renderUnusedTable(array $unusedEntries): string
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
                        'file' => $this->relativePath($reference->filePath),
                        'line' => $reference->lineNumber,
                    ],
                    $allReferences
                ),
            ];
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

    private function resolveUnusedExitCode(
        CatalogIndex $catalogIndex,
        array $unusedEntries,
        ScanConfiguration $configuration
    ): int {
        if ($catalogIndex->errors() !== []) {
            return $this->exitCode(self::EXIT_KEY_FAILURE, 7);
        }

        $hasUnused = $unusedEntries !== [];
        if ($configuration->update && !$configuration->dryRun) {
            $hasUnused = false;
        }

        if ($hasUnused) {
            return $this->exitCode(self::EXIT_KEY_UNUSED, 6);
        }

        return $this->exitCode(self::EXIT_KEY_SUCCESS, 0);
    }

    private function exitCode(string $key, int $fallback): int
    {
        return $this->exitCodes[$key] ?? $fallback;
    }

    /**
     * @return iterable<CatalogEntry>
     */
    private function iterateFilteredCatalogEntries(
        CatalogIndex $catalogIndex,
        ScanConfiguration $configuration
    ): iterable {
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

                    foreach ($identifiers as $entry) {
                        yield $entry;
                    }
                }
            }
        }
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

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(FLOW_PATH_ROOT, '', $path), '/');
    }
}
