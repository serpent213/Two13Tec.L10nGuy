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
    public function newStateDefaultsToConfigurationFlag(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table',
            defaultNewState: 'needs-review',
            defaultNewStateQualifier: null
        );

        $configuration = $factory->createFromCliOptions();

        self::assertSame('needs-review', $configuration->newState);
        self::assertNull($configuration->newStateQualifier);
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

    /**
     * @test
     */
    public function capturesIdPatternFilter(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table'
        );

        $configuration = $factory->createFromCliOptions([
            'id' => 'hero.*',
        ]);

        self::assertSame('hero.*', $configuration->idPattern);
    }

    /**
     * @test
     */
    public function buildsLlmConfigurationWhenEnabled(): void
    {
        $factory = new ScanConfigurationFactory(
            flowI18nSettings: [],
            defaultFormat: 'table',
            defaultLocales: ['en'],
            llmSettings: [
                'provider' => 'ollama',
                'model' => 'llama3.2:latest',
                'batchSize' => 2,
                'contextWindowLines' => 7,
                'includeNodeTypeContext' => false,
                'includeExistingTranslations' => false,
                'newState' => 'new',
                'newStateQualifier' => 'machine',
                'noteEnabled' => true,
                'maxTokensPerCall' => 2048,
                'rateLimitDelay' => 50,
                'systemPrompt' => 'demo prompt',
            ]
        );

        $configuration = $factory->createFromCliOptions([
            'llm' => true,
            'llmProvider' => 'openai',
            'llmModel' => 'gpt-4o',
        ]);

        self::assertNotNull($configuration->llm);
        self::assertTrue($configuration->llm->enabled);
        self::assertSame('openai', $configuration->llm->provider);
        self::assertSame('gpt-4o', $configuration->llm->model);
        self::assertSame(2, $configuration->llm->batchSize);
        self::assertSame(6, $configuration->llm->maxCrossReferenceLocales);
        self::assertSame(7, $configuration->llm->contextWindowLines);
        self::assertFalse($configuration->llm->includeNodeTypeContext);
        self::assertFalse($configuration->llm->includeExistingTranslations);
        self::assertSame('new', $configuration->llm->newState);
        self::assertSame('machine', $configuration->llm->newStateQualifier);
        self::assertTrue($configuration->llm->noteEnabled);
        self::assertSame(2048, $configuration->llm->maxTokensPerCall);
        self::assertSame(50, $configuration->llm->rateLimitDelay);
        self::assertSame('demo prompt', $configuration->llm->systemPrompt);
        self::assertFalse($configuration->llm->debug);
    }
}
