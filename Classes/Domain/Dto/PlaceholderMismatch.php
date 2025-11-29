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
 * Warns about placeholder expectations that do not match the reference call.
 */
#[Flow\Proxy(false)]
final readonly class PlaceholderMismatch
{
    /**
     * @param list<string> $missingPlaceholders
     * @param list<string> $referencePlaceholders
     * @param list<string> $catalogPlaceholders
     */
    public function __construct(
        public string $locale,
        public TranslationKey $key,
        public array $missingPlaceholders,
        public array $referencePlaceholders,
        public array $catalogPlaceholders,
        public TranslationReference $reference,
        public ?CatalogEntry $catalogEntry,
    ) {
    }
}
