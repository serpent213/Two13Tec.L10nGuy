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
 * Identifies a translation by package, source and id.
 */
#[Flow\Proxy(false)]
final readonly class TranslationKey
{
    public function __construct(
        public string $packageKey,
        public string $sourceName,
        public string $identifier
    ) {
    }

    public function withIdentifier(string $identifier): self
    {
        return new self($this->packageKey, $this->sourceName, $identifier);
    }
}
