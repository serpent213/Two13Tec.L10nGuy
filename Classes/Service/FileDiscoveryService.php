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
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;

/**
 * Centralized file discovery that honours the helper specific include/exclude patterns.
 *
 * @Flow\Scope("singleton")
 */
final class FileDiscoveryService
{
    #[Flow\InjectConfiguration(path: 'filePatterns', package: 'Two13Tec.L10nGuy')]
    protected array $filePatternSettings = [];

    /**
     * @param array<string, mixed>|null $filePatternSettings
     */
    public function __construct(?array $filePatternSettings = null)
    {
        if ($filePatternSettings !== null) {
            $this->filePatternSettings = $filePatternSettings;
        }
    }

    /**
     * Placeholder hook for Phase 1 to document how CLI configuration will flow into discovery.
     */
    public function seedFromConfiguration(ScanConfiguration $configuration): void
    {
        // Future phases will use this to lazily resolve package roots.
        if ($configuration->paths === [] && isset($this->filePatternSettings['includes'])) {
            return;
        }

        foreach ($configuration->paths as $configuredPath) {
            if (!is_dir($configuredPath)) {
                throw new \RuntimeException(sprintf('Configured path "%s" does not exist.', $configuredPath), 1731157731);
            }
        }
    }

    /**
     * Discover files matching helper configuration.
     *
     * @param string $basePath Absolute directory that acts as discovery root.
     * @param list<string> $searchPaths Optional relative sub paths to limit scanning.
     * @return list<\SplFileInfo>
     */
    public function discover(string $basePath, array $searchPaths = []): array
    {
        $basePath = Files::getNormalizedPath($basePath);
        $searchPaths = $searchPaths === [] ? [''] : $searchPaths;
        $includes = $this->extractActivePatterns($this->filePatternSettings['includes'] ?? []);
        $excludes = $this->extractActivePatterns($this->filePatternSettings['excludes'] ?? []);

        $matches = [];
        foreach ($searchPaths as $relativePath) {
            $searchRoot = $this->resolveSearchRoot($basePath, $relativePath);
            if ($searchRoot === null) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($searchRoot, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $fileInfo */
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $relativeFilePath = ltrim(str_replace($searchRoot, '', $fileInfo->getPathname()), '/');
                $matchesInclude = $includes === [] || $this->matches($relativeFilePath, $includes);
                if (!$matchesInclude) {
                    continue;
                }
                if ($this->matches($relativeFilePath, $excludes)) {
                    continue;
                }
                $matches[$fileInfo->getPathname()] = $fileInfo;
            }
        }

        ksort($matches);
        return array_values($matches);
    }

    /**
     * @param array<int, array<string, mixed>> $patternConfigurations
     * @return list<string>
     */
    private function extractActivePatterns(array $patternConfigurations): array
    {
        $patterns = [];
        foreach ($patternConfigurations as $patternConfiguration) {
            $enabled = $patternConfiguration['enabled'] ?? true;
            if ($enabled !== true) {
                continue;
            }
            if (!isset($patternConfiguration['pattern'])) {
                continue;
            }
            $patterns[] = $this->globToRegex((string)$patternConfiguration['pattern']);
        }

        return $patterns;
    }

    /**
     * @param string $path
     * @param list<string> $regexList
     */
    private function matches(string $path, array $regexList): bool
    {
        if ($regexList === []) {
            return false;
        }

        foreach ($regexList as $regex) {
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function globToRegex(string $pattern): string
    {
        $pattern = str_replace('\\', '/', $pattern);
        $escaped = preg_quote($pattern, '#');
        $escaped = str_replace(['\*\*', '\*', '\?'], ['.*', '[^/]*', '.'], $escaped);

        return '#^' . $escaped . '$#u';
    }

    private function resolveSearchRoot(string $basePath, string $relativePath): ?string
    {
        $absolutePath = $relativePath === ''
            ? $basePath
            : Files::concatenatePaths([$basePath, $relativePath]);

        if (!is_dir($absolutePath)) {
            return null;
        }

        return Files::getNormalizedPath($absolutePath);
    }
}
