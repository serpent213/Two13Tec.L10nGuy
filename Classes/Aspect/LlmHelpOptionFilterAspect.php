<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Aspect;

/*
 * This file is part of the Two13Tec.L10nGuy package.
 *
 * (c) Steffen Beyer, 213tec
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\Command;
use Neos\Flow\Cli\CommandArgumentDefinition;
use Two13Tec\L10nGuy\Command\L10nCommandController;
use Two13Tec\L10nGuy\Llm\LlmLibraryAvailability;

/**
 * Hides LLM-related CLI options from help output when the dependency is missing.
 */
#[Flow\Aspect]
final class LlmHelpOptionFilterAspect
{
    #[Flow\Inject]
    protected LlmLibraryAvailability $llmLibraryAvailability;

    /**
     * @return array<CommandArgumentDefinition>
     * @Flow\Around("method(Neos\Flow\Cli\Command->getArgumentDefinitions())")
     */
    public function filterLlmOptionsFromHelp(JoinPointInterface $joinPoint): array
    {
        /** @var array<CommandArgumentDefinition> $argumentDefinitions */
        $argumentDefinitions = $joinPoint->getAdviceChain()->proceed($joinPoint);
        $command = $joinPoint->getProxy();

        if (
            !$command instanceof Command
            || $command->getControllerClassName() !== L10nCommandController::class
            || $this->llmLibraryAvailability->isAvailable()
        ) {
            return $argumentDefinitions;
        }

        return array_values(array_filter(
            $argumentDefinitions,
            static fn (CommandArgumentDefinition $definition): bool => stripos($definition->getName(), 'llm') !== 0
        ));
    }
}
