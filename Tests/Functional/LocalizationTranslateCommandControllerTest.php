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

final class LocalizationTranslateCommandControllerTest extends SenegalFixtureTestCase
{
    /**
     * @test
     */
    public function translateCommandRunsDryRunWithoutWritingCatalogs(): void
    {
        $targetCatalog = self::getFixturePackagePath() . '/Resources/Private/Translations/fr/Presentation/Cards.xlf';
        self::assertFileDoesNotExist($targetCatalog);

        [$output, $exitCode] = $this->runTranslate([
            'to' => 'fr',
            'from' => 'en',
            'path' => static::getFixturePackagePath(),
            'dryRun' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('from en to fr', $output);
        self::assertFileDoesNotExist($targetCatalog);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{string, int, string}
     */
    private function runTranslate(array $overrides = []): array
    {
        [$command, $buffer, $response] = $this->bootstrapCommand();
        $arguments = array_merge([
            'to' => 'fr',
            'from' => null,
            'package' => 'Two13Tec.Senegal',
            'source' => null,
            'id' => null,
            'path' => static::getFixturePackagePath(),
            'llmProvider' => null,
            'llmModel' => null,
            'dryRun' => null,
            'batchSize' => null,
            'quiet' => null,
            'quieter' => null,
        ], $overrides);

        ob_start();
        try {
            $command->translateCommand(
                $arguments['to'],
                $arguments['from'],
                $arguments['package'],
                $arguments['source'],
                $arguments['id'],
                $arguments['path'],
                $arguments['llmProvider'],
                $arguments['llmModel'],
                $arguments['dryRun'],
                $arguments['batchSize'],
                $arguments['quiet'],
                $arguments['quieter']
            );
        } catch (StopCommandException) {
        } finally {
            $directOutput = ob_get_clean() ?: '';
        }

        return [$buffer->fetch() . ($directOutput ?? ''), $response->getExitCode(), $buffer->fetchErrorOutput()];
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
