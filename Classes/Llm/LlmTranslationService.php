<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Llm;

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
use Neos\Flow\Log\PsrLoggerFactoryInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use PhpLlm\LlmChain\Platform\Message\Message;
use PhpLlm\LlmChain\Platform\Message\MessageBag;
use Psr\Log\LoggerInterface;
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\LlmRunStatistics;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;
use Two13Tec\L10nGuy\Domain\Dto\TokenEstimation;
use Two13Tec\L10nGuy\Domain\Dto\TranslationContext;
use Two13Tec\L10nGuy\Llm\Exception\LlmConfigurationException;
use Two13Tec\L10nGuy\Llm\Exception\LlmUnavailableException;
use Two13Tec\L10nGuy\Utility\ProgressIndicator;

/**
 * Enriches catalog mutations with LLM-generated translations.
 */
#[Flow\Scope('singleton')]
final class LlmTranslationService
{
    #[Flow\Inject]
    protected LlmProviderFactory $providerFactory;

    #[Flow\Inject]
    protected TranslationContextBuilder $contextBuilder;

    #[Flow\Inject]
    protected PromptBuilder $promptBuilder;

    #[Flow\Inject]
    protected ResponseParser $responseParser;

    #[Flow\Inject]
    protected PlaceholderValidator $placeholderValidator;

    #[Flow\Inject]
    protected TokenEstimator $tokenEstimator;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    #[Flow\Inject]
    protected PsrLoggerFactoryInterface $loggerFactory;

    private ?LoggerInterface $llmLogger = null;

    /**
     * @param list<CatalogMutation> $mutations
     * @return list<CatalogMutation>
     *
     * @throws LlmUnavailableException
     * @throws LlmConfigurationException
     */
    public function translate(
        array $mutations,
        ScanResult $scanResult,
        LlmConfiguration $config,
        ?ProgressIndicator $progressIndicator = null,
        ?LlmRunStatistics $runStatistics = null
    ): array {
        if ($mutations === [] || !$config->enabled) {
            return $mutations;
        }

        $groupedBySourceAndLocale = $this->groupBySourceAndLocale($mutations, $scanResult->missingTranslations);
        if ($groupedBySourceAndLocale === []) {
            return $mutations;
        }

        $systemPrompt = $this->promptBuilder->buildSystemPrompt($config);

        if ($config->dryRun) {
            $this->logDryRunSingleLocale($groupedBySourceAndLocale, $scanResult, $config, $systemPrompt);
            return $mutations;
        }

        $chain = $this->providerFactory->create();
        $batchSize = $config->batchSize;
        $plannedApiCalls = $this->countPlannedApiCalls($groupedBySourceAndLocale, $batchSize);
        $completedApiCalls = 0;
        $systemTokens = $this->tokenEstimator->estimateTokens($systemPrompt);

        foreach ($groupedBySourceAndLocale as $sourceKey => $localeGroups) {
            foreach ($localeGroups as $targetLocale => $groups) {
                foreach ($this->balancedChunk($groups, $batchSize) as $batch) {
                    $promptItems = $this->buildSingleLocalePromptItems($batch, $targetLocale, $scanResult, $config);
                    $userPrompt = $this->promptBuilder->buildSingleLocalePrompt($promptItems, $targetLocale);
                    $inputTokens = $systemTokens + $this->tokenEstimator->estimateTokens($userPrompt);
                    $outputTokens = $this->tokenEstimator->estimateOutputTokens(count($batch));

                    $messages = new MessageBag(
                        Message::forSystem($systemPrompt),
                        Message::ofUser($userPrompt)
                    );

                    $this->logDebugRequest($config, $sourceKey, $targetLocale, $systemPrompt, $userPrompt);

                    try {
                        $response = $chain->call($messages);
                        $responseContent = $response->getContent();
                        $parsed = $this->responseParser->parse($responseContent);

                        $this->logDebugResponse($config, $sourceKey, $targetLocale, $responseContent, $parsed);
                    } catch (\Throwable $exception) {
                        $this->logLlmError($sourceKey, $targetLocale, $userPrompt, $exception, $config);
                        continue;
                    }

                    $this->applySingleLocaleTranslations($batch, $parsed, $targetLocale, $config);

                    if ($runStatistics !== null) {
                        $runStatistics->registerCall($inputTokens, $outputTokens);
                    }

                    if ($config->rateLimitDelay > 0) {
                        usleep($config->rateLimitDelay * 1000);
                    }

                    $completedApiCalls++;
                    if ($progressIndicator !== null && $plannedApiCalls > 0) {
                        $progressIndicator->tick($completedApiCalls, $plannedApiCalls);
                    }
                }
            }
        }

        if ($progressIndicator !== null && $plannedApiCalls > 0) {
            $progressIndicator->finish();
        }

        return $mutations;
    }

