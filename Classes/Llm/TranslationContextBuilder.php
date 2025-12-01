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
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\TranslationContext;
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;

/**
 * Builds contextual data to feed into the LLM prompt.
 */
#[Flow\Scope('singleton')]
final class TranslationContextBuilder
{
    public function __construct(
        private readonly SourceContextExtractor $sourceContextExtractor
    ) {
    }

    public function build(
        MissingTranslation $missing,
        CatalogIndex $catalogIndex,
        LlmConfiguration $config
    ): TranslationContext {
        $sourceSnippet = $this->sourceContextExtractor->extract(
            $missing->reference,
            $config->contextWindowLines
        );

        $nodeTypeContext = $this->buildNodeTypeContext($missing->reference, $config);

        $existingTranslations = $config->includeExistingTranslations
            ? $this->gatherExistingTranslations($catalogIndex, $missing->key->packageKey, $missing->key->sourceName)
            : [];

        return new TranslationContext(
            sourceSnippet: $sourceSnippet,
            nodeTypeContext: $nodeTypeContext,
            existingTranslations: $existingTranslations
        );
    }

    /**
     * Gather translations of a specific identifier in OTHER locales (for cross-reference).
     *
     * @return array<string, string> locale => translation
     */
    public function gatherCrossReferenceTranslations(
        CatalogIndex $catalogIndex,
        string $packageKey,
        string $sourceName,
        string $identifier,
        string $targetLocale,
        int $maxLocales
    ): array {
        $translations = [];

        foreach ($catalogIndex->entries() as $locale => $packages) {
            if ($locale === $targetLocale) {
                continue;
            }

            $entry = $packages[$packageKey][$sourceName][$identifier] ?? null;
            if ($entry === null) {
                continue;
            }

            $target = $entry->target ?? $entry->source;
            if ($target !== null && $target !== '') {
                $translations[$locale] = $target;
            }

            if (count($translations) >= $maxLocales) {
                break;
            }
        }

        ksort($translations, SORT_NATURAL | SORT_FLAG_CASE);

        return $translations;
    }

    private function buildNodeTypeContext(TranslationReference $reference, LlmConfiguration $config): ?string
    {
        if (!$config->includeNodeTypeContext || $reference->context !== TranslationReference::CONTEXT_YAML) {
            return null;
        }

        if ($reference->nodeTypeContext !== null && $reference->nodeTypeContext !== '') {
            return $reference->nodeTypeContext;
        }

        if (!is_file($reference->filePath)) {
            return null;
        }

        $content = @file_get_contents($reference->filePath);

        return $content === false ? null : $content;
    }

    /**
     * @return list<array{id: string, source: ?string, translations: array<string, string>}>
     */
    private function gatherExistingTranslations(
        CatalogIndex $catalogIndex,
        string $packageKey,
        string $sourceName
    ): array {
        $byIdentifier = [];

        foreach ($catalogIndex->entries() as $locale => $packages) {
            foreach ($packages[$packageKey][$sourceName] ?? [] as $identifier => $entry) {
                $byIdentifier[$identifier] ??= [
                    'id' => $identifier,
                    'source' => $entry->source,
                    'translations' => [],
                ];

                if ($entry->target !== null && $entry->target !== '') {
                    $byIdentifier[$identifier]['translations'][$locale] = $entry->target;
                }
            }
        }

        ksort($byIdentifier, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(
            static function (array $item): array {
                ksort($item['translations'], SORT_NATURAL | SORT_FLAG_CASE);
                return $item;
            },
            array_values($byIdentifier)
        );
    }
}
