<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Utility;

use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;

/**
 * Shared helpers for resolving CLI/package search roots.
 */
final class PathResolver
{
    private function __construct()
    {
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
    }

    /**
     * @return list<array{base: string, paths: list<string>}>
     */
    public static function resolveRoots(ScanConfiguration $configuration, string $basePath): array
    {
        if ($configuration->paths !== []) {
            $roots = [];
            foreach ($configuration->paths as $path) {
                if (self::isAbsolute($path)) {
                    $roots[] = ['base' => $path, 'paths' => ['']];
                } else {
                    $roots[] = ['base' => $basePath, 'paths' => [$path]];
                }
            }

            return $roots;
        }

        $defaultPath = 'DistributionPackages';
        if ($configuration->packageKey !== null) {
            $defaultPath .= '/' . $configuration->packageKey;
        }

        return [[
            'base' => $basePath,
            'paths' => [$defaultPath],
        ]];
    }

    /**
     * Convert absolute path to relative path by stripping FLOW_PATH_ROOT prefix
     */
    public static function relativePath(string $path): string
    {
        return ltrim(str_replace(FLOW_PATH_ROOT, '', $path), '/');
    }
}
