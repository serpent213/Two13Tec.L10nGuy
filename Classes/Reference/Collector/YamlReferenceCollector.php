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
 * Collector for Neos NodeType YAML definitions.
 */
final class YamlReferenceCollector implements ReferenceCollectorInterface
{
    public function supports(SplFileInfo $file): bool
    {
        return str_ends_with(strtolower($file->getFilename()), '.yaml');
    }

    public function collect(SplFileInfo $file): array
    {
        if (!$this->supports($file)) {
            return [];
        }

        $contents = @file($file->getPathname(), FILE_IGNORE_NEW_LINES);
        if ($contents === false) {
            return [];
        }

        $references = [];
        $stack = [];
        $nodeTypeContexts = $this->extractNodeTypeContexts($contents);

        foreach ($contents as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $indent = $this->countIndent($line);
            while ($stack !== [] && end($stack)['indent'] >= $indent) {
                array_pop($stack);
            }

            if (!preg_match('/^("([^"]+)"|\'([^\']+)\'|([^:]+)):(.*)$/', $trimmed, $matches)) {
                continue;
            }

            $rawKey = $matches[1];
            $key = $this->normalizeKey($rawKey);
            $value = trim($matches[5]);
            $normalizedValue = trim($value, " \t\"'");

            $path = array_merge(array_column($stack, 'key'), [$key]);

            if ($value === '') {
                $stack[] = ['key' => $key, 'indent' => $indent];
                continue;
            }

            if ($normalizedValue === 'i18n' && $key === 'label') {
                $reference = $this->referenceFromPath(
                    $path,
                    $file->getPathname(),
                    $index + 1,
                    $nodeTypeContexts
                );
                if ($reference !== null) {
                    $references[] = $reference;
                }
            }

            // Scalar entries should not stay on the stack
            while ($stack !== [] && end($stack)['indent'] === $indent) {
                array_pop($stack);
            }
        }

        return $references;
    }

    /**
     * @param array<string, string> $nodeTypeContexts
     */
    private function referenceFromPath(array $path, string $filePath, int $lineNumber, array $nodeTypeContexts): ?TranslationReference
    {
        if ($path === []) {
            return null;
        }

        $nodeType = array_shift($path);
        if ($nodeType === null || !str_contains($nodeType, ':')) {
            return null;
        }

        $nodeTypeContext = $nodeTypeContexts[$nodeType] ?? null;
        [$packageKey] = explode(':', $nodeType, 2);
        $identifier = $this->deriveIdentifier($path);
        if ($identifier === null) {
            return null;
        }

        $sourceName = $this->deriveSourceFromPath($filePath);

        $resolved = TranslationMetadataResolver::resolve($identifier, $packageKey, $sourceName);
        if ($resolved === null) {
            return null;
        }

        return new TranslationReference(
            packageKey: $resolved['packageKey'],
            sourceName: $resolved['sourceName'],
            identifier: $resolved['identifier'],
            context: TranslationReference::CONTEXT_YAML,
            filePath: $filePath,
            lineNumber: $lineNumber,
            fallback: null,
            placeholders: [],
            isPlural: false,
            nodeTypeContext: $nodeTypeContext
        );
    }

    private function deriveIdentifier(array $path): ?string
    {
        $segments = array_filter($path, static fn (string $segment): bool => $segment !== '');
        if ($segments === []) {
            return null;
        }

        if ($segments[0] === 'properties' && isset($segments[1])) {
            return 'properties.' . $segments[1];
        }

        if ($segments[0] === 'ui') {
            if (($segments[1] ?? null) === 'inspector' && isset($segments[2])) {
                if ($segments[2] === 'groups' && isset($segments[3])) {
                    return 'groups.' . $segments[3];
                }
                if ($segments[2] === 'tabs' && isset($segments[3])) {
                    return 'tabs.' . $segments[3];
                }
            }

            return 'ui.' . implode('.', array_slice($segments, 1));
        }

        if ($segments[0] === 'groups' && isset($segments[1])) {
            return 'groups.' . $segments[1];
        }

        return implode('.', $segments);
    }

    private function deriveSourceFromPath(string $filePath): string
    {
        $normalized = str_replace('\\', '/', $filePath);
        if (preg_match('#/(NodeTypes/.*?)/[^/]+\.ya?ml$#', $normalized, $matches)) {
            return str_replace('/', '.', $matches[1]);
        }

        return 'NodeTypes';
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key !== '' && ($key[0] === '"' || $key[0] === "'")) {
            $key = substr($key, 1, -1);
        }
        return $key;
    }

    private function countIndent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /**
     * @param list<string> $lines
     * @return array<string, string>
     */
    private function extractNodeTypeContexts(array $lines): array
    {
        $contexts = [];
        $currentName = null;
        $startIndex = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            $indent = $this->countIndent($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if ($indent === 0 && preg_match('/^("([^"]+)"|\'([^\']+)\'|([^:]+)):/', $trimmed, $matches)) {
                $nodeTypeName = $this->normalizeKey($matches[1]);

                if ($currentName !== null && $startIndex !== null) {
                    $contexts[$currentName] = implode("\n", array_slice($lines, $startIndex, $index - $startIndex));
                }

                $currentName = $nodeTypeName;
                $startIndex = $index;
            }
        }

        if ($currentName !== null && $startIndex !== null) {
            $contexts[$currentName] = implode("\n", array_slice($lines, $startIndex));
        }

        return $contexts;
    }
}