    /**
     * Chunk items into balanced groups.
     *
     * Instead of 11+1, produces 6+6 for 12 items with max 11.
     *
     * @template T
     * @param list<T> $items
     * @return list<list<T>>
     */
    private function balancedChunk(array $items, int $maxPerChunk): array
    {
        $count = count($items);
        if ($count === 0) {
            return [];
        }

        if ($count <= $maxPerChunk) {
            return [$items];
        }

        $numChunks = (int)ceil($count / $maxPerChunk);
        $chunkSize = (int)ceil($count / $numChunks);

        return array_chunk($items, $chunkSize);
    }

    /**
     * Group mutations by (package:source) and then by locale.
     *
     * @param list<CatalogMutation> $mutations
     * @param list<MissingTranslation> $missingTranslations
     * @return array<string, array<string, list<array{mutation: CatalogMutation, missing: MissingTranslation}>>>
     */
    private function groupBySourceAndLocale(array $mutations, array $missingTranslations): array
    {
        $missingByKeyAndLocale = [];
        foreach ($missingTranslations as $missing) {
            $key = $this->translationId($missing);
            $missingByKeyAndLocale[$key][$missing->locale] = $missing;
        }

        $grouped = [];
        foreach ($mutations as $mutation) {
            $translationKey = $this->translationIdFromParts($mutation->packageKey, $mutation->sourceName, $mutation->identifier);
            $missing = $missingByKeyAndLocale[$translationKey][$mutation->locale] ?? null;
            if ($missing === null) {
                continue;
            }

            $sourceKey = sprintf('%s:%s', $mutation->packageKey, $mutation->sourceName);
            $grouped[$sourceKey][$mutation->locale][] = [
                'mutation' => $mutation,
                'missing' => $missing,
            ];
        }

        return $grouped;
    }

    /**
     * @param array<string, array<string, list<array{mutation: CatalogMutation, missing: MissingTranslation}>>> $groupedBySourceAndLocale
     */
    private function countPlannedApiCalls(array $groupedBySourceAndLocale, int $batchSize): int
    {
        $planned = 0;

        foreach ($groupedBySourceAndLocale as $localeGroups) {
            foreach ($localeGroups as $groups) {
                $planned += count($this->balancedChunk($groups, $batchSize));
            }
        }

        return $planned;
    }

    /**
     * Build prompt items for single-locale batch translation.
     *
     * @param list<array{mutation: CatalogMutation, missing: MissingTranslation}> $batch
     * @return list<array{translationId: string, sourceText: string, crossReference: array<string, string>, sourceSnippet: ?string, nodeTypeContext: ?string}>
     */
    private function buildSingleLocalePromptItems(
        array $batch,
        string $targetLocale,
        ScanResult $scanResult,
        LlmConfiguration $config
    ): array {
        $items = [];
        foreach ($batch as $entry) {
            $missing = $entry['missing'];
            $mutation = $entry['mutation'];

            $sourceText = $mutation->fallback !== '' ? $mutation->fallback : $missing->key->identifier;

            $crossReference = $this->contextBuilder->gatherCrossReferenceTranslations(
                $scanResult->catalogIndex,
                $missing->key->packageKey,
                $missing->key->sourceName,
                $missing->key->identifier,
                $targetLocale,
                $config->maxCrossReferenceLocales
            );

            $context = $this->contextBuilder->build($missing, $scanResult->catalogIndex, $config);

            $items[] = [
                'translationId' => $this->translationId($missing),
                'sourceText' => $sourceText,
                'crossReference' => $crossReference,
                'sourceSnippet' => $context->sourceSnippet,
                'nodeTypeContext' => $context->nodeTypeContext,
            ];
        }

        return $items;
    }

