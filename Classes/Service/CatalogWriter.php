<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Service;

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
use Neos\Utility\Files;
use Two13Tec\L10nGuy\Domain\Dto\CatalogEntry;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;

/**
 * Applies catalog mutations and writes deterministic XLF files.
 *
 * @Flow\Scope("singleton")
 */
final class CatalogWriter
{
    /**
     * @param list<CatalogMutation> $mutations
     * @return list<string> Absolute file paths that were touched
     */
    public function write(array $mutations, CatalogIndex $catalogIndex, ScanConfiguration $configuration, string $basePath = FLOW_PATH_ROOT): array
    {
        if ($mutations === []) {
            return [];
        }

        $grouped = $this->groupMutations($mutations);
        $touched = [];

        foreach ($grouped as $key => $group) {
            [$locale, $packageKey, $sourceName] = explode('|', $key, 3);
            $existingPath = $catalogIndex->catalogPath($locale, $packageKey, $sourceName);
            $filePath = $existingPath ?? $this->resolveCatalogPath($configuration, $basePath, $packageKey, $locale, $sourceName);
            if ($filePath === null) {
                continue;
            }

            $parsed = CatalogFileParser::parse($filePath);
            $metadata = $this->resolveMetadata($parsed['meta'], $packageKey, $locale);
            $writeTarget = $this->shouldWriteTarget($metadata, $locale);
            $units = $parsed['units'];
            $updated = false;

            foreach ($group as $mutation) {
                if ($mutation->identifier === '' || isset($units[$mutation->identifier])) {
                    continue;
                }
                $units[$mutation->identifier] = [
                    'source' => $mutation->source,
                    'target' => $writeTarget ? $mutation->target : null,
                    'state' => $writeTarget ? CatalogEntry::STATE_NEEDS_REVIEW : null,
                ];
                $updated = true;
            }

            if (!$updated) {
                continue;
            }

            ksort($units, SORT_NATURAL | SORT_FLAG_CASE);

            if ($configuration->dryRun) {
                $touched[] = $filePath;
                continue;
            }

            $this->writeCatalogFile($filePath, $metadata, $units);
            $touched[] = $filePath;
        }

        return array_values(array_unique($touched));
    }

    /**
     * Delete catalog entries that are no longer referenced anywhere.
     *
     * @param list<CatalogEntry> $entries
     * @return list<string>
     */
    public function deleteEntries(array $entries, ScanConfiguration $configuration): array
    {
        if ($entries === []) {
            return [];
        }

        $grouped = $this->groupEntriesByFile($entries);
        $touched = [];

        foreach ($grouped as $filePath => $groupEntries) {
            if ($filePath === '' || !is_file($filePath)) {
                continue;
            }

            $parsed = CatalogFileParser::parse($filePath);
            $units = $parsed['units'];
            $metadata = $this->resolveMetadata(
                $parsed['meta'],
                $groupEntries[0]->packageKey,
                $groupEntries[0]->locale
            );
            $updated = false;

            foreach ($groupEntries as $entry) {
                if (!isset($units[$entry->identifier])) {
                    continue;
                }
                unset($units[$entry->identifier]);
                $updated = true;
            }

            if (!$updated) {
                continue;
            }

            if ($units !== []) {
                ksort($units, SORT_NATURAL | SORT_FLAG_CASE);
            }

            if ($configuration->dryRun) {
                $touched[] = $filePath;
                continue;
            }

            $this->writeCatalogFile($filePath, $metadata, $units);
            $touched[] = $filePath;
        }

        return array_values(array_unique($touched));
    }

    /**
     * @param list<CatalogMutation> $mutations
     * @return array<string, list<CatalogMutation>>
     */
    private function groupMutations(array $mutations): array
    {
        $grouped = [];
        foreach ($mutations as $mutation) {
            $key = implode('|', [$mutation->locale, $mutation->packageKey, $mutation->sourceName]);
            $grouped[$key] ??= [];
            $grouped[$key][] = $mutation;
        }

        return $grouped;
    }

