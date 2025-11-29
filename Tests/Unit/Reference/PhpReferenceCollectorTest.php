<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Unit\Reference;

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
use Two13Tec\L10nGuy\Reference\Collector\PhpReferenceCollector;

/**
 * @covers \Two13Tec\L10nGuy\Reference\Collector\PhpReferenceCollector
 */
final class PhpReferenceCollectorTest extends TestCase
{
    private PhpReferenceCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new PhpReferenceCollector();
    }

    /**
     * @test
     */
    public function collectsReferencesFromFixture(): void
    {
        $file = new \SplFileInfo($this->fixturePath('Classes/Presentation/CardComponent.php'));
        $references = $this->collector->collect($file);

        self::assertCount(2, $references);

        $cardReference = $references[0];
        self::assertSame('Two13Tec.Senegal', $cardReference->packageKey);
        self::assertSame('Presentation.Cards', $cardReference->sourceName);
        self::assertSame('cards.authorPublishedBy', $cardReference->identifier);
        self::assertSame('Published by {authorName}', $cardReference->fallback);
        self::assertSame(['authorName' => '$authorName'], $cardReference->placeholders);
        self::assertSame('php', $cardReference->context);

        $alertReference = $references[1];
        self::assertSame('Two13Tec.Senegal', $alertReference->packageKey);
        self::assertSame('NodeTypes.Content.YouTube', $alertReference->sourceName);
        self::assertSame('error.no.videoid', $alertReference->identifier);
        self::assertNull($alertReference->fallback);
    }

    private function fixturePath(string $relative): string
    {
        return FLOW_PATH_ROOT . 'DistributionPackages/Two13Tec.L10nGuy/Tests/Fixtures/SenegalBaseline/' . $relative;
    }
}
