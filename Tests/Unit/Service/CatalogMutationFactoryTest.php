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
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\ReferenceIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;
use Two13Tec\L10nGuy\Domain\Dto\TranslationKey;
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;
use Two13Tec\L10nGuy\Service\CatalogMutationFactory;

/**
 * @covers \Two13Tec\L10nGuy\Service\CatalogMutationFactory
 */
final class CatalogMutationFactoryTest extends TestCase
{
    /**
     * @test
     */
    public function addsPlaceholderHintsWhenFallbackMissing(): void
    {
        $factory = new CatalogMutationFactory();
        $reference = new TranslationReference(
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Main',
            identifier: 'cards.title',
            context: TranslationReference::CONTEXT_PHP,
            filePath: '/tmp/source.php',
            lineNumber: 10,
            fallback: null,
            placeholders: [
                'first' => '$first',
                'second' => '$second',
            ]
        );
        $missing = new MissingTranslation(
            locale: 'en',
            key: new TranslationKey('Two13Tec.Senegal', 'Main', 'cards.title'),
            reference: $reference
        );
        $scanResult = new ScanResult(
            missingTranslations: [$missing],
            placeholderMismatches: [],
            referenceIndex: new ReferenceIndex(),
            catalogIndex: new CatalogIndex()
        );

        $mutations = $factory->fromScanResult($scanResult);

        self::assertCount(1, $mutations);
        self::assertSame('cards.title {first} {second}', $mutations[0]->fallback);
    }
}
