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
 * Represents a translation entry that already exists inside an XLF catalog.
 */
final readonly class CatalogEntry
{
    public const STATE_NEW = 'new';
    public const STATE_TRANSLATED = 'translated';
    public const STATE_NEEDS_REVIEW = 'needs-review';

    public function __construct(
        public string $locale,
        public string $packageKey,
        public string $sourceName,
        public string $identifier,
        public string $filePath,
        public ?string $source = null,
        public ?string $target = null,
        public ?string $state = null,
    ) {
    }
}
