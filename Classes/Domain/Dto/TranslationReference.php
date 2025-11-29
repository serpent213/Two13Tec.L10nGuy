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
 * A normalized reference discovered in PHP, Fusion or YAML sources.
 */
#[Flow\Proxy(false)]
final readonly class TranslationReference
{
    public const CONTEXT_PHP = 'php';
    public const CONTEXT_FUSION = 'fusion';
    public const CONTEXT_YAML = 'yaml';

    /**
     * @param array<string, string> $placeholders
     */
    public function __construct(
        public string $packageKey,
        public string $sourceName,
        public string $identifier,
        public string $context,
        public string $filePath,
        public int $lineNumber,
        public ?string $fallback = null,
        public array $placeholders = [],
        public bool $isPlural = false,
    ) {
    }

    /**
     * @return list<string>
     */
    public function placeholderNames(): array
    {
        return array_keys($this->placeholders);
    }

    /**
     * @return list<string>
     */
    public function fallbackPlaceholders(): array
    {
        return $this->extractPlaceholders($this->fallback);
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        preg_match_all('/\{([A-Za-z0-9_.:-]+)\}/', $value, $matches);
        if (!isset($matches[1])) {
            return [];
        }

        $placeholders = array_values(array_unique(array_filter($matches[1], static fn ($placeholder) => $placeholder !== '')));
        sort($placeholders);

        return $placeholders;
    }
}
