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
use Two13Tec\L10nGuy\Reference\Collector\FusionReferenceCollector;

/**
 * @covers \Two13Tec\L10nGuy\Reference\Collector\FusionReferenceCollector
 */
final class FusionReferenceCollectorTest extends TestCase
{
    private FusionReferenceCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new FusionReferenceCollector();
    }

    /**
     * @test
     */
    public function detectsInlineAndFluentTranslations(): void
    {
        $cardFile = new \SplFileInfo($this->fixturePath('Resources/Private/Fusion/Presentation/Cards/Card.fusion'));
        $references = $this->collector->collect($cardFile);

        self::assertCount(1, $references);
        $reference = $references[0];
        self::assertSame('cards.authorPublishedBy', $reference->identifier);
        self::assertSame('Two13Tec.Senegal', $reference->packageKey);
        self::assertSame('Presentation.Cards', $reference->sourceName);
        self::assertSame(['authorName' => 'props.authorName'], $reference->placeholders);
        self::assertSame('Published by {authorName}', $reference->fallback);
        self::assertSame('fusion', $reference->context);

        $youTubeFile = new \SplFileInfo($this->fixturePath('Resources/Private/Fusion/Presentation/YouTube.fusion'));
        $youTubeReferences = $this->collector->collect($youTubeFile);
        self::assertSame('error.no.videoid', $youTubeReferences[0]->identifier);

        $fluentFile = new \SplFileInfo($this->fixturePath('Resources/Private/Fusion/Presentation/AssetList.fusion'));
        $fluentReferences = $this->collector->collect($fluentFile);
        self::assertNotEmpty($fluentReferences);

        $fluent = $fluentReferences[0];
        self::assertSame('content.emptyAssetList', $fluent->identifier);
        self::assertSame('Neos.NodeTypes.AssetList', $fluent->packageKey);
        self::assertSame('NodeTypes.AssetList', $fluent->sourceName);
    }

    /**
     * @test
     */
    public function detectsPluralInvocation(): void
    {
        $filePath = sys_get_temp_dir() . '/plural_' . bin2hex(random_bytes(4)) . '.fusion';
        $contents = <<<'FUSION'
prototype(Test.Plural) {
    label = I18n.plural('items.count', 'One item', {count: count}, 'Presentation.Cards', 'Two13Tec.Senegal')
}
FUSION;
        file_put_contents($filePath, $contents);

        try {
            $file = new \SplFileInfo($filePath);
            $references = $this->collector->collect($file);

            self::assertCount(1, $references);
            self::assertTrue($references[0]->isPlural);
            self::assertSame('items.count', $references[0]->identifier);
        } finally {
            @unlink($filePath);
        }
    }

    private function fixturePath(string $relative): string
    {
        return FLOW_PATH_ROOT . 'DistributionPackages/Two13Tec.L10nGuy/Tests/Fixtures/SenegalBaseline/' . $relative;
    }
}
