<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Functional;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Cli\ConsoleOutput;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Cli\Response as CliResponse;
use Symfony\Component\Console\Output\BufferedOutput;
use Two13Tec\L10nGuy\Command\L10nCommandController;

final class LocalizationFormatCommandControllerTest extends SenegalFixtureTestCase
{
    /**
     * @test
     */
    public function formatCommandRewritesCatalogs(): void
    {
        $cardsEn = self::getFixturePackagePath() . '/Resources/Private/Translations/en/Presentation/Cards.xlf';
        $originalContents = (string)file_get_contents($cardsEn);
        $mutatedContents = str_replace('    <body>', '  <body>', $originalContents, $replacements);
        self::assertGreaterThan(0, $replacements, 'Fixture formatting should be mutated for the test.');
        file_put_contents($cardsEn, $mutatedContents);

        [$output, $exitCode] = $this->runFormat();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Formatted catalog', $output);
        self::assertSame($originalContents, file_get_contents($cardsEn));
    }

    /**
     * @test
     */
    public function formatCommandCheckSignalsDirtyCatalogs(): void
    {
        $cardsEn = self::getFixturePackagePath() . '/Resources/Private/Translations/en/Presentation/Cards.xlf';
        $originalContents = (string)file_get_contents($cardsEn);
        $mutatedContents = rtrim($originalContents);
        self::assertNotSame($originalContents, $mutatedContents, 'Fixture must differ to simulate drift.');
        file_put_contents($cardsEn, $mutatedContents);

        [$output, $exitCode] = $this->runFormat([
            'check' => true,
        ]);

        self::assertSame(8, $exitCode);
        self::assertStringContainsString('requires formatting', $output);
        self::assertSame($mutatedContents, file_get_contents($cardsEn), 'Check mode must not rewrite catalogs.');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{string, int}
     */
    private function runFormat(array $overrides = []): array
    {
        [$command, $buffer, $response] = $this->bootstrapCommand();
        $arguments = array_merge([
            'package' => 'Two13Tec.Senegal',
            'source' => null,
            'path' => static::getFixturePackagePath(),
            'locales' => 'de,en',
            'check' => null,
        ], $overrides);

        try {
            $command->formatCommand(
                $arguments['package'],
                $arguments['source'],
                $arguments['path'],
                $arguments['locales'],
                $arguments['check']
            );
        } catch (StopCommandException) {
        }

        return [$buffer->fetch(), $response->getExitCode()];
    }

    /**
     * @return array{L10nCommandController, BufferedOutput, CliResponse}
     */
    private function bootstrapCommand(): array
    {
        /** @var L10nCommandController $command */
        $command = $this->objectManager->get(L10nCommandController::class);
        $buffer = new BufferedOutput();
        $consoleOutput = new ConsoleOutput();
        $consoleOutput->setOutput($buffer);
        $this->setProtectedProperty($command, 'output', $consoleOutput);

        $response = new CliResponse();
        $this->setProtectedProperty($command, 'response', $response);

        return [$command, $buffer, $response];
    }
}
