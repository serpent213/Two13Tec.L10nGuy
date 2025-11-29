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
use Two13Tec\L10nGuy\Service\ScanConfigurationFactory;

/**
 * Stub for the l10n:unused CLI entry point.
 *
 * @Flow\Scope("singleton")
 */
class LocalizationUnusedCommandController extends CommandController
{
    #[Flow\Inject]
    protected ScanConfigurationFactory $scanConfigurationFactory;

    /**
     * @param string|null $package Package key to limit catalog inspection
     * @param string|null $locales Optional comma separated locale list
     * @param string|null $format Output format override
     * @param bool|null $dryRun Whether to apply deletions
     * @param bool|null $delete Toggle catalog mutations (Phase 5)
     * @return void
     */
    public function unusedCommand(
        ?string $package = null,
        ?string $locales = null,
        ?string $format = null,
        ?bool $dryRun = null,
        ?bool $delete = null
    ): void {
        $configuration = $this->scanConfigurationFactory->createFromCliOptions([
            'package' => $package,
            'locales' => $locales,
            'format' => $format,
            'dryRun' => $dryRun ?? true,
            'update' => $delete ?? false,
        ]);

        $this->outputLine(
            'Prepared unused sweep for %s (locales: %s, dry-run: %s).',
            [
                $configuration->packageKey ?? 'all packages',
                $configuration->locales === [] ? '<none>' : implode(', ', $configuration->locales),
                $configuration->dryRun ? 'yes' : 'no',
            ]
        );
    }
}