    /**
     * Apply translations from single-locale response.
     *
     * @param list<array{mutation: CatalogMutation, missing: MissingTranslation}> $batch
     * @param array<string, array<string, string>> $parsed
     */
    private function applySingleLocaleTranslations(
        array $batch,
        array $parsed,
        string $targetLocale,
        LlmConfiguration $config
    ): void {
        $generatedAt = $config->markAsGenerated ? new \DateTimeImmutable() : null;

        foreach ($batch as $entry) {
            $mutation = $entry['mutation'];
            $missing = $entry['missing'];
            $translationId = $this->translationId($missing);

            $localeTranslations = $parsed[$translationId]
                ?? $parsed[$missing->key->identifier]
                ?? $parsed[ResponseParser::SINGLE_ENTRY_KEY]
                ?? null;

            if ($localeTranslations === null) {
                $this->logger->warning(
                    'LLM response missing translation entry',
                    array_merge(
                        [
                            'translationId' => $translationId,
                            'identifier' => $missing->key->identifier,
                            'locale' => $targetLocale,
                        ],
                        LogEnvironment::fromMethodName(__METHOD__)
                    )
                );
                continue;
            }

            // Handle single-locale format (SINGLE_LOCALE_KEY) or multi-locale format
            $translation = $localeTranslations[ResponseParser::SINGLE_LOCALE_KEY]
                ?? $localeTranslations[$targetLocale]
                ?? null;

            if ($translation === null) {
                continue;
            }

            $expectedPlaceholders = $this->expectedPlaceholders($mutation);
            if ($expectedPlaceholders !== [] && !$this->placeholderValidator->validate(
                $mutation->identifier,
                $mutation->locale,
                $translation,
                $expectedPlaceholders
            )) {
                continue;
            }

            $mutation->target = $translation;

            if (!$config->markAsGenerated) {
                continue;
            }

            $mutation->isLlmGenerated = true;
            $mutation->llmProvider = $config->provider ?? null;
            $mutation->llmModel = $config->model ?? null;
            $mutation->llmGeneratedAt = $generatedAt;
        }
    }

    /**
     * Log dry-run estimation for single-locale batching.
     *
     * @param array<string, array<string, list<array{mutation: CatalogMutation, missing: MissingTranslation}>>> $groupedBySourceAndLocale
     */
    private function logDryRunSingleLocale(
        array $groupedBySourceAndLocale,
        ScanResult $scanResult,
        LlmConfiguration $config,
        string $systemPrompt
    ): void {
        $batchSize = $config->batchSize;
        $calls = [];
        $totalTranslations = 0;
        $uniqueIds = [];

        foreach ($groupedBySourceAndLocale as $sourceKey => $localeGroups) {
            foreach ($localeGroups as $targetLocale => $groups) {
                foreach ($this->balancedChunk($groups, $batchSize) as $batch) {
                    $promptItems = $this->buildSingleLocalePromptItems($batch, $targetLocale, $scanResult, $config);
                    $userPrompt = $this->promptBuilder->buildSingleLocalePrompt($promptItems, $targetLocale);

                    $calls[] = [
                        'userPrompt' => $userPrompt,
                        'translations' => count($batch),
                    ];

                    $totalTranslations += count($batch);
                    foreach ($batch as $entry) {
                        $uniqueIds[$this->translationId($entry['missing'])] = true;
                    }
                }
            }
        }

        $estimation = $this->tokenEstimator->estimate($calls, count($uniqueIds), $systemPrompt);

        $this->outputDryRunReport($estimation, $config, $batchSize);

        $this->logger->info(
            'LLM dry-run estimation completed.',
            array_merge(
                [
                    'uniqueTranslationIds' => count($uniqueIds),
                    'translations' => $totalTranslations,
                    'inputTokens' => $estimation->estimatedInputTokens,
                    'outputTokens' => $estimation->estimatedOutputTokens,
                    'peakTokensPerCall' => $estimation->peakTokensPerCall,
                    'batchSize' => $batchSize,
                    'maxTokensPerCall' => $config->maxTokensPerCall,
                ],
                LogEnvironment::fromMethodName(__METHOD__)
            )
        );
    }

