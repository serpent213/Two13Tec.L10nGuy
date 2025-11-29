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
use SplFileInfo;
use Two13Tec\L10nGuy\Domain\Dto\ReferenceIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Reference\Collector\FusionReferenceCollector;
use Two13Tec\L10nGuy\Reference\Collector\PhpReferenceCollector;
use Two13Tec\L10nGuy\Reference\Collector\ReferenceCollectorInterface;
use Two13Tec\L10nGuy\Reference\Collector\YamlReferenceCollector;

/**
 * Coordinates file discovery and reference collectors.
 *
 * @Flow\Scope("singleton")
 */
final class ReferenceIndexBuilder
{
    /**
     * @var list<ReferenceCollectorInterface>
     */
    private array $collectors;

    public function __construct(
        private readonly FileDiscoveryService $fileDiscoveryService,
        PhpReferenceCollector $phpReferenceCollector,
        FusionReferenceCollector $fusionReferenceCollector,
        YamlReferenceCollector $yamlReferenceCollector
    ) {
        $this->collectors = [
            $phpReferenceCollector,
            $fusionReferenceCollector,
            $yamlReferenceCollector,
        ];
    }

    public function build(ScanConfiguration $configuration, string $basePath = FLOW_PATH_ROOT): ReferenceIndex
    {
        $index = new ReferenceIndex();
        $roots = $this->resolveRoots($configuration, $basePath);
        $visited = [];

        foreach ($roots as $root) {
            foreach ($this->fileDiscoveryService->discover($root['base'], $root['paths']) as $fileInfo) {
                $path = $fileInfo->getPathname();
                if (isset($visited[$path])) {
                    continue;
                }
                $visited[$path] = true;
                $this->collectReferencesForFile($fileInfo, $index);
            }
        }

        return $index;
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

    private function collectReferencesForFile(SplFileInfo $fileInfo, ReferenceIndex $index): void
    {
        foreach ($this->collectors as $collector) {
            if (!$collector->supports($fileInfo)) {
                continue;
            }
            foreach ($collector->collect($fileInfo) as $reference) {
                $index->add($reference);
            }
            break;
        }
    }
}
