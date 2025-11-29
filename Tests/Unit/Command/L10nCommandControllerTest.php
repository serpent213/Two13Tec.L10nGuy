<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Unit\Command;

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
use Two13Tec\L10nGuy\Command\L10nCommandController;
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\MissingTranslation;
use Two13Tec\L10nGuy\Domain\Dto\ReferenceIndex;
use Two13Tec\L10nGuy\Domain\Dto\ScanResult;
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;

/**
 * @covers \Two13Tec\L10nGuy\Command\L10nCommandController
 */
final class L10nCommandControllerTest extends TestCase
{
    /**
     * @test
     */
    public function appendsPlaceholderHintsWhenFallbackMissing(): void
    {
        $controller = new L10nCommandController();
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
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Main',
            identifier: 'cards.title',
            reference: $reference
        );
        $scanResult = new ScanResult(
            missingTranslations: [$missing],
            placeholderMismatches: [],
            referenceIndex: new ReferenceIndex(),
            catalogIndex: new CatalogIndex()
        );

        $method = new \ReflectionMethod($controller, 'buildMutations');
        $method->setAccessible(true);
        /** @var list<\Two13Tec\L10nGuy\Domain\Dto\CatalogMutation> $mutations */
        $mutations = $method->invoke($controller, $scanResult);

        self::assertCount(1, $mutations);
        self::assertSame('cards.title {first} {second}', $mutations[0]->fallback);
    }
}
