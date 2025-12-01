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

final class LocalizationScanCommandControllerTest extends SenegalFixtureTestCase
{
    /**
     * @test
     */
    public function scanCommandReportsMissingEntriesAcrossLocales(): void
    {
        [$output, $exitCode] = $this->runScan();

        self::assertSame(5, $exitCode, 'Missing entries should trigger exit code 5.');
        self::assertStringContainsString('cards.authorPublishedBy', $output);
        self::assertStringContainsString('Locale', $output);
        self::assertStringContainsString('Two13Tec.Senegal', $output);
    }

    /**
     * @test
     */
    public function scanCommandHonorsLocaleFilterAndJsonFormat(): void
    {
        [$output, $exitCode] = $this->runScan([
            'locales' => 'en',
            'format' => 'json',
        ]);

        self::assertSame(5, $exitCode);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $authorMissing = array_values(array_filter($payload['missing'], static fn(array $row) => $row['id'] === 'cards.authorPublishedBy'));
        self::assertCount(1, $authorMissing);
        self::assertSame('en', $authorMissing[0]['locale']);
        self::assertArrayHasKey('warnings', $payload);
        self::assertIsArray($payload['warnings']);
    }

    /**
     * @test
     */
    public function scanCommandIndexesNodeTypeYamlLabels(): void
    {
        [$output, $exitCode] = $this->runScan([
            'format' => 'json',
        ]);

        self::assertSame(5, $exitCode);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $contactFormLabels = array_values(array_filter(
            $payload['missing'] ?? [],
            static fn(array $row): bool => $row['source'] === 'NodeTypes.Content.ContactForm'
                && $row['id'] === 'properties.subject'
        ));

        self::assertCount(2, $contactFormLabels);
        $locales = array_values(array_unique(array_column($contactFormLabels, 'locale')));
        sort($locales);
        self::assertSame(['de', 'en'], $locales);
    }

    /**
     * @test
     */
    public function scanCommandEmitsPlaceholderWarning(): void
    {
        [$output] = $this->runScan();

        self::assertStringContainsString('Placeholder warnings', $output);
        self::assertStringContainsString('cards.placeholderWarning', $output);
    }

    /**
     * @test
     */
    public function scanCommandCanSuppressPlaceholderWarnings(): void
    {
        [$output] = $this->runScan([
            'ignorePlaceholder' => true,
        ]);

        self::assertStringNotContainsString('Placeholder warnings', $output);
    }

    /**
     * @test
     */
    public function scanCommandUpdateWritesMissingEntries(): void
    {
        $cardsDe = self::getFixturePackagePath() . '/Resources/Private/Translations/de/Presentation/Cards.xlf';
        $cardsEn = self::getFixturePackagePath() . '/Resources/Private/Translations/en/Presentation/Cards.xlf';
        self::assertStringNotContainsString('cards.authorPublishedBy', file_get_contents($cardsDe));
        self::assertStringNotContainsString('cards.authorPublishedBy', file_get_contents($cardsEn));

        [$output, $exitCode] = $this->runScan([
            'update' => true,
        ]);

        self::assertSame(5, $exitCode);
        self::assertStringContainsString('Touched catalog', $output);
        self::assertStringContainsString('cards.authorPublishedBy', file_get_contents($cardsDe));
        self::assertStringContainsString('cards.authorPublishedBy', file_get_contents($cardsEn));

        [, $exitCodeAfterUpdate] = $this->runScan();
        self::assertSame(0, $exitCodeAfterUpdate);
    }

    /**
     * @test
     */
    public function scanCommandQuietSuppressesTableButKeepsWarnings(): void
    {
        [$output, $exitCode] = $this->runScan([
            'quiet' => true,
        ]);

        self::assertSame(5, $exitCode);
        self::assertStringNotContainsString('Locale \"', $output);
        self::assertStringContainsString('Prepared scan for', $output);
        self::assertStringContainsString('Placeholder warnings', $output);
    }

    /**
     * @test
     */
    public function scanCommandQuieterDisablesStdout(): void
    {
        [$output, $exitCode, $stderr] = $this->runScan([
            'quieter' => true,
        ]);

        self::assertSame(5, $exitCode);
        self::assertSame('', trim($output));
        self::assertStringContainsString('placeholderWarning', $stderr);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{string, int, string}
     */
    private function runScan(array $overrides = []): array
    {
        [$command, $buffer, $response] = $this->bootstrapCommand();
        $arguments = array_merge([
            'package' => 'Two13Tec.Senegal',
            'source' => null,
            'path' => static::getFixturePackagePath(),
            'locales' => 'de,en',
            'id' => null,
            'format' => null,
            'update' => null,
            'llm' => null,
            'sourceLocale' => null,
            'llmProvider' => null,
            'llmModel' => null,
            'dryRun' => null,
            'ignorePlaceholder' => null,
            'quiet' => null,
            'quieter' => null,
        ], $overrides);

        try {
            $command->scanCommand(
                $arguments['package'],
                $arguments['source'],
                $arguments['path'],
                $arguments['locales'],
                $arguments['id'],
                $arguments['format'],
                $arguments['update'],
                $arguments['llm'],
                $arguments['sourceLocale'],
                $arguments['llmProvider'],
                $arguments['llmModel'],
                $arguments['dryRun'],
                $arguments['ignorePlaceholder'],
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
