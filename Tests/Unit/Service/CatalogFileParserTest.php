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
use Two13Tec\L10nGuy\Exception\CatalogStructureException;
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

    /**
     * @test
     */
    public function throwsWhenGroupNodesPresent(): void
    {
        $filePath = $this->sandboxPath . '/plural.xlf';
        $contents = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file original="" product-name="Test.Package" source-language="en" datatype="plaintext">
    <body>
      <group id="contentcollection.label" restype="x-gettext-plurals">
        <trans-unit id="contentcollection.label[0]" xml:space="preserve">
          <source>One</source>
        </trans-unit>
      </group>
    </body>
  </file>
</xliff>
XML;
        file_put_contents($filePath, $contents);

        $this->expectException(CatalogStructureException::class);
        $this->expectExceptionMessage('group nodes');

        CatalogFileParser::parse($filePath);
    }
}
