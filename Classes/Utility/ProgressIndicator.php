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
    private const HALF_LEFT = '▌';
    private const HALF_RIGHT = '▐';

    private int $position = 0;
    private int $direction = 1; // 1 = right, -1 = left
    private int $phase = 0; // 0 = left block, 1 = right block
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
        // When moving right: phase 0 = ▌ (entering left), phase 1 = ▐ (exiting right)
        // When moving left:  phase 0 = ▐ (entering right), phase 1 = ▌ (exiting left)
        $halfDir = $this->phase === 0 ? -$this->direction : $this->direction;
        $spinner = $this->renderSpinner($this->position, $halfDir);
        $progress = sprintf($this->progressFormat, $completedCalls, $totalCalls);

        echo "\r{$spinner} {$progress}";

        $this->advance();
    }

    /**
     * Clear the spinner line and optionally print a final message with newline.
     */
    public function finish(?string $message = null): void
    {
        $wipe = str_repeat(' ', self::SPINNER_WIDTH + 1 + 32);
        echo "\r{$wipe}\r";

        if ($message !== null) {
            echo $message;
        }

        echo PHP_EOL;
    }

    private function renderSpinner(int $position, int $direction): string
    {
        $slots = array_fill(0, self::SPINNER_WIDTH, self::COLOR_BG_DARK . ' ' . self::COLOR_RESET);
        $slots[$position] = self::COLOR_BG_DARK . self::COLOR_RED
            . ($direction < 0 ? self::HALF_LEFT : self::HALF_RIGHT)
            . self::COLOR_RESET;

        return implode('', $slots);
    }

    private function advance(): void
    {
        if ($this->phase === 0) {
            $this->phase = 1;
            return;
        }

        $this->phase = 0;
        $this->position += $this->direction;

        if ($this->position >= self::SPINNER_WIDTH) {
            $this->position = self::SPINNER_WIDTH - 1;
            $this->direction = -1;
            $this->phase = 1; // right block first when moving back
        } elseif ($this->position < 0) {
            $this->position = 0;
            $this->direction = 1;
            $this->phase = 1; // right block first when moving back
        }
    }
}
