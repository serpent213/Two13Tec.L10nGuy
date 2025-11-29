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

/**
 * A normalized reference discovered in PHP, Fusion or YAML sources.
 */
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
    ) {
    }
}
