<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Reference\Collector;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use SplFileInfo;
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;
use Two13Tec\L10nGuy\Reference\TranslationMetadataResolver;

/**
 * Regex/state-machine fusion + AFX collector.
 */
final class FusionReferenceCollector implements ReferenceCollectorInterface
{
    public function supports(SplFileInfo $file): bool
    {
        $filename = strtolower($file->getFilename());
        return str_ends_with($filename, '.fusion') || str_ends_with($filename, '.afx');
    }

    public function collect(SplFileInfo $file): array
    {
        if (!$this->supports($file)) {
            return [];
        }

        $contents = @file_get_contents($file->getPathname());
        if ($contents === false) {
            return [];
        }

        $references = [];
        foreach ($this->collectI18nTranslateCalls($contents, $file->getPathname()) as $reference) {
            $references[] = $reference;
        }
        foreach ($this->collectI18nPluralCalls($contents, $file->getPathname()) as $reference) {
            $references[] = $reference;
        }
        foreach ($this->collectFluentCalls($contents, $file->getPathname()) as $reference) {
            $references[] = $reference;
        }

        return $references;
    }

    /**
     * @return iterable<TranslationReference>
     */
    private function collectI18nTranslateCalls(string $contents, string $filePath): iterable
    {
        return $this->collectI18nCalls($contents, $filePath, 'I18n.translate', false);
    }

    /**
     * @return iterable<TranslationReference>
     */
    private function collectI18nPluralCalls(string $contents, string $filePath): iterable
    {
        return $this->collectI18nCalls($contents, $filePath, 'I18n.plural', true);
    }

    /**
     * @return iterable<TranslationReference>
     */
    private function collectI18nCalls(string $contents, string $filePath, string $token, bool $isPlural): iterable
    {
        $offset = 0;

        while (($position = strpos($contents, $token, $offset)) !== false) {
            $call = $this->extractFunctionCall($contents, $position + strlen($token));
            if ($call === null) {
                break;
            }

            $args = $this->splitArguments($call['arguments']);
            $identifier = $this->stringLiteral($args[0] ?? null);
            $fallback = $this->stringLiteral($args[1] ?? null);
            $placeholders = $this->extractPlaceholderMap($args[2] ?? null);
            $sourceName = $this->stringLiteral($args[3] ?? null);
            $packageKey = $this->stringLiteral($args[4] ?? null);

            $reference = $this->createReference(
                identifier: $identifier,
                fallback: $fallback,
                placeholders: $placeholders,
                sourceName: $sourceName,
                packageKey: $packageKey,
                filePath: $filePath,
                lineNumber: $this->lineNumberFromContents($contents, $position),
                isPlural: $isPlural
            );
            if ($reference !== null) {
                yield $reference;
            }

            $offset = $call['end'];
        }
    }

    /**
     * @return iterable<TranslationReference>
     */
    private function collectFluentCalls(string $contents, string $filePath): iterable
    {
        $length = strlen($contents);

        foreach (
            [
                'Translation.id' => false,
                'Translation.plural' => true,
                'I18n.id' => false,
                'I18n.plural' => true,
            ] as $token => $isPluralStart
        ) {
            $offset = 0;

            while (($position = strpos($contents, $token, $offset)) !== false) {
                $call = $this->extractFunctionCall($contents, $position + strlen($token));
                if ($call === null) {
                    break;
                }

                $identifier = $this->stringLiteral($call['arguments']);
                $chainOffset = $call['end'];
                $packageKey = null;
                $sourceName = null;
                $placeholders = [];
                $fallback = null;
                $isPlural = $isPluralStart;

                while ($chainOffset < $length) {
                    $chainOffset = $this->skipWhitespace($contents, $chainOffset);
                    if ($chainOffset >= $length || $contents[$chainOffset] !== '.') {
                        break;
                    }
                    $chainOffset++;
                    $methodName = $this->readIdentifier($contents, $chainOffset);
                    if ($methodName === '') {
                        break;
                    }
                    $chainOffset += strlen($methodName);
                    $callData = $this->extractFunctionCall($contents, $chainOffset);
                    if ($callData === null) {
                        break;
                    }
                    $argument = $callData['arguments'];
                    if ($methodName === 'package') {
                        $packageKey = $this->stringLiteral($argument);
                    } elseif ($methodName === 'source') {
                        $sourceName = $this->stringLiteral($argument);
                    } elseif ($methodName === 'arguments') {
                        $placeholders = $this->extractPlaceholderMap($argument);
                    } elseif ($methodName === 'value') {
                        $fallback = $this->stringLiteral($argument);
                    } elseif ($methodName === 'plural') {
                        $isPlural = true;
                    }
                    $chainOffset = $callData['end'];
                }

                $reference = $this->createReference(
                    identifier: $identifier,
                    fallback: $fallback,
                    placeholders: $placeholders,
                    sourceName: $sourceName,
                    packageKey: $packageKey,
                    filePath: $filePath,
                    lineNumber: $this->lineNumberFromContents($contents, $position),
                    isPlural: $isPlural
                );
                if ($reference !== null) {
                    yield $reference;
                }

                $offset = max($chainOffset, $position + strlen($token));
            }
        }
    }

