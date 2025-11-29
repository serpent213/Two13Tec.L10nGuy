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
 * DTO used to express catalog mutations when running in update/delete mode.
 */
final class CatalogMutation
{
    private string $normalizedIdentifier = '';

    /**
     * @param array<string, string> $placeholders
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $packageKey,
        public readonly string $sourceName,
        string $identifier = '',
        public string $fallback = '',
        public array $placeholders = [],
    ) {
        $this->identifier = $identifier;
    }

    public string $identifier {
        get => $this->normalizedIdentifier;
        set => $this->normalizedIdentifier = trim((string)$value);
    }
}
