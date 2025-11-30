<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Domain\Dto;

use Neos\Flow\Annotations as Flow;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

/**
 * Aggregates token estimation results for LLM dry-runs.
 */
#[Flow\Proxy(false)]
final readonly class TokenEstimation
{
    public function __construct(
        public int $translationCount,
        public int $uniqueTranslationIds,
        public int $apiCallCount,
        public int $estimatedInputTokens,
        public int $estimatedOutputTokens,
        public int $peakTokensPerCall
    ) {
    }

    public function exceedsLimit(int $maxTokensPerCall): bool
    {
        return $maxTokensPerCall > 0 && $this->peakTokensPerCall > $maxTokensPerCall;
    }
}