    private function readIdentifier(string $contents, int $offset): string
    {
        $identifier = '';
        $length = strlen($contents);
        while ($offset < $length) {
            $char = $contents[$offset];
            if (!ctype_alpha($char)) {
                break;
            }
            $identifier .= $char;
            $offset++;
        }
        return $identifier;
    }

    private function createReference(
        ?string $identifier,
        ?string $fallback,
        array $placeholders,
        ?string $sourceName,
        ?string $packageKey,
        string $filePath,
        int $lineNumber,
        bool $isPlural = false
    ): ?TranslationReference {
        if ($identifier === null) {
            return null;
        }

        $resolved = TranslationMetadataResolver::resolve($identifier, $packageKey, $sourceName);
        if ($resolved === null) {
            return null;
        }

        return new TranslationReference(
            packageKey: $resolved['packageKey'],
            sourceName: $resolved['sourceName'],
            identifier: $resolved['identifier'],
            context: TranslationReference::CONTEXT_FUSION,
            filePath: $filePath,
            lineNumber: $lineNumber,
            fallback: $fallback,
            placeholders: $placeholders,
            isPlural: $isPlural
        );
    }

    /**
     * @return array{arguments: string, end: int}|null
     */
    private function extractFunctionCall(string $contents, int $offset): ?array
    {
        $offset = $this->skipWhitespace($contents, $offset);
        if (!isset($contents[$offset]) || $contents[$offset] !== '(') {
            return null;
        }
        $offset++;
        $depth = 1;
        $start = $offset;
        $length = strlen($contents);

        while ($offset < $length) {
            $char = $contents[$offset];
            if ($char === "'" || $char === '"') {
                $offset = $this->skipQuotedString($contents, $offset);
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return [
                        'arguments' => substr($contents, $start, $offset - $start),
                        'end' => $offset + 1,
                    ];
                }
            }
            $offset++;
        }

        return null;
    }

    private function skipQuotedString(string $contents, int $offset): int
    {
        $quote = $contents[$offset];
        $offset++;
        $length = strlen($contents);
        while ($offset < $length) {
            $char = $contents[$offset];
            if ($char === '\\') {
                $offset += 2;
                continue;
            }
            if ($char === $quote) {
                return $offset + 1;
            }
            $offset++;
        }

        return $offset;
    }

    private function skipWhitespace(string $contents, int $offset): int
    {
        $length = strlen($contents);
        while ($offset < $length && ctype_space($contents[$offset])) {
            $offset++;
        }
        return $offset;
    }

    /**
     * @return list<string>
     */
    private function splitArguments(string $arguments): array
    {
        $parts = [];
        $length = strlen($arguments);
        $current = '';
        $depthParentheses = 0;
        $depthBraces = 0;
        $depthBrackets = 0;
        $inString = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $arguments[$i];
            if ($inString !== null) {
                $current .= $char;
                if ($char === '\\') {
                    $i++;
                    if ($i < $length) {
                        $current .= $arguments[$i];
                    }
                    continue;
                }
                if ($char === $inString) {
                    $inString = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $inString = $char;
                $current .= $char;
                continue;
            }

            if ($char === '(') {
                $depthParentheses++;
            } elseif ($char === ')') {
                $depthParentheses--;
            } elseif ($char === '{') {
                $depthBraces++;
            } elseif ($char === '}') {
                $depthBraces--;
            } elseif ($char === '[') {
                $depthBrackets++;
            } elseif ($char === ']') {
                $depthBrackets--;
            } elseif ($char === ',' && $depthParentheses === 0 && $depthBraces === 0 && $depthBrackets === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return array_map('trim', $parts);
    }

    private function stringLiteral(?string $candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }
        if (($candidate[0] === "'" || $candidate[0] === '"') && substr($candidate, -1) === $candidate[0]) {
            $unquoted = substr($candidate, 1, -1);
            return stripcslashes($unquoted);
        }
        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractPlaceholderMap(?string $argument): array
    {
        if ($argument === null) {
            return [];
        }

        $argument = trim($argument);
        if ($argument === '' || $argument[0] !== '{') {
            return [];
        }

        $argument = trim($argument, "{} \n\r\t");
        if ($argument === '') {
            return [];
        }

        $placeholders = [];
        $pairs = preg_split('/,(?![^{}]*\})/', $argument) ?: [];
        foreach ($pairs as $pair) {
            if (!str_contains($pair, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $pair, 2);
            $key = trim($key, " \t\n\r\"'");
            if ($key === '') {
                continue;
            }
            $placeholders[$key] = trim($value);
        }

        return $placeholders;
    }

    private function lineNumberFromContents(string $contents, int $offset): int
    {
        $prefix = substr($contents, 0, $offset);
        return substr_count($prefix, "\n") + 1;
    }
}
