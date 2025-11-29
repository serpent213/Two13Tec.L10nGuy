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
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Xliff\Service\XliffFileProvider;
use SplFileInfo;
use Two13Tec\L10nGuy\Domain\Dto\CatalogEntry;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;

/**
 * Builds a catalog index backed by Flow's XLIFF provider.
 *
 * @Flow\Scope("singleton")
 */
final class CatalogIndexBuilder
{
    public function __construct(
        private readonly FileDiscoveryService $fileDiscoveryService,
        private readonly XliffFileProvider $xliffFileProvider
    ) {
    }

    public function build(ScanConfiguration $configuration, string $basePath = FLOW_PATH_ROOT): CatalogIndex
    {
        $index = new CatalogIndex();
        $roots = $this->resolveRoots($configuration, $basePath);
        $visited = [];

        foreach ($roots as $root) {
            foreach ($this->fileDiscoveryService->discover($root['base'], $root['paths']) as $fileInfo) {
                $path = $fileInfo->getPathname();
                if (isset($visited[$path])) {
                    continue;
                }
                $visited[$path] = true;

                if (!$this->supports($fileInfo)) {
                    continue;
                }

                $catalogContext = $this->parseCatalogContext($fileInfo, $configuration);
                if ($catalogContext === null) {
                    continue;
                }

                [$packageKey, $locale, $sourceName] = $catalogContext;

                if ($configuration->locales !== [] && !in_array($locale, $configuration->locales, true)) {
                    continue;
                }
                if ($configuration->packageKey !== null && $configuration->packageKey !== $packageKey) {
                    continue;
                }
                if ($configuration->sourceName !== null && $configuration->sourceName !== $sourceName) {
                    continue;
                }

                $parsedFile = CatalogFileParser::parse($path);
                $index->registerCatalogFile($locale, $packageKey, $sourceName, $path, $parsedFile['meta']);
                $this->addEntriesFromProvider($index, $fileInfo, $packageKey, $sourceName, $locale, $parsedFile['units']);
            }
        }

        $this->detectMissingCatalogs($index, $configuration);

        return $index;
    }

    private function supports(SplFileInfo $fileInfo): bool
    {
        return str_ends_with(strtolower($fileInfo->getFilename()), '.xlf');
    }

    /**
     * @return array{string, string, string}|null
     */
    private function parseCatalogContext(SplFileInfo $fileInfo, ScanConfiguration $configuration): ?array
    {
        $path = str_replace('\\', '/', $fileInfo->getPathname());
        $match = [];
        if (preg_match('#/Resources/Private/Translations/([^/]+)/(.+)\.xlf$#i', $path, $match) !== 1) {
            return null;
        }

        $locale = $match[1];
        $sourceName = $this->normalizeSourceName($match[2]);

        $packageKey = $this->detectPackageKey($path);
        if ($configuration->packageKey !== null) {
            $packageKey = $configuration->packageKey;
        }

        if ($packageKey === null) {
            return null;
        }

        return [$packageKey, $locale, $sourceName];
    }

    private function normalizeSourceName(string $relativePath): string
    {
        $source = preg_replace('#\.xlf$#i', '', $relativePath);
        $source = str_replace(['\\', '/'], '.', $source ?? '');
        $source = trim((string)$source, '.');

        return $source === '' ? 'Main' : $source;
    }

    private function detectPackageKey(string $path): ?string
    {
        $match = [];
        if (preg_match('#/(?:DistributionPackages|Packages/(?:Application|Framework|Plugins|Sites))/([^/]+)/#', $path, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * @param array<string, array{source: ?string, target: ?string, state: ?string}> $nativeUnits
     */
    private function addEntriesFromProvider(
        CatalogIndex $index,
        SplFileInfo $fileInfo,
        string $packageKey,
        string $sourceName,
        string $locale,
        array $nativeUnits
    ): void {
        if ($nativeUnits === []) {
            return;
        }

        $translationUnits = [];
        try {
            $localeObject = new Locale($locale);
            $fileId = $packageKey . ':' . $sourceName;
            $fileData = $this->xliffFileProvider->getMergedFileData($fileId, $localeObject);
            $translationUnits = $fileData['translationUnits'] ?? [];
        } catch (\Throwable $exception) {
            $index->addError(
                'Failed to load catalog via Flow XliffFileProvider',
                [
                    'file' => $fileInfo->getPathname(),
                    'locale' => $locale,
                    'packageKey' => $packageKey,
                    'sourceName' => $sourceName,
                    'reason' => $exception->getMessage(),
                ]
            );
        }

        foreach ($nativeUnits as $identifier => $unit) {
            $resolved = $translationUnits[$identifier][0] ?? [
                'source' => $unit['source'],
                'target' => $unit['target'],
            ];

            $index->addEntry(new CatalogEntry(
                locale: $locale,
                packageKey: $packageKey,
                sourceName: $sourceName,
                identifier: $identifier,
                filePath: $fileInfo->getPathname(),
                source: $resolved['source'] ?? null,
                target: $resolved['target'] ?? null,
                state: $unit['state'] ?? null
            ));
        }
    }

    private function detectMissingCatalogs(CatalogIndex $index, ScanConfiguration $configuration): void
    {
        if ($configuration->locales === []) {
            return;
        }

        $sources = $index->sources();
        foreach ($sources as $packageKey => $sourceNames) {
            if ($configuration->packageKey !== null && $configuration->packageKey !== $packageKey) {
                continue;
            }
            foreach ($sourceNames as $sourceName) {
                if ($configuration->sourceName !== null && $configuration->sourceName !== $sourceName) {
                    continue;
                }
                foreach ($configuration->locales as $locale) {
                    if ($index->catalogPath($locale, $packageKey, $sourceName) !== null) {
                        continue;
                    }

                    $index->markMissingCatalog($locale, $packageKey, $sourceName);
                }
            }
        }
    }

    /**
     * @return list<array{base: string, paths: list<string>}>
     */
    private function resolveRoots(ScanConfiguration $configuration, string $basePath): array
    {
        if ($configuration->paths !== []) {
            $roots = [];
            foreach ($configuration->paths as $path) {
                if ($this->isAbsolutePath($path)) {
                    $roots[] = ['base' => $path, 'paths' => ['']];
                } else {
                    $roots[] = ['base' => $basePath, 'paths' => [$path]];
                }
            }
            return $roots;
        }

        if ($configuration->packageKey !== null) {
            return [[
                'base' => $basePath,
                'paths' => ['DistributionPackages/' . $configuration->packageKey],
            ]];
        }

        return [[
            'base' => $basePath,
            'paths' => ['DistributionPackages'],
        ]];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
    }
}
