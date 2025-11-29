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
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\PlaceholderMismatch;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;
use Two13Tec\L10nGuy\Service\CatalogIndexBuilder;
use Two13Tec\L10nGuy\Service\CatalogWriter;
use Two13Tec\L10nGuy\Service\FileDiscoveryService;
use Two13Tec\L10nGuy\Service\ReferenceIndexBuilder;
use Two13Tec\L10nGuy\Service\ScanConfigurationFactory;
use Two13Tec\L10nGuy\Service\ScanResultBuilder;

/**
 * Flow CLI controller for `./flow l10n:scan`.
 *
 * @Flow\Scope("singleton")
 */
class LocalizationScanCommandController extends CommandController
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
    protected ScanResultBuilder $scanResultBuilder;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    #[Flow\InjectConfiguration(path: 'i18n.helper.exitCodes', package: 'Neos.Flow')]
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

        $this->renderReport($scanResult, $configuration);

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

        $exitCode = $this->resolveExitCode($scanResult);
        if ($exitCode !== ($this->exitCodes['success'] ?? 0)) {
            $this->quit($exitCode);
        }
    }

    private function renderReport(ScanResult $scanResult, ScanConfiguration $configuration): void
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
            $this->output($this->renderJson($scanResult));
            $this->outputLine();
            return;
        }

        $this->output($this->renderTable($scanResult));
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

    private function resolveExitCode(ScanResult $scanResult): int
    {
        if ($scanResult->catalogIndex->errors() !== []) {
            return $this->exitCodes['failure'] ?? 7;
        }

        if ($scanResult->missingTranslations !== []) {
            return $this->exitCodes['missing'] ?? 5;
        }

        return $this->exitCodes['success'] ?? 0;
    }

    private function renderTable(ScanResult $scanResult): string
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

    private function renderJson(ScanResult $scanResult): string
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
            'duplicates' => $this->summarizeDuplicates($scanResult),
            'diagnostics' => [
                'errors' => $scanResult->catalogIndex->errors(),
                'missingCatalogs' => $scanResult->catalogIndex->missingCatalogs(),
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function summarizeDuplicates(ScanResult $scanResult): array
    {
        $duplicates = [];
        foreach ($scanResult->referenceIndex->duplicates() as $packageKey => $sources) {
            foreach ($sources as $sourceName => $identifiers) {
                foreach ($identifiers as $identifier => $list) {
                    $allReferences = $scanResult->referenceIndex->allFor($packageKey, $sourceName, $identifier);
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

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(FLOW_PATH_ROOT, '', $path), '/');
    }
}
