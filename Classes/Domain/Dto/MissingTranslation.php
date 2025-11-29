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
 * Represents a missing translation entry for a given locale.
 */
#[Flow\Proxy(false)]
final readonly class MissingTranslation
{
    public function __construct(
        public string $locale,
        public string $packageKey,
        public string $sourceName,
        public string $identifier,
        public TranslationReference $reference
    ) {
    }
}