    /**
     * @param list<CatalogEntry> $entries
     * @return array<string, list<CatalogEntry>>
     */
    private function groupEntriesByFile(array $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $grouped[$entry->filePath] ??= [];
            $grouped[$entry->filePath][] = $entry;
        }

        return $grouped;
    }

    private function resolveMetadata(array $meta, string $packageKey, string $locale): array
    {
        $productName = $meta['productName'] ?: $packageKey;
        $sourceLanguage = $meta['sourceLanguage'] ?: 'en';
        $targetLanguage = $meta['targetLanguage'];
        if ($targetLanguage === null && strcasecmp($sourceLanguage, $locale) !== 0) {
            $targetLanguage = $locale;
        }

        return [
            'productName' => $productName,
            'sourceLanguage' => $sourceLanguage,
            'targetLanguage' => $targetLanguage,
            'original' => $meta['original'] ?? '',
            'datatype' => $meta['datatype'] ?? 'plaintext',
        ];
    }

    private function shouldWriteTarget(array $metadata, string $locale): bool
    {
        if (!empty($metadata['targetLanguage'])) {
            return true;
        }

        return strcasecmp($metadata['sourceLanguage'], $locale) !== 0;
    }

    /**
     * @param array<string, array{source: ?string, target: ?string, state: ?string}> $units
     */
    private function writeCatalogFile(string $filePath, array $metadata, array $units): void
    {
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            Files::createDirectoryRecursively($directory);
        }

        $xml = $this->renderCatalog($metadata, $units);
        file_put_contents($filePath, $xml);
    }

    /**
     * @param array<string, array{source: ?string, target: ?string, state: ?string}> $units
     */
    private function renderCatalog(array $metadata, array $units): string
    {
        $fileAttributes = [
            'original' => $metadata['original'] ?? '',
            'product-name' => $metadata['productName'],
            'source-language' => $metadata['sourceLanguage'],
            'datatype' => $metadata['datatype'] ?? 'plaintext',
        ];
        if (!empty($metadata['targetLanguage'])) {
            $fileAttributes['target-language'] = $metadata['targetLanguage'];
        }

        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">';
        $lines[] = '  <file ' . $this->formatAttributes($fileAttributes) . '>';
        $lines[] = '    <body>';

        foreach ($units as $identifier => $unit) {
            $lines[] = sprintf('      <trans-unit id="%s" xml:space="preserve">', $this->escape($identifier));
            $lines[] = sprintf('        <source>%s</source>', $this->escape($unit['source'] ?? ''));
            if ($unit['target'] !== null && $unit['target'] !== '') {
                $targetAttributes = $unit['state'] !== null ? sprintf(' state="%s"', $this->escape($unit['state'])) : '';
                $lines[] = sprintf('        <target%s>%s</target>', $targetAttributes, $this->escape($unit['target']));
            }
            $lines[] = '      </trans-unit>';
        }

        $lines[] = '    </body>';
        $lines[] = '  </file>';
        $lines[] = '</xliff>';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function formatAttributes(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $name => $value) {
            $parts[] = sprintf('%s="%s"', $name, $this->escape((string)$value));
        }

        return implode(' ', $parts);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function resolveCatalogPath(ScanConfiguration $configuration, string $basePath, string $packageKey, string $locale, string $sourceName): ?string
    {
        $relative = 'Resources/Private/Translations/' . $locale . '/' . str_replace('.', '/', $sourceName) . '.xlf';

        if ($configuration->paths !== []) {
            $path = $configuration->paths[0];
            $absoluteRoot = $this->isAbsolutePath($path) ? $path : Files::concatenatePaths([$basePath, $path]);
            return rtrim($absoluteRoot, '/') . '/' . $relative;
        }

        $candidates = [
            $basePath . 'DistributionPackages/' . $packageKey,
            $basePath . 'Packages/Application/' . $packageKey,
            $basePath . 'Packages/Sites/' . $packageKey,
        ];

        foreach ($candidates as $packagePath) {
            if (is_dir($packagePath)) {
                return rtrim($packagePath, '/') . '/' . $relative;
            }
        }

        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
    }
}
