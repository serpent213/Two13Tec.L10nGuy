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
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Reference\Collector\FusionReferenceCollector;
use Two13Tec\L10nGuy\Reference\Collector\PhpReferenceCollector;
use Two13Tec\L10nGuy\Reference\Collector\YamlReferenceCollector;
use Two13Tec\L10nGuy\Service\FileDiscoveryService;
use Two13Tec\L10nGuy\Service\ReferenceIndexBuilder;

/**
 * @covers \Two13Tec\L10nGuy\Service\ReferenceIndexBuilder
 */
final class ReferenceIndexBuilderTest extends TestCase
{
    /**
     * @test
     */
    public function buildsIndexAndFlagsDuplicates(): void
    {
        $builder = new ReferenceIndexBuilder(
            new FileDiscoveryService([
                'includes' => [
                    ['pattern' => '**/*.php', 'enabled' => true],
                    ['pattern' => '**/*.fusion', 'enabled' => true],
                    ['pattern' => '**/*.afx', 'enabled' => true],
                    ['pattern' => '**/*.yaml', 'enabled' => true],
                ],
                'excludes' => [],
            ]),
            new PhpReferenceCollector(),
            new FusionReferenceCollector(),
            new YamlReferenceCollector()
        );

        $configuration = new ScanConfiguration(
            locales: ['de'],
            packageKey: 'Two13Tec.Senegal',
            sourceName: null,
            paths: [$this->fixtureRoot()],
            format: 'table',
            dryRun: true,
            update: false
        );

        $index = $builder->build($configuration);
        self::assertGreaterThanOrEqual(3, $index->uniqueCount());
        self::assertGreaterThanOrEqual(1, $index->duplicateCount());

        $references = $index->references();
        self::assertArrayHasKey('Two13Tec.Senegal', $references);
        self::assertArrayHasKey('Presentation.Cards', $references['Two13Tec.Senegal']);
        self::assertArrayHasKey('cards.authorPublishedBy', $references['Two13Tec.Senegal']['Presentation.Cards']);
    }

    private function fixtureRoot(): string
    {
        return FLOW_PATH_ROOT . 'DistributionPackages/Two13Tec.L10nGuy/Tests/Fixtures/SenegalBaseline';
    }
}
