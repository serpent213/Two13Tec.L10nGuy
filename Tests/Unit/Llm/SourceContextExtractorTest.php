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
use Two13Tec\L10nGuy\Domain\Dto\TranslationReference;
use Two13Tec\L10nGuy\Llm\SourceContextExtractor;

/**
 * @covers \Two13Tec\L10nGuy\Llm\SourceContextExtractor
 */
final class SourceContextExtractorTest extends TestCase
{
    private SourceContextExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new SourceContextExtractor();
    }

    /**
     * @test
     */
    public function extractsSnippetAroundGivenLine(): void
    {
        $reference = new TranslationReference(
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            identifier: 'cards.authorPublishedBy',
            context: TranslationReference::CONTEXT_PHP,
            filePath: $this->fixturePath('Classes/Presentation/CardComponent.php'),
            lineNumber: 28,
        );

        $snippet = $this->extractor->extract($reference, 2);

        $expected = implode("\n", [
            '    public function render(string $authorName): string',
            '    {',
            '        $cta = I18n::translate(',
            "            'cards.authorPublishedBy',",
            "            'Published by {authorName}',",
        ]);

        self::assertSame($expected, $snippet);
    }

    /**
     * @test
     */
    public function returnsNullWhenFileIsMissing(): void
    {
        $reference = new TranslationReference(
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            identifier: 'cards.authorPublishedBy',
            context: TranslationReference::CONTEXT_PHP,
            filePath: '/does/not/exist.php',
            lineNumber: 10,
        );

        self::assertNull($this->extractor->extract($reference, 3));
    }

    private function fixturePath(string $relative): string
    {
        return FLOW_PATH_ROOT . 'DistributionPackages/Two13Tec.L10nGuy/Tests/Fixtures/SenegalBaseline/' . $relative;
    }
}
