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
    public function updateFlagIsExplicitOptIn(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table'
        );

        $updateConfiguration = $factory->createFromCliOptions([
            'update' => true,
        ]);
        self::assertTrue($updateConfiguration->update);
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

    /**
     * @test
     */
    public function helperLocaleDefaultsOverrideFlowSettings(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [
                'defaultLocale' => 'de',
                'fallbackRule' => [
                    'order' => ['en'],
                ],
            ],
            defaultFormat: 'table',
            defaultLocales: ['fr', 'es']
        );

        $configuration = $factory->createFromCliOptions([]);

        self::assertSame(['fr', 'es'], $configuration->locales);
    }

    /**
     * @test
     */
    public function cliLocaleOverrideBeatsHelperDefaults(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table',
            defaultLocales: ['fr', 'es']
        );

        $configuration = $factory->createFromCliOptions([
            'locales' => 'de , en',
        ]);

        self::assertSame(['de', 'en'], $configuration->locales);
    }

    /**
     * @test
     */
    public function needsReviewDefaultsToConfigurationFlag(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table',
            defaultSetNeedsReview: true
        );

        $configuration = $factory->createFromCliOptions();

        self::assertTrue($configuration->setNeedsReview);
    }

    /**
     * @test
     */
    public function cliCanDisableNeedsReviewFlag(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table',
            defaultSetNeedsReview: true
        );

        $configuration = $factory->createFromCliOptions(['setNeedsReview' => false]);
        self::assertFalse($configuration->setNeedsReview);

        $stringConfiguration = $factory->createFromCliOptions(['setNeedsReview' => 'false']);
        self::assertFalse($stringConfiguration->setNeedsReview);
    }

    /**
     * @test
     */
    public function quietFlagsAreRespected(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table'
        );

        $quietConfiguration = $factory->createFromCliOptions(['quiet' => true]);
        self::assertTrue($quietConfiguration->quiet);
        self::assertFalse($quietConfiguration->quieter);

        $quieterConfiguration = $factory->createFromCliOptions(['quieter' => true]);
        self::assertTrue($quieterConfiguration->quiet, 'quieter should imply quiet');
        self::assertTrue($quieterConfiguration->quieter);
    }
}
