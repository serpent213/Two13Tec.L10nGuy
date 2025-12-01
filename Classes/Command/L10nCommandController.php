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

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Log\Utility\LogEnvironment;
use Psr\Log\LoggerInterface;
use Two13Tec\L10nGuy\Cli\Table\Table;
use Two13Tec\L10nGuy\Domain\Dto\CatalogEntry;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\PlaceholderMismatch;
use Two13Tec\L10nGuy\Domain\Dto\ReferenceIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;
use Two13Tec\L10nGuy\Service\CatalogFileParser;
use Two13Tec\L10nGuy\Service\CatalogIndexBuilder;
use Two13Tec\L10nGuy\Service\CatalogWriter;
use Two13Tec\L10nGuy\Service\FileDiscoveryService;
use Two13Tec\L10nGuy\Service\ReferenceIndexBuilder;
use Two13Tec\L10nGuy\Service\CatalogMutationFactory;
use Two13Tec\L10nGuy\Service\ScanConfigurationFactory;
use Two13Tec\L10nGuy\Service\ScanResultBuilder;
use Two13Tec\L10nGuy\Domain\Dto\TranslationKey;
use Two13Tec\L10nGuy\Llm\Exception\LlmConfigurationException;
use Two13Tec\L10nGuy\Llm\Exception\LlmUnavailableException;
use Two13Tec\L10nGuy\Llm\LlmTranslationService;
use Two13Tec\L10nGuy\Domain\Dto\LlmRunStatistics;
use Two13Tec\L10nGuy\Utility\ProgressIndicator;
use Two13Tec\L10nGuy\Command\Rendering\TableFormatter;
use Two13Tec\L10nGuy\Command\Rendering\LlmReportRenderer;
use Two13Tec\L10nGuy\Command\Rendering\ScanReportRenderer;
use Two13Tec\L10nGuy\Command\Rendering\UnusedReportRenderer;
use Two13Tec\L10nGuy\Utility\PathResolver;

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
    private const SOURCE_LOCALE = 'en';

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
    protected CatalogMutationFactory $catalogMutationFactory;

    #[Flow\Inject]
    protected ScanResultBuilder $scanResultBuilder;

    #[Flow\Inject]
    protected LlmTranslationService $llmTranslationService;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    #[Flow\Inject]
    protected TableFormatter $tableFormatter;

    #[Flow\Inject]
    protected LlmReportRenderer $llmReportRenderer;

    #[Flow\Inject]
    protected ScanReportRenderer $scanReportRenderer;

    #[Flow\Inject]
    protected UnusedReportRenderer $unusedReportRenderer;

    /**
     * @var array{
     *     success?: int,
     *     missing?: int,
     *     failure?: int,
     *     dirty?: int,
     *     unused?: int
     * }
     */
    #[Flow\InjectConfiguration(path: 'exitCodes', package: 'Two13Tec.L10nGuy')]
    protected array $exitCodes = [];

    /**
     * Run the localization scan and optionally write missing catalog entries.
     *
     * @param string|null $package Package key to scan (defaults to configuration or all packages)
     * @param string|null $source Optional source restriction (e.g., Presentation.Cards)
     * @param string|null $path Optional absolute/relative search root for references and catalogs
     * @param string|null $locales Optional comma separated locale list (defaults to configured locales)
     * @param string|null $id Optional translation ID glob pattern (e.g., hero.*, *.label)
     * @param string|null $format Output format: table (default) or json
     * @param bool|null $update Write missing catalog entries to XLF files
     * @param bool|null $llm Enable LLM-based translation of missing entries
     * @param string|null $llmProvider Override the configured LLM provider
     * @param string|null $llmModel Override the configured LLM model
     * @param bool|null $dryRun Estimate LLM tokens without making API calls; inhibits catalog writes even when --update is set
     * @param bool|null $ignorePlaceholder Suppress placeholder mismatch warnings
     * @param bool|null $quiet Suppress table output
     * @param bool|null $quieter Suppress all stdout output (warnings/errors still surface on stderr)
     */
    public function scanCommand(
        ?string $package = null,
        ?string $source = null,
        ?string $path = null,
        ?string $locales = null,
        ?string $id = null,
        ?string $format = null,
        ?bool $update = null,
        ?bool $llm = null,
        ?string $llmProvider = null,
        ?string $llmModel = null,
        ?bool $dryRun = null,
        ?bool $ignorePlaceholder = null,
        ?bool $quiet = null,
        ?bool $quieter = null
    ): void {
        $configuration = $this->scanConfigurationFactory->createFromCliOptions([
            'package' => $package,
            'source' => $source,
            'paths' => $path ? [$path] : [],
            'locales' => $locales,
            'id' => $id,
            'format' => $format,
            'update' => $update,
            'llm' => $llm,
            'llmProvider' => $llmProvider,
            'llmModel' => $llmModel,
            'dryRun' => $dryRun,
            'ignorePlaceholder' => $ignorePlaceholder,
            'quiet' => $quiet,
            'quieter' => $quieter,
        ]);

        $isJson = $configuration->format === 'json';
        if (!$isJson && !$configuration->quieter) {
            $this->outputLine(
                'Prepared scan for %s (locales: %s, format: %s, update: %s).',
                [
                    $configuration->packageKey ?? 'all packages',
                    $configuration->locales === [] ? '<none>' : implode(', ', $configuration->locales),
                    $configuration->format,
                    $configuration->update ? 'yes' : 'no',
                ]
            );
        }

        $this->fileDiscoveryService->seedFromConfiguration($configuration);

        $referenceIndex = $this->referenceIndexBuilder->build($configuration);
        $catalogIndex = $this->catalogIndexBuilder->build($configuration);
        $scanResult = $this->scanResultBuilder->build($configuration, $referenceIndex, $catalogIndex);

        if (!$isJson && !$configuration->quieter) {
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
            $mutations = $this->catalogMutationFactory->fromScanResult($scanResult);
            if (!$isJson && !$configuration->quieter && $mutations === []) {
                $this->outputLine('No catalog entries need to be created.');
            } elseif ($mutations !== []) {
                if ($configuration->llm !== null && $configuration->llm->enabled) {
                    $progressIndicator = null;
                    $runStatistics = null;
                    if (!$isJson && !$configuration->quieter && !$configuration->llm->dryRun) {
                        $modelLabel = $configuration->llm->model ?? 'model?';
                        $progressIndicator = new ProgressIndicator(sprintf('%%d/%%d LLM API calls (%s)', $modelLabel));
                        $runStatistics = new LlmRunStatistics();
                    }

                    try {
                        $mutations = $this->llmTranslationService->translate(
                            $mutations,
                            $scanResult,
                            $configuration->llm,
                            $progressIndicator,
                            $runStatistics
                        );
                    } catch (LlmUnavailableException|LlmConfigurationException $exception) {
                        $this->outputLine('! %s', [$exception->getMessage()]);
                        $this->quit($this->exitCode(self::EXIT_KEY_FAILURE, 7));
                    }

                    if ($configuration->llm->dryRun) {
                        if (!$isJson && !$configuration->quieter) {
                            $this->outputLine('LLM dry-run completed; catalogs were not modified.');
                        }
                        $this->outputLlmReport(
                            $mutations,
                            $scanResult->catalogIndex,
                            self::SOURCE_LOCALE,
                            null,
                            $isJson,
                            $configuration->quiet,
                            $configuration->quieter
                        );
                        return;
                    }

                    $this->outputLlmReport(
                        $mutations,
                        $scanResult->catalogIndex,
                        self::SOURCE_LOCALE,
                        $runStatistics,
                        $isJson,
                        $configuration->quiet,
                        $configuration->quieter
                    );
                }

                $touched = $this->catalogWriter->write($mutations, $catalogIndex, $configuration);
                if (!$isJson && !$configuration->quieter) {
                    if ($touched === []) {
                        $this->outputLine('Catalog writer did not touch any files.');
                    } else {
                        foreach ($touched as $file) {
                            $this->outputLine('Touched catalog: %s', [PathResolver::relativePath($file)]);
                        }
                    }
                }
            }
        }

        $exitCode = $this->scanReportRenderer->resolveExitCode($scanResult, $this->exitCodes);
        if ($exitCode !== $this->exitCode(self::EXIT_KEY_SUCCESS, 0)) {
            $this->quit($exitCode);
        }
    }

    /**
     * Bulk translate catalog entries to a new locale using LLM.
     *
     * @param string $to Target locale for translation
     * @param string|null $from Source locale (auto-detected when omitted)
     * @param string|null $package Package key to translate
     * @param string|null $source Source restriction (e.g., Presentation.Cards)
     * @param string|null $id Translation ID glob pattern
     * @param string|null $path Optional search root for references and catalogs
     * @param string|null $llmProvider Override the configured LLM provider
     * @param string|null $llmModel Override the configured LLM model
     * @param bool|null $dryRun Estimate tokens without making API calls; inhibits catalog writes
     * @param bool|null $quiet Suppress table output
     * @param bool|null $quieter Suppress all stdout output (warnings/errors still surface on stderr)
     */
    public function translateCommand(
        string $to,
        ?string $from = null,
        ?string $package = null,
        ?string $source = null,
        ?string $id = null,
        ?string $path = null,
        ?string $llmProvider = null,
        ?string $llmModel = null,
        ?bool $dryRun = null,
        ?bool $quiet = null,
        ?bool $quieter = null
    ): void {
        $baseConfiguration = $this->scanConfigurationFactory->createFromCliOptions([
            'package' => $package,
            'source' => $source,
            'paths' => $path ? [$path] : [],
            'locales' => $from !== null ? [$from, $to] : [$to],
            'id' => $id,
            'update' => true,
            'llm' => true,
            'llmProvider' => $llmProvider,
            'llmModel' => $llmModel,
            'dryRun' => $dryRun,
            'quiet' => $quiet,
            'quieter' => $quieter,
        ]);

        $catalogConfiguration = $this->copyConfigurationWithLocales($baseConfiguration, []);

        if (!$baseConfiguration->quieter) {
            $this->outputLine(
                'Prepared translation to %s (package: %s, source filter: %s).',
                [
                    $to,
                    $baseConfiguration->packageKey ?? 'all packages',
                    $baseConfiguration->sourceName ?? '<none>',
                ]
            );
        }

        $this->fileDiscoveryService->seedFromConfiguration($catalogConfiguration);

        $referenceIndex = $this->referenceIndexBuilder->build($baseConfiguration);
        $catalogIndex = $this->catalogIndexBuilder->build($catalogConfiguration);

        $sourceLocale = $this->detectSourceLocale($catalogIndex, $to, $from);
        if ($sourceLocale === null) {
            $this->outputLine('! Unable to determine source locale. Provide --from to continue.');
            $this->quit($this->exitCode(self::EXIT_KEY_FAILURE, 7));
        }

        $runConfiguration = $this->copyConfigurationWithLocales($baseConfiguration, array_values(array_unique([$sourceLocale, $to])));

        $scanResult = $this->scanResultBuilder->build($runConfiguration, $referenceIndex, $catalogIndex);
        $missingForTarget = array_values(array_filter(
            $scanResult->missingTranslations,
            static fn (MissingTranslation $missing): bool => $missing->locale === $to
        ));

        if ($missingForTarget === []) {
            if (!$runConfiguration->quieter) {
                $this->outputLine('No entries need translation from %s to %s.', [$sourceLocale, $to]);
            }
            return;
        }

        if (!$runConfiguration->quieter) {
            $this->outputLine(
                'Found %d entries to translate from %s to %s.',
                [count($missingForTarget), $sourceLocale, $to]
            );
        }

        $adjustedScanResult = new ScanResult(
            $this->buildBulkMissingTranslations($missingForTarget, $catalogIndex, $sourceLocale),
            [],
            $referenceIndex,
            $catalogIndex
        );

        $mutations = $this->catalogMutationFactory->fromScanResult($adjustedScanResult);

        if ($runConfiguration->llm === null) {
            $this->outputLine('! LLM configuration is missing; translation cannot proceed.');
            $this->quit($this->exitCode(self::EXIT_KEY_FAILURE, 7));
        }

        $runStatistics = null;
        try {
            $progressIndicator = null;
            if (!$runConfiguration->quieter && !$runConfiguration->llm->dryRun) {
                $modelLabel = $runConfiguration->llm->model ?? 'model?';
                $progressIndicator = new ProgressIndicator(sprintf('%%d/%%d LLM API calls (%s)', $modelLabel));
                $runStatistics = new LlmRunStatistics();
            }

            $mutations = $this->llmTranslationService->translate(
                $mutations,
                $adjustedScanResult,
                $runConfiguration->llm,
                $progressIndicator,
                $runStatistics
            );
        } catch (LlmUnavailableException|LlmConfigurationException $exception) {
            $this->outputLine('! %s', [$exception->getMessage()]);
            $this->quit($this->exitCode(self::EXIT_KEY_FAILURE, 7));
        }

        if ($runConfiguration->llm->dryRun) {
            if (!$runConfiguration->quieter) {
                $this->outputLine('LLM dry-run completed; catalogs were not modified.');
            }
            $this->outputLlmReport(
                $mutations,
                $catalogIndex,
                $sourceLocale,
                null,
                false,
                $runConfiguration->quiet,
                $runConfiguration->quieter
            );
            return;
        }

        $this->outputLlmReport(
            $mutations,
            $catalogIndex,
            $sourceLocale,
            $runStatistics,
            false,
            $runConfiguration->quiet,
            $runConfiguration->quieter
        );

        $touched = $this->catalogWriter->write($mutations, $catalogIndex, $runConfiguration);
        if (!$runConfiguration->quieter) {
            if ($touched === []) {
                $this->outputLine('Catalog writer did not touch any files.');
            } else {
                foreach ($touched as $file) {
                    $this->outputLine('Touched catalog: %s', [PathResolver::relativePath($file)]);
                }
            }
        }
    }

    /**
     * Detect translation catalog entries that have no matching references and optionally delete them.
     *
     * @param string|null $package Package key to limit catalog inspection
     * @param string|null $source Optional source restriction (e.g., Presentation.Cards)
     * @param string|null $path Optional absolute/relative root for catalog discovery
     * @param string|null $locales Optional comma separated locale list (defaults to configured locales)
     * @param string|null $id Optional translation ID glob pattern (e.g., hero.*, *.label)
     * @param string|null $format Output format: table (default) or json
     * @param bool|null $delete Delete unused catalog entries
     * @param bool|null $quiet Suppress table output
     * @param bool|null $quieter Suppress all stdout output (warnings/errors still surface on stderr)
     */
    public function unusedCommand(
        ?string $package = null,
        ?string $source = null,
        ?string $path = null,
        ?string $locales = null,
        ?string $id = null,
        ?string $format = null,
        ?bool $delete = null,
        ?bool $quiet = null,
        ?bool $quieter = null
    ): void {
        $configuration = $this->scanConfigurationFactory->createFromCliOptions([
            'package' => $package,
            'source' => $source,
            'paths' => $path ? [$path] : [],
            'locales' => $locales,
            'id' => $id,
            'format' => $format,
            'update' => $delete,
            'quiet' => $quiet,
            'quieter' => $quieter,
        ]);

        $isJson = $configuration->format === 'json';
        if (!$isJson && !$configuration->quieter) {
            $this->outputLine(
                'Prepared unused sweep for %s (locales: %s, format: %s, delete: %s).',
                [
                    $configuration->packageKey ?? 'all packages',
                    $configuration->locales === [] ? '<none>' : implode(', ', $configuration->locales),
                    $configuration->format,
                    $configuration->update ? 'yes' : 'no',
                ]
            );
        }

        $this->fileDiscoveryService->seedFromConfiguration($configuration);

        $referenceIndex = $this->referenceIndexBuilder->build($configuration);
        $catalogIndex = $this->catalogIndexBuilder->build($configuration);
        $unusedEntries = $this->findUnusedEntries($catalogIndex, $referenceIndex, $configuration);

        $this->unusedReportRenderer->logDiagnostics($catalogIndex);

        if ($configuration->format === 'json' && !$configuration->quieter) {
            $this->output($this->unusedReportRenderer->renderJson($unusedEntries, $referenceIndex, $catalogIndex));
            $this->outputLine();
        } elseif (!$configuration->quiet && !$configuration->quieter) {
            $this->output($this->unusedReportRenderer->renderTable($unusedEntries));
            $this->outputLine();
        }

        if ($configuration->update && $unusedEntries !== []) {
            $touched = $this->catalogWriter->deleteEntries($unusedEntries, $configuration);

            if (!$isJson && !$configuration->quieter) {
                if ($touched === []) {
                    $this->outputLine('No catalog entries were deleted.');
                } else {
                    foreach ($touched as $file) {
                        $this->outputLine('Touched catalog: %s', [PathResolver::relativePath($file)]);
                    }
                }
            }
        }

        $exitCode = $this->unusedReportRenderer->resolveExitCode($catalogIndex, $unusedEntries, $configuration, $this->exitCodes);
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
                !$checkMode,
                [
                    'fileAttributes' => $parsed['fileAttributes'] ?? [],
                    'fileChildren' => $parsed['fileChildren'] ?? [],
                    'bodyOrder' => $parsed['bodyOrder'] ?? [],
                ]
            );

            if ($checkMode) {
                if (!$isClean) {
                    $dirty[] = $filePath;
                }
                continue;
            }

            if (!$isClean) {
                $formatted[] = $filePath;
                $this->outputLine('Formatted catalog: %s', [PathResolver::relativePath($filePath)]);
            }
        }

        if ($checkMode) {
            if ($dirty === []) {
                $this->outputLine('All catalogs already match the canonical format.');
                return;
            }

            foreach ($dirty as $file) {
                $this->outputLine('Catalog requires formatting: %s', [PathResolver::relativePath($file)]);
            }

            $exitCode = $dirty !== []
                ? $this->exitCode(self::EXIT_KEY_DIRTY, $this->exitCode(self::EXIT_KEY_FAILURE, 7))
                : $this->exitCode(self::EXIT_KEY_FAILURE, 7);
            $this->quit($exitCode);
        }

        if ($formatted === []) {
            $this->outputLine('Catalogs already normalized.');
        }
    }

    /**
     * @return list<MissingTranslation>
     */
    private function buildBulkMissingTranslations(array $missingTranslations, CatalogIndex $catalogIndex, string $sourceLocale): array
    {
        $result = [];
        foreach ($missingTranslations as $missing) {
            $fallback = $this->fallbackForMissing($missing, $catalogIndex, $sourceLocale);
            $reference = $missing->reference;

            $result[] = new MissingTranslation(
                locale: $missing->locale,
                key: $missing->key,
                reference: new TranslationReference(
                    packageKey: $reference->packageKey,
                    sourceName: $reference->sourceName,
                    identifier: $reference->identifier,
                    context: $reference->context,
                    filePath: $reference->filePath,
                    lineNumber: $reference->lineNumber,
                    fallback: $fallback,
                    placeholders: $reference->placeholders,
                    isPlural: $reference->isPlural,
                    nodeTypeContext: $reference->nodeTypeContext
                )
            );
        }

        return $result;
    }

    private function fallbackForMissing(MissingTranslation $missing, CatalogIndex $catalogIndex, string $sourceLocale): string
    {
        $entries = $catalogIndex->entriesFor($sourceLocale, $missing->key);
        $entry = $entries[$missing->key->identifier] ?? null;
        $fallback = $entry?->target ?? $entry?->source ?? $missing->reference->fallback ?? '';

        if ($fallback !== '') {
            return $fallback;
        }

        return $this->fallbackWithPlaceholderHints($missing->key->identifier, $missing->reference->placeholders);
    }

    private function fallbackWithPlaceholderHints(string $identifier, array $placeholderMap): string
    {
        if ($placeholderMap === []) {
            return $identifier;
        }

        $placeholders = array_map(
            static fn (string $name): string => sprintf('{%s}', $name),
            array_keys($placeholderMap)
        );

        return trim($identifier . ' ' . implode(' ', $placeholders));
    }

    private function detectSourceLocale(CatalogIndex $catalogIndex, string $targetLocale, ?string $requestedSource): ?string
    {
        if ($requestedSource !== null && $requestedSource !== '') {
            return $requestedSource;
        }

        foreach ($catalogIndex->locales() as $locale) {
            if ($locale === $targetLocale) {
                continue;
            }
            return $locale;
        }

        return null;
    }

    private function copyConfigurationWithLocales(ScanConfiguration $configuration, array $locales): ScanConfiguration
    {
        return new ScanConfiguration(
            locales: $locales,
            packageKey: $configuration->packageKey,
            sourceName: $configuration->sourceName,
            idPattern: $configuration->idPattern,
            paths: $configuration->paths,
            format: $configuration->format,
            update: $configuration->update,
            newState: $configuration->newState,
            newStateQualifier: $configuration->newStateQualifier,
            ignorePlaceholderWarnings: $configuration->ignorePlaceholderWarnings,
            meta: $configuration->meta,
            quiet: $configuration->quiet,
            quieter: $configuration->quieter,
            llm: $configuration->llm
        );
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
            if (!$configuration->quieter) {
                $this->output($this->scanReportRenderer->renderJson($scanResult, $configuration));
                $this->outputLine();
            }
            return;
        }

        if (!$configuration->quiet && !$configuration->quieter) {
            $this->output($this->scanReportRenderer->renderTable($scanResult));
            $this->outputLine();
        }

        if ($configuration->ignorePlaceholderWarnings || $scanResult->placeholderMismatches === []) {
            return;
        }

        if (!$configuration->quieter) {
            $this->outputLine('Placeholder warnings:');
        }

        foreach ($scanResult->placeholderMismatches as $warning) {
            $message = sprintf(
                ' - [%s] %s / %s / %s missing {%s} (%s)',
                $warning->locale,
                $warning->key->packageKey,
                $warning->key->sourceName,
                $warning->key->identifier,
                implode(', ', $warning->missingPlaceholders),
                PathResolver::relativePath($warning->reference->filePath) . ':' . $warning->reference->lineNumber
            );

            if ($configuration->quieter) {
                $this->writeToErrorOutput($message);
            } else {
                $this->outputLine($message);
            }

            $this->logger->warning(
                sprintf(
                    'Placeholder mismatch for %s:%s:%s (%s)',
                    $warning->key->packageKey,
                    $warning->key->sourceName,
                    $warning->key->identifier,
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
                    if ($configuration->sourceName !== null && !$this->matchesPattern($sourceName, $configuration->sourceName)) {
                        continue;
                    }

                    foreach ($identifiers as $identifier => $entry) {
                        if ($configuration->idPattern !== null && !$this->matchesPattern($identifier, $configuration->idPattern)) {
                            continue;
                        }
                        yield $entry;
                    }
                }
            }
        }
    }

    private function outputLlmReport(
        array $mutations,
        CatalogIndex $catalogIndex,
        string $sourceLocale,
        ?LlmRunStatistics $runStatistics,
        bool $isJson,
        bool $quiet,
        bool $quieter
    ): void {
        if ($quieter) {
            return;
        }

        $table = null;
        if (!$quiet) {
            $table = $this->llmReportRenderer->renderTranslationsTable($mutations, $catalogIndex, $sourceLocale);
        }

        if ($runStatistics !== null && !$isJson) {
            $this->outputLine($this->llmReportRenderer->renderStatistics($runStatistics));
            if ($table !== null) {
                $this->outputLine();
            }
        }

        if ($table !== null) {
            $this->output($table);
            $this->outputLine();
        }
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return $value === $pattern;
        }

        $escaped = preg_quote($pattern, '/');
        $regex = '/^' . str_replace('\*', '.*', $escaped) . '$/i';

        return preg_match($regex, $value) === 1;
    }

    private function writeToErrorOutput(string $message): void
    {
        $messageWithNewline = $message . PHP_EOL;

        $errorOutput = null;
        if (is_object($this->output) && method_exists($this->output, 'getErrorOutput')) {
            $errorOutput = $this->output->getErrorOutput();
        }
        if ($errorOutput === null && is_object($this->output) && method_exists($this->output, 'getOutput')) {
            $rawOutput = $this->output->getOutput();
            if (is_object($rawOutput) && method_exists($rawOutput, 'getErrorOutput')) {
                $errorOutput = $rawOutput->getErrorOutput();
            }
        }

        if ($errorOutput !== null) {
            $errorOutput->write($messageWithNewline);
            return;
        }

        if (defined('STDERR')) {
            fwrite(STDERR, $messageWithNewline);
            return;
        }

        $this->outputLine($message);
    }
}
