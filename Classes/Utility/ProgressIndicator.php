<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Utility;

/**
 * Renders a single-line spinner with progress for CLI tasks.
 */
final class ProgressIndicator
{
    private const SPINNER_WIDTH = 4;
    private const COLOR_RED = "\033[31m";
    private const COLOR_BG_DARK = "\033[100m";
    private const COLOR_RESET = "\033[0m";
    private const CURSOR_LINE_END = "\033[999G";
    private const CLEAR_LINE = "\033[2K";
    private const HALF_LEFT = '▌';
    private const HALF_RIGHT = '▐';

    private int $position = 0;  // Half-cell index: 0..(SPINNER_WIDTH*2 - 1)
    private int $direction = 1; // 1 = right, -1 = left
    private string $progressFormat;

    public function __construct(string $progressFormat)
    {
        $this->progressFormat = $progressFormat;
    }

    /**
     * Advance the spinner one heartbeat and render formatted progress text.
     */
    public function tick(int $completedCalls, int $totalCalls): void
    {
        $this->render($completedCalls, $totalCalls, true);
    }

    /**
     * Render the initial spinner state before work begins.
     */
    public function start(int $totalCalls): void
    {
        $this->render(0, $totalCalls, false);
    }

    private function render(int $completedCalls, int $totalCalls, bool $advance): void
    {
        if ($advance) {
            $this->advance();
        }

        $cellIndex = intdiv($this->position, 2);
        $isRightHalf = ($this->position % 2) === 1;
        $spinner = $this->renderSpinner($cellIndex, $isRightHalf);
        $progress = sprintf($this->progressFormat, $completedCalls, $totalCalls);

        echo "\r{$spinner} {$progress}" . self::CURSOR_LINE_END;
    }

    /**
     * Clear the spinner line and optionally print a final message with newline.
     */
    public function finish(?string $message = null): void
    {
        echo "\r" . self::CLEAR_LINE;

        if ($message !== null) {
            echo $message;
        }
    }

    private function renderSpinner(int $cellIndex, bool $isRightHalf): string
    {
        $slots = array_fill(0, self::SPINNER_WIDTH, self::COLOR_BG_DARK . ' ' . self::COLOR_RESET);
        $slots[$cellIndex] = self::COLOR_BG_DARK . self::COLOR_RED
            . ($isRightHalf ? self::HALF_RIGHT : self::HALF_LEFT)
            . self::COLOR_RESET;

        return implode('', $slots);
    }

    private function advance(): void
    {
        $maxPosition = self::SPINNER_WIDTH * 2 - 1;
        $this->position += $this->direction;

        if ($this->position > $maxPosition) {
            $this->position = $maxPosition - 1;
            $this->direction = -1;
        } elseif ($this->position < 0) {
            $this->position = 1;
            $this->direction = 1;
        }
    }
}
