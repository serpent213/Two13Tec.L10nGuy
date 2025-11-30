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
 * Encapsulates LLM-related configuration derived from settings and CLI options.
 */
#[Flow\Proxy(false)]
final readonly class LlmConfiguration
{
    public function __construct(
        public bool $enabled = false,
        public ?string $provider = null,
        public ?string $model = null,
        public bool $dryRun = false,
        public int $batchSize = 1,
        public int $maxBatchSize = 10,
        public int $contextWindowLines = 5,
        public bool $includeNodeTypeContext = true,
        public bool $includeExistingTranslations = true,
        public bool $markAsGenerated = true,
        public string $defaultState = 'needs-review',
        public int $maxTokensPerCall = 4096,
        public int $rateLimitDelay = 100,
        public string $systemPrompt = '',
    ) {
    }
}
