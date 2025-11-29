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
use Two13Tec\L10nGuy\Reference\Collector\YamlReferenceCollector;

/**
 * @covers \Two13Tec\L10nGuy\Reference\Collector\YamlReferenceCollector
 */
final class YamlReferenceCollectorTest extends TestCase
{
    private YamlReferenceCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new YamlReferenceCollector();
    }

    /**
     * @test
     */
    public function collectsNodeTypeInspectorLabels(): void
    {
        $file = new \SplFileInfo($this->fixturePath('NodeTypes/Content/ContactForm/ContactForm.yaml'));
        $references = $this->collector->collect($file);

        self::assertNotEmpty($references);
        $first = $references[0];
        self::assertSame('Two13Tec.Senegal', $first->packageKey);
        self::assertSame('NodeTypes.Content.ContactForm', $first->sourceName);
        self::assertSame('ui.label', $first->identifier);
        self::assertSame('yaml', $first->context);

        $groupReference = $this->findReference($references, 'groups.email');
        self::assertNotNull($groupReference);
        self::assertSame('groups.email', $groupReference->identifier);

        $propertyReference = $this->findReference($references, 'properties.subject');
        self::assertNotNull($propertyReference);
    }

    /**
     * @param list<\Two13Tec\L10nGuy\Domain\Dto\TranslationReference> $references
     */
    private function findReference(array $references, string $identifier): ?\Two13Tec\L10nGuy\Domain\Dto\TranslationReference
    {
        foreach ($references as $reference) {
            if ($reference->identifier === $identifier) {
                return $reference;
            }
        }

        return null;
    }

    private function fixturePath(string $relative): string
    {
        return FLOW_PATH_ROOT . 'DistributionPackages/Two13Tec.L10nGuy/Tests/Fixtures/SenegalBaseline/' . $relative;
    }
}
