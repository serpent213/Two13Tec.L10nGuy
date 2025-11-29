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
use Two13Tec\L10nGuy\Command\LocalizationScanCommandController;

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
        $authorMissing = array_values(array_filter($payload['missing'], static fn (array $row) => $row['id'] === 'cards.authorPublishedBy'));
        self::assertCount(1, $authorMissing);
        self::assertSame('en', $authorMissing[0]['locale']);
        self::assertArrayHasKey('warnings', $payload);
        self::assertIsArray($payload['warnings']);
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
     * @param array<string, mixed> $overrides
     * @return array{string, int}
     */
    private function runScan(array $overrides = []): array
    {
        [$command, $buffer, $response] = $this->bootstrapCommand();
        $arguments = array_merge([
            'package' => 'Two13Tec.Senegal',
            'source' => null,
            'path' => static::getFixturePackagePath(),
            'locales' => 'de,en',
            'format' => null,
            'dryRun' => null,
            'update' => null,
        ], $overrides);

        try {
            $command->scanCommand(
                $arguments['package'],
                $arguments['source'],
                $arguments['path'],
                $arguments['locales'],
                $arguments['format'],
                $arguments['dryRun'],
                $arguments['update']
            );
        } catch (StopCommandException) {
        }

        return [$buffer->fetch(), $response->getExitCode()];
    }

    /**
     * @return array{LocalizationScanCommandController, BufferedOutput, CliResponse}
     */
    private function bootstrapCommand(): array
    {
        /** @var LocalizationScanCommandController $command */
        $command = $this->objectManager->get(LocalizationScanCommandController::class);
        $buffer = new BufferedOutput();
        $consoleOutput = new ConsoleOutput();
        $consoleOutput->setOutput($buffer);
        $this->setProtectedProperty($command, 'output', $consoleOutput);

        $response = new CliResponse();
        $this->setProtectedProperty($command, 'response', $response);

        return [$command, $buffer, $response];
    }

    private function setProtectedProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionObject($object);
        while ($reflection !== false) {
            if ($reflection->hasProperty($propertyName)) {
                $property = $reflection->getProperty($propertyName);
                $property->setAccessible(true);
                $property->setValue($object, $value);
                return;
            }
            $reflection = $reflection->getParentClass();
        }

        throw new \RuntimeException(sprintf('Property %s not found on %s', $propertyName, $object::class), 1731159001);
    }
}
