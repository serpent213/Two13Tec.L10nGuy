<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Domain\Dto;

/**
 * Captures runtime stats for LLM API calls.
 */
final class LlmRunStatistics
{
    public int $apiCalls = 0;
    public int $estimatedInputTokens = 0;
    public int $estimatedOutputTokens = 0;

    public function registerCall(int $inputTokens, int $outputTokens): void
    {
        $this->apiCalls++;
        $this->estimatedInputTokens += $inputTokens;
        $this->estimatedOutputTokens += $outputTokens;
    }
}
