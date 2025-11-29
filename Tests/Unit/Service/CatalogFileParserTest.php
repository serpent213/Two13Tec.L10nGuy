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

use Neos\Utility\Files;
use PHPUnit\Framework\TestCase;
use Two13Tec\L10nGuy\Exception\CatalogFileParserException;
use Two13Tec\L10nGuy\Service\CatalogFileParser;

/**
 * @covers \Two13Tec\L10nGuy\Service\CatalogFileParser
 */
final class CatalogFileParserTest extends TestCase
{
    private string $sandboxPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandboxPath = Files::concatenatePaths([
            sys_get_temp_dir(),
            'l10nguy_parser_' . bin2hex(random_bytes(6)),
        ]);
        Files::createDirectoryRecursively($this->sandboxPath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->sandboxPath)) {
            Files::removeDirectoryRecursively($this->sandboxPath);
        }
    }

    /**
     * @test
     */
    public function returnsDefaultsWhenFileMissing(): void
    {
        $result = CatalogFileParser::parse($this->sandboxPath . '/missing.xlf');

        self::assertSame(
            [
                'meta' => [
                    'productName' => null,
                    'sourceLanguage' => null,
                    'targetLanguage' => null,
                    'original' => null,
                    'datatype' => null,
                ],
                'units' => [],
            ],
            $result
        );
    }

    /**
     * @test
     */
    public function throwsWhenFileUnreadable(): void
    {
        $filePath = $this->sandboxPath . '/unreadable.xlf';
        file_put_contents($filePath, '<xliff></xliff>');
        chmod($filePath, 0);

        $this->expectException(CatalogFileParserException::class);
        $this->expectExceptionMessage(sprintf('Unable to read catalog file "%s".', $filePath));

        try {
            CatalogFileParser::parse($filePath);
        } finally {
            chmod($filePath, 0644);
        }
    }

    /**
     * @test
     */
    public function throwsWhenFileEmpty(): void
    {
        $filePath = $this->sandboxPath . '/empty.xlf';
        file_put_contents($filePath, '');

        $this->expectException(CatalogFileParserException::class);
        $this->expectExceptionMessage(sprintf('Catalog file "%s" is empty.', $filePath));

        CatalogFileParser::parse($filePath);
    }

    /**
     * @test
     */
    public function throwsWhenXmlIsMalformed(): void
    {
        $filePath = $this->sandboxPath . '/invalid.xlf';
        file_put_contents($filePath, '<xliff><file><body><trans-unit id="foo"><source>Missing closing tags');

        $this->expectException(CatalogFileParserException::class);
        $this->expectExceptionMessage('contains malformed XML');

        CatalogFileParser::parse($filePath);
    }
}
