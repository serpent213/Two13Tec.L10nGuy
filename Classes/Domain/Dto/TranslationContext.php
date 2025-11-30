<?php

declare(strict_types=1);

namespace Two13Tec\L10nGuy\Domain\Dto;

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
 * Aggregated context for LLM translation prompts.
 */
#[Flow\Proxy(false)]
final readonly class TranslationContext
{
    /**
     * @param list<array{id: string, source: ?string, translations: array<string, string>}> $existingTranslations
     */
    public function __construct(
        public ?string $sourceSnippet = null,
        public ?string $nodeTypeContext = null,
        public array $existingTranslations = [],
    ) {
    }
}
