<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Domain\Dto;

use Neos\Flow\Annotations as Flow;
use Two13Tec\L10nGuy\Domain\Dto\TranslationKey;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

/**
 * Aggregates catalog entries and metadata grouped by locale/package/source.
 */
#[Flow\Proxy(false)]
final class CatalogIndex
{
    /**
     * @var array<string, array<string, array<string, array<string, CatalogEntry>>>>
     */
    private array $entries = [];

    /**
     * @var array<string, array<string, array<string, array<string, list<string>>>>>
     */
    private array $pluralGroups = [];

    /**
     * @var array<string, array<string, array<string, array{path: string, metadata: array<string, mixed>}>>>
     */
    private array $catalogFiles = [];

    /**
     * @var array<string, array<string, bool>>
     */
    private array $sources = [];

    /**
     * @var list<array{locale: string, packageKey: string, sourceName: string}>
     */
    private array $missingCatalogs = [];

    /**
     * @var list<array{message: string, context: array<string, string>}>
     */
    private array $errors = [];

    public function addEntry(CatalogEntry $entry): void
    {
        $this->entries[$entry->locale][$entry->packageKey][$entry->sourceName][$entry->identifier] = $entry;
        $this->sources[$entry->packageKey][$entry->sourceName] = true;
    }

    /**
     * @param list<string> $forms
     */
    public function addPluralGroup(string $locale, string $packageKey, string $sourceName, string $identifier, array $forms): void
    {
        $uniqueForms = array_values(array_unique($forms));
        sort($uniqueForms, SORT_NATURAL | SORT_FLAG_CASE);
        $this->pluralGroups[$locale][$packageKey][$sourceName][$identifier] = $uniqueForms;
        $this->sources[$packageKey][$sourceName] = true;
    }

    public function registerCatalogFile(string $locale, string $packageKey, string $sourceName, string $filePath, array $metadata = []): void
    {
        $this->catalogFiles[$locale][$packageKey][$sourceName] = [
            'path' => $filePath,
            'metadata' => $metadata,
        ];
        $this->sources[$packageKey][$sourceName] = true;
    }

    public function catalogPath(string $locale, string $packageKey, string $sourceName): ?string
    {
        return $this->catalogFiles[$locale][$packageKey][$sourceName]['path'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        $fromCatalogs = array_keys($this->catalogFiles);
        $fromEntries = array_keys($this->entries);
        $locales = array_values(array_unique(array_merge($fromCatalogs, $fromEntries)));
        sort($locales);

        return $locales;
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogMetadata(string $locale, string $packageKey, string $sourceName): array
    {
        return $this->catalogFiles[$locale][$packageKey][$sourceName]['metadata'] ?? [];
    }

    /**
     * @return array<string, array<string, array<string, array<string, CatalogEntry>>>>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return array<string, CatalogEntry>
     */
    public function entriesFor(string $locale, TranslationKey $key): array
    {
        return $this->entries[$locale][$key->packageKey][$key->sourceName] ?? [];
    }

    /**
     * @return list<string>
     */
    public function pluralGroup(string $locale, TranslationKey $key): array
    {
        return $this->pluralGroups[$locale][$key->packageKey][$key->sourceName][$key->identifier] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function sources(): array
    {
        $sources = [];
        foreach ($this->sources as $packageKey => $sourceNames) {
            $sources[$packageKey] = array_keys($sourceNames);
        }

        return $sources;
    }

    public function markMissingCatalog(string $locale, string $packageKey, string $sourceName): void
    {
        $this->missingCatalogs[] = [
            'locale' => $locale,
            'packageKey' => $packageKey,
            'sourceName' => $sourceName,
        ];
    }

    /**
     * @return list<array{locale: string, packageKey: string, sourceName: string, path: string}>
     */
    public function catalogList(): array
    {
        $catalogs = [];
        foreach ($this->iterateCatalogFileContexts() as [$locale, $packageKey, $sourceName, $data]) {
            $catalogs[] = [
                'locale' => $locale,
                'packageKey' => $packageKey,
                'sourceName' => $sourceName,
                'path' => $data['path'],
            ];
        }

        return $catalogs;
    }

    /**
     * @return list<array{locale: string, packageKey: string, sourceName: string}>
     */
    public function missingCatalogs(): array
    {
        return $this->missingCatalogs;
    }

    public function addError(string $message, array $context = []): void
    {
        $this->errors[] = [
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{message: string, context: array<string, string>}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return iterable<array{0: string, 1: string, 2: string, 3: array{path: string, metadata: array<string, mixed>}}>
     */
    private function iterateCatalogFileContexts(): iterable
    {
        foreach ($this->catalogFiles as $locale => $packages) {
            foreach ($packages as $packageKey => $sources) {
                foreach ($sources as $sourceName => $data) {
                    yield [$locale, $packageKey, $sourceName, $data];
                }
            }
        }
    }
}
