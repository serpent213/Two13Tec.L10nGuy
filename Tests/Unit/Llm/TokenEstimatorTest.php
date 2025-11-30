<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Tests\Unit\Llm;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use PHPUnit\Framework\TestCase;
use Two13Tec\L10nGuy\Llm\TokenEstimator;

/**
 * @covers \Two13Tec\L10nGuy\Llm\TokenEstimator
 */
final class TokenEstimatorTest extends TestCase
{
    /**
     * @test
     */
    public function estimatesInputAndOutputTokens(): void
    {
        $estimator = new TokenEstimator();
        $result = $estimator->estimate(
            [
                ['userPrompt' => str_repeat('a', 400), 'translations' => 2],
                ['userPrompt' => 'short', 'translations' => 1],
            ],
            2,
            'system prompt'
        );

        self::assertSame(3, $result->translationCount);
        self::assertSame(2, $result->uniqueTranslationIds);
        self::assertSame(2, $result->apiCallCount);
        self::assertSame(110, $result->estimatedInputTokens);
        self::assertSame(90, $result->estimatedOutputTokens);
        self::assertSame(104, $result->peakTokensPerCall);
    }

    /**
     * @test
     */
    public function detectsWhenCallWouldExceedLimit(): void
    {
        $estimator = new TokenEstimator();
        $result = $estimator->estimate(
            [
                ['userPrompt' => 'abc', 'translations' => 1],
            ],
            1,
            'sys'
        );

        self::assertFalse($result->exceedsLimit(10));
        self::assertTrue($result->exceedsLimit(1));
    }
}
