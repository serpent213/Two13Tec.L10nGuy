<?php
declare(strict_types=1);

namespace Two13Tec\L10nGuy\Command;

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
use Neos\Flow\Cli\CommandController;
use Two13Tec\L10nGuy\Service\FileDiscoveryService;
use Two13Tec\L10nGuy\Service\ScanConfigurationFactory;

/**
 * Command controller that will eventually host the l10n:scan CLI entry point.
 *
 * @Flow\Scope("singleton")
 */
class LocalizationScanCommandController extends CommandController
{
    #[Flow\Inject]
    protected ScanConfigurationFactory $scanConfigurationFactory;

    #[Flow\Inject]
    protected FileDiscoveryService $fileDiscoveryService;

    /**
     * Initialize the scan workflow. The heavy lifting follows in later phases; for now we build the configuration.
     *
     * @param string|null $package Package key to limit scanning
     * @param string|null $source Source name (e.g. Presentation.Cards)
     * @param string|null $path Optional filesystem path override
     * @param string|null $locales Comma separated locale list overriding Flow settings
     * @param string|null $format Output format (table|json)
     * @param bool|null $dryRun Whether to avoid catalog writes
     * @param bool|null $update Toggle update mode that will later write catalogs
     * @return void
     */
    public function scanCommand(
        ?string $package = null,
        ?string $source = null,
        ?string $path = null,
        ?string $locales = null,
        ?string $format = null,
        ?bool $dryRun = null,
        ?bool $update = null
    ): void {
        $configuration = $this->scanConfigurationFactory->createFromCliOptions([
            'package' => $package,
            'source' => $source,
            'paths' => $path ? [$path] : [],
            'locales' => $locales,
            'format' => $format,
            'dryRun' => $dryRun,
            'update' => $update,
        ]);

        $this->outputLine(
            'Prepared scan for %s (locales: %s, format: %s, dry-run: %s).',
            [
                $configuration->packageKey ?? 'all packages',
                $configuration->locales === [] ? '<none>' : implode(', ', $configuration->locales),
                $configuration->format,
                $configuration->dryRun ? 'yes' : 'no',
            ]
        );

        // Phase 2 will hook file discovery and scanner wiring. Keeping a small interaction now documents intent.
        $this->fileDiscoveryService->seedFromConfiguration($configuration);
    }
}
