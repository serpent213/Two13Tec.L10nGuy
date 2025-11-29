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
use Two13Tec\L10nGuy\Domain\Dto\ScanConfiguration;
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
            paths: [$this->sandboxPath],
            format: 'table',
            dryRun: false,
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

        self::assertStringContainsString('<trans-unit id="cards.publishedDate" xml:space="preserve">' . PHP_EOL . '        <source>Published on {publishedDate}</source>' . PHP_EOL . '      </trans-unit>', $englishCatalog);
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
    public function dryRunLeavesCatalogsUntouched(): void
    {
        $writer = new CatalogWriter();
        $configuration = new ScanConfiguration(
            locales: ['de'],
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            paths: [$this->sandboxPath],
            format: 'table',
            dryRun: true,
            update: true
        );

        $catalogIndex = $this->createCatalogIndex();
        $mutation = new CatalogMutation(
            locale: 'de',
            packageKey: 'Two13Tec.Senegal',
            sourceName: 'Presentation.Cards',
            identifier: 'cards.additional',
            fallback: 'Zusätzlicher Text'
        );

        $before = md5_file($this->sandboxPath . '/Resources/Private/Translations/de/Presentation/Cards.xlf');
        $touched = $writer->write([$mutation], $catalogIndex, $configuration, $this->sandboxPath);
        $after = md5_file($this->sandboxPath . '/Resources/Private/Translations/de/Presentation/Cards.xlf');

        self::assertSame($before, $after);
        self::assertSame([$this->sandboxPath . '/Resources/Private/Translations/de/Presentation/Cards.xlf'], $touched);
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