    /**
     * @param list<array{mutations: list<CatalogMutation>, missing: MissingTranslation}> $batch
     * @param array<string, array<string, string>> $parsed
     */
    private function applyTranslations(array $batch, array $parsed, LlmConfiguration $config): void
    {
        $generatedAt = $config->markAsGenerated ? new \DateTimeImmutable() : null;

        foreach ($batch as $group) {
            $translationId = $this->translationId($group['missing']);
            $translations = $parsed[$translationId]
                ?? $parsed[$group['missing']->key->identifier]
                ?? $parsed[ResponseParser::SINGLE_ENTRY_KEY]
                ?? null;

            if ($translations === null) {
                $this->logger->warning(
                    'LLM response missing translation entry',
                    array_merge(
                        [
                            'translationId' => $translationId,
                            'identifier' => $group['missing']->key->identifier,
                        ],
                        LogEnvironment::fromMethodName(__METHOD__)
                    )
                );
                continue;
            }

            foreach ($group['mutations'] as $mutation) {
                if (!isset($translations[$mutation->locale])) {
                    continue;
                }

                $translation = $translations[$mutation->locale];
                $expectedPlaceholders = $this->expectedPlaceholders($mutation);
                if ($expectedPlaceholders !== [] && !$this->placeholderValidator->validate(
                    $mutation->identifier,
                    $mutation->locale,
                    $translation,
                    $expectedPlaceholders
                )) {
                    continue;
                }

                $mutation->target = $translation;

                if (!$config->markAsGenerated) {
                    continue;
                }

                $mutation->isLlmGenerated = true;
                $mutation->llmProvider = $config->provider ?? null;
                $mutation->llmModel = $config->model ?? null;
                $mutation->llmGeneratedAt = $generatedAt;
            }
        }
    }

    /**
     * @param list<array{missing: MissingTranslation, context: TranslationContext, targetLanguages: list<string>}> $items
     * @return array{messages: MessageBag, userPrompt: string}
     */
    private function buildMessages(array $items, string $systemPrompt): array
    {
        $userPrompt = count($items) === 1
            ? $this->promptBuilder->buildUserPrompt(
                $items[0]['missing'],
                $items[0]['context'],
                $items[0]['targetLanguages'],
                $this->translationId($items[0]['missing'])
            )
            : $this->promptBuilder->buildBatchPrompt($this->mapItemsForPrompt($items));

        return [
            'messages' => new MessageBag(
                Message::forSystem($systemPrompt),
                Message::ofUser($userPrompt)
            ),
            'userPrompt' => $userPrompt,
        ];
    }

    /**
     * @param list<array{missing: MissingTranslation, context: TranslationContext, targetLanguages: list<string>}> $items
     * @return list<array{missing: MissingTranslation, context: TranslationContext, targetLanguages: list<string>, translationId: string}>
     */
    private function mapItemsForPrompt(array $items): array
    {
        return array_map(
            fn (array $item): array => array_merge(
                $item,
                ['translationId' => $this->translationId($item['missing'])]
            ),
            $items
        );
    }

    /**
     * @param list<array{mutations: list<CatalogMutation>, missing: MissingTranslation}> $batch
     * @return list<array{missing: MissingTranslation, context: TranslationContext, targetLanguages: list<string>}>
     */
    private function buildContexts(array $batch, ScanResult $scanResult, LlmConfiguration $config): array
    {
        $contexts = [];
        foreach ($batch as $group) {
            $targetLanguages = $this->collectLocales($group['mutations']);
            $contexts[] = [
                'missing' => $group['missing'],
                'context' => $this->contextBuilder->build(
                    $group['missing'],
                    $scanResult->catalogIndex,
                    $config
                ),
                'targetLanguages' => $targetLanguages,
            ];
        }

        return $contexts;
    }

