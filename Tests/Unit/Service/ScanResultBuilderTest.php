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
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\ReferenceIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;
use Two13Tec\L10nGuy\Service\ScanResultBuilder;

/**
 * @covers \Two13Tec\L10nGuy\Service\ScanResultBuilder
 */
final class ScanResultBuilderTest extends TestCase
{
    /**
     * @test
     */
    public function filtersReferencesBySourcePattern(): void
    {
        $builder = new ScanResultBuilder();
        $configuration = new ScanConfiguration(
            locales: ['en'],
            packageKey: 'Acme.Site',
            sourceName: 'Presentation.*',
            idPattern: null,
            paths: [],
            format: 'table',
            update: false
        );

        $referenceIndex = new ReferenceIndex();
        $referenceIndex->add($this->reference('Acme.Site', 'Presentation.Cards', 'cards.title'));
        $referenceIndex->add($this->reference('Acme.Site', 'NodeTypes.Content.Card', 'node.title'));

        $scanResult = $builder->build($configuration, $referenceIndex, new CatalogIndex());

        self::assertSame(1, $scanResult->missingCount());
        self::assertSame('cards.title', $scanResult->missingTranslations[0]->key->identifier);
    }

    /**
     * @test
     */
    public function filtersReferencesByIdPattern(): void
    {
        $builder = new ScanResultBuilder();
        $configuration = new ScanConfiguration(
            locales: ['en'],
            packageKey: 'Acme.Site',
            sourceName: null,
            idPattern: 'cards.*',
            paths: [],
            format: 'table',
            update: false
        );

        $referenceIndex = new ReferenceIndex();
        $referenceIndex->add($this->reference('Acme.Site', 'Presentation.Cards', 'cards.title'));
        $referenceIndex->add($this->reference('Acme.Site', 'Presentation.Cards', 'node.title'));

        $scanResult = $builder->build($configuration, $referenceIndex, new CatalogIndex());

        self::assertSame(1, $scanResult->missingCount());
        self::assertSame('cards.title', $scanResult->missingTranslations[0]->key->identifier);
    }

    private function reference(string $packageKey, string $sourceName, string $identifier): TranslationReference
    {
        return new TranslationReference(
            packageKey: $packageKey,
            sourceName: $sourceName,
            identifier: $identifier,
            context: TranslationReference::CONTEXT_PHP,
            filePath: '/tmp/example.php',
            lineNumber: 1
        );
    }
}
