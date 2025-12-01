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
use Neos\Flow\Cli\Request as CliRequest;
use Neos\Flow\Cli\Response as CliResponse;
use Neos\Flow\Command\HelpCommandController;
use Two13Tec\L10nGuy\Llm\LlmLibraryAvailability;

final class CommandHelpOutputTest extends SenegalFixtureTestCase
{
    private LlmLibraryAvailability $llmLibraryAvailability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmLibraryAvailability = $this->objectManager->get(LlmLibraryAvailability::class);
        $this->llmLibraryAvailability->forceAvailability(null);
    }

    protected function tearDown(): void
    {
        $this->llmLibraryAvailability->forceAvailability(null);
        parent::tearDown();
    }

    /**
     * @test
     */
    public function helpHidesLlmOptionsWhenLibraryMissing(): void
    {
        $this->llmLibraryAvailability->forceAvailability(false);

        $output = $this->renderHelpForCommand('l10n:scan');

        self::assertStringNotContainsString('--llm', $output);
        self::assertStringNotContainsString('--llm-provider', $output);
        self::assertStringNotContainsString('--llm-model', $output);
    }

    /**
     * @test
     */
    public function helpShowsLlmOptionsWhenLibraryAvailable(): void
    {
        $this->llmLibraryAvailability->forceAvailability(true);

        $output = $this->renderHelpForCommand('l10n:scan');

        self::assertStringContainsString('--llm', $output);
        self::assertStringContainsString('--llm-provider', $output);
        self::assertStringContainsString('--llm-model', $output);
    }

    private function renderHelpForCommand(string $commandIdentifier): string
    {
        /** @var HelpCommandController $help */
        $help = $this->objectManager->get(HelpCommandController::class);
        $buffer = new BufferedConsoleOutput();
        $consoleOutput = new ConsoleOutput();
        $consoleOutput->setOutput($buffer);
        $this->setProtectedProperty($help, 'output', $consoleOutput);

        $response = new CliResponse();
        $this->setProtectedProperty($help, 'response', $response);

        $request = new CliRequest();
        $request->setControllerObjectName(HelpCommandController::class);
        $request->setControllerCommandName('help');
        $this->setProtectedProperty($help, 'request', $request);

        $help->helpCommand($commandIdentifier);

        return $buffer->fetch();
    }
}