    /**
     * @param list<CatalogMutation> $mutations
     * @param list<MissingTranslation> $missingTranslations
     * @return list<array{mutations: list<CatalogMutation>, missing: MissingTranslation}>
     */
    private function groupMutationsByIdentifier(array $mutations, array $missingTranslations): array
    {
        $missingByKey = [];
        foreach ($missingTranslations as $missing) {
            $missingByKey[$this->translationId($missing)] = $missing;
        }

        $grouped = [];
        foreach ($mutations as $mutation) {
            $groupKey = $this->translationIdFromParts($mutation->packageKey, $mutation->sourceName, $mutation->identifier);
            $missing = $missingByKey[$groupKey] ?? null;
            if ($missing === null) {
                continue;
            }

            $grouped[$groupKey]['mutations'] ??= [];
            $grouped[$groupKey]['mutations'][] = $mutation;
            $grouped[$groupKey]['missing'] = $missing;
        }

        return array_values($grouped);
    }

    /**
     * @param list<CatalogMutation> $mutations
     * @return list<string>
     */
    private function collectLocales(array $mutations): array
    {
        $locales = array_map(static fn (CatalogMutation $mutation): string => $mutation->locale, $mutations);
        $locales = array_values(array_unique($locales));
        sort($locales, SORT_NATURAL | SORT_FLAG_CASE);

        return $locales;
    }

