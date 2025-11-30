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
use Two13Tec\L10nGuy\Domain\Dto\CatalogIndex;
use Two13Tec\L10nGuy\Domain\Dto\CatalogMutation;
use Two13Tec\L10nGuy\Domain\Dto\LlmConfiguration;
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
use Two13Tec\L10nGuy\Exception\CatalogFileParserException;
use Two13Tec\L10nGuy\Service\CatalogFileParser;
use Two13Tec\L10nGuy\Service\CatalogWriter;

/**
 * @covers \Two13Tec\L10nGuy\Service\CatalogWriter
 */
final class CatalogWriterTest extends TestCase
{
    private string $sandboxPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandboxPath = Files::concatenatePaths([
            sys_get_temp_dir(),
            'l10nguy_' . bin2hex(random_bytes(6)),
        ]);
        Files::createDirectoryRecursively($this->sandboxPath . '/Resources/Private/Translations/en/Presentation');
        Files::createDirectoryRecursively($this->sandboxPath . '/Resources/Private/Translations/de/Presentation');

        copy(
            $this->fixturePath('en/Presentation/Cards.xlf'),
            $this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Cards.xlf'
        );
        copy(
            $this->fixturePath('de/Presentation/Cards.xlf'),
            $this->sandboxPath . '/Resources/Private/Translations/de/Presentation/Cards.xlf'
        );
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
    public function writesDeterministicXmlAndCopiesFallbacks(): void
    {
        $writer = new CatalogWriter();
        $configuration = new ScanConfiguration(
            locales: ['de', 'en'],
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            idPattern: null,
            paths: [$this->sandboxPath],
            format: 'table',
            update: true
        );

        $catalogIndex = $this->createCatalogIndex();
        $mutations = [
            new CatalogMutation(
                locale: 'en',
                packageKey: 'Two13Tec.Senegal',
                sourceName: 'Presentation.Cards',
                identifier: 'cards.publishedDate',
                fallback: 'Published on {publishedDate}'
            ),
            new CatalogMutation(
                locale: 'de',
                packageKey: 'Two13Tec.Senegal',
                sourceName: 'Presentation.Cards',
                identifier: 'cards.publishedDate',
                fallback: 'Veröffentlicht am {publishedDate}'
            ),
        ];

        $touched = $writer->write($mutations, $catalogIndex, $configuration, $this->sandboxPath);
        self::assertCount(2, $touched);

        $englishCatalog = file_get_contents($this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Cards.xlf');
        $germanCatalog = file_get_contents($this->sandboxPath . '/Resources/Private/Translations/de/Presentation/Cards.xlf');

        self::assertStringContainsString('<trans-unit id="cards.publishedDate" xml:space="preserve">' . PHP_EOL . '        <source state="needs-review">Published on {publishedDate}</source>' . PHP_EOL . '      </trans-unit>', $englishCatalog);
        self::assertStringContainsString('<target state="needs-review">Veröffentlicht am {publishedDate}</target>', $germanCatalog);
        self::assertLessThan(
            strpos($germanCatalog, '</file>'),
            strpos($germanCatalog, 'cards.publishedDate'),
            'New entry should be rendered before file closes.'
        );
    }

    /**
     * @test
     */
    public function needsReviewFlagCanBeDisabled(): void
    {
        $writer = new CatalogWriter();
        $configuration = new ScanConfiguration(
            locales: ['de', 'en'],
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            idPattern: null,
            paths: [$this->sandboxPath],
            format: 'table',
            update: true,
            setNeedsReview: false
        );

        $catalogIndex = $this->createCatalogIndex();
        $mutations = [
            new CatalogMutation(
                locale: 'en',
                packageKey: 'Two13Tec.Senegal',
                sourceName: 'Presentation.Cards',
                identifier: 'cards.noState',
                fallback: 'Review me'
            ),
            new CatalogMutation(
                locale: 'de',
                packageKey: 'Two13Tec.Senegal',
                sourceName: 'Presentation.Cards',
                identifier: 'cards.noState',
                fallback: 'Bitte prüfen'
            ),
        ];

        $writer->write($mutations, $catalogIndex, $configuration, $this->sandboxPath);

        $englishCatalog = (string)file_get_contents($this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Cards.xlf');
        $germanCatalog = (string)file_get_contents($this->sandboxPath . '/Resources/Private/Translations/de/Presentation/Cards.xlf');

        self::assertStringContainsString('<source>Review me</source>', $englishCatalog);
        self::assertStringNotContainsString('<source state="needs-review">Review me</source>', $englishCatalog);
        self::assertStringContainsString('<target>Bitte prüfen</target>', $germanCatalog);
        self::assertStringNotContainsString('<target state="needs-review">Bitte prüfen</target>', $germanCatalog);
    }

    /**
     * @test
     */
    public function writesLlmMetadataAndDefaultState(): void
    {
        $writer = new CatalogWriter();
        $configuration = new ScanConfiguration(
            locales: ['de'],
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            idPattern: null,
            paths: [$this->sandboxPath],
            format: 'table',
            update: true,
            setNeedsReview: false,
            llm: new LlmConfiguration(
                enabled: true,
                provider: 'openai',
                model: 'gpt-4o-mini',
                markAsGenerated: true,
                defaultState: 'needs-review'
            )
        );

        $catalogIndex = $this->createCatalogIndex();
        $mutation = new CatalogMutation(
            locale: 'de',
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            identifier: 'cards.llmGenerated',
            fallback: 'Generated label'
        );
        $mutation->target = 'Erzeugt';
        $mutation->isLlmGenerated = true;
        $mutation->llmProvider = 'openai';
        $mutation->llmModel = 'gpt-4o-mini';
        $mutation->llmGeneratedAt = new \DateTimeImmutable('2024-01-02T03:04:05+00:00');

        $writer->write([$mutation], $catalogIndex, $configuration, $this->sandboxPath);

        $contents = (string)file_get_contents($this->sandboxPath . '/Resources/Private/Translations/de/Presentation/Cards.xlf');

        self::assertStringContainsString('<target state="needs-review">Erzeugt</target>', $contents);
        self::assertStringContainsString('<note from="l10nguy" priority="1">llm-generated</note>', $contents);
        self::assertStringContainsString(
            '<note from="l10nguy">provider:openai model:gpt-4o-mini generated:2024-01-02T03:04:05+00:00</note>',
            $contents
        );
    }

    /**
     * @test
     */
    public function writesPluralMutationsIntoGroup(): void
    {
        $writer = new CatalogWriter();
        $configuration = new ScanConfiguration(
            locales: ['en'],
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            idPattern: null,
            paths: [$this->sandboxPath],
            format: 'table',
            update: true
        );

        $catalogIndex = $this->createCatalogIndex();
        $mutations = [
            new CatalogMutation(
                locale: 'en',
                packageKey: 'Two13Tec.Senegal',
                sourceName: 'Presentation.Cards',
                identifier: 'cards.plural[0]',
                fallback: 'One card'
            ),
            new CatalogMutation(
                locale: 'en',
                packageKey: 'Two13Tec.Senegal',
                sourceName: 'Presentation.Cards',
                identifier: 'cards.plural[1]',
                fallback: '{count} cards'
            ),
        ];

        $writer->write($mutations, $catalogIndex, $configuration, $this->sandboxPath);

        $contents = (string)file_get_contents($this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Cards.xlf');
        self::assertStringContainsString('<group id="cards.plural" restype="x-gettext-plurals">', $contents);
        self::assertStringContainsString('<trans-unit id="cards.plural[0]" xml:space="preserve">', $contents);
        self::assertStringContainsString('<trans-unit id="cards.plural[1]" xml:space="preserve">', $contents);
        self::assertLessThan(
            strpos($contents, '<trans-unit id="cards.plural[1]"'),
            strpos($contents, '<trans-unit id="cards.plural[0]"')
        );
    }

    /**
     * @test
     */
    public function customTabWidthControlsIndentation(): void
    {
        $writer = new CatalogWriter(tabWidth: 4);
        $filePath = $this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Custom.xlf';
        $metadata = [
            'productName' => 'Custom',
            'sourceLanguage' => 'en',
            'targetLanguage' => 'de',
            'original' => '',
            'datatype' => 'plaintext',
        ];
        $units = [
            'example.identifier' => [
                'source' => 'Example',
                'target' => 'Beispiel',
                'state' => null,
            ],
        ];

        $writer->reformatCatalog($filePath, $metadata, $units, 'Two13Tec.Senegal', 'de', true);

        $contents = (string)file_get_contents($filePath);
        self::assertStringContainsString(PHP_EOL . '        <body>' . PHP_EOL, $contents);
        self::assertStringContainsString(PHP_EOL . '                <source>Example</source>' . PHP_EOL, $contents);
    }

    /**
     * @test
     */
    public function orderByIdFlagDisablesSorting(): void
    {
        $writer = new CatalogWriter(orderById: false);
        $filePath = $this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Order.xlf';
        $metadata = [
            'productName' => 'Order',
            'sourceLanguage' => 'en',
            'targetLanguage' => null,
            'original' => '',
            'datatype' => 'plaintext',
        ];
        $units = [
            'z.last' => [
                'source' => 'Z',
                'target' => null,
                'state' => null,
            ],
            'a.first' => [
                'source' => 'A',
                'target' => null,
                'state' => null,
            ],
        ];

        $writer->reformatCatalog($filePath, $metadata, $units, 'Two13Tec.Senegal', 'en', true);

        $contents = (string)file_get_contents($filePath);
        self::assertMatchesRegularExpression(
            '/<trans-unit id="z\\.last"[^>]*>.*<trans-unit id="a\\.first"/s',
            $contents
        );
    }

    /**
     * @test
     */
    public function appendsNewEntriesSortedWhenOrderByIdDisabled(): void
    {
        $writer = new CatalogWriter(orderById: false);
        $configuration = new ScanConfiguration(
            locales: ['en'],
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            idPattern: null,
            paths: [$this->sandboxPath],
            format: 'table',
            update: true
        );

        $catalogIndex = $this->createCatalogIndex();
        $mutations = [
            new CatalogMutation(
                locale: 'en',
                packageKey: 'Two13Tec.Senegal',
                sourceName: 'Presentation.Cards',
                identifier: 'cards.zeta',
                fallback: 'Zeta'
            ),
            new CatalogMutation(
                locale: 'en',
                packageKey: 'Two13Tec.Senegal',
                sourceName: 'Presentation.Cards',
                identifier: 'cards.alpha',
                fallback: 'Alpha'
            ),
        ];

        $writer->write($mutations, $catalogIndex, $configuration, $this->sandboxPath);

        $contents = (string)file_get_contents($this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Cards.xlf');
        $alphaPos = strpos($contents, 'cards.alpha');
        $zetaPos = strpos($contents, 'cards.zeta');
        $placeholderPos = strpos($contents, 'cards.placeholderWarning');

        self::assertNotFalse($alphaPos);
        self::assertNotFalse($zetaPos);
        self::assertNotFalse($placeholderPos);
        self::assertLessThan($zetaPos, $alphaPos);
        self::assertLessThan($alphaPos, $placeholderPos);
    }

    /**
     * @test
     */
    public function orderByIdSortingOverridesExistingBodyOrder(): void
    {
        $writer = new CatalogWriter(orderById: true);
        $filePath = $this->sandboxPath . '/Resources/Private/Translations/en/Presentation/BodyOrder.xlf';
        $metadata = [
            'productName' => 'Order',
            'sourceLanguage' => 'en',
            'targetLanguage' => null,
            'original' => '',
            'datatype' => 'plaintext',
        ];
        $units = [
            'z.last' => [
                'source' => 'Z',
                'target' => null,
                'state' => null,
            ],
            'a.first' => [
                'source' => 'A',
                'target' => null,
                'state' => null,
            ],
        ];
        $structure = [
            'bodyOrder' => [
                ['type' => 'trans-unit', 'identifier' => 'z.last'],
                ['type' => 'trans-unit', 'identifier' => 'a.first'],
            ],
        ];

        $writer->reformatCatalog($filePath, $metadata, $units, 'Two13Tec.Senegal', 'en', true, $structure);

        $contents = (string)file_get_contents($filePath);
        self::assertMatchesRegularExpression(
            '/<trans-unit id="a\\.first"[^>]*>.*<trans-unit id="z\\.last"/s',
            $contents
        );
    }

    /**
     * @test
     */
    public function preservesUnicodePlaceholderNames(): void
    {
        $writer = new CatalogWriter();
        $configuration = new ScanConfiguration(
            locales: ['en'],
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            idPattern: null,
            paths: [$this->sandboxPath],
            format: 'table',
            update: true
        );

        $catalogIndex = $this->createCatalogIndex();
        $mutation = new CatalogMutation(
            locale: 'en',
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            identifier: 'cards.unicode',
            fallback: 'Grüße {会議時間}'
        );

        $writer->write([$mutation], $catalogIndex, $configuration, $this->sandboxPath);

        $contents = (string)file_get_contents($this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Cards.xlf');
        self::assertStringContainsString('<source state="needs-review">Grüße {会議時間}</source>', $contents);
    }

    /**
     * @test
     */
    public function abortsWhenCatalogCannotBeParsed(): void
    {
        $writer = new CatalogWriter();
        $configuration = new ScanConfiguration(
            locales: ['en'],
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            idPattern: null,
            paths: [$this->sandboxPath],
            format: 'table',
            update: true
        );

        $catalogIndex = $this->createCatalogIndex();
        $filePath = $this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Cards.xlf';
        file_put_contents($filePath, '<xliff><broken>');
        $mutation = new CatalogMutation(
            locale: 'en',
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            identifier: 'cards.invalid',
            fallback: 'Invalid'
        );

        $this->expectException(CatalogFileParserException::class);

        $writer->write([$mutation], $catalogIndex, $configuration, $this->sandboxPath);
    }

    /**
     * @test
     */
    public function throwsWhenWritingToReadOnlyCatalog(): void
    {
        $writer = new CatalogWriter();
        $filePath = $this->sandboxPath . '/Resources/Private/Translations/en/Presentation/ReadOnly.xlf';
        $metadata = [
            'productName' => 'ReadOnly',
            'sourceLanguage' => 'en',
            'targetLanguage' => 'de',
            'original' => '',
            'datatype' => 'plaintext',
        ];
        $units = [
            'read.only' => [
                'source' => 'Foo',
                'target' => 'Bar',
                'state' => null,
            ],
        ];
        Files::createDirectoryRecursively(dirname($filePath));
        file_put_contents($filePath, '<dummy/>');
        chmod($filePath, 0444);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to write catalog file');

        try {
            $writer->reformatCatalog($filePath, $metadata, $units, 'Two13Tec.Senegal', 'de', true);
        } finally {
            chmod($filePath, 0644);
        }
    }

    /**
     * @test
     */
    public function reformatKeepsUnknownElementsAndAttributes(): void
    {
        $writer = new CatalogWriter();
        $filePath = $this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Unknown.xlf';
        $contents = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file original="" product-name="Test.Package" source-language="en" datatype="plaintext" custom-file="keep">
    <header><meta keep="yes"/></header>
    <body>
      <group id="plural" custom="true">
        <trans-unit id="plural[0]" xml:space="preserve">
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
        Files::createDirectoryRecursively(dirname($filePath));
        file_put_contents($filePath, $contents);

        $parsed = CatalogFileParser::parse($filePath);
        $writer->reformatCatalog(
            $filePath,
            $parsed['meta'],
            $parsed['units'],
            'Two13Tec.Senegal',
            'en',
            true,
            [
                'fileAttributes' => $parsed['fileAttributes'],
                'fileChildren' => $parsed['fileChildren'],
                'bodyOrder' => $parsed['bodyOrder'],
            ]
        );

        $output = (string)file_get_contents($filePath);
        self::assertStringContainsString('custom-file="keep"', $output);
        self::assertStringContainsString('<header>', $output);
        self::assertStringContainsString('<meta keep="yes"/>', $output);
        self::assertStringContainsString('<group custom="true" id="plural">', $output);
        self::assertStringContainsString('<note priority="1">Meta</note>', $output);
        self::assertStringContainsString('<target state="translated" reviewer="demo">Text</target>', $output);
        self::assertStringContainsString(
            PHP_EOL
            . '      <group custom="true" id="plural">' . PHP_EOL
            . '        <trans-unit id="plural[0]" xml:space="preserve">' . PHP_EOL
            . '          <source>One</source>' . PHP_EOL
            . '        </trans-unit>' . PHP_EOL
            . '      </group>' . PHP_EOL,
            $output
        );
        self::assertLessThan(
            strpos($output, '<trans-unit id="simple"'),
            strpos($output, '<group id="plural"'),
            'Unknown group should be emitted before sorted trans-units.'
        );
    }

    private function createCatalogIndex(): CatalogIndex
    {
        $index = new CatalogIndex();
        $enPath = $this->sandboxPath . '/Resources/Private/Translations/en/Presentation/Cards.xlf';
        $dePath = $this->sandboxPath . '/Resources/Private/Translations/de/Presentation/Cards.xlf';
        $enParsed = CatalogFileParser::parse($enPath);
        $deParsed = CatalogFileParser::parse($dePath);

        $index->registerCatalogFile('en', 'Two13Tec.Senegal', 'Presentation.Cards', $enPath, $enParsed['meta']);
        $index->registerCatalogFile('de', 'Two13Tec.Senegal', 'Presentation.Cards', $dePath, $deParsed['meta']);

        return $index;
    }

    private function fixturePath(string $suffix): string
    {
        return FLOW_PATH_ROOT . 'DistributionPackages/Two13Tec.L10nGuy/Tests/Fixtures/SenegalBaseline/Resources/Private/Translations/' . $suffix;
    }
}
