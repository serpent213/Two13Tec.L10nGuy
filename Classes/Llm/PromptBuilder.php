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
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\TranslationContext;

/**
 * Builds system and user prompts for LLM translation calls.
 */
#[Flow\Scope('singleton')]
final class PromptBuilder
{
    private const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
You are a professional translator for a web application built with Neos CMS.

Guidelines:
- Preserve all placeholders exactly: {0}, {name}, %s, etc.
- Maintain consistent terminology with existing translations provided
- Match tone and formality level of the application
- For UI labels: be concise
- For help text: be clear and helpful
- Never translate brand names or technical identifiers
PROMPT;

    public function buildSystemPrompt(LlmConfiguration $config): string
    {
        $prompt = $config->systemPrompt;
        if ($prompt === '') {
            $prompt = self::DEFAULT_SYSTEM_PROMPT;
        }

        return $prompt;
    }

    /**
     * @param list<string> $targetLanguages
     */
    public function buildUserPrompt(
        MissingTranslation $missing,
        TranslationContext $context,
        array $targetLanguages,
        string $translationId
    ): string {
        return $this->renderSection($missing, $context, $targetLanguages, $translationId, true);
    }

    /**
     * @param list<array{missing: MissingTranslation, context: TranslationContext, targetLanguages: list<string>, translationId: string}> $items
     */
    public function buildBatchPrompt(array $items): string
    {
        $parts = [];
        $parts[] = 'Translate each item below. Respond ONLY with valid JSON in this exact format:';
        $parts[] = '```json';
        $parts[] = '{';
        $parts[] = '  "translations": [';
        $parts[] = '    {';
        $parts[] = '      "id": "package:source:identifier",';
        $parts[] = '      "translations": {';
        $parts[] = '        "de": "translation for de",';
        $parts[] = '        "fr": "translation for fr"';
        $parts[] = '      }';
        $parts[] = '    }';
        $parts[] = '  ]';
        $parts[] = '}';
        $parts[] = '```';

        foreach ($items as $index => $item) {
            if ($index > 0) {
                $parts[] = '';
            }
            $parts[] = sprintf('### Item %d', $index + 1);
            $parts[] = $this->renderSection(
                $item['missing'],
                $item['context'],
                $item['targetLanguages'],
                $item['translationId'],
                false
            );
        }

        return implode("\n", $parts);
    }

    /**
     * @param list<string> $targetLanguages
     */
    private function renderSection(
        MissingTranslation $missing,
        TranslationContext $context,
        array $targetLanguages,
        string $translationId,
        bool $includeResponseTemplate
    ): string {
        $sourceText = $missing->reference->fallback ?? $missing->key->identifier;
        $parts = [];
        $parts[] = sprintf('Translation ID: "%s"', $translationId);
        $parts[] = sprintf('Translate to: %s.', implode(', ', $targetLanguages));
        $parts[] = sprintf('Source text: "%s"', $sourceText);

        if ($context->sourceSnippet !== null && $context->sourceSnippet !== '') {
            $parts[] = '';
            $parts[] = 'Context (surrounding code):';
            $parts[] = '```';
            $parts[] = $context->sourceSnippet;
            $parts[] = '```';
        }

        if ($context->nodeTypeContext !== null && $context->nodeTypeContext !== '') {
            $parts[] = '';
            $parts[] = 'NodeType definition:';
            $parts[] = '```yaml';
            $parts[] = $context->nodeTypeContext;
            $parts[] = '```';
        }

        if ($context->existingTranslations !== []) {
            $parts[] = '';
            $parts[] = 'Existing translations in this source (for terminology consistency):';
            $parts[] = '```json';
            $parts[] = json_encode(
                $context->existingTranslations,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ) ?: '[]';
            $parts[] = '```';
        }

        if ($includeResponseTemplate) {
            $parts[] = '';
            $parts[] = 'Respond ONLY with valid JSON in this exact format:';
            $parts[] = '```json';
            $parts[] = '{';
            $parts[] = sprintf('  "id": "%s",', $translationId);
            $parts[] = '  "translations": {';
            foreach ($targetLanguages as $index => $locale) {
                $comma = $index < count($targetLanguages) - 1 ? ',' : '';
                $parts[] = sprintf('    "%s": "translation for %s"%s', $locale, $locale, $comma);
            }
            $parts[] = '  }';
            $parts[] = '}';
            $parts[] = '```';
        }

        return implode("\n", $parts);
    }
}
