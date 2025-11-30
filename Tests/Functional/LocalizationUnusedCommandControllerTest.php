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
use Two13Tec\L10nGuy\Command\L10nCommandController;
use Two13Tec\L10nGuy\Tests\Functional\BufferedConsoleOutput;

final class LocalizationUnusedCommandControllerTest extends SenegalFixtureTestCase
{
    /**
     * @test
     */
    public function unusedCommandReportsEntriesInJson(): void
    {
        [$output, $exitCode] = $this->runUnused([
            'format' => 'json',
        ]);

        self::assertSame(6, $exitCode);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $moreButton = array_values(array_filter(
            $payload['unused'] ?? [],
            static fn (array $row) => $row['id'] === 'cards.moreButton'
        ));
        self::assertNotEmpty($moreButton);
        self::assertSame('Two13Tec.Senegal', $moreButton[0]['package']);
        self::assertArrayHasKey('duplicates', $payload);
        self::assertArrayHasKey('diagnostics', $payload);
    }

    /**
     * @test
     */
    public function unusedCommandDeleteRemovesEntries(): void
    {
        $cardsDe = self::getFixturePackagePath() . '/Resources/Private/Translations/de/Presentation/Cards.xlf';
        $cardsEn = self::getFixturePackagePath() . '/Resources/Private/Translations/en/Presentation/Cards.xlf';
        self::assertStringContainsString('cards.moreButton', file_get_contents($cardsDe));
        self::assertStringContainsString('cards.moreButton', file_get_contents($cardsEn));

        [$output, $exitCode] = $this->runUnused([
            'delete' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Touched catalog', $output);
        self::assertStringNotContainsString('cards.moreButton', file_get_contents($cardsDe));
        self::assertStringNotContainsString('cards.moreButton', file_get_contents($cardsEn));

        [, $exitCodeAfterDelete] = $this->runUnused();
        self::assertSame(0, $exitCodeAfterDelete);
    }

    /**
     * @test
     */
    public function unusedCommandQuietSuppressesTableOutput(): void
    {
        [$output, $exitCode] = $this->runUnused([
            'quiet' => true,
        ]);

        self::assertSame(6, $exitCode);
        self::assertStringContainsString('Prepared unused sweep', $output);
        self::assertStringNotContainsString('Locale \"', $output);
    }

    /**
     * @test
     */
    public function unusedCommandQuieterDisablesStdout(): void
    {
        [$output, $exitCode, $stderr] = $this->runUnused([
            'quieter' => true,
        ]);

        self::assertSame(6, $exitCode);
        self::assertSame('', trim($output));
        self::assertSame('', trim($stderr));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{string, int, string}
     */
    private function runUnused(array $overrides = []): array
    {
        [$command, $buffer, $response] = $this->bootstrapCommand();
        $arguments = array_merge([
            'package' => 'Two13Tec.Senegal',
            'source' => null,
            'path' => static::getFixturePackagePath(),
            'locales' => 'de,en',
            'format' => null,
            'delete' => null,
            'quiet' => null,
            'quieter' => null,
        ], $overrides);

        try {
            $command->unusedCommand(
                $arguments['package'],
                $arguments['source'],
                $arguments['path'],
                $arguments['locales'],
                $arguments['format'],
                $arguments['delete'],
                $arguments['quiet'],
                $arguments['quieter']
            );
        } catch (StopCommandException) {
        }

        return [$buffer->fetch(), $response->getExitCode(), $buffer->fetchErrorOutput()];
    }

    /**
     * @return array{L10nCommandController, BufferedConsoleOutput, CliResponse}
     */
    private function bootstrapCommand(): array
    {
        /** @var L10nCommandController $command */
        $command = $this->objectManager->get(L10nCommandController::class);
        $buffer = new BufferedConsoleOutput();
        $consoleOutput = new ConsoleOutput();
        $consoleOutput->setOutput($buffer);
        $consoleOutput->getOutput()->setErrorOutput($buffer->getErrorOutput());
        $this->setProtectedProperty($command, 'output', $consoleOutput);

        $response = new CliResponse();
        $this->setProtectedProperty($command, 'response', $response);

        return [$command, $buffer, $response];
    }

}
