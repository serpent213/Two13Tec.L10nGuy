<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Unit\Service;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use PHPUnit\Framework\TestCase;
use Two13Tec\L10nGuy\Service\ScanConfigurationFactory;

final class ScanConfigurationFactoryTest extends TestCase
{
    /**
     * @test
     */
    public function localesFallBackToFlowSettings(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [
                'defaultLocale' => 'de',
                'fallbackRule' => [
                    'order' => ['en'],
                ],
            ],
            defaultFormat: 'table'
        );

        $configuration = $factory->createFromCliOptions();

        self::assertSame(['de', 'en'], $configuration->locales);
        self::assertTrue($configuration->dryRun);
    }

    /**
     * @test
     */
    public function localesAreOverriddenByCliOptions(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [
                'defaultLocale' => 'de',
                'fallbackRule' => [
                    'order' => ['en'],
                ],
            ],
            defaultFormat: 'json'
        );

        $configuration = $factory->createFromCliOptions([
            'locales' => 'fr , en',
            'package' => 'Two13Tec.Senegal',
        ]);

        self::assertSame(['fr', 'en'], $configuration->locales);
        self::assertSame('json', $configuration->format);
        self::assertSame('Two13Tec.Senegal', $configuration->packageKey);
    }

    /**
     * @test
     */
    public function updateModeDisablesDryRunByDefault(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table'
        );

        $updateConfiguration = $factory->createFromCliOptions([
            'update' => true,
        ]);
        self::assertFalse($updateConfiguration->dryRun);

        $explicitDryRunConfiguration = $factory->createFromCliOptions([
            'update' => true,
            'dryRun' => true,
        ]);
        self::assertTrue($explicitDryRunConfiguration->dryRun);
    }

    /**
     * @test
     */
    public function defaultsAreAppliedWhenCliOmitsOptions(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table',
            defaultPackages: ['Two13Tec.Senegal'],
            defaultPaths: ['DistributionPackages/Two13Tec.Senegal']
        );

        $configuration = $factory->createFromCliOptions([]);

        self::assertSame('Two13Tec.Senegal', $configuration->packageKey);
        self::assertSame(['DistributionPackages/Two13Tec.Senegal'], $configuration->paths);
    }

    /**
     * @test
     */
    public function cliOverridesDefaults(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table',
            defaultPackages: ['Two13Tec.Senegal'],
            defaultPaths: ['DistributionPackages/Two13Tec.Senegal']
        );

        $configuration = $factory->createFromCliOptions([
            'package' => 'Acme.Demo',
            'paths' => ['DistributionPackages/Acme.Demo'],
        ]);

        self::assertSame('Acme.Demo', $configuration->packageKey);
        self::assertSame(['DistributionPackages/Acme.Demo'], $configuration->paths);
    }
}