    /**
     * @return list<string>
     */
    private function expectedPlaceholders(CatalogMutation $mutation): array
    {
        $placeholders = array_keys($mutation->placeholders);
        if ($placeholders === [] && $mutation->fallback !== '') {
            $placeholders = $this->extractPlaceholders($mutation->fallback);
        }

        $placeholders = array_values(array_unique($placeholders));
        sort($placeholders, SORT_NATURAL | SORT_FLAG_CASE);

        return $placeholders;
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(string $value): array
    {
        preg_match_all('/\{([A-Za-z0-9_.:-]+)\}/', $value, $matches);
        $placeholders = array_filter(
            $matches[1] ?? [],
            static fn (string $placeholder): bool => $placeholder !== ''
        );

        return array_values(array_unique($placeholders));
    }

    private function translationId(MissingTranslation $missing): string
    {
        return $this->translationIdFromParts(
            $missing->key->packageKey,
            $missing->key->sourceName,
            $missing->key->identifier
        );
    }

    private function translationIdFromParts(string $packageKey, string $sourceName, string $identifier): string
    {
        return sprintf('%s:%s:%s', $packageKey, $sourceName, $identifier);
    }


    /**
     * @param list<array{mutations: list<CatalogMutation>, missing: MissingTranslation}> $groups
     */
    private function logDryRun(array $groups, ScanResult $scanResult, LlmConfiguration $config, string $systemPrompt): void
    {
        $batchSize = $config->batchSize;
        $calls = [];

        foreach (array_chunk($groups, $batchSize) as $batch) {
            $contexts = $this->buildContexts($batch, $scanResult, $config);
            $messages = $this->buildMessages($contexts, $systemPrompt);
            $translationsInBatch = $this->countTranslations($batch);

            $calls[] = [
                'userPrompt' => $messages['userPrompt'],
                'translations' => $translationsInBatch,
            ];
        }

        $estimation = $this->tokenEstimator->estimate($calls, count($groups), $systemPrompt);

        $this->outputDryRunReport($estimation, $config, $batchSize);

        $this->logger->info(
            'LLM dry-run estimation completed.',
            array_merge(
                [
                    'uniqueTranslationIds' => $estimation->uniqueTranslationIds,
                    'translations' => $estimation->translationCount,
                    'inputTokens' => $estimation->estimatedInputTokens,
                    'outputTokens' => $estimation->estimatedOutputTokens,
                    'peakTokensPerCall' => $estimation->peakTokensPerCall,
                    'batchSize' => $batchSize,
                    'maxTokensPerCall' => $config->maxTokensPerCall,
                ],
                LogEnvironment::fromMethodName(__METHOD__)
            )
        );
    }

    /**
     * @param list<array{mutations: list<CatalogMutation>, missing: MissingTranslation}> $batch
     */
    private function countTranslations(array $batch): int
    {
        return array_sum(
            array_map(static fn (array $group): int => count($group['mutations']), $batch)
        );
    }

    private function outputDryRunReport(TokenEstimation $estimation, LlmConfiguration $config, int $batchSize): void
    {
        $lines = [
            '',
            '→ Analysing translation workload (LLM dry-run)...',
            '',
            sprintf('  Unique translation IDs:      %d', $estimation->uniqueTranslationIds),
            sprintf('  Total translations:          %d', $estimation->translationCount),
            sprintf('  Estimated input tokens:      ~%s', number_format($estimation->estimatedInputTokens)),
            sprintf('  Estimated output tokens:     ~%s', number_format($estimation->estimatedOutputTokens)),
            sprintf(
                '  Batch configuration:         %d ID%s per call = %d API call%s',
                $batchSize,
                $batchSize === 1 ? '' : 's',
                $estimation->apiCallCount,
                $estimation->apiCallCount === 1 ? '' : 's'
            ),
        ];

        if ($config->maxTokensPerCall > 0) {
            $lines[] = sprintf(
                '  Configured max tokens/call:  %s',
                number_format($config->maxTokensPerCall)
            );
            $lines[] = sprintf(
                '  Peak estimated tokens/call:  ~%s',
                number_format($estimation->peakTokensPerCall)
            );

            if ($estimation->exceedsLimit($config->maxTokensPerCall)) {
                $lines[] = '  WARNING: estimated tokens per call exceed the configured limit.';
            }
        }

        $lines[] = '';

        echo implode(PHP_EOL, $lines);
    }

    private function getLlmLogger(): LoggerInterface
    {
        if ($this->llmLogger === null) {
            $this->llmLogger = $this->loggerFactory->get('l10nGuyLlmLogger');
        }

        return $this->llmLogger;
    }

    private function logDebugRequest(
        LlmConfiguration $config,
        string $sourceKey,
        string $targetLocale,
        string $systemPrompt,
        string $userPrompt
    ): void {
        if (!$config->debug) {
            return;
        }

        $this->getLlmLogger()->debug(
            'LLM request',
            [
                'source' => $sourceKey,
                'locale' => $targetLocale,
                'provider' => $config->provider,
                'model' => $config->model,
                'systemPrompt' => $systemPrompt,
                'userPrompt' => $userPrompt,
            ]
        );
    }

    /**
     * @param array<string, array<string, string>> $parsed
     */
    private function logDebugResponse(
        LlmConfiguration $config,
        string $sourceKey,
        string $targetLocale,
        string $responseContent,
        array $parsed
    ): void {
        if (!$config->debug) {
            return;
        }

        $this->getLlmLogger()->debug(
            'LLM response',
            [
                'source' => $sourceKey,
                'locale' => $targetLocale,
                'rawResponse' => $responseContent,
                'parsedTranslations' => $parsed,
            ]
        );
    }

    private function logLlmError(
        string $sourceKey,
        string $targetLocale,
        string $userPrompt,
        \Throwable $exception,
        LlmConfiguration $config
    ): void {
        $context = [
            'error' => $exception->getMessage(),
            'source' => $sourceKey,
            'locale' => $targetLocale,
            'exceptionClass' => get_class($exception),
        ];

        if ($config->debug) {
            $context['userPrompt'] = $userPrompt;
            $context['trace'] = $exception->getTraceAsString();
        }

        $this->getLlmLogger()->error('LLM translation failed', $context);

        $this->logger->warning(
            'LLM translation failed',
            array_merge(
                [
                    'error' => $exception->getMessage(),
                    'source' => $sourceKey,
                    'locale' => $targetLocale,
                ],
                LogEnvironment::fromMethodName(__METHOD__)
            )
        );
    }
}
