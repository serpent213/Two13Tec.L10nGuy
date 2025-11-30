<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Unit\Llm;

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
use Psr\Log\LoggerInterface;
use Two13Tec\L10nGuy\Llm\PlaceholderValidator;

/**
 * @covers \Two13Tec\L10nGuy\Llm\PlaceholderValidator
 */
final class PlaceholderValidatorTest extends TestCase
{
    /**
     * @test
     */
    public function acceptsTranslationsWithAllPlaceholders(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $validator = new PlaceholderValidator();
        $validator->setLogger($logger);

        self::assertTrue(
            $validator->validate(
                'cards.title',
                'de',
                'Titel {name} {count}',
                ['name', 'count']
            )
        );
    }

    /**
     * @test
     */
    public function warnsWhenPlaceholdersAreMissing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('missing placeholders'),
                self::arrayHasKey('missing')
            );

        $validator = new PlaceholderValidator();
        $validator->setLogger($logger);

        self::assertFalse(
            $validator->validate(
                'cards.subtitle',
                'fr',
                'Sous-titre',
                ['name', 'count']
            )
        );
    }
}
