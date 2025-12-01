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
    public const DEFAULT_BATCH_SIZE = 11;
    public const DEFAULT_MAX_CROSS_REFERENCE_LOCALES = 6;
    public const DEFAULT_CONTEXT_WINDOW_LINES = 5;
    public const DEFAULT_RATE_LIMIT_DELAY = 100;

    public function __construct(
        public bool $enabled = false,
        public ?string $provider = null,
        public ?string $model = null,
        public bool $dryRun = false,
        public ?string $sourceLocale = null,
        public int $batchSize = self::DEFAULT_BATCH_SIZE,
        public int $maxCrossReferenceLocales = self::DEFAULT_MAX_CROSS_REFERENCE_LOCALES,
        public int $contextWindowLines = self::DEFAULT_CONTEXT_WINDOW_LINES,
        public bool $includeNodeTypeContext = true,
        public bool $includeExistingTranslations = true,
        public ?string $newState = null,
        public ?string $newStateQualifier = null,
        public bool $noteEnabled = false,
        public int $maxTokensPerCall = 4096,
        public int $rateLimitDelay = self::DEFAULT_RATE_LIMIT_DELAY,
        public string $systemPrompt = '',
        public bool $debug = false,
    ) {}
}
