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

/**
 * Detects whether the optional php-llm/llm-chain dependency is available.
 */
#[Flow\Scope('singleton')]
final class LlmLibraryAvailability
{
    private const LLM_CHAIN_CLASS = 'PhpLlm\\LlmChain\\Chain\\Chain';

    private ?bool $forcedAvailability = null;

    public function isAvailable(): bool
    {
        if ($this->forcedAvailability !== null) {
            return $this->forcedAvailability;
        }

        return class_exists(self::LLM_CHAIN_CLASS);
    }

    public function forceAvailability(?bool $forceAvailability): void
    {
        $this->forcedAvailability = $forceAvailability;
    }
}
