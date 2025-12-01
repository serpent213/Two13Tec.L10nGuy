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
You are a professional translator for a Neos CMS website.

Content types you will encounter:
- UI labels (buttons, menus, headings): keep concise
- Form fields and validation messages: be clear and helpful
- Help text and descriptions: use natural, friendly language
- Error messages: be specific and actionable

Rules:
- Preserve ALL placeholders exactly as they appear: {0}, {1}, {name}, %s, %d, etc.
- Never translate brand names, product names, or technical identifiers
- Use consistent terminology throughout (check existing translations for reference)
- Match the formality level of the source text
- Produce natural, fluent translations - avoid overly literal phrasing
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
     * Build prompt for single-locale batch translation with cross-reference context.
     *
     * @param list<array{translationId: string, sourceText: string, crossReference: array<string, string>, sourceSnippet: ?string, nodeTypeContext: ?string}> $items
     */
    public function buildSingleLocalePrompt(array $items, string $targetLocale): string
    {
        $localeName = $this->localeDisplayName($targetLocale);
        $parts = [];

        $parts[] = sprintf('Translate these items to %s (%s).', $localeName, $targetLocale);
        $parts[] = '';
        $parts[] = 'For each item, existing translations in other languages are provided as context to help you maintain consistency.';
        $parts[] = '';
        $parts[] = 'Respond ONLY with valid JSON in this exact format:';
        $parts[] = '```json';
        $parts[] = '{';
        $parts[] = '  "translations": [';
        $parts[] = '    { "id": "package:source:identifier", "translation": "your translation here" }';
        $parts[] = '  ]';
        $parts[] = '}';
        $parts[] = '```';

        foreach ($items as $index => $item) {
            $parts[] = '';
            $parts[] = sprintf('### Item %d', $index + 1);
            $parts[] = sprintf('ID: "%s"', $item['translationId']);
            $parts[] = sprintf('Source text: "%s"', $item['sourceText']);

            if ($item['crossReference'] !== []) {
                $parts[] = 'Existing translations in other languages:';
                foreach ($item['crossReference'] as $locale => $translation) {
                    $parts[] = sprintf('  - %s: "%s"', $locale, $translation);
                }
            }

            if ($item['sourceSnippet'] !== null && $item['sourceSnippet'] !== '') {
                $parts[] = '';
                $parts[] = 'Context (surrounding code):';
                $parts[] = '```';
                $parts[] = $item['sourceSnippet'];
                $parts[] = '```';
            }

            if ($item['nodeTypeContext'] !== null && $item['nodeTypeContext'] !== '') {
                $parts[] = '';
                $parts[] = 'NodeType definition:';
                $parts[] = '```yaml';
                $parts[] = $item['nodeTypeContext'];
                $parts[] = '```';
            }
        }

        return implode("\n", $parts);
    }

    private function localeDisplayName(string $locale): string
    {
        $map = [
            'de' => 'German',
            'de_AT' => 'Austrian German',
            'de_CH' => 'Swiss German',
            'en' => 'English',
            'en_GB' => 'British English',
            'en_US' => 'American English',
            'fr' => 'French',
            'fr_CA' => 'Canadian French',
            'fr_CH' => 'Swiss French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'nl' => 'Dutch',
            'pt' => 'Portuguese',
            'pt_BR' => 'Brazilian Portuguese',
            'pl' => 'Polish',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'zh' => 'Chinese',
            'ko' => 'Korean',
        ];

        return $map[$locale] ?? $locale;
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
