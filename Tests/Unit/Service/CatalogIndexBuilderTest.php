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

use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Xliff\Service\XliffFileProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Service\CatalogIndexBuilder;
use Two13Tec\L10nGuy\Service\FileDiscoveryService;

/**
 * @covers \Two13Tec\L10nGuy\Service\CatalogIndexBuilder
 */
final class CatalogIndexBuilderTest extends TestCase
{
    /**
     * @test
     */
    public function buildsIndexViaProviderAndFlagsMissingCatalogs(): void
    {
        $fileDiscoveryService = new FileDiscoveryService([
            'includes' => [
                ['pattern' => 'Resources/Private/Translations/**/*.xlf', 'enabled' => true],
            ],
            'excludes' => [],
        ]);

        /** @var XliffFileProvider&MockObject $provider */
        $provider = $this->createMock(XliffFileProvider::class);
        $provider->expects(self::exactly(2))
            ->method('getMergedFileData')
            ->willReturnCallback(static function (string $fileId, Locale $locale): array {
                $sharedUnits = [
                    'cards.moreButton' => [
                        [
                            'source' => 'More',
                            'target' => $locale->getLanguage() === 'de' ? 'Mehr' : 'More',
                        ],
                    ],
                    'cards.placeholderWarning' => [
                        [
                            'source' => 'Placeholder {name}',
                            'target' => $locale->getLanguage() === 'de' ? 'Platzhalter {name}' : 'Placeholder {name}',
                        ],
                    ],
                    'cards.onlyFallback' => [
                        [
                            'source' => 'Only fallback',
                            'target' => 'Nur Fallback',
                        ],
                    ],
                ];

                return ['translationUnits' => $sharedUnits];
            });

        $builder = new CatalogIndexBuilder($fileDiscoveryService, $provider);
        $configuration = new ScanConfiguration(
            locales: ['de', 'en', 'fr'],
            packageKey: 'Two13Tec.Senegal',
            sourceName: null,
            paths: [$this->fixtureRoot()],
            format: 'table',
            dryRun: true,
            update: false
        );

        $index = $builder->build($configuration);
        $entriesDe = $index->entriesFor('de', 'Two13Tec.Senegal', 'Presentation.Cards');
        $entriesEn = $index->entriesFor('en', 'Two13Tec.Senegal', 'Presentation.Cards');

        self::assertArrayHasKey('cards.placeholderWarning', $entriesDe);
        self::assertArrayHasKey('cards.moreButton', $entriesEn);
        self::assertArrayNotHasKey('cards.onlyFallback', $entriesEn, 'Fallback-only entries must not leak into catalogs.');

        $missing = $index->missingCatalogs();
        self::assertContains([
            'locale' => 'fr',
            'packageKey' => 'Two13Tec.Senegal',
            'sourceName' => 'Presentation.Cards',
        ], $missing, 'Locales without catalogs should be reported instead of crashing.');
    }

    private function fixtureRoot(): string
    {
        return FLOW_PATH_ROOT . 'DistributionPackages/Two13Tec.L10nGuy/Tests/Fixtures/SenegalBaseline';
    }
}
