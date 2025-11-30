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

/**
 * Extracts translation payloads from LLM responses.
 */
#[Flow\Scope('singleton')]
final class ResponseParser
{
    public const SINGLE_ENTRY_KEY = '__single__';

    /**
     * @return array<string, array<string, string>> keyed by translation id
     */
    public function parse(string $response): array
    {
        $jsonPayload = $this->extractJson($response);
        if ($jsonPayload === null) {
            return [];
        }

        $data = json_decode($jsonPayload, true);
        if (!is_array($data)) {
            return [];
        }

        $translations = $data['translations'] ?? $data;
        if (!is_array($translations)) {
            return [];
        }

        return $this->normalizeTranslations($translations);
    }

    /**
     * @param array<mixed> $translations
     * @return array<string, array<string, string>>
     */
    private function normalizeTranslations(array $translations): array
    {
        if ($this->isLocaleMap($translations)) {
            $normalized = $this->filterLocaleMap($translations);
            return $normalized === [] ? [] : [self::SINGLE_ENTRY_KEY => $normalized];
        }

        $result = [];

        foreach ($translations as $key => $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $id = is_string($key) ? $key : (is_string($payload['id'] ?? null) ? $payload['id'] : null);
            $localeMap = $payload['translations'] ?? $payload;
            if (!$this->isLocaleMap($localeMap)) {
                continue;
            }

            $normalizedLocales = $this->filterLocaleMap($localeMap);
            if ($normalizedLocales === []) {
                continue;
            }

            if ($id === null || $id === '') {
                $id = self::SINGLE_ENTRY_KEY;
            }

            $result[$id] = $normalizedLocales;
        }

        return $result;
    }

    /**
     * @param array<mixed> $localeMap
     */
    private function isLocaleMap(array $localeMap): bool
    {
        foreach ($localeMap as $value) {
            if (!is_string($value)) {
                return false;
            }
        }

        return $localeMap !== [];
    }

    /**
     * @param array<mixed> $localeMap
     * @return array<string, string>
     */
    private function filterLocaleMap(array $localeMap): array
    {
        $result = [];
        foreach ($localeMap as $locale => $value) {
            if (!is_string($locale) || !is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            $result[$locale] = $trimmed;
        }

        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);

        return $result;
    }

    private function extractJson(string $response): ?string
    {
        if (preg_match('/```(?:json)?\\s*(\\{[\\s\\S]*?\\})\\s*```/i', $response, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(\\{[\\s\\S]*\\})/', $response, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
