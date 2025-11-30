<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Llm;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Two13Tec\L10nGuy\Domain\Dto\TokenEstimation;

/**
 * Estimates token usage for LLM calls based on prompt sizes.
 */
#[Flow\Scope('singleton')]
final class TokenEstimator
{
    private const TOKENS_PER_TRANSLATION = 30;

    /**
     * @param list<array{userPrompt: string, translations: int}> $calls
     */
    public function estimate(array $calls, int $uniqueTranslationIds, string $systemPrompt): TokenEstimation
    {
        $systemTokens = $this->estimateTokens($systemPrompt);

        $inputTokens = 0;
        $outputTokens = 0;
        $peakTokensPerCall = 0;

        foreach ($calls as $call) {
            $userTokens = $this->estimateTokens($call['userPrompt']);
            $callTokens = $systemTokens + $userTokens;

            $peakTokensPerCall = max($peakTokensPerCall, $callTokens);
            $inputTokens += $callTokens;
            $outputTokens += $call['translations'] * self::TOKENS_PER_TRANSLATION;
        }

        return new TokenEstimation(
            translationCount: array_sum(
                array_map(static fn (array $call): int => $call['translations'], $calls)
            ),
            uniqueTranslationIds: $uniqueTranslationIds,
            apiCallCount: count($calls),
            estimatedInputTokens: $inputTokens,
            estimatedOutputTokens: $outputTokens,
            peakTokensPerCall: $peakTokensPerCall
        );
    }

    public function estimateTokens(string $text): int
    {
        $length = mb_strlen($text);

        return max(1, (int)ceil($length / 4));
    }
}
