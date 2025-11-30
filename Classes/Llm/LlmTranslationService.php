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
use Neos\Flow\Log\Utility\LogEnvironment;
use PhpLlm\LlmChain\Platform\Message\Message;
use PhpLlm\LlmChain\Platform\Message\MessageBag;
use Psr\Log\LoggerInterface;
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;
use Two13Tec\L10nGuy\Domain\Dto\TranslationContext;
use Two13Tec\L10nGuy\Llm\Exception\LlmConfigurationException;
use Two13Tec\L10nGuy\Llm\Exception\LlmUnavailableException;

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
    protected LoggerInterface $logger;

    /**
     * @param list<CatalogMutation> $mutations
     * @return list<CatalogMutation>
     *
     * @throws LlmUnavailableException
     * @throws LlmConfigurationException
     */
    public function translate(array $mutations, ScanResult $scanResult, LlmConfiguration $config): array
    {
        if ($mutations === [] || !$config->enabled) {
            return $mutations;
        }

        $grouped = $this->groupMutationsByIdentifier($mutations, $scanResult->missingTranslations);
        if ($grouped === []) {
            return $mutations;
        }

        if ($config->dryRun) {
            $this->logDryRun($grouped, $config);
            return $mutations;
        }

        $chain = $this->providerFactory->create();
        $systemPrompt = $this->promptBuilder->buildSystemPrompt($config);
        $batchSize = $this->normalizeBatchSize($config);

        foreach (array_chunk($grouped, $batchSize) as $batch) {
            $contexts = $this->buildContexts($batch, $scanResult, $config);
            $messages = $this->buildMessages($contexts, $systemPrompt);

            try {
                $response = $chain->call($messages);
                $parsed = $this->responseParser->parse($response->getContent());
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    'LLM translation failed',
                    array_merge(
                        [
                            'error' => $exception->getMessage(),
                        ],
                        LogEnvironment::fromMethodName(__METHOD__)
                    )
                );
                continue;
            }

            $this->applyTranslations($batch, $parsed);

            if ($config->rateLimitDelay > 0) {
                usleep($config->rateLimitDelay * 1000);
            }
        }

        return $mutations;
    }

    /**
     * @param list<array{mutations: list<CatalogMutation>, missing: MissingTranslation}> $batch
     * @param array<string, array<string, string>> $parsed
     */
    private function applyTranslations(array $batch, array $parsed): void
    {
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
                if (isset($translations[$mutation->locale])) {
                    $mutation->target = $translations[$mutation->locale];
                }
            }
        }
    }

    /**
     * @param list<array{missing: MissingTranslation, context: TranslationContext, targetLanguages: list<string>}> $items
     */
    private function buildMessages(array $items, string $systemPrompt): MessageBag
    {
        $userPrompt = count($items) === 1
            ? $this->promptBuilder->buildUserPrompt(
                $items[0]['missing'],
                $items[0]['context'],
                $items[0]['targetLanguages'],
                $this->translationId($items[0]['missing'])
            )
            : $this->promptBuilder->buildBatchPrompt($this->mapItemsForPrompt($items));

        return new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser($userPrompt)
        );
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

    private function normalizeBatchSize(LlmConfiguration $config): int
    {
        $batchSize = max(1, $config->batchSize);
        return min($batchSize, max(1, $config->maxBatchSize));
    }

    /**
     * @param list<array{mutations: list<CatalogMutation>, missing: MissingTranslation}> $groups
     */
    private function logDryRun(array $groups, LlmConfiguration $config): void
    {
        $translationCount = array_sum(
            array_map(static fn (array $group): int => count($group['mutations']), $groups)
        );

        $this->logger->info(
            sprintf(
                'LLM dry run: %d translation ids, %d total translations, batch size %d (max %d).',
                count($groups),
                $translationCount,
                $config->batchSize,
                $config->maxBatchSize
            ),
            LogEnvironment::fromMethodName(__METHOD__)
        );
    }
}
