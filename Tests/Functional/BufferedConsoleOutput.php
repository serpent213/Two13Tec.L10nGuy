<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Functional;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Buffered symfony console output that also buffers stderr for assertions.
 */
final class BufferedConsoleOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private OutputInterface $errorOutput;
    private array $consoleSectionOutputs = [];

    public function __construct()
    {
        parent::__construct();
        $this->errorOutput = new BufferedOutput();
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(OutputInterface $error)
    {
        $this->errorOutput = $error;
    }

    public function fetchErrorOutput(): string
    {
        return $this->errorOutput instanceof BufferedOutput ? $this->errorOutput->fetch() : '';
    }

    public function section(): ConsoleSectionOutput
    {
        $stream = fopen('php://memory', 'w+');

        return new ConsoleSectionOutput(
            $stream,
            $this->consoleSectionOutputs,
            $this->getVerbosity(),
            $this->isDecorated(),
            $this->getFormatter()
        );
    }
}
