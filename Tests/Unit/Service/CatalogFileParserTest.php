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
                'fileAttributes' => [],
                'fileChildren' => [],
                'bodyOrder' => [],
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
    public function preservesUnknownElementsAndAttributes(): void
    {
        $filePath = $this->sandboxPath . '/plural.xlf';
        $contents = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file original="" product-name="Test.Package" source-language="en" datatype="plaintext" extra-file="keep">
    <header>
      <phase-group custom="true"><phase/></phase-group>
    </header>
    <body>
      <group id="contentcollection.label" restype="x-gettext-plurals">
        <trans-unit id="contentcollection.label[0]" xml:space="preserve">
          <source>One</source>
        </trans-unit>
      </group>
      <trans-unit id="simple" xml:space="preserve" data-foo="bar">
        <source xml:lang="en" suffix="!">Text</source>
        <note priority="1">Meta</note>
        <target state="translated" reviewer="demo">Text</target>
      </trans-unit>
    </body>
  </file>
</xliff>
XML;
        file_put_contents($filePath, $contents);

        $parsed = CatalogFileParser::parse($filePath);

        self::assertSame(['extra-file' => 'keep'], $parsed['fileAttributes']);
        self::assertCount(1, $parsed['fileChildren']);
        self::assertStringContainsString('<phase-group custom="true"><phase/></phase-group>', $parsed['fileChildren'][0]);
        self::assertCount(2, $parsed['bodyOrder']);
        self::assertSame('unknown', $parsed['bodyOrder'][0]['type']);
        self::assertSame('trans-unit', $parsed['bodyOrder'][1]['type']);
        self::assertSame(['data-foo' => 'bar'], $parsed['units']['simple']['attributes']);
        self::assertSame(['suffix' => '!', 'xml:lang' => 'en'], $parsed['units']['simple']['sourceAttributes']);
        self::assertSame(['reviewer' => 'demo'], $parsed['units']['simple']['targetAttributes']);
        self::assertSame(
            [
                ['type' => 'source'],
                ['type' => 'unknown', 'xml' => '<note priority="1">Meta</note>'],
                ['type' => 'target'],
            ],
            $parsed['units']['simple']['children']
        );
    }
}
